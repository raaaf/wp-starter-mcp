<?php

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$wpsmcpAutoload = __DIR__ . '/vendor/autoload.php';

if (is_readable($wpsmcpAutoload)) {
    require_once $wpsmcpAutoload;
    WpStarterMcp\Uninstall::run();
}
