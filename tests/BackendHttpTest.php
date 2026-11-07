<?php

declare(strict_types=1);

use Hachitechs\HideguardWordPress\Settings;

final class BackendHttpTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $values = (new Settings())->values();
        $values['keys']['development']['apiKey'] = 'fixture-backend-secret';
        $values['points'] = [];
        update_option(Settings::OPTION, $values);
        update_option('woocommerce_enable_signup_and_login_from_checkout', 'yes');
        $this->apiResponse('allow');
    }

    public function testApiAllowAndBlockOnStoreCartReuseTheExistingGate(): void
    {
        $config = $this->verifyBrowser();
        self::assertTrue($config['backendEvents']);
        self::assertArrayNotHasKey('apiKey', $config);
        self::assertStringNotContainsString('fixture-backend-secret', json_encode($config));
        [, , $headers] = $this->request('/wp-json/wc/store/cart');
        $nonce = $this->storeNonce($headers);
        $product = (int)get_option('hg_fixture_product_id');
        [$status, $body] = $this->request('/wp-json/wc/store/cart/add-item',
            ['id' => $product, 'quantity' => 1], ['Nonce: ' . $nonce]);
        self::assertSame(201, $status, $body);
        self::assertCount(1, $this->apiCalls());
        self::assertSame(['visitorId' => 'fixture-visitor'], json_decode($this->apiCalls()[0]['body'], true));
        $this->apiResponse('block');
        [$status, $body, $headers] = $this->request('/wp-json/WC/STORE/V1/CART/ADD-ITEM',
            ['id' => $product, 'quantity' => 1], ['Nonce: ' . $nonce]);
        self::assertSame(401, $status, $body);
        $blocked = json_decode($body, true);
        self::assertTrue($blocked['blocked']);
        self::assertTrue($this->hasHeader($headers, 'Retry-After:'));
        self::assertCount(2, $this->apiCalls());
        [, $body] = $this->request('/wp-json/wc/store/cart');
        self::assertSame(1, json_decode($body, true)['items_count']);
        [$status, $body, $headers] = $this->request('/wp-json/hachitech-hideguard/v1/state', $this->tokens($config));
        self::assertSame(401, $status, $body);
        self::assertSame($blocked['blockedUntil'], json_decode($body, true)['blockedUntil']);
        self::assertTrue($this->hasHeader($headers, 'Cache-Control: private, no-store'));
        $this->apiResponse('allow');
        self::assertSame(401, $this->request('/wp-json/wc/store/cart/add-item',
            ['id' => $product, 'quantity' => 1], ['Nonce: ' . $nonce])[0]);
        self::assertCount(2, $this->apiCalls(), 'An active ban skips API calls.');
        $gate = parse_url($config['gateUrl']);
        [$status, $body] = $this->request(($gate['path'] ?? '/') . '?' . ($gate['query'] ?? ''));
        self::assertSame(401, $status);
        self::assertStringContainsString('data-state="blocked"', $body);
        self::assertStringNotContainsString('Unprotected fixture content', $body);
    }

    public function testClassicAjaxAddIsDeniedBeforeInsertion(): void
    {
        $config = $this->verifyBrowser();
        $this->apiResponse('block');
        [, $body] = $this->request('/?wc-ajax=add_to_cart',
            ['product_id' => (int)get_option('hg_fixture_product_id'), 'quantity' => 1]);
        self::assertTrue(json_decode($body, true)['error']);
        [, $body] = $this->request('/wp-json/wc/store/v1/cart');
        self::assertSame(0, json_decode($body, true)['items_count']);
        self::assertSame(401, $this->request('/wp-json/hachitech-hideguard/v1/state', $this->tokens($config))[0]);
        self::assertCount(1, $this->apiCalls());
    }

    public function testStoreCheckoutBlockPrecedesOrderAndCustomerCreation(): void
    {
        $config = $this->verifyBrowser();
        [, , $headers] = $this->request('/wp-json/wc/store/v1/cart');
        $nonce = $this->storeNonce($headers);
        self::assertSame(201, $this->request('/wp-json/wc/store/v1/cart/add-item',
            ['id' => (int)get_option('hg_fixture_product_id'), 'quantity' => 1], ['Nonce: ' . $nonce])[0]);
        $before = $this->orderCount();
        $users = count_users()['total_users'];
        $this->apiResponse('block');
        [$status, $body] = $this->request('/wp-json/wc/store/checkout',
            ['billing_address' => $this->billing(), 'create_account' => true], ['Nonce: ' . $nonce]);
        self::assertSame(401, $status, $body);
        self::assertSame($before, $this->orderCount());
        self::assertSame($users, count_users()['total_users']);
        self::assertSame(401, $this->request('/wp-json/hachitech-hideguard/v1/state', $this->tokens($config))[0]);
    }

    public function testClassicCheckoutBlockPrecedesOrderCreation(): void
    {
        $this->verifyBrowser();
        $this->request('/?wc-ajax=add_to_cart',
            ['product_id' => (int)get_option('hg_fixture_product_id'), 'quantity' => 1]);
        [, $checkout] = $this->request('/checkout/');
        self::assertSame(1, preg_match(
            '#name="woocommerce-process-checkout-nonce"[^>]*value="([^"]+)"#', $checkout, $nonce
        ));
        $data = ['woocommerce-process-checkout-nonce' => $nonce[1], 'terms' => '1', 'terms-field' => '1'];
        foreach ($this->billing() as $name => $value) {
            $data['billing_' . $name] = $value;
        }
        $before = $this->orderCount();
        $this->apiResponse('block');
        [, $body] = $this->request('/?wc-ajax=checkout', $data);
        self::assertSame('failure', json_decode($body, true)['result'], $body);
        self::assertSame($before, $this->orderCount());
    }

    public function testStoreCheckoutReadCannotCreateADraftOrderOnBlock(): void
    {
        $this->verifyBrowser();
        [, , $headers] = $this->request('/wp-json/wc/store/cart');
        $nonce = $this->storeNonce($headers);
        self::assertSame(201, $this->request('/wp-json/wc/store/cart/add-item',
            ['id' => (int)get_option('hg_fixture_product_id'), 'quantity' => 1], ['Nonce: ' . $nonce])[0]);
        $before = $this->orderCount();
        $this->apiResponse('block');
        [$status, $body] = $this->request('/wp-json/wc/store/checkout', null, ['Nonce: ' . $nonce]);
        self::assertSame(401, $status, $body);
        self::assertSame($before, $this->orderCount());
    }

    public function testBackendLoginUsesCoreAuthenticationAndDoesNotIssueCookiesOnBlock(): void
    {
        $config = $this->verifyBrowser('/wp-login.php');
        self::assertTrue($config['pageProtected']);
        self::assertTrue($config['protectLogin']);
        $this->apiResponse('block');
        [$status, $html] = $this->request('/wp-login.php', [
            'log' => 'hg_test_admin', 'pwd' => getenv('HG_TEST_ADMIN_PASSWORD'), 'testcookie' => '1',
        ]);
        self::assertSame(200, $status);
        self::assertStringContainsString('hideguard', strtolower($html));
        self::assertSame([], array_filter(array_keys($this->cookies),
            static fn(string $name): bool => str_starts_with($name, 'wordpress_logged_in_')));
        self::assertCount(1, $this->apiCalls());
        self::assertSame(401, $this->request('/wp-json/hachitech-hideguard/v1/state', $this->tokens($config))[0]);
    }

    public function testWordPressAndWooRegistrationBlocksBeforeUserInsertion(): void
    {
        foreach (['wordpress', 'woocommerce'] as $mode) {
            $this->cookies = [];
            global $wpdb;
            foreach (['session', 'block', 'rate'] as $table) {
                $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'hachitech_' . $table);
            }
            $this->apiResponse('allow');
            $path = $mode === 'wordpress' ? '/wp-login.php?action=register' : '/my-account/';
            $this->verifyBrowser($path);
            $suffix = bin2hex(random_bytes(5));
            if ($mode === 'wordpress') {
                $data = ['user_login' => 'fixture_' . $suffix, 'user_email' => $suffix . '@example.test'];
            } else {
                [, $html] = $this->request($path);
                self::assertSame(1, preg_match(
                    '#name="woocommerce-register-nonce"[^>]*value="([^"]+)"#', $html, $nonce
                ));
                $data = ['username' => 'fixture_' . $suffix, 'email' => $suffix . '@example.test',
                    'password' => 'Fixture-password-123!', 'register' => '1',
                    'woocommerce-register-nonce' => $nonce[1]];
            }
            $before = count_users()['total_users'];
            $this->apiResponse('block');
            $this->request($path, $data);
            self::assertSame($before, count_users()['total_users'], $mode);
            self::assertFalse(username_exists('fixture_' . $suffix), $mode);
        }
    }

    public function testExistingOrderPaymentBlocksBeforeGatewayAndAllowsAfterVerification(): void
    {
        $config = $this->verifyBrowser();
        $order = new WC_Order();
        $order->set_billing_email('payment@example.test');
        $order->set_total(10);
        $order->set_status('pending');
        $order->save();
        $url = parse_url($order->get_checkout_payment_url());
        $path = ($url['path'] ?? '/') . '?' . ($url['query'] ?? '');
        [, $html] = $this->request($path);
        self::assertSame(1, preg_match('#name="woocommerce-pay-nonce"[^>]*value="([^"]+)"#', $html, $nonce), $html);
        $data = ['woocommerce_pay' => '1', 'woocommerce-pay-nonce' => $nonce[1],
            'payment_method' => 'hg_fixture_gateway', 'terms' => '1', 'terms-field' => '1'];
        $this->apiResponse('block');
        [$status, , $headers] = $this->request($path, $data);
        self::assertSame(302, $status);
        self::assertTrue($this->hasHeader($headers, 'Location: ' . home_url('/?hachitech_hideguard_gate=')));
        self::assertFalse((bool)wc_get_order($order->get_id())->get_meta('hg_fixture_payment_called'));
        self::assertSame(401, $this->request('/wp-json/hachitech-hideguard/v1/state', $this->tokens($config))[0]);
        $this->cookies = [];
        global $wpdb;
        foreach (['session', 'block', 'rate'] as $table) {
            $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'hachitech_' . $table);
        }
        $this->apiResponse('allow');
        $this->verifyBrowser();
        [, $html] = $this->request($path);
        self::assertSame(1, preg_match('#name="woocommerce-pay-nonce"[^>]*value="([^"]+)"#', $html, $nonce));
        $data['woocommerce-pay-nonce'] = $nonce[1];
        self::assertSame(302, $this->request($path, $data)[0]);
        wp_cache_flush();
        self::assertTrue((bool)wc_get_order($order->get_id())->get_meta('hg_fixture_payment_called'));
    }

    public function testBackendAuthenticationAlsoWorksWithoutWooCommerce(): void
    {
        $plugins = get_option('active_plugins');
        update_option('active_plugins', ['hachitechs-hideguard/hachitechs-hideguard.php']);
        try {
            $this->verifyBrowser('/wp-login.php');
            self::assertSame(302, $this->request('/wp-login.php', [
                'log' => 'hg_test_admin', 'pwd' => getenv('HG_TEST_ADMIN_PASSWORD'), 'testcookie' => '1',
            ])[0]);
            self::assertNotEmpty(array_filter(array_keys($this->cookies),
                static fn(string $name): bool => str_starts_with($name, 'wordpress_logged_in_')));
            self::assertCount(1, $this->apiCalls());
        } finally {
            update_option('active_plugins', $plugins);
        }
    }

    public function testStateProbeIsReadOnlyAndCannotStartVerification(): void
    {
        $values = (new Settings())->values();
        $values['passDuration'] = 0;
        update_option(Settings::OPTION, $values);
        $config = $this->verifyBrowser();
        global $wpdb;
        $before = $wpdb->get_results('SELECT * FROM ' . $wpdb->prefix . 'hachitech_session', ARRAY_A);
        self::assertSame(200, $this->request('/wp-json/hachitech-hideguard/v1/state', $this->tokens($config))[0]);
        self::assertSame($before, $wpdb->get_results('SELECT * FROM ' . $wpdb->prefix . 'hachitech_session', ARRAY_A));
        self::assertSame([], $this->apiCalls());
        $this->cookies = [];
        self::assertSame(400, $this->request('/wp-json/hachitech-hideguard/v1/state', $this->tokens($config))[0]);
    }

    public function testClassicCheckoutWithNewAccountCanCompleteOnApiAllow(): void
    {
        $values = (new Settings())->values();
        $values['points'] = ['complete_order'];
        update_option(Settings::OPTION, $values);
        $this->verifyBrowser();
        $this->request('/?wc-ajax=add_to_cart',
            ['product_id' => (int)get_option('hg_fixture_product_id'), 'quantity' => 1]);
        [, $checkout] = $this->request('/checkout/');
        self::assertSame(1, preg_match(
            '#name="woocommerce-process-checkout-nonce"[^>]*value="([^"]+)"#', $checkout, $nonce
        ));
        $suffix = bin2hex(random_bytes(5));
        $data = ['woocommerce-process-checkout-nonce' => $nonce[1], 'terms' => '1', 'terms-field' => '1',
            'createaccount' => '1', 'account_username' => 'checkout_' . $suffix,
            'account_password' => 'Fixture-password-123!'];
        foreach ($this->billing() as $name => $value) {
            $data['billing_' . $name] = $value;
        }
        $data['billing_email'] = $suffix . '@example.test';
        [, $body] = $this->request('/?wc-ajax=checkout', $data);
        self::assertSame('success', json_decode($body, true)['result'], $body);
        wp_cache_flush();
        self::assertNotFalse(username_exists('checkout_' . $suffix));
    }

    /** @dataProvider paidStoreAccounts */
    public function testPaidStoreCheckoutRetainsRequestAuthorizationAfterGlobalConsumption(bool $createAccount): void
    {
        $values = (new Settings())->values();
        $values['points'] = ['complete_order'];
        update_option(Settings::OPTION, $values);
        $config = $this->verifyBrowser();
        $product = new WC_Product_Simple();
        $product->set_name('Paid backend fixture');
        $product->set_regular_price('10');
        $product->set_virtual(true);
        $product->set_status('publish');
        $product->save();
        [, , $headers] = $this->request('/wp-json/wc/store/cart');
        $nonce = $this->storeNonce($headers);
        self::assertSame(201, $this->request('/wp-json/wc/store/cart/add-item',
            ['id' => $product->get_id(), 'quantity' => 1], ['Nonce: ' . $nonce])[0]);
        $billing = $this->billing();
        $billing['email'] = bin2hex(random_bytes(5)) . '@example.test';
        [$status, $body] = $this->request('/wp-json/wc/store/checkout', [
            'billing_address' => $billing, 'create_account' => $createAccount,
            'payment_method' => 'hg_fixture_gateway', 'payment_data' => [],
        ], ['Nonce: ' . $nonce]);
        self::assertSame(200, $status, $body);
        $result = json_decode($body, true);
        self::assertGreaterThan(0, $result['order_id']);
        wp_cache_flush();
        self::assertTrue((bool)wc_get_order($result['order_id'])->get_meta('hg_fixture_payment_called'));
        if (!$createAccount) {
            [$status, $body] = $this->request('/wp-json/hachitech-hideguard/v1/state', $this->tokens($config));
            self::assertSame(200, $status, $body);
            self::assertSame('verification_required', json_decode($body, true)['status']);
        } else {
            self::assertNotFalse(email_exists($billing['email']));
        }
    }

    public static function paidStoreAccounts(): array
    {
        return [[false], [true]];
    }

    private function verifyBrowser(string $path = '/public/'): array
    {
        [$status, $page] = $this->request($path);
        self::assertSame(200, $status, $page);
        self::assertStringContainsString('hachitech-hideguard', $page);
        self::assertStringNotContainsString('fixture-backend-secret', $page);
        $config = $this->extractConfig($page);
        [$status, $body] = $this->request('/wp-json/hachitech-hideguard/v1/check', $this->tokens($config));
        self::assertSame(200, $status, $body);
        $config['verificationId'] = json_decode($body, true)['verificationId'];
        self::assertSame(200, $this->verdict($config, SignedPayload::payload())[0]);
        return $config;
    }

    private function apiResponse(string $verdict): void
    {
        update_option('hg_fixture_api_response', ['body' => json_encode([
            'reasons' => [], 'score' => $verdict === 'block' ? 100 : 0,
            'source' => 'visit', 'verdict' => $verdict, 'visitorId' => 'fixture-visitor',
        ])]);
    }

    private function apiCalls(): array
    {
        // The HTTP process may create the option after this process cached its absence.
        wp_cache_flush();
        return get_option('hg_fixture_api_calls', []);
    }

    private function storeNonce(array $headers): string
    {
        foreach ($headers as $header) {
            if (stripos($header, 'Nonce:') === 0) {
                return trim(substr($header, 6));
            }
        }
        self::fail('Missing native Store API nonce.');
    }

    private function billing(): array
    {
        return ['first_name' => 'Fixture', 'last_name' => 'Customer', 'country' => 'IT', 'state' => 'RM',
            'address_1' => 'Via Test 1', 'city' => 'Roma', 'postcode' => '00100',
            'phone' => '+390612345678', 'email' => 'customer@example.test'];
    }
}
