<?php

declare(strict_types=1);

namespace WpStarterMcp;

use WpStarterMcp\OAuth\Authorize;
use WpStarterMcp\OAuth\Discovery;
use WpStarterMcp\OAuth\Token;

/**
 * Boots the plugin. While the feature is off only Updates is hooked, so the update channel
 * keeps working and no endpoint exists.
 */
final class McpPlugin
{
    public static function boot(): void
    {
        Updates::register();

        if (!Config::isEnabled()) {
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
