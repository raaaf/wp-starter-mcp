<?php

declare(strict_types=1);

namespace WpStarterMcp\OAuth;

use WpStarterMcp\Audit;
use WpStarterMcp\Config;
use WpStarterMcp\Contract;
use WpStarterMcp\Logger;
use WpStarterMcp\RateLimiter;

/**
 * Token endpoint: authorization_code and refresh_token grants for public clients.
 *
 * @phpstan-type Reply array{status: int, body: array<string, mixed>, headers: array<string, string>}
 */
final class Token
{
    private const CODE_LIMIT = 10;
    private const FAMILY_LIMIT = 30;
    private const ADDRESS_LIMIT = 600;

    public function __construct(
        private readonly TokenStore $store,
        private readonly RateLimiter $limiter,
    ) {
    }

    public static function register(): void
    {
        add_action('rest_api_init', static function (): void {
            register_rest_route(Config::ROUTE_NAMESPACE, '/' . Config::ROUTE_TOKEN, [
                'methods' => 'POST',
                'permission_callback' => '__return_true',
                'callback' => static function (\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
                    if (Contract::current() === null) {
                        return new \WP_Error('rest_no_route', 'No route was found matching the URL and request method.', ['status' => 404]);
                    }

                    $ip = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
                    $reply = (new self(TokenStore::make(), new RateLimiter()))->process($request->get_body_params(), $ip);
                    $response = new \WP_REST_Response($reply['body'], $reply['status']);
                    foreach ($reply['headers'] as $name => $value) {
                        $response->header($name, $value);
                    }

                    return $response;
                },
            ]);
        });
    }

    /**
     * @param array<mixed> $params form body
     * @return Reply
     */
    public function process(array $params, string $address): array
    {
        if (!$this->limiter->allow('addr:' . $address, self::ADDRESS_LIMIT)) {
            return $this->throttled();
        }

        return match ($this->str($params, 'grant_type')) {
            'authorization_code' => $this->authorizationCode($params),
            'refresh_token' => $this->refresh($params),
            default => $this->error('unsupported_grant_type'),
        };
    }

    /**
     * @param array<mixed> $params
     * @return Reply
     */
    private function authorizationCode(array $params): array
    {
        $code = $this->str($params, 'code');
        $verifier = $this->str($params, 'code_verifier');

        if ($code === '' || $this->str($params, 'redirect_uri') === '' || $this->str($params, 'client_id') === ''
            || preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $verifier) !== 1) {
            return $this->error('invalid_request');
        }

        if (!$this->limiter->allow('code:' . TokenStore::hash($code), self::CODE_LIMIT)) {
            return $this->throttled();
        }

        $row = $this->store->find('code', $code);
        if ($row === null) {
            return $this->error('invalid_grant');
        }

        // Consume before any check: a wrong verifier burns the code.
        if (!$this->store->consume((int) $row['id'])) {
            $this->revokeForReuse($row, 'code_reuse');

            return $this->error('invalid_grant');
        }

        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        if ($row['revoked_at'] !== null
            || (int) $row['expires_at'] <= $this->store->now()
            || !hash_equals((string) $row['client_id'], $this->str($params, 'client_id'))
            || !hash_equals((string) $row['redirect_uri'], $this->str($params, 'redirect_uri'))
            || !hash_equals((string) $row['code_challenge'], $challenge)
            || !user_can((int) $row['user_id'], 'edit_pages')) {
            return $this->error('invalid_grant');
        }

        if (!$this->resourceMatches($params, $row)) {
            return $this->error('invalid_target');
        }

        return $this->issuePair($row, (string) $row['family_id']);
    }

    /**
     * @param array<mixed> $params
     * @return Reply
     */
    private function refresh(array $params): array
    {
        $token = $this->str($params, 'refresh_token');
        if ($token === '' || $this->str($params, 'client_id') === '') {
            return $this->error('invalid_request');
        }

        $row = $this->store->find('refresh', $token);
        if ($row === null || $row['revoked_at'] !== null) {
            return $this->error('invalid_grant');
        }

        $family = (string) $row['family_id'];
        if (!$this->limiter->allow('family:' . $family, self::FAMILY_LIMIT)) {
            return $this->throttled();
        }

        if ((int) $row['expires_at'] <= $this->store->now()
            || !hash_equals((string) $row['client_id'], $this->str($params, 'client_id'))
            || !user_can((int) $row['user_id'], 'edit_pages')) {
            return $this->error('invalid_grant');
        }

        if (!$this->resourceMatches($params, $row)) {
            return $this->error('invalid_target');
        }

        if (!$this->store->consume((int) $row['id'])) {
            $current = $this->store->find('refresh', $token);
            $usedAt = $current === null ? 0 : (int) $current['used_at'];
            $retry = $current !== null && $current['revoked_at'] === null
                && $usedAt > 0 && $this->store->now() - $usedAt <= Config::REFRESH_GRACE;

            if (!$retry) {
                $this->revokeForReuse($row, 'refresh_reuse');

                return $this->error('invalid_grant');
            }
        }

        return $this->issuePair($row, $family);
    }

    /**
     * @param array<string, mixed> $row
     * @return Reply
     */
    private function issuePair(array $row, string $family): array
    {
        $fields = [
            'user_id' => (int) $row['user_id'],
            'family_id' => $family,
            'client_id' => (string) $row['client_id'],
            'scope' => (string) $row['scope'],
            'resource' => (string) $row['resource'],
        ];

        $access = $this->store->issue('access', Config::ACCESS_TTL, $fields);
        $refresh = $this->store->issue('refresh', Config::REFRESH_TTL, $fields);

        // A revocation between the checks and the inserts has not seen the new rows: close them too.
        if ($this->store->familyRevoked($family)) {
            $this->store->revokeFamily($family);

            return $this->error('invalid_grant');
        }

        return [
            'status' => 200,
            'body' => [
                'access_token' => $access,
                'token_type' => 'Bearer',
                'expires_in' => Config::ACCESS_TTL,
                'refresh_token' => $refresh,
                'scope' => $fields['scope'],
            ],
            'headers' => $this->headers(),
        ];
    }

    /**
     * @param array<mixed> $params
     * @param array<string, mixed> $row
     */
    private function resourceMatches(array $params, array $row): bool
    {
        $resource = $this->str($params, 'resource');

        return $resource === '' || $resource === (string) $row['resource'];
    }

    /** @param array<string, mixed> $row */
    private function revokeForReuse(array $row, string $event): void
    {
        $family = (string) $row['family_id'];
        $this->store->revokeFamily($family);

        $host = ClientMap::host((string) $row['client_id']);
        Logger::warn($event, ['family' => $family, 'user' => (int) $row['user_id'], 'client_host' => $host]);
        Audit::record($event, (int) $row['user_id'], 0, $host);
    }

    /** @param array<mixed> $params */
    private function str(array $params, string $key): string
    {
        $value = $params[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache'];
    }

    /** @return Reply */
    private function error(string $error): array
    {
        return ['status' => 400, 'body' => ['error' => $error], 'headers' => $this->headers()];
    }

    /** @return Reply */
    private function throttled(): array
    {
        Logger::warn('rate_limited');

        return [
            'status' => 429,
            'body' => ['error' => 'temporarily_unavailable'],
            'headers' => $this->headers() + ['Retry-After' => '60'],
        ];
    }
}
