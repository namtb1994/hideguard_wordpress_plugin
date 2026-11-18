<?php

declare(strict_types=1);

// Installed only in the disposable fixture. Never use this mock on an application site.
if (!preg_match('/^hg_wp_test_[a-zA-Z0-9_]+$/D', DB_NAME)) {
    throw new RuntimeException('The HideGuard HTTP mock is restricted to test databases.');
}
add_filter('pre_http_request', static function ($preempt, array $args, string $url) {
    if ($preempt !== false || $url !== 'https://hideguard.io/api/v1/verdict') {
        return $preempt;
    }
    $calls = get_option('hg_fixture_api_calls', []);
    $calls[] = ['url' => $url, 'body' => $args['body'], 'headers' => $args['headers'],
        'redirection' => $args['redirection'], 'sslverify' => $args['sslverify'], 'timeout' => $args['timeout']];
    update_option('hg_fixture_api_calls', $calls, false);
    $mock = get_option('hg_fixture_api_response', []);
    if (isset($mock['error'])) {
        return new WP_Error('http_request_failed', 'Fixture transport failure.');
    }
    return ['headers' => [], 'cookies' => [], 'response' => ['code' => $mock['status'] ?? 200],
        'body' => $mock['body'] ?? '{}'];
}, 10, 3);

add_filter('woocommerce_payment_gateways', static function (array $gateways): array {
    $gateways[] = new class extends WC_Payment_Gateway {
        public function __construct()
        {
            $this->id = 'hg_fixture_gateway';
            $this->enabled = 'yes';
            $this->title = 'Fixture gateway';
        }

        // Native WooCommerce method naming/signature; no external payment service is called.
        public function process_payment($order_id)
        {
            $order = wc_get_order($order_id);
            $order->update_meta_data('hg_fixture_payment_called', true);
            $order->save();
            return ['result' => 'success', 'redirect' => $this->get_return_url($order)];
        }
    };
    return $gateways;
});
