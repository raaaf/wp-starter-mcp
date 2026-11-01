<?php

declare(strict_types=1);

namespace WpStarterMcp;

use WpStarterMcp\OAuth\TokenStore;

/**
 * The actions behind the setup page. Each returns a notice code; capability is checked here,
 * the nonce by the caller (SetupPage).
 */
final class Setup
{
    public static function enable(bool $ackTransfer, bool $ackUpdates): string
    {
        if (!current_user_can('manage_options')) {
            return 'denied';
        }

        if (!$ackTransfer || !$ackUpdates) {
            return 'need_ack';
        }

        if (!Updates::allowsSwitchOn()) {
            return 'below_min';
        }

        Schema::install();
        update_option(Config::OPTION_ENABLED, '1');
        Pruner::schedule();
        Audit::record('enable', get_current_user_id());

        return 'enabled';
    }

    public static function disable(): string
    {
        if (!current_user_can('manage_options')) {
            return 'denied';
        }

        // Revokes every token and its adapter sessions, switches off, clears the cron.
        Lifecycle::deactivateSite();
        Audit::record('disable', get_current_user_id());

        return 'disabled';
    }

    public static function start(): string
    {
        if (!current_user_can('edit_pages')) {
            return 'denied';
        }

        if (!Config::isEnabled()) {
            return 'off';
        }

        (new Intent())->create(get_current_user_id());

        return 'started';
    }

    /** Own connections for editors, any connection for administrators. */
    public static function revoke(string $familyId): string
    {
        if (!current_user_can('edit_pages')) {
            return 'denied';
        }

        $store = TokenStore::make();
        $userId = current_user_can('manage_options') ? null : get_current_user_id();

        foreach ($store->connections($userId) as $connection) {
            if ($connection['family_id'] === $familyId) {
                $store->revokeFamily($familyId);
                Audit::record('revoke', get_current_user_id(), 0, $connection['client_host']);

                return 'revoked';
            }
        }

        return 'denied';
    }

    public static function setDeleteData(bool $delete): string
    {
        if (!current_user_can('manage_options')) {
            return 'denied';
        }

        update_option(Uninstall::OPTION_DELETE_DATA, $delete ? '1' : '0');

        return 'saved';
    }
}
