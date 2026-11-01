<?php

declare(strict_types=1);

namespace WpStarterMcp\OAuth;

use WpStarterMcp\Audit;
use WpStarterMcp\Config;
use WpStarterMcp\Contract;
use WpStarterMcp\Intent;
use WpStarterMcp\Logger;

/**
 * Authorization endpoint. Invalid client or redirect renders a local error page and never
 * redirects. Consent needs a login, edit_pages and a fresh per-user intent.
 *
 * @phpstan-type Request array{client_id: string, redirect_uri: string, code_challenge: string, resource: string, scope: string, state: string}
 * @phpstan-type Result array{view: string, message?: string, status?: int, request?: Request, nonce_action?: string, url?: string}
 */
final class Authorize
{
    public function __construct(
        private readonly TokenStore $store,
        private readonly Intent $intent,
    ) {
    }

    public static function register(): void
    {
        add_action('parse_request', [self::class, 'handle'], 1);
    }

    public static function handle(): void
    {
        if (Contract::current() === null) {
            return;
        }

        $uri = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        $path = rtrim((string) parse_url($uri, PHP_URL_PATH), '/');

        if ($path !== rtrim((string) parse_url(Config::authorizeUrl(), PHP_URL_PATH), '/')) {
            return;
        }

        $post = isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST';
        /** @var array<mixed> $params */
        $params = wp_unslash($post ? $_POST : $_GET);
        $userId = get_current_user_id();

        $result = (new self(TokenStore::make(), new Intent()))->process(
            $post ? 'POST' : 'GET',
            $params,
            $userId,
            $userId > 0 && current_user_can('edit_pages')
        );

        if ($result['view'] === 'login') {
            $query = isset($_SERVER['QUERY_STRING']) && is_string($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : '';
            wp_safe_redirect(wp_login_url(Config::authorizeUrl() . '?' . $query));
            exit;
        }

        if ($result['view'] === 'redirect') {
            // Only reached after an exact client map match.
            header('Cache-Control: no-store');
            header('Referrer-Policy: no-referrer');
            wp_redirect($result['url'] ?? home_url()); // phpcs:ignore WordPress.Security.SafeRedirect
            exit;
        }

        ConsentPage::send($result, $userId);
        exit;
    }

    /**
     * @param array<mixed> $params
     * @return Result
     */
    public function process(string $method, array $params, int $userId, bool $canEdit): array
    {
        $request = self::validate($params);

        if (is_string($request)) {
            Logger::warn('authorize_rejected');

            return ['view' => 'error', 'status' => 400, 'message' => $request];
        }

        if ($userId <= 0) {
            return ['view' => 'login'];
        }

        if (!$canEdit) {
            return ['view' => 'error', 'status' => 403, 'message' => 'forbidden'];
        }

        if (!$this->intent->isFresh($userId)) {
            return ['view' => 'intent'];
        }

        $action = 'wpsmcp_consent_' . self::fingerprint($request);

        if ($method !== 'POST') {
            return ['view' => 'consent', 'request' => $request, 'nonce_action' => $action];
        }

        $nonce = $params['_wpnonce'] ?? '';
        if (!is_string($nonce) || wp_verify_nonce($nonce, $action) === false) {
            return ['view' => 'error', 'status' => 403, 'message' => 'expired'];
        }

        if (($params['decision'] ?? '') !== 'allow') {
            return ['view' => 'redirect', 'url' => self::redirect($request, ['error' => 'access_denied'])];
        }

        // Atomic enough for one user: a second concurrent click finds no intent left.
        if (!$this->intent->consume($userId)) {
            return ['view' => 'intent'];
        }

        $code = $this->store->issue('code', Config::CODE_TTL, [
            'user_id' => $userId,
            'family_id' => TokenStore::newFamilyId(),
            'client_id' => $request['client_id'],
            'redirect_uri' => $request['redirect_uri'],
            'code_challenge' => $request['code_challenge'],
            'resource' => $request['resource'],
            'scope' => $request['scope'],
        ]);

        $host = ClientMap::host($request['client_id']);
        Audit::record('connect', $userId, 0, $host);
        self::notify($userId, $host);

        return ['view' => 'redirect', 'url' => self::redirect($request, ['code' => $code])];
    }

    /**
     * @param array<mixed> $params
     * @return Request|string request, or an error key
     */
    public static function validate(array $params): array|string
    {
        $get = static fn (string $key): string => is_string($params[$key] ?? null) ? $params[$key] : '';

        $clientId = $get('client_id');
        $redirect = $get('redirect_uri');

        if (!ClientMap::allows($clientId, $redirect)) {
            return 'unknown_client';
        }

        if ($get('response_type') !== 'code' || $get('code_challenge_method') !== 'S256'
            || preg_match('/^[A-Za-z0-9_-]{43}$/', $get('code_challenge')) !== 1) {
            return 'invalid_request';
        }

        $resource = $get('resource');
        if ($resource !== '' && $resource !== Config::mcpUrl()) {
            return 'invalid_target';
        }

        $scopes = array_values(array_filter(explode(' ', $get('scope')), static fn (string $s): bool => $s !== ''));
        if ($scopes === []) {
            $scopes = Config::SCOPES;
        }
        if (array_diff($scopes, Config::SCOPES) !== []) {
            return 'invalid_scope';
        }

        if (strlen($get('state')) > 512) {
            return 'invalid_request';
        }

        return [
            'client_id' => $clientId,
            'redirect_uri' => $redirect,
            'code_challenge' => $get('code_challenge'),
            'resource' => Config::mcpUrl(),
            'scope' => implode(' ', $scopes),
            'state' => $get('state'),
        ];
    }

    /** @param Request $request */
    private static function fingerprint(array $request): string
    {
        return hash('sha256', (string) wp_json_encode($request));
    }

    /**
     * @param Request $request
     * @param array<string, string> $extra
     */
    private static function redirect(array $request, array $extra): string
    {
        if ($request['state'] !== '') {
            $extra['state'] = $request['state'];
        }

        return $request['redirect_uri'] . (str_contains($request['redirect_uri'], '?') ? '&' : '?') . http_build_query($extra);
    }

    private static function notify(int $userId, string $clientHost): void
    {
        $user = get_userdata($userId);
        if ($user === false) {
            return;
        }

        wp_mail(
            $user->user_email,
            __('Neue Claude-Verbindung', 'wp-starter-mcp'),
            sprintf(
                /* translators: 1: client host, 2: setup page URL */
                __("Soeben wurde %1\$s mit Ihrem Konto verbunden.\n\nWaren Sie das nicht, trennen Sie die Verbindung sofort unter:\n%2\$s", 'wp-starter-mcp'),
                $clientHost,
                admin_url(Config::SETUP_PAGE)
            )
        );
    }
}
