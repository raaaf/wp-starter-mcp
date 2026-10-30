<?php

declare(strict_types=1);

namespace WpStarterMcp\Tests\Unit;

use WpStarterMcp\McpPlugin;

final class McpPluginTest extends WpTestCase
{
    public function testOffRegistersNoEndpointHooks(): void
    {
        McpPlugin::boot();

        // Update channel, privacy tools, setup page and replace snapshots only: no OAuth,
        // REST, bearer or ability hook while the feature is off.
        $this->assertEqualsCanonicalizing([
            'auto_update_plugin', 'upgrader_pre_download', 'wpsmcp_manifest', 'init',
            'wp_privacy_personal_data_exporters', 'wp_privacy_personal_data_erasers',
            'admin_menu', 'admin_menu', 'wp_ajax_wpsmcp_probe',
            'admin_post_wpsmcp_enable', 'admin_post_wpsmcp_disable', 'admin_post_wpsmcp_start', 'admin_post_wpsmcp_revoke', 'admin_post_wpsmcp_delete_data',
            'page_row_actions', 'admin_post_wpsmcp_replace', 'admin_notices', 'wp_restore_post_revision', 'pre_delete_post', 'before_delete_post',
        ], array_column(\WpState::$hooks, 0));
    }

    public function testOnRegistersEndpoints(): void
    {
        \WpState::$options['wpsmcp_enabled'] = '1';
        McpPlugin::boot();

        $this->assertContains('parse_request', array_column(\WpState::$hooks, 0));
    }

    public function testBelowMinimumVersionRegistersNoEndpoint(): void
    {
        \WpState::$options['wpsmcp_enabled'] = '1';
        \WpState::$options['wpsmcp_min_version'] = '9.0.0';
        McpPlugin::boot();

        $this->assertNotContains('parse_request', array_column(\WpState::$hooks, 0));
    }
}
