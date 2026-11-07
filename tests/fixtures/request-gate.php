<?php

declare(strict_types=1);

if (!preg_match('/^hg_wp_test_[a-zA-Z0-9_]+$/D', DB_NAME)) {
    throw new RuntimeException('The request queue fixture is restricted to test databases.');
}
add_action('hachitech_hideguard_ready', static function ($services): void {
    $GLOBALS['hg_fixture_services'] = $services;
});
add_action('wp_enqueue_scripts', static function (): void {
    if (!get_option('hg_fixture_request_gate') || !is_page('protected')) {
        return;
    }
    wp_enqueue_script('jquery');
    // Deliberately registered by a MU plugin before HideGuard's enqueue callback.
    wp_add_inline_script('jquery-core', <<<'JS'
window.hgFixture = window.hgFixture || {finished: [], errors: []};
window.hgFixture.jquery = jQuery.ajax({url:'/wp-json/hg-fixture/v1/opaque?kind=jquery', timeout:1000})
    .done(function () { hgFixture.finished.push('jquery'); })
    .fail(function (_xhr, status) { hgFixture.errors.push('jquery-' + status); });
JS, 'after');
}, 0);
add_action('wp_head', static function (): void {
    if (!get_option('hg_fixture_request_gate') || !is_page('protected')) {
        return;
    }
    wp_print_inline_script_tag(<<<'JS'
window.hgFixture = window.hgFixture || {finished: [], errors: []};
fetch('/wp-json/hg-fixture/v1/opaque?kind=fetch').then(function (response) {
    if (!response.ok) { throw new Error('HTTP ' + response.status); }
    hgFixture.finished.push('fetch');
}).catch(function (error) { hgFixture.errors.push(error.name); });
window.hgFixture.xhr = new XMLHttpRequest();
hgFixture.xhr.open('GET', '/wp-json/hg-fixture/v1/opaque?kind=xhr');
hgFixture.xhr.onload = function () {
    if (hgFixture.xhr.status === 200) { hgFixture.finished.push('xhr'); }
    else { hgFixture.errors.push('xhr-HTTP-' + hgFixture.xhr.status); }
};
hgFixture.xhr.onerror = function () { hgFixture.errors.push('xhr-error'); };
hgFixture.xhr.send();
JS, ['id' => 'hg-fixture-early-widget']);
}, -10000);
add_action('rest_api_init', static function (): void {
    if (!get_option('hg_fixture_request_gate')) {
        return;
    }
    register_rest_route('hg-fixture/v1', '/opaque', [
        'methods' => 'GET',
        'permission_callback' => static function () {
            $services = $GLOBALS['hg_fixture_services'];
            $decision = $services->guard()->inspect($services->identity->context(), $services->settings->policy());
            return $decision->status === 'allowed' ? true
                : new WP_Error('hg_fixture_denied', 'Fixture access denied.', ['status' => 403]);
        },
        'callback' => static fn() => ['ok' => true],
    ]);
});
