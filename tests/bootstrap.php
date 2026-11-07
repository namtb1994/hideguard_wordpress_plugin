<?php

declare(strict_types=1);

ob_start();
require dirname(__DIR__) . '/vendor/autoload.php';
$root = rtrim((string)getenv('HG_WP_ROOT'), '/');
if (!is_file($root . '/wp-load.php')) {
    throw new RuntimeException('Set HG_WP_ROOT to the disposable installed WordPress fixture.');
}
$_SERVER['HTTP_HOST'] = '127.0.0.1:18889';
$_SERVER['REQUEST_METHOD'] = 'GET';
require $root . '/wp-load.php';
if (!preg_match('/^hg_wp_test_[a-zA-Z0-9_]+$/D', DB_NAME) || home_url() !== 'http://127.0.0.1:18889') {
    throw new RuntimeException('Refuse to run mutating tests outside the disposable hg_wp_test_* fixture.');
}
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
require_once __DIR__ . '/SignedPayload.php';
add_filter('pre_wp_mail', '__return_true');
require_once __DIR__ . '/AdapterTestCase.php';
require_once __DIR__ . '/HttpTestCase.php';
