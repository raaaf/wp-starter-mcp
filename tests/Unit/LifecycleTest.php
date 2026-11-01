<?php

declare(strict_types=1);

namespace WpStarterMcp\Tests\Unit;

use WpStarterMcp\Lifecycle;

final class LifecycleTest extends WpTestCase
{
    /** @return array{user_id: int, family_id: string, client_id: string, scope: string} */
    private function fields(): array
    {
        return ['user_id' => 3, 'family_id' => 'f1', 'client_id' => 'c', 'scope' => 'mcp:read'];
    }

    public function testDeactivationRevokesTokensAndSwitchesOff(): void
    {
        $this->allowUser(3);
        $store = new \WpStarterMcp\OAuth\TokenStore();
        $token = $store->issue('access', 3600, $this->fields());
        \WpState::$options['wpsmcp_enabled'] = '1';
        $this->assertNotNull($store->validateAccess($token));

        Lifecycle::deactivate();

        $this->assertNull($store->validateAccess($token));
        $this->assertSame('0', \WpState::$options['wpsmcp_enabled']);
        $this->assertSame(['wpsmcp_prune', 'wpsmcp_manifest'], \WpState::$clearedCron);
    }

    public function testReactivationRevivesNoToken(): void
    {
        $this->allowUser(3);
        $store = new \WpStarterMcp\OAuth\TokenStore();
        $token = $store->issue('access', 3600, $this->fields());
        Lifecycle::deactivate();
        \WpState::$options['wpsmcp_enabled'] = '1';

        $this->assertNull($store->validateAccess($token));
    }

    public function testPasswordChangeRevokesButOtherProfileEditDoesNot(): void
    {
        $this->allowUser(3);
        $store = new \WpStarterMcp\OAuth\TokenStore();
        $token = $store->issue('access', 3600, $this->fields());

        Lifecycle::onProfileUpdate(3, (object) ['user_pass' => 'hash']);
        $this->assertNotNull($store->validateAccess($token));

        Lifecycle::onProfileUpdate(3, (object) ['user_pass' => 'old-hash']);
        $this->assertNull($store->validateAccess($token));
    }

    private function revokedAt(string $token): mixed
    {
        $row = (new \WpStarterMcp\OAuth\TokenStore())->find('access', $token);
        $this->assertNotNull($row);

        return $row['revoked_at'];
    }

    public function testPasswordChangeRevokesTheUsersTokensOnEverySiteOfTheNetwork(): void
    {
        $this->allowUser(3);
        \WpState::$multisite = true;
        \WpState::$blogsOfUser[3] = [1, 2];
        $store = new \WpStarterMcp\OAuth\TokenStore();
        $here = $store->issue('access', 3600, $this->fields());
        switch_to_blog(2);
        $there = $store->issue('access', 3600, $this->fields());
        restore_current_blog();

        Lifecycle::onPasswordReset((object) ['ID' => 3]);

        $this->assertNotNull($this->revokedAt($here));
        switch_to_blog(2);
        $this->assertNotNull($this->revokedAt($there));
        restore_current_blog();
        $this->assertSame(1, \WpState::$currentBlog);
    }

    public function testNetworkRevocationAlsoCoversTheCurrentSite(): void
    {
        $this->allowUser(3);
        \WpState::$multisite = true;
        $token = (new \WpStarterMcp\OAuth\TokenStore())->issue('access', 3600, $this->fields());

        Lifecycle::revokeUser(3);

        $this->assertNotNull($this->revokedAt($token));
    }

    public function testRevocationOnASiteWithoutTablesSendsNoQuery(): void
    {
        unset(\WpState::$options[\WpStarterMcp\Schema::OPTION]);
        \WpState::$userMeta[3]['mcp_adapter_sessions'] = ['s1' => 1];

        Lifecycle::revokeUser(3);
        Lifecycle::revokeAll();

        $this->assertSame(0, $GLOBALS['wpdb']->queries);
        $this->assertArrayHasKey('mcp_adapter_sessions', \WpState::$userMeta[3]);
    }

    public function testLosingEditPagesRevokesTheTokens(): void
    {
        $this->allowUser(3);
        $token = (new \WpStarterMcp\OAuth\TokenStore())->issue('access', 3600, $this->fields());
        \WpState::$canEdit[3] = false;

        Lifecycle::onRoleChange(3, 'subscriber', ['editor']);

        $this->assertNotNull($this->revokedAt($token));
    }

    public function testChangingToAnotherRoleThatCanEditPagesKeepsTheTokens(): void
    {
        $this->allowUser(3);
        $token = (new \WpStarterMcp\OAuth\TokenStore())->issue('access', 3600, $this->fields());

        Lifecycle::onRoleChange(3, 'administrator', ['editor']);

        $this->assertNull($this->revokedAt($token));
    }
}
