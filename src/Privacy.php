<?php

declare(strict_types=1);

namespace WpStarterMcp;

/**
 * Personal-data exporter and eraser for the audit table, the connection table and the user
 * meta this plugin and the MCP adapter keep. Registered while the feature is off too, because
 * the data outlives the switch.
 */
final class Privacy
{
    public static function register(): void
    {
        add_filter('wp_privacy_personal_data_exporters', [self::class, 'addExporter']);
        add_filter('wp_privacy_personal_data_erasers', [self::class, 'addEraser']);
    }

    /**
     * @param array<string, mixed> $exporters
     * @return array<string, mixed>
     */
    public static function addExporter(array $exporters): array
    {
        $exporters['wp-starter-mcp'] = [
            'exporter_friendly_name' => __('Claude-Anbindung', 'wp-starter-mcp'),
            'callback' => [self::class, 'export'],
        ];

        return $exporters;
    }

    /**
     * @param array<string, mixed> $erasers
     * @return array<string, mixed>
     */
    public static function addEraser(array $erasers): array
    {
        $erasers['wp-starter-mcp'] = [
            'eraser_friendly_name' => __('Claude-Anbindung', 'wp-starter-mcp'),
            'callback' => [self::class, 'erase'],
        ];

        return $erasers;
    }

    /** @return array{data: list<array<string, mixed>>, done: bool} */
    public static function export(string $email): array
    {
        global $wpdb;

        $user = get_user_by('email', $email);
        if ($user === false) {
            return ['data' => [], 'done' => true];
        }

        $items = [];

        /** @var list<array<string, mixed>> $audit */
        $audit = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . Schema::auditTable() . ' WHERE user_id = %d', (int) $user->ID), ARRAY_A);
        foreach ($audit as $row) {
            $items[] = [
                'group_id' => 'wpsmcp-audit',
                'group_label' => __('Claude-Protokoll', 'wp-starter-mcp'),
                'item_id' => 'wpsmcp-audit-' . (string) $row['id'],
                'data' => [
                    ['name' => __('Zeitpunkt', 'wp-starter-mcp'), 'value' => gmdate('Y-m-d H:i:s', (int) $row['created_at']) . ' UTC'],
                    ['name' => __('Ereignis', 'wp-starter-mcp'), 'value' => (string) $row['event']],
                    ['name' => __('Seite', 'wp-starter-mcp'), 'value' => (string) $row['post_id']],
                    ['name' => __('Client', 'wp-starter-mcp'), 'value' => (string) $row['client_host']],
                ],
            ];
        }

        /** @var list<array<string, mixed>> $tokens */
        $tokens = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . Schema::tokensTable() . ' WHERE user_id = %d', (int) $user->ID), ARRAY_A);
        foreach ($tokens as $row) {
            $items[] = [
                'group_id' => 'wpsmcp-connections',
                'group_label' => __('Claude-Verbindungen', 'wp-starter-mcp'),
                'item_id' => 'wpsmcp-token-' . (string) $row['id'],
                'data' => [
                    ['name' => __('Art', 'wp-starter-mcp'), 'value' => (string) $row['type']],
                    ['name' => __('Client', 'wp-starter-mcp'), 'value' => OAuth\ClientMap::host((string) $row['client_id'])],
                    ['name' => __('Erstellt', 'wp-starter-mcp'), 'value' => gmdate('Y-m-d H:i:s', (int) $row['created_at']) . ' UTC'],
                    ['name' => __('Widerrufen', 'wp-starter-mcp'), 'value' => $row['revoked_at'] === null ? '' : gmdate('Y-m-d H:i:s', (int) $row['revoked_at']) . ' UTC'],
                ],
            ];
        }

        $sessions = get_user_meta((int) $user->ID, OAuth\TokenStore::sessionMetaKey(), true);
        if ($sessions !== '' && $sessions !== []) {
            $items[] = [
                'group_id' => 'wpsmcp-sessions',
                'group_label' => __('MCP-Sitzungen', 'wp-starter-mcp'),
                'item_id' => 'wpsmcp-sessions-' . (string) $user->ID,
                'data' => [['name' => __('Sitzungen', 'wp-starter-mcp'), 'value' => (string) wp_json_encode(array_keys((array) $sessions))]],
            ];
        }

        return ['data' => $items, 'done' => true];
    }

    /** @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool} */
    public static function erase(string $email): array
    {
        global $wpdb;

        $user = get_user_by('email', $email);
        $removed = false;

        if ($user !== false) {
            $id = (int) $user->ID;
            $store = OAuth\TokenStore::make();
            $store->revokeUser($id);

            $count = (int) $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::auditTable() . ' WHERE user_id = %d', $id))
                + (int) $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::tokensTable() . ' WHERE user_id = %d', $id));
            delete_user_meta($id, 'wpsmcp_intent');
            $removed = $count > 0;
        }

        return ['items_removed' => $removed, 'items_retained' => false, 'messages' => [], 'done' => true];
    }
}
