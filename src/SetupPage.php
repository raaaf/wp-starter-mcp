<?php

declare(strict_types=1);

namespace WpStarterMcp;

use WpStarterMcp\OAuth\TokenStore;

/** Tools > Claude: admin menu entry, form handlers, self-check and the view. */
final class SetupPage
{
    public const SLUG = 'wpsmcp';
    public const PROBE_ACTION = 'wpsmcp_probe';
    private const ACTIONS = ['enable', 'disable', 'start', 'revoke', 'delete_data'];

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'addMenu']);
        add_action('wp_ajax_' . self::PROBE_ACTION, [self::class, 'probe']);

        foreach (self::ACTIONS as $action) {
            add_action('admin_post_wpsmcp_' . $action, static fn () => self::handle($action));
        }
    }

    public static function addMenu(): void
    {
        add_management_page(__('Claude', 'wp-starter-mcp'), __('Claude', 'wp-starter-mcp'), 'edit_pages', self::SLUG, [self::class, 'render']);
    }

    /** Answers whether the Authorization header reaches PHP. The browser calls it with a dummy Bearer. */
    public static function probe(): void
    {
        wp_send_json(['authorization' => BearerGate::bearer() !== null]);
    }

    private static function handle(string $action): void
    {
        check_admin_referer('wpsmcp_' . $action);

        $flag = static fn (string $key): bool => isset($_POST[$key]) && $_POST[$key] === '1';
        $notice = match ($action) {
            'enable' => Setup::enable($flag('ack_transfer'), $flag('ack_updates')),
            'disable' => Setup::disable(),
            'start' => Setup::start(),
            'revoke' => Setup::revoke(isset($_POST['family']) && is_string($_POST['family']) ? sanitize_key($_POST['family']) : ''),
            default => Setup::setDeleteData($flag('delete_data')),
        };

        wp_safe_redirect(add_query_arg('wpsmcp_notice', $notice, admin_url(Config::SETUP_PAGE)));
        exit;
    }

    public static function render(): void
    {
        if (!current_user_can('edit_pages')) {
            wp_die(esc_html__('Sie dürfen diese Seite nicht öffnen.', 'wp-starter-mcp'));
        }

        $isAdmin = current_user_can('manage_options');
        $enabled = Config::isEnabled();
        $store = TokenStore::make();
        $notice = isset($_GET['wpsmcp_notice']) && is_string($_GET['wpsmcp_notice']) ? sanitize_key($_GET['wpsmcp_notice']) : '';

        View::render('setup', [
            'is_admin' => $isAdmin,
            'enabled' => $enabled,
            'notice' => self::noticeText($notice),
            'notice_ok' => in_array($notice, ['enabled', 'disabled', 'started', 'revoked', 'saved'], true),
            'below_minimum' => Updates::belowMinimum(),
            'contract_message' => Contract::message(),
            'checks' => $isAdmin ? self::checks($enabled) : [],
            'connections' => array_map(static function (array $c): array {
                $user = get_userdata($c['user_id']);

                return $c + ['user' => $user === false ? '' : (string) $user->display_name];
            }, $store->connections($isAdmin ? null : get_current_user_id())),
            'audit' => $isAdmin ? self::auditRows() : [],
            'intent_fresh' => (new Intent())->isFresh(get_current_user_id()),
            'mcp_url' => Config::mcpUrl(),
            'delete_data' => get_option(Uninstall::OPTION_DELETE_DATA) === '1',
            'version' => defined('WPSMCP_VERSION') ? (string) WPSMCP_VERSION : '',
            'probe_url' => admin_url('admin-ajax.php?action=' . self::PROBE_ACTION),
            'discovery_url' => self::discoveryUrl(),
            'action_url' => admin_url('admin-post.php'),
            'date_format' => 'd.m.Y H:i',
        ]);
    }

    private static function discoveryUrl(): string
    {
        $home = (string) parse_url(home_url(), PHP_URL_PATH);

        return home_url('/.well-known/oauth-authorization-server') . rtrim($home, '/');
    }

    private static function noticeText(string $code): string
    {
        return match ($code) {
            'enabled' => __('Die Claude-Anbindung ist eingeschaltet.', 'wp-starter-mcp'),
            'disabled' => __('Die Claude-Anbindung ist ausgeschaltet. Alle Verbindungen wurden getrennt.', 'wp-starter-mcp'),
            'started' => __('Die Verbindung ist bereit. Sie haben 10 Minuten Zeit, sie in Claude abzuschließen.', 'wp-starter-mcp'),
            'revoked' => __('Die Verbindung wurde getrennt.', 'wp-starter-mcp'),
            'saved' => __('Gespeichert.', 'wp-starter-mcp'),
            'need_ack' => __('Bitte bestätigen Sie beide Hinweise, bevor Sie einschalten.', 'wp-starter-mcp'),
            'below_min' => __('Diese Plugin-Version ist zu alt. Bitte erst aktualisieren.', 'wp-starter-mcp'),
            'off' => __('Die Claude-Anbindung ist ausgeschaltet. Bitte fragen Sie Ihre Administratorin oder Ihren Administrator.', 'wp-starter-mcp'),
            'denied' => __('Das ist mit Ihrem Konto nicht möglich.', 'wp-starter-mcp'),
            default => '',
        };
    }

    /**
     * Self-check with a fix text per item. ok is true, false, or null for "checked in the browser".
     *
     * @return list<array{label: string, ok: bool|null, fix: string, probe?: string}>
     */
    private static function checks(bool $enabled): array
    {
        $contractProblem = Contract::problem();
        $adapter = class_exists('WP\\MCP\\Core\\McpAdapter');
        $autoUpdates = self::autoUpdateProblem();

        return [
            ['label' => __('Die Website nutzt HTTPS', 'wp-starter-mcp'), 'ok' => str_starts_with(home_url(), 'https://'),
                'fix' => __('Claude verbindet sich nur über HTTPS. Bitte ein Zertifikat einrichten und die Website-Adresse auf https umstellen.', 'wp-starter-mcp')],
            ['label' => __('Advanced Custom Fields ist aktiv', 'wp-starter-mcp'), 'ok' => function_exists('acf_get_field'),
                'fix' => __('Das Plugin Advanced Custom Fields (ACF) installieren und aktivieren.', 'wp-starter-mcp')],
            ['label' => __('Der MCP Adapter ist aktiv', 'wp-starter-mcp'), 'ok' => $adapter,
                'fix' => __('Das Plugin "MCP Adapter" installieren und aktivieren.', 'wp-starter-mcp')],
            ['label' => __('Das Theme unterstützt die Anbindung', 'wp-starter-mcp'), 'ok' => $contractProblem === '',
                'fix' => Contract::message()],
            ['label' => __('Der Authorization-Header kommt an', 'wp-starter-mcp'), 'ok' => null, 'probe' => 'authorization',
                'fix' => __('Der Server entfernt den Authorization-Header. Bitte beim Hoster nachfragen oder in der .htaccess "SetEnvIf Authorization (.+) HTTP_AUTHORIZATION=$1" ergänzen. Außerdem muss Claude den Header MCP-Protocol-Version senden.', 'wp-starter-mcp')],
            ['label' => __('Die Adresse unter .well-known ist erreichbar', 'wp-starter-mcp'), 'ok' => null, 'probe' => $enabled ? 'wellknown' : 'off',
                'fix' => __('Der Server blockiert Adressen, die mit .well-known beginnen. Bitte beim Hoster freigeben. Eine Firewall darf außerdem Anthropic (160.79.104.0/21) nicht aussperren.', 'wp-starter-mcp')],
            ['label' => __('Automatische Updates sind möglich', 'wp-starter-mcp'), 'ok' => $autoUpdates === '', 'fix' => $autoUpdates],
        ];
    }

    private static function autoUpdateProblem(): string
    {
        if (!wp_is_file_mod_allowed('automatic_updater')) {
            return __('Dateiänderungen sind gesperrt (DISALLOW_FILE_MODS). Sicherheitsupdates können nicht automatisch eingespielt werden.', 'wp-starter-mcp');
        }

        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        $updater = new \WP_Automatic_Updater();

        if ($updater->is_disabled()) {
            return __('Automatische Updates sind abgeschaltet (AUTOMATIC_UPDATER_DISABLED oder ein Filter). Bitte aktivieren.', 'wp-starter-mcp');
        }

        if ($updater->is_vcs_checkout(WP_PLUGIN_DIR . '/wp-starter-mcp')) {
            return __('Das Plugin liegt in einem Git-Ordner. Dort werden keine automatischen Updates eingespielt.', 'wp-starter-mcp');
        }

        return '';
    }

    /** @return list<array<string, mixed>> */
    private static function auditRows(): array
    {
        global $wpdb;

        /** @var list<array<string, mixed>> $rows */
        $rows = $wpdb->get_results('SELECT * FROM ' . Schema::auditTable() . ' ORDER BY id DESC LIMIT 50', ARRAY_A);

        return array_map(static function (array $row): array {
            $user = get_userdata((int) $row['user_id']);

            return $row + ['user' => $user === false ? '' : (string) $user->display_name];
        }, $rows);
    }
}
