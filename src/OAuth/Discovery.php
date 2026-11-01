<?php

declare(strict_types=1);

namespace WpStarterMcp\OAuth;

use WpStarterMcp\Config;

/**
 * Well-known documents, answered on parse_request. Nothing is rate limited per address:
 * Anthropic fetches them server-side from shared addresses.
 */
final class Discovery
{
    private const RESOURCE_PREFIX = '/.well-known/oauth-protected-resource';
    private const SERVER_PREFIXES = ['/.well-known/oauth-authorization-server', '/.well-known/openid-configuration'];

    public static function register(): void
    {
        add_action('parse_request', [self::class, 'handle'], 1);
    }

    public static function handle(): void
    {
        $uri = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        $document = self::match((string) parse_url($uri, PHP_URL_PATH));

        if ($document === null) {
            return;
        }

        status_header(200);
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        echo wp_json_encode($document, JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function match(string $path): ?array
    {
        $pos = strpos($path, '/.well-known/');
        if ($pos === false) {
            return null;
        }

        $known = substr($path, $pos);

        if (str_starts_with($known, self::RESOURCE_PREFIX)) {
            $suffix = rtrim(substr($known, strlen(self::RESOURCE_PREFIX)), '/');

            return $suffix === '' || $suffix === Config::mcpPath() ? self::resourceDocument() : null;
        }

        foreach (self::SERVER_PREFIXES as $prefix) {
            if (str_starts_with($known, $prefix)) {
                $suffix = rtrim(substr($known, strlen($prefix)), '/');
                $home = rtrim((string) parse_url(home_url(), PHP_URL_PATH), '/');

                return $suffix === '' || $suffix === $home ? self::serverDocument() : null;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public static function resourceDocument(): array
    {
        return [
            'resource' => Config::mcpUrl(),
            'authorization_servers' => [Config::issuer()],
            'bearer_methods_supported' => ['header'],
            'scopes_supported' => Config::SCOPES,
        ];
    }

    /** @return array<string, mixed> */
    public static function serverDocument(): array
    {
        return [
            'issuer' => Config::issuer(),
            'authorization_endpoint' => Config::authorizeUrl(),
            'token_endpoint' => Config::tokenUrl(),
            'scopes_supported' => Config::SCOPES,
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'code_challenge_methods_supported' => ['S256'],
            'client_id_metadata_document_supported' => true,
        ];
    }
}
