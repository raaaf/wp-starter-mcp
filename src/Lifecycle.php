<?php

declare(strict_types=1);

namespace WpStarterMcp;

use WpStarterMcp\OAuth\TokenStore;

/** Events after which no connection may survive. */
final class Lifecycle
{
    /** The tables exist only after the feature was switched on once, and not after "Delete all data". */
    private static function installed(): bool
    {
        return get_option(Schema::OPTION) !== false;
    }

    public static function revokeAll(): void
    {
        if (self::installed()) {
            TokenStore::make()->revokeAll();
        }
    }

    /** Tokens belong to a site, the account does not: on a network every site the user belongs to is covered. */
    public static function revokeUser(int $userId): void
    {
        if (!is_multisite()) {
            self::revokeUserHere($userId);

            return;
        }

        $sites = array_map('intval', array_keys(get_blogs_of_user($userId, true)));
        $sites[] = (int) get_current_blog_id();

        foreach (array_unique($sites) as $siteId) {
            switch_to_blog($siteId);
            self::revokeUserHere($userId);
            restore_current_blog();
        }
    }

    private static function revokeUserHere(int $userId): void
    {
        if (self::installed()) {
            TokenStore::make()->revokeUser($userId);
        }
    }

    /**
     * Roles are per site: a user who can no longer edit pages here loses the connections of this site.
     *
     * @param list<string> $oldRoles
     */
    public static function onRoleChange(int $userId, string $role, array $oldRoles): void
    {
        if (!user_can($userId, 'edit_pages')) {
            self::revokeUserHere($userId);
        }
    }

    /** @param \WP_User|object $user */
    public static function onPasswordReset(object $user): void
    {
        if (isset($user->ID)) {
            self::revokeUser((int) $user->ID);
        }
    }

    /** Revokes only when the password hash changed. */
    public static function onProfileUpdate(int $userId, object $oldUser): void
    {
        $current = get_userdata($userId);

        if ($current !== false && isset($oldUser->user_pass) && $current->user_pass !== $oldUser->user_pass) {
            self::revokeUser($userId);
        }
    }

    public static function deactivate(bool $networkWide = false): void
    {
        self::forEachSite($networkWide, [self::class, 'deactivateSite']);
    }

    /** Runs a callback for the current site, or for every site of a network. */
    public static function forEachSite(bool $networkWide, callable $callback): void
    {
        if ($networkWide && is_multisite()) {
            foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $siteId) {
                switch_to_blog((int) $siteId);
                $callback();
                restore_current_blog();
            }

            return;
        }

        $callback();
    }

    /** Revokes every token, switches the feature off and clears the cron for the current site. */
    public static function deactivateSite(): void
    {
        self::revokeAll();
        update_option(Config::OPTION_ENABLED, '0');
        wp_clear_scheduled_hook(Config::PRUNE_HOOK);
        wp_clear_scheduled_hook(Updates::MANIFEST_HOOK);
    }
}
