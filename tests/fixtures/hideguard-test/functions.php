<?php
add_action('after_setup_theme', static function (): void {
    add_theme_support('woocommerce');
});
