<?php

declare(strict_types=1);

namespace WpStarterMcp\OAuth;

/**
 * Static map of known client IDs to their exact redirect URIs. No metadata fetch and no
 * registration endpoint: an unlisted client or an inexact redirect is refused.
 */
final class ClientMap
{
    /** @var array<string, list<string>> */
    private const CLIENTS = [
        // Verified 2026-10-30 with the claude.ai web connector (plan, Claude client facts).
        'https://claude.ai/oauth/mcp-oauth-client-metadata' => [
            'https://claude.ai/api/mcp/auth_callback',
        ],
        // TODO(step 11): add the Claude Code client ID and its loopback redirect once
        // recorded from a real run. Do not invent one here.
    ];

    public static function allows(string $clientId, string $redirectUri): bool
    {
        return isset(self::CLIENTS[$clientId]) && in_array($redirectUri, self::CLIENTS[$clientId], true);
    }

    public static function host(string $url): string
    {
        return strtolower((string) parse_url($url, PHP_URL_HOST));
    }
}
