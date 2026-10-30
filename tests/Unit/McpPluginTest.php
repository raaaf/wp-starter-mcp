<?php

declare(strict_types=1);

namespace WpStarterMcp\Tests\Unit;

use WpStarterMcp\McpPlugin;

final class McpPluginTest extends WpTestCase
{
    public function testOffRegistersNoHooksBesideUpdates(): void
    {
        McpPlugin::boot();

        $this->assertSame([], \WpState::$hooks);
    }

    public function testOnRegistersEndpoints(): void
    {
        \WpState::$options['wpsmcp_enabled'] = '1';
        McpPlugin::boot();

        $this->assertContains('parse_request', array_column(\WpState::$hooks, 0));
    }
}
