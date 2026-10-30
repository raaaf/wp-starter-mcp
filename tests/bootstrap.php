<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Minimal WordPress stubs. Only what the plugin code under test touches.

define('ABSPATH', sys_get_temp_dir() . '/wpsmcp-fake-wp/');
define('ARRAY_A', 'ARRAY_A');

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
    public static int $afterSetupTheme = 1;
    public static bool $restUrlCalled = false;
    /** Simulates a user_has_cap / map_meta_cap filter that resolves the current user. */
    public static ?\Closure $onUserCan = null;
    /** @var list<string> */
    public static array $clearedCron = [];

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
        self::$afterSetupTheme = 1;
        self::$restUrlCalled = false;
        self::$onUserCan = null;
        self::$clearedCron = [];
        $GLOBALS['wpdb'] = new wpdb();
    }
}

class WP_Error
{
    public function __construct(public string $code = '', public string $message = '', public mixed $data = null)
    {
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
    return false;
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

    return WpState::$home . '/wp-json/' . $path;
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

    /** @var array<string, array<int, array<string, mixed>>> */
    public array $tables = [];

    /** @param array<string, mixed> $data */
    public function insert(string $table, array $data): int|false
    {
        $this->tables[$table] ??= [];
        $this->insert_id = count($this->tables[$table]) + 1;
        $this->tables[$table][$this->insert_id] = ['id' => $this->insert_id, 'used_at' => null, 'revoked_at' => null, 'last_used_at' => null] + $data;

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
