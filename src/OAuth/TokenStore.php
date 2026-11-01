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

        return $row;
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
        $key = 'mcp_adapter_sessions';

        if (is_multisite()) {
            $blog = (int) get_current_blog_id();
            $key = $blog >= 1 ? $key . '_' . $blog : $key;
        }

        delete_user_meta($userId, $key);
    }

    public static function make(): self
    {
        return new self();
    }
}
