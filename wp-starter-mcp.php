<?php

/**
 * Plugin Name:       WP Starter MCP
 * Description:       Connect Claude to this site via MCP with an OAuth sign-in. Draft-only. Off until an administrator switches it on.
 * Version:           0.1.0
 * Requires at least: 6.9
 * Requires PHP:      8.3
 * Requires Plugins:  mcp-adapter
 * Author:            Rafael Alex
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-starter-mcp
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

const WPSMCP_VERSION = '0.1.0';
const WPSMCP_FILE = __FILE__;

$wpsmcpAutoload = __DIR__ . '/vendor/autoload.php';

// Fail closed: without the autoloader nothing is registered.
if (!is_readable($wpsmcpAutoload)) {
    return;
}

require_once $wpsmcpAutoload;

register_activation_hook(__FILE__, [WpStarterMcp\Schema::class, 'install']);
register_deactivation_hook(__FILE__, [WpStarterMcp\Lifecycle::class, 'deactivate']);

WpStarterMcp\McpPlugin::boot();
