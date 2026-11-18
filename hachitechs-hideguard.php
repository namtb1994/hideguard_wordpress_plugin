<?php

/**
 * Plugin Name: Hachitech Hideguard
 * Description: HideGuard page and WooCommerce protection with server-verified Ed25519 verdicts.
 * Version: 1.6.0
 * Requires at least: 6.9
 * Requires PHP: 8.1
 * Text Domain: hachitechs-hideguard
 * Author: Hachitechs
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}
// Composer projects may already have loaded this adapter; built ZIPs own vendor/.
if (!class_exists(\Hachitechs\HideguardWordPress\Plugin::class)) {
    if (!is_file(__DIR__ . '/vendor/autoload.php')) {
        add_action('admin_notices', static function (): void {
            echo '<div class="notice notice-error"><p>'
                . esc_html__(
                    'Install the Hachitech Composer dependencies before activating protection.',
                    'hachitechs-hideguard'
                )
                . '</p></div>';
        });
        register_activation_hook(__FILE__, static function (): void {
            wp_die('Install the Hachitech Composer dependencies before activation.', '', ['response' => 503]);
        });
        if (!empty(get_option('hachitech_hideguard', [])['enabled'])) {
            add_action('parse_request', static function (): never {
                nocache_headers();
                wp_die('Access protection is unavailable.', '', ['response' => 503]);
            }, -100);
        }
        return;
    }
    require __DIR__ . '/vendor/autoload.php';
}
$hachitechPlugin = new \Hachitechs\HideguardWordPress\Plugin(__FILE__);
$hachitechPlugin->boot();
register_activation_hook(__FILE__, [$hachitechPlugin, 'activate']);
register_deactivation_hook(__FILE__, [$hachitechPlugin, 'deactivate']);
