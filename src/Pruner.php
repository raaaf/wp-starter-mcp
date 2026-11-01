<?php

declare(strict_types=1);

namespace WpStarterMcp;

final class Pruner
{
    /** Expired tokens stay one day so a late reuse of a rotated refresh token is still seen. */
    private const TOKEN_KEEP = 86400;

    public static function run(?int $now = null): void
    {
        global $wpdb;

        $now ??= time();

        $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . Schema::tokensTable() . ' WHERE expires_at < %d',
            $now - self::TOKEN_KEEP
        ));
        $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . Schema::auditTable() . ' WHERE created_at < %d',
            $now - Config::AUDIT_RETENTION
        ));
    }

    public static function schedule(): void
    {
        if (wp_next_scheduled(Config::PRUNE_HOOK) === false) {
            wp_schedule_event(time() + 3600, 'daily', Config::PRUNE_HOOK);
        }
    }
}
