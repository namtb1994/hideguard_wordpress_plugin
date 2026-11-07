<?php

declare(strict_types=1);

// Emulate the verified request-started/native order-created hook boundary, never a live PayPal service.
if (!preg_match('/^hg_wp_test_[a-zA-Z0-9_]+$/D', DB_NAME)) {
    throw new RuntimeException('The PayPal mock is restricted to test databases.');
}
add_action('init', static function (): void {
    if (!get_option('hg_fixture_paypal_enabled')) {
        return;
    }
    foreach (['create', 'approve'] as $action) {
        add_action('wc_ajax_ppc-' . $action . '-order', static function () use ($action): void {
            if (!wp_verify_nonce($_POST['nonce'] ?? '', 'hg_fixture_paypal')) {
                wp_send_json_error(['message' => 'Invalid native nonce.'], 403);
            }
            do_action('woocommerce_paypal_payments_' . $action . '_order_request_started', wp_unslash($_POST));
            update_option('hg_fixture_paypal_calls', (int)get_option('hg_fixture_paypal_calls') + 1);
            if ($action === 'create') {
                $id = 'REMOTE-' . bin2hex(random_bytes(8));
                do_action('woocommerce_paypal_payments_paypal_order_created', new class($id) {
                    public function __construct(private string $value)
                    {
                    }
                    public function id(): string
                    {
                        return $this->value;
                    }
                });
                wp_send_json_success(['id' => $id]);
            }
            $order = wc_create_order();
            $order->set_customer_id(get_current_user_id());
            $order->set_total(10);
            $order->set_payment_method('ppcp-gateway');
            $order->save();
            do_action('woocommerce_paypal_payments_woocommerce_order_created_from_cart', $order, []);
            $gateway = WC()->payment_gateways()->payment_gateways()['ppcp-gateway'];
            $gateway->process_payment($order->get_id());
            wp_send_json_success(['order_id' => $order->get_id()]);
        });
    }
    add_action('wp_footer', static function (): void {
        echo '<script type="application/json" id="hg-fixture-paypal">'
            . wp_json_encode(['nonce' => wp_create_nonce('hg_fixture_paypal')]) . '</script>';
    });
});
add_filter('woocommerce_payment_gateways', static function (array $gateways): array {
    if (get_option('hg_fixture_paypal_enabled')) {
        $gateways[] = new class extends WC_Payment_Gateway {
            public function __construct()
            {
                $this->id = 'ppcp-gateway';
                $this->enabled = 'yes';
                $this->title = 'PayPal fixture';
            }
            public function process_payment($order_id)
            {
                $order = wc_get_order($order_id);
                $order->update_meta_data('hg_fixture_payment_called', true);
                $order->save();
                return ['result' => 'success', 'redirect' => $this->get_return_url($order)];
            }
        };
    }
    return $gateways;
});
