<?php

declare(strict_types=1);

namespace WpStarterMcp\Tests\Unit;

use WpStarterMcp\Pruner;
use WpStarterMcp\Schema;

final class TokenStoreTest extends WpTestCase
{
    /** @return array{user_id: int, family_id: string, client_id: string, scope: string} */
    private function fields(): array
    {
        return ['user_id' => 3, 'family_id' => 'f1', 'client_id' => 'c', 'scope' => 'mcp:read'];
    }

    public function testOnlyHashIsStored(): void
    {
        $token = $this->store()->issue('access', 3600, $this->fields());

        $this->assertNotNull($this->store()->find('access', $token));
        $this->assertStringNotContainsString($token, json_encode($GLOBALS['wpdb']->tables) ?: '');
    }

    public function testConsumeSucceedsOnlyOnce(): void
    {
        $token = $this->store()->issue('code', 60, $this->fields());
        $id = (int) $this->store()->find('code', $token)['id'];

        $this->assertTrue($this->store()->consume($id));
        $this->assertFalse($this->store()->consume($id));
    }

    public function testRevokedRowCannotBeConsumed(): void
    {
        $token = $this->store()->issue('code', 60, $this->fields());
        $id = (int) $this->store()->find('code', $token)['id'];
        $this->store()->revokeFamily('f1');

        $this->assertFalse($this->store()->consume($id));
    }

    public function testExpiredAccessTokenIsRefused(): void
    {
        $this->allowUser(3);
        $token = $this->store()->issue('access', 3600, $this->fields());
        $this->now += 3601;

        $this->assertNull($this->store()->validateAccess($token));
    }

    public function testUserWithoutEditPagesIsRefused(): void
    {
        $token = $this->store()->issue('access', 3600, $this->fields());

        $this->assertNull($this->store()->validateAccess($token));
    }

    public function testRefreshTokenIsNotAnAccessToken(): void
    {
        $this->allowUser(3);
        $token = $this->store()->issue('refresh', 3600, $this->fields());

        $this->assertNull($this->store()->validateAccess($token));
    }

    public function testRevokeFamilyLeavesOtherFamilies(): void
    {
        $this->allowUser(3);
        $a = $this->store()->issue('access', 3600, $this->fields());
        $b = $this->store()->issue('access', 3600, ['family_id' => 'f2'] + $this->fields());

        $this->store()->revokeFamily('f1');

        $this->assertNull($this->store()->validateAccess($a));
        $this->assertNotNull($this->store()->validateAccess($b));
    }

    public function testRevokingDeletesAdapterSessions(): void
    {
        \WpState::$userMeta[3]['mcp_adapter_sessions'] = ['s1' => 1];
        $this->store()->issue('access', 3600, $this->fields());

        $this->store()->revokeFamily('f1');

        $this->assertArrayNotHasKey('mcp_adapter_sessions', \WpState::$userMeta[3]);
    }

    public function testPruneKeepsRecentlyExpiredAndLiveRowsButDropsOldOnes(): void
    {
        $old = $this->store()->issue('access', 10, $this->fields());
        $this->now += 3 * 86400;
        $recent = $this->store()->issue('refresh', 3600, $this->fields());
        $this->now += 3600 + 100; // recent expired 100 s ago

        Pruner::run($this->now);

        $this->assertNull($this->store()->find('access', $old));
        $this->assertNotNull($this->store()->find('refresh', $recent));
    }

    public function testPruneDropsOldAuditRowsOnly(): void
    {
        global $wpdb;
        \WpStarterMcp\Audit::record('connect', 3);
        $wpdb->tables[Schema::auditTable()][1]['created_at'] = $this->now - 91 * 86400;
        $wpdb->insert(Schema::auditTable(), ['created_at' => $this->now - 86400, 'user_id' => 3, 'event' => 'connect']);

        Pruner::run($this->now);

        $this->assertCount(1, $wpdb->tables[Schema::auditTable()]);
    }
}
