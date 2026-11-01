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
}
