<?php

declare(strict_types=1);

namespace WpStarterMcp;

use WpStarterMcp\OAuth\TokenStore;

/**
 * Bearer authentication for the MCP route only. Two gates: the path decides whether a token
 * may authenticate at all, and rest_pre_dispatch refuses any Bearer request whose resolved
 * route is not the MCP route (`?rest_route=` overrides the path).
 */
final class BearerGate
{
    /** user_can() inside token validation can reach determine_current_user again (e.g. a map_meta_cap filter calling wp_get_current_user). */
    private static bool $resolving = false;

    public static function register(): void
    {
        add_filter('determine_current_user', [self::class, 'determineUser'], 30);
        add_filter('rest_authentication_errors', [self::class, 'authenticationErrors'], 30);
        add_filter('rest_pre_dispatch', [self::class, 'preDispatch'], 10, 3);
        add_filter('rest_post_dispatch', [self::class, 'postDispatch'], 10, 3);
    }

    public static function bearer(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';

        return is_string($header) && preg_match('/^Bearer\s+(\S+)$/i', $header, $m) === 1 ? $m[1] : null;
    }

    private static function isMcpPath(): bool
    {
        $uri = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';

        return rtrim((string) parse_url($uri, PHP_URL_PATH), '/') === Config::mcpPath();
    }

    /**
     * Runs after application passwords (priority 20). Never before after_setup_theme: rest_url()
     * needs $wp_rewrite, and a user-0 answer would stay cached.
     */
    public static function determineUser(mixed $user): mixed
    {
        if (!did_action('after_setup_theme') || $user) {
            return $user;
        }

        $token = self::bearer();
        if ($token === null || !self::isMcpPath()) {
            return $user;
        }

        if (self::$resolving) {
            return $user;
        }

        self::$resolving = true;
        try {
            $row = TokenStore::make()->validateAccess($token);
        } finally {
            self::$resolving = false;
        }

        return $row === null ? $user : (int) $row['user_id'];
    }

    /** Authenticate again, in case an early caller cached user 0. */
    public static function authenticationErrors(mixed $result): mixed
    {
        $token = self::bearer();
        if ($result !== null || $token === null || !self::isMcpPath()) {
            return $result;
        }

        $row = TokenStore::make()->validateAccess($token);
        if ($row === null) {
            return new \WP_Error('wpsmcp_invalid_token', 'Invalid or expired token.', ['status' => 401]);
        }

        wp_set_current_user((int) $row['user_id']);

        return $result;
    }

    public static function preDispatch(mixed $result, mixed $server, \WP_REST_Request $request): mixed
    {
        // Only the MCP path can authenticate a token, so Bearer requests elsewhere belong to other plugins (JWT).
        if (self::bearer() !== null && self::isMcpPath() && untrailingslashit($request->get_route()) !== Config::mcpRoute()) {
            return new \WP_Error('wpsmcp_wrong_route', 'This token is not valid here.', ['status' => 401]);
        }

        return $result;
    }

    public static function postDispatch(\WP_HTTP_Response $response, mixed $server, \WP_REST_Request $request): \WP_HTTP_Response
    {
        $status = $response->get_status();

        if (untrailingslashit($request->get_route()) === Config::mcpRoute()
            && ($status === 401 || ($status === 403 && !is_user_logged_in()))) {
            $response->set_status(401);
            $response->header(
                'WWW-Authenticate',
                'Bearer resource_metadata="' . home_url('/.well-known/oauth-protected-resource' . Config::mcpPath()) . '"'
            );
        }

        return $response;
    }
}
