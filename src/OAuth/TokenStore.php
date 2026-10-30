<?php

declare(strict_types=1);

namespace WpStarterMcp\OAuth;

use WpStarterMcp\Schema;

/**
 * Codes, access tokens and refresh tokens in one table. Only SHA-256 hashes are stored.
 *
 * @phpstan-type Row array<string, mixed>
 */
final class TokenStore
{
    private readonly \Closure $clock;

    public function __construct(?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function now(): int
    {
        return ($this->clock)();
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function randomToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function newFamilyId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * @param array{user_id: int, family_id: string, client_id: string, scope: string, resource?: string, redirect_uri?: string, code_challenge?: string} $fields
     * @return string the plain token, shown once
     */
    public function issue(string $type, int $ttl, array $fields): string
    {
        global $wpdb;

        $token = self::randomToken();
        $now = $this->now();

        $wpdb->insert(Schema::tokensTable(), [
            'token_hash' => self::hash($token),
            'type' => $type,
            'family_id' => $fields['family_id'],
            'user_id' => $fields['user_id'],
            'client_id' => $fields['client_id'],
            'redirect_uri' => $fields['redirect_uri'] ?? '',
            'code_challenge' => $fields['code_challenge'] ?? '',
            'resource' => $fields['resource'] ?? '',
            'scope' => $fields['scope'],
            'created_at' => $now,
            'expires_at' => $now + $ttl,
        ]);

        return $token;
    }

    /** @return array<string, mixed>|null */
    public function find(string $type, string $token): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . Schema::tokensTable() . ' WHERE token_hash = %s AND type = %s',
            self::hash($token),
            $type
        ), ARRAY_A);

        return is_array($row) ? $row : null;
    }

    /** Atomic: exactly one caller gets true for a row. */
    public function consume(int $id): bool
    {
        global $wpdb;

        return $wpdb->update(
            Schema::tokensTable(),
            ['used_at' => $this->now()],
            ['id' => $id, 'used_at' => null]
        ) === 1;
    }

    /**
     * A usable access token: not revoked, not expired, owner still exists and may edit pages.
     *
     * @return array<string, mixed>|null
     */
    public function validateAccess(string $token): ?array
    {
        $row = $this->find('access', $token);

        if ($row === null || $row['revoked_at'] !== null || (int) $row['expires_at'] <= $this->now()) {
            return null;
        }

        if (!user_can((int) $row['user_id'], 'edit_pages')) {
            return null;
        }

        $this->touch($row);

        return $row;
    }

    /**
     * Last use for the connection list, at most once a minute per token.
     *
     * @param array<string, mixed> $row
     */
    private function touch(array $row): void
    {
        global $wpdb;

        $now = $this->now();

        if ($row['last_used_at'] === null || $now - (int) $row['last_used_at'] >= 60) {
            $wpdb->update(Schema::tokensTable(), ['last_used_at' => $now], ['id' => (int) $row['id']]);
        }
    }

    /**
     * Live connections: families with an unused, unrevoked, unexpired refresh token.
     *
     * @return list<array{family_id: string, user_id: int, client_host: string, created_at: int, last_used_at: int}>
     */
    public function connections(?int $userId = null): array
    {
        global $wpdb;

        /** @var list<array<string, mixed>> $rows */
        $rows = $wpdb->get_results('SELECT * FROM ' . Schema::tokensTable() . ' WHERE revoked_at IS NULL', ARRAY_A);
        $families = [];

        foreach ($rows as $row) {
            if ($userId !== null && (int) $row['user_id'] !== $userId) {
                continue;
            }
            $family = (string) $row['family_id'];
            $families[$family] ??= ['family_id' => $family, 'user_id' => (int) $row['user_id'], 'client_host' => ClientMap::host((string) $row['client_id']), 'created_at' => (int) $row['created_at'], 'last_used_at' => 0, 'live' => false];
            $families[$family]['created_at'] = min($families[$family]['created_at'], (int) $row['created_at']);
            $families[$family]['last_used_at'] = max($families[$family]['last_used_at'], (int) ($row['last_used_at'] ?? 0));
            if ($row['type'] === 'refresh' && $row['used_at'] === null && (int) $row['expires_at'] > $this->now()) {
                $families[$family]['live'] = true;
            }
        }

        $live = array_filter($families, static fn (array $f): bool => $f['live']);
        usort($live, static fn (array $a, array $b): int => $b['created_at'] <=> $a['created_at']);

        return array_map(static fn (array $f): array => [
            'family_id' => $f['family_id'], 'user_id' => $f['user_id'], 'client_host' => $f['client_host'],
            'created_at' => $f['created_at'], 'last_used_at' => $f['last_used_at'],
        ], $live);
    }

    public function revokeFamily(string $familyId): void
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . Schema::tokensTable() . ' WHERE family_id = %s',
            $familyId
        ), ARRAY_A);

        $wpdb->update(
            Schema::tokensTable(),
            ['revoked_at' => $this->now()],
            ['family_id' => $familyId, 'revoked_at' => null]
        );

        $this->forgetSessions(is_array($rows) ? $rows : []);
    }

    public function revokeUser(int $userId): void
    {
        global $wpdb;

        $wpdb->update(
            Schema::tokensTable(),
            ['revoked_at' => $this->now()],
            ['user_id' => $userId, 'revoked_at' => null]
        );

        self::forgetUserSessions($userId);
    }

    public function revokeAll(): void
    {
        global $wpdb;

        $rows = $wpdb->get_results('SELECT * FROM ' . Schema::tokensTable() . ' WHERE revoked_at IS NULL', ARRAY_A);

        $wpdb->update(Schema::tokensTable(), ['revoked_at' => $this->now()], ['revoked_at' => null]);

        $this->forgetSessions(is_array($rows) ? $rows : []);
    }

    /** @param array<int, mixed> $rows */
    private function forgetSessions(array $rows): void
    {
        $users = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $users[(int) $row['user_id']] = true;
            }
        }

        foreach (array_keys($users) as $userId) {
            self::forgetUserSessions($userId);
        }
    }

    /** The MCP adapter keeps sessions in user meta, per site suffix on multisite. */
    private static function forgetUserSessions(int $userId): void
    {
        delete_user_meta($userId, self::sessionMetaKey());
    }

    /** User meta key of the adapter's sessions, with the site suffix on multisite. */
    public static function sessionMetaKey(): string
    {
        $key = 'mcp_adapter_sessions';

        if (is_multisite()) {
            $blog = (int) get_current_blog_id();
            $key = $blog >= 1 ? $key . '_' . $blog : $key;
        }

        return $key;
    }

    public static function make(): self
    {
        return new self();
    }
}
