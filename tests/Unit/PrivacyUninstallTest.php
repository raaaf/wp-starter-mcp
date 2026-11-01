<?php

declare(strict_types=1);

namespace WpStarterMcp\Tests\Unit;

use WpStarterMcp\Audit;
use WpStarterMcp\Privacy;
use WpStarterMcp\Schema;
use WpStarterMcp\Uninstall;

final class PrivacyUninstallTest extends WpTestCase
{
    private function seed(): void
    {
        $this->allowUser(3);
        $this->allowUser(4);
        Audit::record('create_draft_page', 3, 50, 'claude.ai');
        Audit::record('create_draft_page', 4, 51, 'claude.ai');
        $this->store()->issue('refresh', 3600, ['user_id' => 3, 'family_id' => 'f3', 'client_id' => 'https://claude.ai/x', 'scope' => 'mcp:read']);
    }

    public function testExportCoversAuditAndConnectionsOfThatUserOnly(): void
    {
        $this->seed();

        $items = Privacy::export('u3@example.test')['data'];

        $groups = array_count_values(array_column($items, 'group_id'));
        $this->assertSame(['wpsmcp-audit' => 1, 'wpsmcp-connections' => 1], $groups);
    }

    public function testExportOfUnknownEmailIsEmpty(): void
    {
        $this->seed();

        $this->assertSame([], Privacy::export('nobody@example.test')['data']);
    }

    public function testEraserRemovesAuditAndTokensOfThatUserOnly(): void
    {
        $this->seed();

        $result = Privacy::erase('u3@example.test');

        $this->assertTrue($result['items_removed']);
        $this->assertCount(1, $GLOBALS['wpdb']->tables[Schema::auditTable()]);
        $this->assertSame(4, (int) array_values($GLOBALS['wpdb']->tables[Schema::auditTable()])[0]['user_id']);
        $this->assertSame([], $GLOBALS['wpdb']->tables[Schema::tokensTable()]);
    }

    public function testUninstallKeepsDataWithoutDeleteAllData(): void
    {
        $this->seed();

        Uninstall::site();

        $this->assertArrayHasKey(Schema::auditTable(), $GLOBALS['wpdb']->tables);
    }

    public function testUninstallDropsTablesAfterDeleteAllData(): void
    {
        $this->seed();
        \WpState::$options['wpsmcp_delete_data'] = '1';

        Uninstall::site();

        $this->assertArrayNotHasKey(Schema::auditTable(), $GLOBALS['wpdb']->tables);
        $this->assertArrayNotHasKey(Schema::tokensTable(), $GLOBALS['wpdb']->tables);
    }
}
