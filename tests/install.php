<?php

declare(strict_types=1);

// Explicit disposable setup; never install over an existing WordPress configuration/database.
$root = rtrim((string)getenv('HG_WP_ROOT'), '/');
$database = (string)getenv('HG_WP_DB_NAME');
if (!is_file($root . '/wp-load.php') || is_file($root . '/wp-config.php')
    || !preg_match('/^hg_wp_test_[a-zA-Z0-9_]+$/D', $database)
) {
    throw new RuntimeException('Use a fresh WordPress directory and an empty hg_wp_test_* database.');
}
$theme = $root . '/wp-content/themes/hideguard-test';
if (!is_dir($theme)) {
    mkdir($theme, 0775, true);
}
foreach (glob(__DIR__ . '/fixtures/hideguard-test/*') as $file) {
    if (!copy($file, $theme . '/' . basename($file))) {
        throw new RuntimeException('Unable to install the fixture theme.');
    }
}
$config = "<?php\n";
foreach ([
    'DB_NAME' => $database, 'DB_USER' => (string)getenv('HG_WP_DB_USER'),
    'DB_PASSWORD' => (string)getenv('HG_WP_DB_PASSWORD'), 'DB_HOST' => (string)getenv('HG_WP_DB_HOST'),
    'DB_CHARSET' => 'utf8mb4', 'DB_COLLATE' => '', 'WP_HOME' => 'http://127.0.0.1:18889',
    'WP_SITEURL' => 'http://127.0.0.1:18889', 'DISABLE_WP_CRON' => true, 'WP_HTTP_BLOCK_EXTERNAL' => true,
    'WP_ENVIRONMENT_TYPE' => 'local', 'WP_DEBUG' => false, 'WP_CACHE' => false,
] as $name => $value) {
    $config .= 'define(' . var_export($name, true) . ', ' . var_export($value, true) . ");\n";
}
foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY',
    'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'] as $name) {
    $config .= 'define(' . var_export($name, true) . ', ' . var_export(bin2hex(random_bytes(32)), true) . ");\n";
}
$config .= "\$table_prefix = 'wp_';\nif (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }\n"
    . "require_once ABSPATH . 'wp-settings.php';\n";
file_put_contents($root . '/wp-config.php', $config);
chmod($root . '/wp-config.php', 0600);
define('WP_INSTALLING', true);
$_SERVER['HTTP_HOST'] = '127.0.0.1:18889';
$_SERVER['REQUEST_METHOD'] = 'GET';
require $root . '/wp-load.php';
if ($wpdb->get_col('SHOW TABLES') !== []) {
    throw new RuntimeException('Refuse to modify a nonempty fixture database.');
}
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
add_filter('pre_wp_mail', '__return_true');
wp_install('HideGuard Test', 'hg_test_admin', 'admin@example.test', false, '',
    (string)getenv('HG_TEST_ADMIN_PASSWORD'));
if (!is_dir($root . '/wp-content/mu-plugins')) {
    mkdir($root . '/wp-content/mu-plugins', 0775, true);
}
file_put_contents($root . '/wp-content/mu-plugins/fixture-mail.php',
    "<?php\nadd_filter('pre_wp_mail', '__return_true');\n");
copy(__DIR__ . '/fixtures/backend-api.php', $root . '/wp-content/mu-plugins/backend-api.php');
copy(__DIR__ . '/fixtures/paypal.php', $root . '/wp-content/mu-plugins/paypal.php');
copy(__DIR__ . '/fixtures/request-gate.php', $root . '/wp-content/mu-plugins/request-gate.php');
require_once ABSPATH . 'wp-admin/includes/plugin.php';
foreach (['woocommerce/woocommerce.php', 'hachitechs-hideguard/hachitechs-hideguard.php'] as $plugin) {
    $result = activate_plugin($plugin);
    if (is_wp_error($result)) {
        throw new RuntimeException($result->get_error_message());
    }
}
switch_theme('hideguard-test');
foreach (['protected' => 'Original protected fixture content', 'public' => 'Unprotected fixture content',
    'cart' => '[woocommerce_cart]', 'checkout' => '[woocommerce_checkout]',
    'my-account' => '[woocommerce_my_account]'] as $slug => $content) {
    $existing = get_page_by_path($slug);
    $post = ['post_type' => 'page', 'post_status' => 'publish', 'post_title' => ucfirst($slug),
        'post_name' => $slug, 'post_content' => $content];
    $id = $existing ? wp_update_post(['ID' => $existing->ID] + $post) : wp_insert_post($post);
    if (in_array($slug, ['cart', 'checkout'], true)) {
        update_option('woocommerce_' . $slug . '_page_id', $id);
    }
    if ($slug === 'my-account') {
        update_option('woocommerce_myaccount_page_id', $id);
    }
}
$product = new WC_Product_Simple();
$product->set_name('HideGuard fixture product');
$product->set_regular_price('0');
$product->set_virtual(true);
$product->set_status('publish');
$product->save();
update_option('hg_fixture_product_id', $product->get_id());
update_option('woocommerce_currency', 'EUR');
update_option('woocommerce_default_country', 'IT');
update_option('woocommerce_enable_guest_checkout', 'yes');
update_option('woocommerce_coming_soon', 'no');
update_option('woocommerce_enable_myaccount_registration', 'yes');
update_option('woocommerce_enable_signup_and_login_from_checkout', 'yes');
update_option('woocommerce_registration_generate_username', 'no');
update_option('woocommerce_registration_generate_password', 'no');
update_option('users_can_register', 1);
update_option('permalink_structure', '/%postname%/');
flush_rewrite_rules();
echo "PASS: isolated WordPress and WooCommerce fixture installed; no mail or external HTTP enabled.\n";
