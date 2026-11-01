<?php

declare(strict_types=1);

namespace WpStarterMcp;

/**
 * Fixed names, lifetimes and URLs. No theme class is referenced anywhere in this plugin.
 */
final class Config
{
    public const ROUTE_NAMESPACE = 'wpsmcp/v1';
    public const ROUTE_MCP = 'mcp';
    public const ROUTE_TOKEN = 'token';
    public const OPTION_ENABLED = 'wpsmcp_enabled';
    public const PRUNE_HOOK = 'wpsmcp_prune';
    public const AUTHORIZE_PATH = '/wpsmcp-authorize';
    /** Setup page, created in a later step. */
    public const SETUP_PAGE = 'tools.php?page=wpsmcp';
    public const SCOPES = ['mcp:read', 'mcp:draft'];

    public const CODE_TTL = 60;
    public const ACCESS_TTL = 3600;
    public const REFRESH_TTL = 2592000;
    public const REFRESH_GRACE = 30;
    public const INTENT_TTL = 600;
    public const AUDIT_RETENTION = 7776000;

    public static function isEnabled(): bool
    {
        return get_option(self::OPTION_ENABLED) === '1';
    }

    public static function mcpUrl(): string
    {
        return rest_url(self::ROUTE_NAMESPACE . '/' . self::ROUTE_MCP);
    }

    public static function mcpRoute(): string
    {
        return '/' . self::ROUTE_NAMESPACE . '/' . self::ROUTE_MCP;
    }

    /** URL path of the MCP endpoint without trailing slash. */
    public static function mcpPath(): string
    {
        return rtrim((string) parse_url(self::mcpUrl(), PHP_URL_PATH), '/');
    }

    public static function tokenUrl(): string
    {
        return rest_url(self::ROUTE_NAMESPACE . '/' . self::ROUTE_TOKEN);
    }

    public static function authorizeUrl(): string
    {
        return home_url(self::AUTHORIZE_PATH);
    }

    public static function issuer(): string
    {
        return untrailingslashit(home_url());
    }
}
