<?php

declare(strict_types=1);

namespace WpStarterMcp;

/**
 * Registers this plugin's own MCP server on the adapter with an explicit ability list, so
 * other plugins' abilities never appear. Not registered without a usable theme contract.
 */
final class Server
{
    public static function register(): void
    {
        // The adapter's default server must not exist on client sites.
        add_filter('mcp_adapter_create_default_server', '__return_false');
        add_action('mcp_adapter_init', [self::class, 'create']);
    }

    public static function create(object $adapter): void
    {
        if (!method_exists($adapter, 'create_server') || Contract::current() === null) {
            return;
        }

        $result = $adapter->create_server(
            'wp-starter-mcp',
            Config::ROUTE_NAMESPACE,
            Config::ROUTE_MCP,
            'WP Starter',
            'Draft pages from modules',
            defined('WPSMCP_VERSION') ? WPSMCP_VERSION : '0',
            ['WP\\MCP\\Transport\\HttpTransport'],
            null,
            null,
            Abilities::NAMES,
            [],
            [],
            static fn (): bool => current_user_can('edit_pages')
        );

        if (is_wp_error($result)) {
            error_log('wpsmcp: create_server failed ' . $result->get_error_code());
        }
    }
}
