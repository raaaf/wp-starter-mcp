<?php

declare(strict_types=1);

namespace WpStarterMcp;

/**
 * Replaces a live page with its draft copy (made by create-draft-copy). Always confirmed, naming
 * the page. Before anything is written the original's sections and meta are snapshotted into an
 * explicit, pinned revision, so the old state can be restored from core's revision screen.
 */
final class ReplaceOriginal
{
    public const SNAPSHOT_META = '_wpsmcp_snapshot';
    public const PIN_META = '_wpsmcp_pinned';
    public const PAGE = 'wpsmcp-replace';
    public const ACTION = 'wpsmcp_replace';
    private const BASE_META = ['_wp_page_template', '_thumbnail_id'];

    public static function register(): void
    {
        add_filter('page_row_actions', [self::class, 'rowActions'], 10, 2);
        add_action('admin_menu', [self::class, 'addPage']);
        add_action('admin_post_' . self::ACTION, [self::class, 'handlePost']);
        add_action('admin_notices', [self::class, 'notice']);
        add_action('wp_restore_post_revision', [self::class, 'restore'], 10, 2);
        add_filter('pre_delete_post', [self::class, 'blockPinnedDelete'], 10, 2);
        add_action('before_delete_post', [self::class, 'unpin']);
    }

    public static function nonceAction(int $copyId, string $modified): string
    {
        return 'wpsmcp_replace_' . $copyId . '_' . $modified;
    }

    private static function allowed(int $originalId, int $copyId): bool
    {
        return current_user_can('edit_post', $originalId)
            && current_user_can('edit_post', $copyId)
            && current_user_can('publish_pages');
    }

    /**
     * What replacing would do, or the reason it cannot.
     *
     * @return array{copy: int, original: int, title: string, live_url: string, preview_url: string, removed: list<string>, new_hosts: list<string>, changed: bool, modified: string}|string
     */
    public static function plan(int $copyId): array|string
    {
        $copy = get_post($copyId);
        $originalId = (int) get_post_meta($copyId, '_wpsmcp_copy_of', true);
        $original = $originalId > 0 ? get_post($originalId) : null;

        if (!$copy || $copy->post_status !== 'draft' || $originalId <= 0) {
            return 'not_copy';
        }

        if (!$original || $original->post_status === 'trash') {
            return 'no_original';
        }

        if (!self::allowed($originalId, $copyId)) {
            return 'forbidden';
        }

        $contract = Contract::current();
        if ($contract === null) {
            return 'unavailable';
        }

        $content = new PageContent($contract, new ModuleSchema($contract));
        $before = $content->stored($originalId);
        $after = $content->stored($copyId);

        return [
            'copy' => $copyId,
            'original' => $originalId,
            'title' => (string) $original->post_title,
            'live_url' => (string) get_permalink($originalId),
            'preview_url' => (string) get_preview_post_link($copyId),
            'removed' => self::removedSections($before, $after),
            'new_hosts' => array_values(array_diff(self::hosts($after), self::hosts($before), [self::ownHost()])),
            'changed' => (string) $original->post_modified_gmt !== (string) get_post_meta($copyId, '_wpsmcp_copy_modified_gmt', true),
            'modified' => (string) $original->post_modified_gmt,
        ];
    }

    /**
     * @return array{original: int, revision: int}|string error code
     */
    public static function execute(int $copyId, string $nonce): array|string
    {
        $plan = self::plan($copyId);
        if (is_string($plan)) {
            return $plan;
        }

        // The nonce carries the original's modified time seen on the confirmation page, so an
        // edit of the original in between invalidates it.
        if (!wp_verify_nonce($nonce, self::nonceAction($copyId, $plan['modified']))) {
            return 'nonce';
        }

        $contract = Contract::current();
        $original = get_post($plan['original']);
        $copy = get_post($copyId);
        if ($contract === null || !$original || !$copy) {
            return 'unavailable';
        }

        $content = new PageContent($contract, new ModuleSchema($contract));
        $metaKeys = array_merge($contract->copyMeta(), self::BASE_META);
        $revision = self::snapshot($plan['original'], $content, $metaKeys);
        if ($revision === 0) {
            return 'snapshot';
        }

        // update_field before wp_update_post, as the plan fixes.
        update_field($contract->pageFieldKey(), $content->stored($copyId), $plan['original']);

        $title = (string) $copy->post_title === sprintf('%s (Kopie)', $original->post_title) ? (string) $original->post_title : (string) $copy->post_title;
        wp_update_post(wp_slash(['ID' => $plan['original'], 'post_title' => $title, 'post_content' => $copy->post_content]));

        foreach ($metaKeys as $key) {
            $value = get_post_meta($copyId, $key, true);
            if ($value === '') {
                delete_post_meta($plan['original'], $key);
            } else {
                update_post_meta($plan['original'], $key, wp_slash($value));
            }
        }

        $contract->afterWrite($plan['original']);
        wp_trash_post($copyId);
        Audit::record('replace_original', get_current_user_id(), $plan['original']);

        return ['original' => $plan['original'], 'revision' => $revision];
    }

    /** @param list<string> $metaKeys */
    private static function snapshot(int $originalId, PageContent $content, array $metaKeys): int
    {
        $original = get_post($originalId);
        $revision = $original ? _wp_put_post_revision($original) : null;

        if (!is_int($revision) || $revision <= 0) {
            return 0;
        }

        $meta = [];
        foreach ($metaKeys as $key) {
            $meta[$key] = get_post_meta($originalId, $key, true);
        }

        // update_post_meta() would redirect a revision ID to its parent post, so the generic API.
        update_metadata('post', $revision, self::SNAPSHOT_META, wp_slash(['sections' => $content->stored($originalId), 'meta' => $meta]));
        update_metadata('post', $revision, self::PIN_META, '1');

        return $revision;
    }

    /** Core's "restore revision" copies title and content only; this brings back sections and meta. */
    public static function restore(int $postId, int $revisionId): void
    {
        $snapshot = get_post_meta($revisionId, self::SNAPSHOT_META, true);
        $contract = Contract::current();

        if (!is_array($snapshot) || $contract === null) {
            return;
        }

        update_field($contract->pageFieldKey(), $snapshot['sections'] ?? [], $postId);

        foreach ((array) ($snapshot['meta'] ?? []) as $key => $value) {
            if ($value === '') {
                delete_post_meta($postId, (string) $key);
            } else {
                update_post_meta($postId, (string) $key, wp_slash($value));
            }
        }

        $contract->afterWrite($postId);
    }

    /** The theme prunes revisions; a snapshot must outlive that while its page exists. */
    /** @param \WP_Post $post */
    public static function blockPinnedDelete(mixed $check, object $post): mixed
    {
        if ($post->post_type === 'revision' && get_post_meta((int) $post->ID, self::PIN_META, true) === '1') {
            return false;
        }

        return $check;
    }

    /** Deleting the page itself releases its snapshots. */
    public static function unpin(int $postId): void
    {
        foreach (wp_get_post_revisions($postId, ['fields' => 'ids']) as $revisionId) {
            delete_metadata('post', is_int($revisionId) ? $revisionId : $revisionId->ID, self::PIN_META);
        }
    }

    /**
     * @param array<string, string> $actions
     * @param \WP_Post $post
     * @return array<string, string>
     */
    public static function rowActions(array $actions, object $post): array
    {
        $originalId = (int) get_post_meta((int) $post->ID, '_wpsmcp_copy_of', true);
        $original = $originalId > 0 ? get_post($originalId) : null;

        if ($post->post_status === 'draft' && $original && $original->post_status !== 'trash' && self::allowed($originalId, (int) $post->ID)) {
            $actions['wpsmcp_replace'] = '<a href="' . esc_url(admin_url('admin.php?page=' . self::PAGE . '&copy=' . (int) $post->ID)) . '">'
                . esc_html__('Original ersetzen', 'wp-starter-mcp') . '</a>';
        }

        return $actions;
    }

    public static function addPage(): void
    {
        // A null parent keeps the page out of the menu; WordPress accepts it.
        // @phpstan-ignore argument.type
        add_submenu_page(null, __('Original ersetzen', 'wp-starter-mcp'), '', 'edit_pages', self::PAGE, [self::class, 'renderPage']);
    }

    public static function renderPage(): void
    {
        $copyId = isset($_GET['copy']) ? absint($_GET['copy']) : 0;
        $plan = self::plan($copyId);
        $code = isset($_GET['wpsmcp_error']) && is_string($_GET['wpsmcp_error']) ? sanitize_key($_GET['wpsmcp_error']) : '';

        View::render('replace', [
            'plan' => $plan,
            'error' => is_string($plan) ? self::errorText($plan) : ($code !== '' ? self::errorText($code) : ''),
            'nonce' => is_array($plan) ? wp_create_nonce(self::nonceAction($copyId, $plan['modified'])) : '',
            'post_url' => admin_url('admin-post.php'),
            'list_url' => admin_url('edit.php?post_type=page'),
        ]);
    }

    public static function handlePost(): void
    {
        $copyId = isset($_POST['copy']) ? absint($_POST['copy']) : 0;
        $nonce = isset($_POST['_wpnonce']) && is_string($_POST['_wpnonce']) ? $_POST['_wpnonce'] : '';
        $result = self::execute($copyId, $nonce);

        if (is_string($result)) {
            wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE . '&copy=' . $copyId . '&wpsmcp_error=' . $result));
            exit;
        }

        wp_safe_redirect(admin_url('edit.php?post_type=page&wpsmcp_replaced=' . $result['original'] . '&wpsmcp_rev=' . $result['revision']));
        exit;
    }

    public static function notice(): void
    {
        $id = isset($_GET['wpsmcp_replaced']) ? absint($_GET['wpsmcp_replaced']) : 0;
        $revision = isset($_GET['wpsmcp_rev']) ? absint($_GET['wpsmcp_rev']) : 0;

        if ($id <= 0 || !current_user_can('edit_post', $id)) {
            return;
        }

        echo '<div class="notice notice-success" role="status"><p>'
            . sprintf(
                /* translators: 1: link to the live page, 2: link to the revision */
                esc_html__('Die Seite wurde ersetzt. %1$s. Der vorherige Stand ist gesichert: %2$s.', 'wp-starter-mcp'),
                '<a href="' . esc_url((string) get_permalink($id)) . '">' . esc_html__('Live-Seite ansehen', 'wp-starter-mcp') . '</a>',
                '<a href="' . esc_url(admin_url('revision.php?revision=' . $revision)) . '">' . esc_html__('Vorherigen Stand ansehen und wiederherstellen', 'wp-starter-mcp') . '</a>'
            )
            . '</p></div>';
    }

    private static function errorText(string $code): string
    {
        return match ($code) {
            'forbidden' => __('Ihr Konto darf diese Seite nicht ersetzen.', 'wp-starter-mcp'),
            'no_original' => __('Die Originalseite existiert nicht mehr. Der Entwurf bleibt erhalten.', 'wp-starter-mcp'),
            'nonce' => __('Die Bestätigung ist abgelaufen oder das Original wurde inzwischen geändert. Bitte prüfen Sie die Angaben erneut.', 'wp-starter-mcp'),
            'snapshot' => __('Der vorherige Stand konnte nicht gesichert werden. Es wurde nichts geändert.', 'wp-starter-mcp'),
            default => __('Dieser Entwurf ist keine Kopie einer Seite oder die Anbindung ist nicht verfügbar.', 'wp-starter-mcp'),
        };
    }

    /**
     * Layouts present in the original and missing from the copy, one entry per missing section.
     *
     * @param list<array<string, mixed>> $before
     * @param list<array<string, mixed>> $after
     * @return list<string>
     */
    private static function removedSections(array $before, array $after): array
    {
        $count = static fn (array $rows): array => array_count_values(array_map(static fn (array $row): string => (string) ($row['acf_fc_layout'] ?? ''), $rows));
        $remaining = $count($after);
        $removed = [];

        foreach ($count($before) as $name => $n) {
            for ($i = 0; $i < $n - ($remaining[$name] ?? 0); $i++) {
                $removed[] = (string) $name;
            }
        }

        return $removed;
    }

    /** @return list<string> */
    private static function hosts(mixed $value): array
    {
        $found = [];
        $walk = static function (mixed $item) use (&$walk, &$found): void {
            if (is_array($item)) {
                array_map($walk, $item);
            } elseif (is_string($item) && preg_match_all('#https?://[^\s"\'<>)\]]+#i', $item, $m) > 0) {
                foreach ($m[0] as $url) {
                    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
                    if ($host !== '') {
                        $found[$host] = true;
                    }
                }
            }
        };
        $walk($value);

        return array_keys($found);
    }

    private static function ownHost(): string
    {
        return strtolower((string) parse_url(home_url(), PHP_URL_HOST));
    }
}
