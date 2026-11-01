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
    ];

    /** Claude Code's published metadata (checked 2026-11-01). It listens on a free local port per run. */
    private const LOOPBACK_CLIENT = 'https://claude.ai/oauth/claude-code-client-metadata';
    private const LOOPBACK_REDIRECT = '#^http://(localhost|127\.0\.0\.1)(:(?:[1-9]\d{0,3}|[1-5]\d{4}|6[0-4]\d{3}|65[0-4]\d{2}|655[0-2]\d|6553[0-5]))?/callback$#D';

    public static function allows(string $clientId, string $redirectUri): bool
    {
        if ($clientId === self::LOOPBACK_CLIENT) {
            return preg_match(self::LOOPBACK_REDIRECT, $redirectUri) === 1;
        }

        return isset(self::CLIENTS[$clientId]) && in_array($redirectUri, self::CLIENTS[$clientId], true);
    }

    public static function host(string $url): string
    {
        return strtolower((string) parse_url($url, PHP_URL_HOST));
    }
}
