<?php

declare(strict_types=1);

namespace WpStarterMcp;

/**
 * Audit trail: time, user ID, event, post ID, client host. No IP, titles or bodies.
 */
final class Audit
{
    public static function record(string $event, int $userId, int $postId = 0, string $clientHost = ''): void
    {
        global $wpdb;

        $wpdb->insert(Schema::auditTable(), [
            'created_at' => time(),
            'user_id' => $userId,
            'event' => $event,
            'post_id' => $postId,
            'client_host' => $clientHost,
        ]);
    }
}
