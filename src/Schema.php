<?php

declare(strict_types=1);

namespace WpStarterMcp;

final class Schema
{
    public const VERSION = '1';
    public const OPTION = 'wpsmcp_schema_version';

    public static function tokensTable(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'wpsmcp_tokens';
    }

    public static function auditTable(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'wpsmcp_audit';
    }

    public static function install(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $tokens = self::tokensTable();
        $audit = self::auditTable();

        dbDelta("CREATE TABLE {$tokens} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  token_hash char(64) NOT NULL,
  type varchar(10) NOT NULL,
  family_id char(32) NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  client_id varchar(255) NOT NULL,
  redirect_uri varchar(255) NOT NULL DEFAULT '',
  code_challenge varchar(64) NOT NULL DEFAULT '',
  resource varchar(255) NOT NULL DEFAULT '',
  scope varchar(100) NOT NULL DEFAULT '',
  created_at bigint(20) unsigned NOT NULL,
  expires_at bigint(20) unsigned NOT NULL,
  used_at bigint(20) unsigned DEFAULT NULL,
  revoked_at bigint(20) unsigned DEFAULT NULL,
  last_used_at bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY token_hash (token_hash),
  KEY family_id (family_id),
  KEY user_id (user_id),
  KEY expires_at (expires_at)
) {$charset};");

        dbDelta("CREATE TABLE {$audit} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  event varchar(40) NOT NULL,
  post_id bigint(20) unsigned NOT NULL DEFAULT 0,
  client_host varchar(100) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY created_at (created_at)
) {$charset};");

        update_option(self::OPTION, self::VERSION);
    }

    public static function maybeUpgrade(): void
    {
        if (get_option(self::OPTION) !== self::VERSION) {
            self::install();
        }
    }
}
