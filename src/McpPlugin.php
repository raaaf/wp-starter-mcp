<?php

declare(strict_types=1);

namespace WpStarterMcp;

use WpStarterMcp\OAuth\Authorize;
use WpStarterMcp\OAuth\Discovery;
use WpStarterMcp\OAuth\Token;

/**
 * Boots the plugin. While the feature is off, or below the minimum version, no endpoint exists.
 */
final class McpPlugin
{
    public static function boot(): void
    {
        // Hooked while off: the update channel, the privacy tools for data that outlives the
        // switch, the setup page (the switch lives there) and the pinned replace snapshots.
        Updates::register();
        Privacy::register();
        SetupPage::register();
        ReplaceOriginal::register();

        // Below the published minimum version nothing else is registered: endpoints 404.
        if (!Config::isEnabled() || Updates::belowMinimum()) {
            return;
        }

        add_action('plugins_loaded', [Schema::class, 'maybeUpgrade']);
        add_action('init', [Pruner::class, 'schedule']);
        add_action(Config::PRUNE_HOOK, [Pruner::class, 'run']);

        add_action('switch_theme', [Lifecycle::class, 'revokeAll']);
        add_action('delete_user', [Lifecycle::class, 'revokeUser']);
        add_action('after_password_reset', [Lifecycle::class, 'onPasswordReset']);
        add_action('profile_update', [Lifecycle::class, 'onProfileUpdate'], 10, 2);

        Discovery::register();
        Authorize::register();
        Token::register();
        BearerGate::register();
        Abilities::register();
        Server::register();
    }
}
