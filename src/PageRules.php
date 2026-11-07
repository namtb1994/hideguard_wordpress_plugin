<?php

declare(strict_types=1);

namespace Hachitechs\HideguardWordPress;

use Hachitechs\HideguardPhp\Configuration\ProtectionPolicy;
use Hachitechs\HideguardPhp\Rules\PathMatcher;

/** WordPress/WooCommerce route selection; the browser receives the server's decision. */
final class PageRules
{
    public function protects(ProtectionPolicy $policy): bool
    {
        if (
            !$policy->enabled || is_admin() || wp_doing_ajax() || wp_doing_cron()
            || (defined('REST_REQUEST') && REST_REQUEST) || is_feed() || is_robots() || is_trackback()
        ) {
            return false;
        }
        $path = (string)(wp_parse_url(wp_unslash($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
        $base = rtrim((string)wp_parse_url(home_url('/'), PHP_URL_PATH), '/');
        if ($base !== '' && str_starts_with($path, $base . '/')) {
            $path = substr($path, strlen($base));
        }
        return $policy->protects('all_pages') || (new PathMatcher())->matches($path, $policy->pageRules)
            || ($policy->protects('product') && function_exists('is_product') && is_product())
            || ($policy->protects('cart') && function_exists('is_cart') && is_cart())
            || ($policy->protects('checkout') && function_exists('is_checkout') && is_checkout());
    }
}
