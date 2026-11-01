<?php

declare(strict_types=1);

namespace WpStarterMcp;

/**
 * Removes all plugin data, but only for a site whose administrator ticked "Delete all data".
 * Without the tick, uninstalling leaves tables and options in place.
 */
final class Uninstall
{
    public const OPTION_DELETE_DATA = 'wpsmcp_delete_data';

    public static function run(): void
    {
        Lifecycle::forEachSite(true, [self::class, 'site']);
    }

    public static function site(): void
    {
        global $wpdb;

        if (get_option(self::OPTION_DELETE_DATA) !== '1') {
            return;
        }

        $wpdb->query('DROP TABLE IF EXISTS ' . Schema::tokensTable());
        $wpdb->query('DROP TABLE IF EXISTS ' . Schema::auditTable());

        foreach ([Config::OPTION_ENABLED, Schema::OPTION, Updates::OPTION_MINIMUM, self::OPTION_DELETE_DATA] as $option) {
            delete_option($option);
        }

        delete_metadata('user', 0, OAuth\TokenStore::sessionMetaKey(), '', true);
        delete_metadata('user', 0, 'wpsmcp_intent', '', true);
        delete_post_meta_by_key('_wpsmcp_copy_of');
        delete_post_meta_by_key('_wpsmcp_copy_modified_gmt');
        delete_post_meta_by_key(ReplaceOriginal::SNAPSHOT_META);
        delete_post_meta_by_key(ReplaceOriginal::PIN_META);

        wp_clear_scheduled_hook(Config::PRUNE_HOOK);
        wp_clear_scheduled_hook(Updates::MANIFEST_HOOK);
    }
}
