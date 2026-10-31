<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Minimal WordPress stubs. Only what the plugin code under test touches.

define('ABSPATH', sys_get_temp_dir() . '/wpsmcp-fake-wp/');
define('ARRAY_A', 'ARRAY_A');

// Schema::install() requires WordPress's upgrade.php for dbDelta; the tests only need the call to exist.
if (!is_dir(ABSPATH . 'wp-admin/includes')) {
    mkdir(ABSPATH . 'wp-admin/includes', 0777, true);
}
file_put_contents(ABSPATH . 'wp-admin/includes/upgrade.php', "<?php\nfunction dbDelta(string \$sql): array\n{\n    return [];\n}\n");
define('WPSMCP_VERSION', '1.0.0');

/** Per-test state, reset by WpTestCase. */
final class WpState
{
    /** @var array<string, mixed> */
    public static array $options = [];
    /** @var array<int, array<string, mixed>> */
    public static array $userMeta = [];
    /** @var array<string, mixed> */
    public static array $transients = [];
    /** @var list<array{string, mixed}> */
    public static array $hooks = [];
    /** @var list<array{to: string, subject: string, message: string}> */
    public static array $mails = [];
    /** @var array<int, object> */
    public static array $users = [];
    /** @var array<int, bool> */
    public static array $canEdit = [];
    public static string $home = 'https://example.test';
    /** Plain permalinks: rest_url() answers index.php?rest_route=. */
    public static bool $plainPermalinks = false;
    public static int $afterSetupTheme = 1;
    public static bool $revisionFails = false;
    /** @var array<string, array<string, mixed>> abilities by name */
    public static array $abilities = [];
    public static bool $multisite = false;
    public static int $currentBlog = 1;
    /** @var list<int> */
    public static array $blogStack = [];
    /** @var array<int, list<int>> site ids by user */
    public static array $blogsOfUser = [];
    public static bool $restUrlCalled = false;
    /** Simulates a user_has_cap / map_meta_cap filter that resolves the current user. */
    public static ?\Closure $onUserCan = null;
    public static mixed $contract = null;
    /** @var array<int, mixed> ACF field values by post */
    public static array $fields = [];
    /** @var array<int, array<string, mixed>> post meta by post */
    public static array $meta = [];
    public static int $nextPostId = 100;
    /** @var array<int, object> */
    public static array $posts = [];
    /** @var list<string> capabilities of the current user */
    public static array $currentCaps = ['edit_pages', 'edit_post'];
    /** @var list<array{string, array<string, mixed>}> */
    public static array $ksesCalls = [];
    /** @var list<string> */
    public static array $clearedCron = [];
    /** @var array<string, array{int, string}|WP_Error> remote GET answers by URL; absent means unreachable */
    public static array $remote = [];
    /** @var list<string> every URL fetched */
    public static array $fetched = [];
    public static string $downloadBody = '';
    /** @var list<string> */
    public static array $scheduled = [];
    /** @var array<int, int> revision ids by post */
    public static array $revisions = [];

    public static function reset(): void
    {
        self::$options = [];
        self::$userMeta = [];
        self::$transients = [];
        self::$hooks = [];
        self::$mails = [];
        self::$users = [];
        self::$canEdit = [];
        self::$home = 'https://example.test';
        self::$plainPermalinks = false;
        self::$afterSetupTheme = 1;
        self::$revisionFails = false;
        self::$abilities = [];
        self::$multisite = false;
        self::$currentBlog = 1;
        self::$blogStack = [];
        self::$blogsOfUser = [];
        self::$restUrlCalled = false;
        self::$onUserCan = null;
        self::$contract = null;
        self::$fields = [];
        self::$meta = [];
        self::$nextPostId = 100;
        self::$posts = [];
        self::$currentCaps = ['edit_pages', 'edit_post'];
        self::$ksesCalls = [];
        self::$clearedCron = [];
        self::$remote = [];
        self::$fetched = [];
        self::$downloadBody = '';
        self::$scheduled = [];
        self::$revisions = [];
        $GLOBALS['wpdb'] = new wpdb();
    }
}

class WP_Error
{
    public function __construct(public string $code = '', public string $message = '', public mixed $data = null)
    {
    }

    public function get_error_code(): string
    {
        return $this->code;
    }

    public function get_error_message(): string
    {
        return $this->message;
    }
}

class WP_HTTP_Response
{
    /** @var array<string, string> */
    public array $headers = [];

    public function __construct(private int $status = 200)
    {
    }

    public function get_status(): int
    {
        return $this->status;
    }

    public function set_status(int $status): void
    {
        $this->status = $status;
    }

    public function header(string $key, string $value): void
    {
        $this->headers[$key] = $value;
    }
}

class WP_REST_Request
{
    public function __construct(private string $route = '')
    {
    }

    public function get_route(): string
    {
        return $this->route;
    }
}

function add_action(string $hook, mixed $callback, int $priority = 10, int $args = 1): bool
{
    WpState::$hooks[] = [$hook, $callback];

    return true;
}

function add_filter(string $hook, mixed $callback, int $priority = 10, int $args = 1): bool
{
    WpState::$hooks[] = [$hook, $callback];

    return true;
}

function did_action(string $hook): int
{
    return WpState::$afterSetupTheme;
}

function get_option(string $name, mixed $default = false): mixed
{
    return WpState::$options[$name] ?? $default;
}

function update_option(string $name, mixed $value): bool
{
    WpState::$options[$name] = $value;

    return true;
}

function get_user_meta(int $id, string $key, bool $single = false): mixed
{
    return WpState::$userMeta[$id][$key] ?? '';
}

function update_user_meta(int $id, string $key, mixed $value): bool
{
    WpState::$userMeta[$id][$key] = $value;

    return true;
}

function delete_user_meta(int $id, string $key): bool
{
    unset(WpState::$userMeta[$id][$key]);

    return true;
}

function is_multisite(): bool
{
    return WpState::$multisite;
}

function get_current_blog_id(): int
{
    return WpState::$currentBlog;
}

/** @return array<int, object> */
function get_blogs_of_user(int $id, bool $all = false): array
{
    return array_fill_keys(WpState::$blogsOfUser[$id] ?? [], (object) []);
}

/** Tables of a site other than the first carry its id in the prefix, as in WordPress. */
function switch_to_blog(int $id): bool
{
    WpState::$blogStack[] = WpState::$currentBlog;
    WpState::$currentBlog = $id;
    $GLOBALS['wpdb']->prefix = $id === 1 ? 'wp_' : 'wp_' . $id . '_';

    return true;
}

function restore_current_blog(): bool
{
    WpState::$currentBlog = (int) array_pop(WpState::$blogStack);
    $GLOBALS['wpdb']->prefix = WpState::$currentBlog === 1 ? 'wp_' : 'wp_' . WpState::$currentBlog . '_';

    return true;
}

function user_can(int $id, string $cap): bool
{
    if (WpState::$onUserCan !== null) {
        (WpState::$onUserCan)();
    }

    return WpState::$canEdit[$id] ?? false;
}

function get_userdata(int $id): object|false
{
    return WpState::$users[$id] ?? false;
}

function wp_mail(string $to, string $subject, string $message): bool
{
    WpState::$mails[] = ['to' => $to, 'subject' => $subject, 'message' => $message];

    return true;
}

function wp_verify_nonce(string $nonce, string $action): int|false
{
    return $nonce === 'nonce-' . $action ? 1 : false;
}

function wp_json_encode(mixed $value, int $flags = 0): string|false
{
    return json_encode($value, $flags);
}

function __(string $text, string $domain = 'default'): string
{
    return $text;
}

function admin_url(string $path = ''): string
{
    return WpState::$home . '/wp-admin/' . $path;
}

function home_url(string $path = ''): string
{
    return WpState::$home . $path;
}

function rest_url(string $path = ''): string
{
    WpState::$restUrlCalled = true;

    return WpState::$plainPermalinks
        ? WpState::$home . '/index.php?rest_route=/' . ltrim($path, '/')
        : WpState::$home . '/wp-json/' . $path;
}

/** @return array{baseurl: string} */
function wp_get_upload_dir(): array
{
    return ['baseurl' => WpState::$home . '/wp-content/uploads'];
}

function plugin_basename(string $file): string
{
    return (string) preg_replace('#^.*/plugins/#', '', $file);
}

function untrailingslashit(string $value): string
{
    return rtrim($value, '/\\');
}

function get_transient(string $name): mixed
{
    return WpState::$transients[$name] ?? false;
}

function set_transient(string $name, mixed $value, int $expiration = 0): bool
{
    WpState::$transients[$name] = $value;

    return true;
}

function is_user_logged_in(): bool
{
    return false;
}

function wp_clear_scheduled_hook(string $hook): void
{
    WpState::$clearedCron[] = $hook;
}

function wp_set_current_user(int $id): void
{
}

/**
 * In-memory stand-in for wpdb. Supports insert, update with equality or null conditions
 * (rows affected), get_row / get_results with "col = v", "col IS NULL" and
 * "col < n" conditions joined by AND, and DELETE ... WHERE with the same conditions.
 */
class wpdb
{
    public string $prefix = 'wp_';
    public int $insert_id = 0;
    /** Statements sent, to prove that a missing table is never queried. */
    public int $queries = 0;
    /** Called after each insert, to simulate a write from another request. */
    public ?\Closure $afterInsert = null;

    public function get_charset_collate(): string
    {
        return '';
    }

    /** @var array<string, array<int, array<string, mixed>>> */
    public array $tables = [];

    /** @param array<string, mixed> $data */
    public function insert(string $table, array $data): int|false
    {
        $this->tables[$table] ??= [];
        $this->insert_id = count($this->tables[$table]) + 1;
        $this->tables[$table][$this->insert_id] = ['id' => $this->insert_id, 'used_at' => null, 'revoked_at' => null, 'last_used_at' => null] + $data;

        if ($this->afterInsert !== null) {
            ($this->afterInsert)();
        }

        return 1;
    }

    public function prepare(string $query, string|int ...$args): string
    {
        foreach ($args as $arg) {
            $query = (string) preg_replace('/%[sd]/', is_int($arg) ? (string) $arg : "'" . $arg . "'", $query, 1);
        }

        return $query;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function matches(array $row, string $where): bool
    {
        foreach (explode(' AND ', $where) as $cond) {
            if (preg_match('/^(\w+) IS NULL$/', trim($cond), $m) === 1) {
                if ($row[$m[1]] !== null) {
                    return false;
                }
            } elseif (preg_match('/^(\w+) IS NOT NULL$/', trim($cond), $m) === 1) {
                if ($row[$m[1]] === null) {
                    return false;
                }
            } elseif (preg_match("/^(\\w+) = '?([^']*)'?$/", trim($cond), $m) === 1) {
                if ((string) $row[$m[1]] !== $m[2]) {
                    return false;
                }
            } elseif (preg_match('/^(\w+) < (\d+)$/', trim($cond), $m) === 1) {
                if (!((int) $row[$m[1]] < (int) $m[2])) {
                    return false;
                }
            } else {
                throw new RuntimeException('Unsupported condition: ' . $cond);
            }
        }

        return true;
    }

    /** @return array<int, array<string, mixed>> */
    public function get_results(string $query, string $output = 'OBJECT'): array
    {
        $this->queries++;
        preg_match('/FROM (\S+)(?: WHERE (.*))?$/', $query, $m);
        $found = [];
        foreach ($this->tables[$m[1]] ?? [] as $row) {
            if (!isset($m[2]) || $this->matches($row, $m[2])) {
                $found[] = $row;
            }
        }

        return $found;
    }

    /** @return array<string, mixed>|null */
    public function get_row(string $query, string $output = 'OBJECT'): ?array
    {
        return $this->get_results($query)[0] ?? null;
    }

    public function query(string $query): int|bool
    {
        $this->queries++;
        if (preg_match('/^DROP TABLE IF EXISTS (\S+)$/', $query, $drop) === 1) {
            unset($this->tables[$drop[1]]);

            return true;
        }

        preg_match('/^DELETE FROM (\S+) WHERE (.*)$/', $query, $m);
        $count = 0;
        foreach ($this->tables[$m[1]] ?? [] as $id => $row) {
            if ($this->matches($row, $m[2])) {
                unset($this->tables[$m[1]][$id]);
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where
     */
    public function update(string $table, array $data, array $where): int|false
    {
        $this->queries++;
        $affected = 0;
        foreach ($this->tables[$table] ?? [] as $id => $row) {
            foreach ($where as $col => $value) {
                if ($row[$col] !== $value) {
                    continue 2;
                }
            }
            $this->tables[$table][$id] = $data + $row;
            $affected++;
        }

        return $affected;
    }
}

$GLOBALS['wpdb'] = new wpdb();

function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
{
    return $hook === 'wp_starter_mcp_contract' ? WpState::$contract : $value;
}

function current_user_can(string $cap, mixed ...$args): bool
{
    return in_array($cap, WpState::$currentCaps, true);
}

function get_current_user_id(): int
{
    return 3;
}

function wp_strip_all_tags(string $text): string
{
    return strip_tags($text);
}

function sanitize_text_field(string $text): string
{
    return trim(preg_replace('/\s+/', ' ', strip_tags($text)) ?? '');
}

function sanitize_textarea_field(string $text): string
{
    return trim(strip_tags($text));
}

function wp_salt(string $scheme = 'auth'): string
{
    return 'test-salt';
}

function get_post(int $id): ?object
{
    return WpState::$posts[$id] ?? null;
}

function get_attached_file(int $id): string
{
    return (string) (WpState::$posts[$id]->file ?? '');
}

function term_exists(int $term, string $taxonomy): bool
{
    return $term === 7 && $taxonomy === 'category';
}

/** @return array<string, array<string, bool>> */
function wp_kses_allowed_html(string $context): array
{
    return ['p' => [], 'a' => ['href' => true], 'strong' => [], 'img' => [], 'video' => [], 'audio' => [], 'form' => [], 'input' => [], 'script' => [], 'iframe' => []];
}

/**
 * Stand-in for kses: drops tags that are not on the allowlist and records the call. The real
 * filter is exercised on a WordPress site, the unit tests pin which allowlist the plugin passes.
 *
 * @param array<string, array<string, bool>> $allowed
 * @param list<string> $protocols
 */
function wp_kses(string $html, array $allowed, array $protocols = []): string
{
    WpState::$ksesCalls[] = [$html, $allowed];

    return (string) preg_replace_callback('#</?([a-z0-9]+)[^>]*>#i', static fn (array $m): string => isset($allowed[strtolower($m[1])]) ? $m[0] : '', $html);
}

function is_email(string $email): string|false
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : false;
}

function is_wp_error(mixed $thing): bool
{
    return $thing instanceof WP_Error;
}

function get_field(string $selector, int $postId, bool $format = true): mixed
{
    return WpState::$fields[$postId] ?? false;
}

function update_field(string $selector, mixed $value, int $postId): bool
{
    WpState::$fields[$postId] = $value;

    return true;
}

function get_post_meta(int $id, string $key, bool $single = false): mixed
{
    return WpState::$meta[$id][$key] ?? '';
}

function wpsmcp_test_unslash(mixed $value): mixed
{
    if (is_array($value)) {
        return array_map('wpsmcp_test_unslash', $value);
    }

    return is_string($value) ? stripslashes($value) : $value;
}

function wp_slash(mixed $value): mixed
{
    if (is_array($value)) {
        return array_map('wp_slash', $value);
    }

    return is_string($value) ? addslashes($value) : $value;
}

function update_post_meta(int $id, string $key, mixed $value): bool
{
    // WordPress unslashes meta values on save.
    WpState::$meta[$id][$key] = wpsmcp_test_unslash($value);

    return true;
}

/** @param array<string, mixed> $args */
function wp_insert_post(array $args, bool $wpError = false): int
{
    $id = WpState::$nextPostId++;
    // WordPress unslashes post fields on save.
    $args = wpsmcp_test_unslash($args);
    WpState::$posts[$id] = (object) ($args + ['ID' => $id, 'post_content' => '', 'post_modified_gmt' => '']);
    WpState::$posts[$id]->ID = $id;

    return $id;
}

/** @param array<string, mixed> $args */
function wp_update_post(array $args): int
{
    foreach (wpsmcp_test_unslash($args) as $key => $value) {
        WpState::$posts[(int) $args['ID']]->$key = $value;
    }

    return (int) $args['ID'];
}

function get_edit_post_link(int $id, string $context = 'display'): string
{
    return "https://example.test/wp-admin/post.php?post={$id}&action=edit";
}

function get_preview_post_link(int $id): string
{
    return "https://example.test/?p={$id}&preview=true";
}

/** @return array<string, mixed> */
function acf_get_field(string $selector): array
{
    return ['key' => $selector, 'type' => 'flexible_content'];
}

function acf_validate_value(mixed $value, array $field, string $input): bool
{
    return true;
}

function acf_reset_validation_errors(): void
{
}

/** @return array<int, array<string, mixed>> */
function acf_get_validation_errors(): array
{
    return [];
}

function get_user_by(string $field, string $value): object|false
{
    foreach (WpState::$users as $user) {
        if ($user->user_email === $value) {
            return $user;
        }
    }

    return false;
}

function delete_option(string $name): bool
{
    unset(WpState::$options[$name]);

    return true;
}

function update_metadata(string $type, int $id, string $key, mixed $value): bool
{
    WpState::$meta[$id][$key] = wpsmcp_test_unslash($value);

    return true;
}

function delete_metadata(string $type, int $objectId, string $key, mixed $value = '', bool $all = false): bool
{
    if ($type === 'post') {
        unset(WpState::$meta[$objectId][$key]);

        return true;
    }

    foreach (WpState::$userMeta as $id => $_) {
        unset(WpState::$userMeta[$id][$key]);
    }

    return true;
}

function delete_post_meta_by_key(string $key): bool
{
    foreach (WpState::$meta as $id => $_) {
        unset(WpState::$meta[$id][$key]);
    }

    return true;
}

function delete_post_meta(int $id, string $key): bool
{
    unset(WpState::$meta[$id][$key]);

    return true;
}

function wp_trash_post(int $id): void
{
    WpState::$posts[$id]->post_status = 'trash';
}

function _wp_put_post_revision(object $post): ?int
{
    if (WpState::$revisionFails) {
        return null;
    }

    $id = WpState::$nextPostId++;
    WpState::$posts[$id] = (object) ['ID' => $id, 'post_type' => 'revision', 'post_parent' => $post->ID, 'post_title' => $post->post_title ?? '', 'post_content' => $post->post_content ?? '', 'post_status' => 'inherit'];
    WpState::$revisions[$post->ID] = $id;

    return $id;
}

/** @param array<string, mixed> $args */
function wp_register_ability(string $name, array $args): void
{
    WpState::$abilities[$name] = $args;
}

/** @return list<int> */
function wp_get_post_revisions(int $id, array $args = []): array
{
    return isset(WpState::$revisions[$id]) ? [WpState::$revisions[$id]] : [];
}

function get_permalink(int $id): string
{
    return "https://example.test/?page_id={$id}";
}

function wp_create_nonce(string $action): string
{
    return 'nonce-' . $action;
}

function download_url(string $url): string|WP_Error
{
    $file = tempnam(sys_get_temp_dir(), 'wpsmcp');
    file_put_contents($file, WpState::$downloadBody);

    return $file;
}

/** @return array{int, string}|WP_Error */
function wp_remote_get(string $url, array $args = []): array|WP_Error
{
    WpState::$fetched[] = $url;

    return WpState::$remote[$url] ?? new WP_Error('http_request_failed', 'unreachable');
}

function wp_remote_retrieve_response_code(array $response): int
{
    return $response[0];
}

function wp_remote_retrieve_body(array $response): string
{
    return $response[1];
}

function wp_next_scheduled(string $hook): int|false
{
    return in_array($hook, WpState::$scheduled, true) ? 1 : false;
}

function wp_schedule_event(int $time, string $recurrence, string $hook): bool
{
    WpState::$scheduled[] = $hook;

    return true;
}
