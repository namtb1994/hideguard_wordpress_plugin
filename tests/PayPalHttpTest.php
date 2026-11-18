<?php

declare(strict_types=1);

use Hachitechs\HideguardWordPress\Settings;

final class PayPalHttpTest extends HttpTestCase
{
    private string $nonce;
    private array $config;

    protected function setUp(): void
    {
        parent::setUp();
        $values = (new Settings())->values();
        $values['keys']['apiKey'] = 'fixture-backend-secret';
        $values['points'] = ['complete_order'];
        update_option(Settings::OPTION, $values);
        update_option('hg_fixture_paypal_enabled', true);
        $this->api('allow');
        [$status, $html] = $this->request('/public/');
        self::assertSame(200, $status);
        $this->config = $this->extractConfig($html);
        self::assertSame(1, preg_match('#id="hg-fixture-paypal">(.*?)</script>#s', $html, $match));
        $this->nonce = json_decode($match[1], true)['nonce'];
        [, $body] = $this->request('/wp-json/hachitech-hideguard/v1/check', $this->tokens($this->config));
        $this->config['verificationId'] = json_decode($body, true)['verificationId'];
        self::assertSame(200, $this->verdict($this->config, SignedPayload::payload())[0]);
        $product = new WC_Product_Simple();
        $product->set_name('Paid PayPal test');
        $product->set_regular_price('10');
        $product->set_virtual(true);
        $product->set_status('publish');
        $product->save();
        [, $body] = $this->request('/?wc-ajax=add_to_cart', ['product_id' => $product->get_id(), 'quantity' => 1]);
        self::assertArrayHasKey('fragments', json_decode($body, true), $body);
        delete_option('hg_fixture_api_calls');
    }

    protected function tearDown(): void
    {
        delete_option('hg_fixture_paypal_enabled');
        parent::tearDown();
    }

    /** @dataProvider orderRoutes */
    public function testPaypalFirstReusesOneVerdictInLaterNativeCheckout(string $route): void
    {
        [$status, $created] = $this->paypal('create');
        self::assertSame(200, $status, $created);
        self::assertTrue(json_decode($created, true)['success']);
        self::assertSame(1, $this->calls('hg_fixture_api_calls'));
        $before = $this->orderCount();
        $orderId = $this->checkout($route);
        self::assertSame($before + 1, $this->orderCount());
        self::assertTrue((bool)wc_get_order($orderId)->get_meta('hg_fixture_payment_called'));
        self::assertSame(1, $this->calls('hg_fixture_api_calls'), 'The later HTTP checkout must reuse PayPal approval.');
        self::assertSame(1, $this->calls('hg_fixture_paypal_calls'));
    }

    public static function orderRoutes(): array
    {
        return [['classic'], ['store'], ['store-token']];
    }

    public function testWooFirstReusesConsumedApprovalForItsExactPayNowOrder(): void
    {
        $orderId = $this->checkout('classic');
        self::assertSame(1, $this->calls('hg_fixture_api_calls'));
        [$status, $body] = $this->paypal('create', ['context' => 'pay-now', 'order_id' => $orderId]);
        self::assertSame(200, $status, $body);
        self::assertTrue(json_decode($body, true)['success']);
        self::assertSame(1, $this->calls('hg_fixture_api_calls'));
        $other = wc_create_order();
        $other->set_total(10);
        $other->save();
        [$status] = $this->paypal('create', ['context' => 'pay-now', 'order_id' => $other->get_id()]);
        self::assertGreaterThanOrEqual(400, $status, 'Consumed approval cannot authorize another native order.');
        self::assertSame(1, $this->calls('hg_fixture_paypal_calls'));
    }

    public function testCheckoutReadFirstCanPassItsVerdictToPaypalAndTheLaterStoreCheckout(): void
    {
        [, , $headers] = $this->request('/wp-json/wc/store/cart');
        $nonce = '';
        foreach ($headers as $header) {
            if (stripos($header, 'Nonce:') === 0) {
                $nonce = trim(substr($header, 6));
            }
        }
        [$status, $body] = $this->request('/wp-json/wc/store/checkout', null, ['Nonce: ' . $nonce]);
        self::assertSame(200, $status, $body);
        $draft = json_decode($body, true)['order_id'];
        self::assertSame(1, $this->calls('hg_fixture_api_calls'));
        self::assertSame(200, $this->paypal('create')[0]);
        $beforeCheckout = $this->orderCount();
        $orderId = $this->checkout('store');
        self::assertGreaterThan(0, $orderId);
        if ($draft > 0) {
            self::assertSame($draft, $orderId);
            self::assertSame($beforeCheckout, $this->orderCount());
        } else {
            // WooCommerce 11.2 defers creating a draft until POST; checkout GET returns order_id=0.
            self::assertSame($beforeCheckout + 1, $this->orderCount());
        }
        self::assertSame(1, $this->calls('hg_fixture_api_calls'));
    }

    public function testApproveReusesConfirmedRemoteOrderAndGuardsItsDirectWooCreation(): void
    {
        [, $body] = $this->paypal('create');
        $remote = json_decode($body, true)['data']['id'];
        $before = $this->orderCount();
        [$status, $body] = $this->paypal('approve', ['order_id' => $remote]);
        self::assertSame(200, $status, $body);
        $orderId = json_decode($body, true)['data']['order_id'];
        self::assertSame($before + 1, $this->orderCount());
        self::assertTrue((bool)wc_get_order($orderId)->get_meta('hg_fixture_payment_called'));
        self::assertSame(1, $this->calls('hg_fixture_api_calls'));
        self::assertGreaterThanOrEqual(400, $this->paypal('approve', [
            'order_id' => $remote, 'should_create_wc_order' => 1,
        ])[0]);
        self::assertSame($before + 1, $this->orderCount(), 'A repeated direct approve cannot create a second order.');
    }

    /** @dataProvider paypalEntries */
    public function testBlockStopsBeforeRemoteApiOrderCreationAndPayment(string $entry): void
    {
        $this->api('block');
        $before = $this->orderCount();
        [$status, $body, $headers] = $this->paypal($entry, ['order_id' => 'UNAPPROVED-REMOTE']);
        self::assertSame(401, $status, $body);
        self::assertTrue($this->hasHeader($headers, 'Retry-After:'));
        self::assertTrue($this->hasHeader($headers, 'Cache-Control: private, no-store'));
        self::assertFalse(json_decode($body, true)['success']);
        self::assertSame($before, $this->orderCount());
        self::assertSame(0, $this->calls('hg_fixture_paypal_calls'));
        self::assertSame(1, $this->calls('hg_fixture_api_calls'));
        [$status, $body] = $this->request('/wp-json/hachitech-hideguard/v1/state', $this->tokens($this->config));
        self::assertSame(401, $status, $body);
        self::assertTrue(json_decode($body, true)['blocked']);
        self::assertSame(401, $this->request('/?hachitech_hideguard_gate=1')[0]);
        self::assertSame(401, $this->paypal($entry)[0]);
        self::assertSame(1, $this->calls('hg_fixture_api_calls'));
    }

    public static function paypalEntries(): array
    {
        return [['create'], ['approve']];
    }

    public function testNewPaypalCreateRequestAndDifferentRemoteOrderNeedFreshVerdicts(): void
    {
        self::assertSame(200, $this->paypal('create')[0]);
        self::assertSame(200, $this->paypal('create')[0]);
        self::assertSame(2, $this->calls('hg_fixture_api_calls'));
        $this->api('block');
        self::assertSame(401, $this->paypal('approve', ['order_id' => 'OTHER-REMOTE'])[0]);
        self::assertSame(3, $this->calls('hg_fixture_api_calls'));
        self::assertSame(2, $this->calls('hg_fixture_paypal_calls'));
    }

    public function testDisabledPluginLeavesTheNativePaypalEndpointUntouched(): void
    {
        $values = (new Settings())->values();
        $values['enabled'] = false;
        update_option(Settings::OPTION, $values);
        self::assertSame(200, $this->paypal('create')[0]);
        self::assertSame(0, $this->calls('hg_fixture_api_calls'));
        self::assertSame(1, $this->calls('hg_fixture_paypal_calls'));
    }

    public function testInvalidNativeNonceCannotCallHideguardOrPaypal(): void
    {
        self::assertSame(403, $this->paypal('create', ['nonce' => 'invalid'])[0]);
        self::assertSame(0, $this->calls('hg_fixture_api_calls'));
        self::assertSame(0, $this->calls('hg_fixture_paypal_calls'));
    }

    public function testApiFailureStopsBeforePaypalWithoutInventingABlock(): void
    {
        update_option('hg_fixture_api_response', ['error' => true]);
        self::assertGreaterThanOrEqual(400, $this->paypal('create')[0]);
        self::assertSame(0, $this->calls('hg_fixture_paypal_calls'));
        [$status, $body] = $this->request('/wp-json/hachitech-hideguard/v1/state', $this->tokens($this->config));
        self::assertSame(200, $status, $body);
        self::assertNotSame('blocked', json_decode($body, true)['status']);
        $this->api('allow');
        self::assertSame(200, $this->paypal('create')[0], 'A failed lookup releases its pending claim.');
    }

    private function paypal(string $action, array $data = []): array
    {
        return $this->request('/?wc-ajax=ppc-' . $action . '-order', $data + ['nonce' => $this->nonce, 'context' => 'checkout']);
    }

    private function checkout(string $route): int
    {
        $billing = ['first_name' => 'PayPal', 'last_name' => 'Fixture', 'country' => 'IT', 'state' => 'RM',
            'address_1' => 'Via Test 1', 'city' => 'Roma', 'postcode' => '00100',
            'phone' => '+390612345678', 'email' => 'paypal@example.test'];
        if (str_starts_with($route, 'store')) {
            [, , $headers] = $this->request('/wp-json/wc/store/cart');
            $nonce = '';
            $cartToken = '';
            foreach ($headers as $header) {
                if (stripos($header, 'Nonce:') === 0) {
                    $nonce = trim(substr($header, 6));
                }
                if (stripos($header, 'Cart-Token:') === 0) {
                    $cartToken = trim(substr($header, 11));
                }
            }
            [$status, $body] = $this->request('/wp-json/wc/store/checkout', [
                'billing_address' => $billing, 'payment_method' => 'ppcp-gateway', 'payment_data' => [],
            ], $route === 'store-token' ? ['Cart-Token: ' . $cartToken] : ['Nonce: ' . $nonce]);
            self::assertSame(200, $status, $body);
            return json_decode($body, true)['order_id'];
        }
        [, $html] = $this->request('/checkout/');
        self::assertSame(1, preg_match('#name="woocommerce-process-checkout-nonce"[^>]*value="([^"]+)"#', $html, $match));
        $data = ['woocommerce-process-checkout-nonce' => $match[1], 'payment_method' => 'ppcp-gateway',
            'terms' => '1', 'terms-field' => '1'];
        foreach ($billing as $key => $value) {
            $data['billing_' . $key] = $value;
        }
        [, $body] = $this->request('/?wc-ajax=checkout', $data);
        self::assertSame('success', json_decode($body, true)['result'], $body);
        parse_str(parse_url(json_decode($body, true)['redirect'], PHP_URL_QUERY) ?: '', $query);
        $order = wc_get_order_id_by_order_key($query['key']);
        self::assertGreaterThan(0, $order);
        return (int)$order;
    }

    private function api(string $verdict): void
    {
        update_option('hg_fixture_api_response', ['body' => json_encode([
            'visitorId' => 'fixture-visitor', 'verdict' => $verdict, 'score' => 0, 'reasons' => [],
        ])]);
    }

    private function calls(string $option): int
    {
        wp_cache_flush();
        $value = get_option($option, 0);
        return is_array($value) ? count($value) : (int)$value;
    }
}
