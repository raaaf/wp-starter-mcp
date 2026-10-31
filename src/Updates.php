<?php

declare(strict_types=1);

namespace WpStarterMcp;

use WP_Error;

/**
 * Own update channel: plugin-update-checker against GitHub releases, a mandatory checksum
 * plus a detached Ed25519 signature per release zip, auto-update while the feature is on, and
 * a daily minimum-version manifest. Registered while the feature is off, so a security fix
 * still arrives.
 *
 * A version below the published minimum fails closed (McpPlugin registers no endpoint). An
 * unreachable or malformed manifest changes nothing, so a GitHub outage cannot disconnect sites.
 */
final class Updates
{
    public const REPO = 'https://github.com/raaaf/wp-starter-mcp/';
    public const SLUG = 'wp-starter-mcp';
    /** Main file as the packager lays it out in the zip. The installed folder may be named differently, see pluginFile(). */
    public const PLUGIN_FILE = 'wp-starter-mcp/wp-starter-mcp.php';
    public const MANIFEST_URL = 'https://raw.githubusercontent.com/raaaf/wp-starter-mcp/main/min-version.json';
    public const MANIFEST_HOOK = 'wpsmcp_manifest';
    public const OPTION_MINIMUM = 'wpsmcp_min_version';

    /**
     * Ed25519 public keys (base64) whose signatures a release must carry. The private key stays
     * on the maintainer's machine (scripts/release-signing.php). Same key as the theme.
     *
     * @var list<string>
     */
    private const RELEASE_PUBLIC_KEYS = [
        '487alxgU1p6+PM9vVumTr6jJGSv/E497NFDb1qcBZzU=',
    ];

    /**
     * Test seams, set through reflection or the setters below only.
     *
     * @var list<string>|null
     */
    // @phpstan-ignore property.unusedType
    private static ?array $publicKeysOverride = null;
    // @phpstan-ignore property.unusedType
    private static ?\Closure $checkerFactory = null;
    // @phpstan-ignore property.unusedType
    private static ?string $fileOverride = null;

    public static function register(): void
    {
        // No is_admin() gate: the checker must also run under cron.
        self::buildChecker();

        add_filter('auto_update_plugin', [self::class, 'autoUpdate'], 10, 2);
        add_filter('upgrader_pre_download', [self::class, 'verifyPackage'], 10, 4);
        add_action(self::MANIFEST_HOOK, [self::class, 'refreshManifest']);
        add_action('init', [self::class, 'scheduleManifest']);
    }

    private static function buildChecker(): void
    {
        $factory = self::$checkerFactory ?? static function (): void {
            if (!class_exists(\YahnisElsts\PluginUpdateChecker\v5\PucFactory::class) || !defined('WPSMCP_FILE')) {
                return;
            }

            $checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(self::REPO, WPSMCP_FILE, self::SLUG);
            // @phpstan-ignore method.notFound
            $api = $checker->getVcsApi();
            if (defined('GITHUB_ACCESS_TOKEN') && GITHUB_ACCESS_TOKEN) {
                $api->setAuthentication(GITHUB_ACCESS_TOKEN);
            }
            // The trait method is only on concrete VCS classes, not on the abstract base.
            if (method_exists($api, 'enableReleaseAssets')) {
                $api->enableReleaseAssets();
            }
        };

        $factory(self::REPO, self::SLUG);
    }

    public static function scheduleManifest(): void
    {
        if (wp_next_scheduled(self::MANIFEST_HOOK) === false) {
            wp_schedule_event(time() + 600, 'daily', self::MANIFEST_HOOK);
        }
    }

    /** Basename of the running plugin file, whatever its folder is called (a zip from GitHub unpacks as wp-starter-mcp-main). */
    private static function pluginFile(): string
    {
        $file = self::$fileOverride ?? (defined('WPSMCP_FILE') ? WPSMCP_FILE : null);

        return $file === null ? self::PLUGIN_FILE : plugin_basename($file);
    }

    /** Forced on for this plugin, and only while the feature is on. Everything else is untouched. */
    public static function autoUpdate(mixed $update, mixed $item): mixed
    {
        if (is_object($item) && ($item->plugin ?? null) === self::pluginFile() && Config::isEnabled()) {
            return true;
        }

        return $update;
    }

    /** Whether the running version is below the last published minimum. */
    public static function belowMinimum(): bool
    {
        $minimum = get_option(self::OPTION_MINIMUM, '');

        return is_string($minimum) && $minimum !== '' && version_compare(self::currentVersion(), $minimum, '<');
    }

    private static function currentVersion(): string
    {
        return defined('WPSMCP_VERSION') ? (string) WPSMCP_VERSION : '0';
    }

    /** Daily job. An unreachable or malformed manifest leaves the stored minimum as it is. */
    public static function refreshManifest(): void
    {
        $minimum = self::fetchMinimum();

        if ($minimum !== null) {
            update_option(self::OPTION_MINIMUM, $minimum);
        }
    }

    /**
     * Synchronous check at switch-on.
     *
     * @return bool false only when the manifest is reachable and the version is below it
     */
    public static function allowsSwitchOn(): bool
    {
        self::refreshManifest();

        return !self::belowMinimum();
    }

    private static function fetchMinimum(): ?string
    {
        $body = self::fetchAsset(self::MANIFEST_URL);
        $data = $body === null ? null : json_decode($body, true);
        $minimum = is_array($data) ? ($data['minimum'] ?? null) : null;

        return is_string($minimum) && preg_match('/^\d+(\.\d+){0,2}$/', $minimum) === 1 ? $minimum : null;
    }

    /**
     * Before WordPress installs this plugin's package, its `.sha256` and its `.sig` from the same
     * release must both exist and match; a missing or wrong one refuses the update. The checksum
     * catches a broken download, the signature proves the zip was built by the key holder. The
     * signature does not cover the version, so the zip's own Version header must also be above the
     * running one: an old, validly signed release is no update.
     *
     * @param array<string, mixed> $hookExtra
     */
    public static function verifyPackage(mixed $reply, string $package, mixed $upgrader = null, array $hookExtra = []): mixed
    {
        if (($hookExtra['plugin'] ?? null) !== self::pluginFile() || $reply !== false) {
            return $reply;
        }

        $file = download_url($package);
        if (is_wp_error($file)) {
            return $file;
        }

        $error = self::checkChecksum($file, $package) ?? self::checkSignature($file, $package) ?? self::checkVersion($file);
        if ($error !== null) {
            @unlink($file);

            return $error;
        }

        return $file;
    }

    private static function checkChecksum(string $file, string $package): ?WP_Error
    {
        $body = self::fetchAsset($package . '.sha256');
        if ($body === null) {
            return new WP_Error('wpsmcp_update_checksum_missing', __('Zu diesem Update ist keine Prüfsumme veröffentlicht. Das Update wurde abgebrochen.', 'wp-starter-mcp'));
        }

        $token = strtok(trim($body), " \t");
        $expected = $token === false ? '' : $token;
        $actual = hash_file('sha256', $file);

        if (preg_match('/^[a-f0-9]{64}$/i', $expected) !== 1 || $actual === false || !hash_equals(strtolower($expected), $actual)) {
            return new WP_Error('wpsmcp_update_checksum_mismatch', __('Die Prüfsumme des Updates stimmt nicht mit dem veröffentlichten Release überein. Das Update wurde abgebrochen.', 'wp-starter-mcp'));
        }

        return null;
    }

    private static function checkSignature(string $file, string $package): ?WP_Error
    {
        $keys = self::$publicKeysOverride ?? self::RELEASE_PUBLIC_KEYS;
        if ($keys === []) {
            return new WP_Error('wpsmcp_update_no_signing_key', __('Im Plugin ist kein Schlüssel zum Prüfen der Update-Signatur hinterlegt. Das Update wurde abgebrochen.', 'wp-starter-mcp'));
        }

        $body = self::fetchAsset($package . '.sig');
        $signature = $body !== null ? base64_decode(trim($body), true) : false;
        if ($signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return new WP_Error('wpsmcp_update_signature_missing', __('Dieses Update ist noch nicht signiert. Das Update wurde abgebrochen; bitte später erneut versuchen.', 'wp-starter-mcp'));
        }

        $contents = file_get_contents($file);
        if ($contents !== false) {
            foreach ($keys as $key) {
                $publicKey = base64_decode($key, true);
                if (
                    $publicKey !== false
                    && strlen($publicKey) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
                    && sodium_crypto_sign_verify_detached($signature, $contents, $publicKey)
                ) {
                    return null;
                }
            }
        }

        return new WP_Error('wpsmcp_update_signature_invalid', __('Die Signatur des Updates ist ungültig. Das Update wurde abgebrochen.', 'wp-starter-mcp'));
    }

    private static function checkVersion(string $file): ?WP_Error
    {
        $header = false;
        if (class_exists(\ZipArchive::class)) {
            $zip = new \ZipArchive();
            if ($zip->open($file) === true) {
                $header = $zip->getFromName(self::PLUGIN_FILE, 8192);
                $zip->close();
            }
        }

        $version = is_string($header) && preg_match('/^[ \t\/*#@]*Version:[ \t]*(\S+)/mi', $header, $m) === 1 ? $m[1] : '';

        if ($version === '' || version_compare($version, self::currentVersion(), '<=')) {
            return new WP_Error('wpsmcp_update_not_newer', __('Das Paket ist nicht neuer als die installierte Version oder nicht lesbar. Das Update wurde abgebrochen.', 'wp-starter-mcp'));
        }

        return null;
    }

    /** Body of a remote asset, or null when it is missing or unreachable. */
    private static function fetchAsset(string $url): ?string
    {
        $response = wp_remote_get($url, ['timeout' => 10]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        return wp_remote_retrieve_body($response);
    }
}
