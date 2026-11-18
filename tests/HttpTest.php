<?php

declare(strict_types=1);

use Hachitechs\HideguardWordPress\Settings;

final class HttpTest extends HttpTestCase
{
    public function testOldSchemaFailsClosedWithoutMigratingInsideHttpRequest(): void
    {
        update_option('hachitechs_hideguard_schema', 2);
        try {
            [$status, $html] = $this->request('/protected/');
            self::assertSame(503, $status);
            self::assertStringNotContainsString('Original protected fixture content', $html);
            wp_cache_delete('hachitechs_hideguard_schema', 'options');
            self::assertSame(2, (int)get_option('hachitechs_hideguard_schema'));
        } finally {
            update_option('hachitechs_hideguard_schema', 3);
        }
    }

    public function testSignedAllowBlockFixedBanAndNoProtectedHtml(): void
    {
        [$status, $home] = $this->request('/public/');
        self::assertSame(200, $status);
        self::assertStringContainsString('wp-script-module-data-hachitech-hideguard', $home);
        $config = $this->pageConfig();
        self::assertArrayNotHasKey('signaturePublicKey', $config);
        self::assertSame('https://hideguard.io', $config['endpointUrl']);
        self::assertSame(400, $this->request('/wp-json/hachitech-hideguard/v1/check', [])[0]);
        self::assertSame(403, $this->request('/protected/', ['operation' => 'unsafe'])[0]);
        self::assertSame(413, $this->request('/wp-json/hachitech-hideguard/v1/check',
            $this->tokens($config) + ['pad' => str_repeat('x', 65537)])[0]);
        [$status, $body] = $this->verdict($config, SignedPayload::payload());
        self::assertSame(200, $status);
        $allowed = json_decode($body, true);
        self::assertTrue($allowed['allowed']);
        self::assertSame('verified', $allowed['verification']);
        [$status, $body] = $this->request('/wp-json/hachitech-hideguard/v1/check', $this->tokens($config));
        self::assertSame(200, $status);
        self::assertSame($allowed['expiresAt'], json_decode($body, true)['expiresAt']);
        [$status, $body] = $this->verdict($config, SignedPayload::payload('block'));
        self::assertSame(401, $status);
        $expiry = json_decode($body, true)['blockedUntil'];
        [$status, $body] = $this->verdict($config, SignedPayload::payload('block'));
        self::assertSame(401, $status);
        self::assertSame($expiry, json_decode($body, true)['blockedUntil']);
        [$status, $body] = $this->verdict($config, SignedPayload::payload('allow'));
        self::assertSame(401, $status);
        [$status, $html, $headers] = $this->request('/protected/');
        self::assertSame(401, $status);
        self::assertStringNotContainsString('Original protected fixture content', $html);
        self::assertStringContainsString('data-state="blocked"', $html);
        self::assertTrue($this->hasHeader($headers, 'Retry-After:'));
        self::assertSame(401, $this->request('/public/')[0]);
    }

    public function testUnsignedTamperedAndWrongEnvironmentPayloadsFailClosed(): void
    {
        foreach (['unsigned', 'tampered', 'wrong-environment', 'expired'] as $case) {
            $this->cookies = [];
            $config = $this->pageConfig();
            $payload = SignedPayload::payload();
            if ($case === 'unsigned') {
                unset($payload['signature']);
            } elseif ($case === 'tampered') {
                $payload['score'] = 7;
            } elseif ($case === 'wrong-environment') {
                $payload = SignedPayload::payload(environment: 'staging');
            } else {
                $payload = SignedPayload::payload(issuedAt: time() - 301);
            }
            self::assertSame(400, $this->verdict($config, $payload)[0], $case);
            [$status, $body] = $this->request('/wp-json/hachitech-hideguard/v1/check', $this->tokens($config));
            self::assertSame(403, $status);
            self::assertSame('verification_failed', json_decode($body, true)['status']);
        }
    }

    public function testFailureRequiresRetryAndCannotRevokeCompletedGrant(): void
    {
        $config = $this->pageConfig();
        self::assertSame(403, $this->request('/wp-json/hachitech-hideguard/v1/fail', $this->tokens($config))[0]);
        self::assertSame(403, $this->request('/wp-json/hachitech-hideguard/v1/check', $this->tokens($config))[0]);
        [$status, $body] = $this->request('/wp-json/hachitech-hideguard/v1/check',
            $this->tokens($config) + ['retry' => '1']);
        self::assertSame(200, $status);
        $next = json_decode($body, true);
        self::assertNotSame($config['verificationId'], $next['verificationId']);
        $old = $config;
        $config['verificationId'] = $next['verificationId'];
        self::assertSame(200, $this->verdict($config, SignedPayload::payload())[0]);
        self::assertSame(200, $this->request('/wp-json/hachitech-hideguard/v1/fail', $this->tokens($old))[0]);
        [$status, $body] = $this->request('/wp-json/hachitech-hideguard/v1/check', $this->tokens($config));
        self::assertSame(200, $status);
        self::assertTrue(json_decode($body, true)['allowed']);
    }

    public function testDeadlinePersistsAcrossReloadAndExpiresWithoutSdk(): void
    {
        global $wpdb;
        $first = $this->pageConfig();
        $second = $this->pageConfig();
        self::assertSame($first['verificationId'], $second['verificationId']);
        $wpdb->query('UPDATE ' . $wpdb->prefix . 'hachitech_session SET verification_until = UNIX_TIMESTAMP() - 1');
        [$status, $body] = $this->request('/wp-json/hachitech-hideguard/v1/check', $this->tokens($first));
        self::assertSame(403, $status, $body);
        self::assertSame('verification_failed', json_decode($body, true)['status']);
        [, , $headers] = $this->request('/protected/');
        self::assertTrue($this->hasHeader($headers, 'Location:'));
        $gate = parse_url($first['deniedUrl']);
        [$status, $html] = $this->request(($gate['path'] ?? '/') . '?' . ($gate['query'] ?? ''));
        self::assertSame(403, $status, $body);
        self::assertStringContainsString('data-action="retry"', $html);
        self::assertStringNotContainsString('Original protected fixture content', $html);
    }

    public function testBrowserBoundNonceCannotBeReusedByAnotherBrowser(): void
    {
        $config = $this->pageConfig();
        $this->cookies = [];
        self::assertSame(400, $this->request('/wp-json/hachitech-hideguard/v1/check', $this->tokens($config))[0]);
    }

    public function testAlwaysStartsANewAttemptOnProtectedNavigation(): void
    {
        $values = (new Settings())->values();
        $values['passDuration'] = 0;
        update_option(Settings::OPTION, $values);
        $first = $this->pageConfig();
        self::assertSame(200, $this->verdict($first, SignedPayload::payload())[0]);
        $second = $this->pageConfig();
        self::assertNotSame($first['verificationId'], $second['verificationId']);
        [$status, $body] = $this->verdict($first, SignedPayload::payload());
        self::assertSame(200, $status);
        self::assertSame('verification_required', json_decode($body, true)['status']);
        self::assertSame($second['verificationId'], json_decode($body, true)['verificationId']);
        self::assertSame(200, $this->verdict($second, SignedPayload::payload())[0]);
    }

    public function testGateRejectsOffOriginAndBackslashReturnUrls(): void
    {
        foreach (['https://evil.example/path', '//evil.example/path', '/\\evil.example/path'] as $return) {
            [$status, $html] = $this->request('/?hachitech_hideguard_gate=1&return=' . rawurlencode($return));
            self::assertSame(200, $status);
            self::assertSame(home_url('/'), $this->extractConfig($html)['returnUrl']);
        }
    }

    public function testRestActionsFollowWordPressCaseInsensitiveRouting(): void
    {
        $config = $this->pageConfig();
        [$status, $body, $headers] = $this->request('/wp-json/HACHITECH-HIDEGUARD/V1/VERDICT',
            $this->tokens($config) + ['response' => json_encode(SignedPayload::payload())]);
        self::assertSame(200, $status);
        self::assertTrue(json_decode($body, true)['allowed']);
        self::assertTrue($this->hasHeader($headers, 'Cache-Control: private, no-store'));
        $this->cookies = [];
        $config = $this->pageConfig();
        [$status, $body] = $this->request('/wp-json/HACHITECH-HIDEGUARD/V1/FAIL', $this->tokens($config));
        self::assertSame(403, $status);
        self::assertSame('verification_failed', json_decode($body, true)['status']);
    }

    public function testLegacyEnvironmentAndEndpointCannotOverrideTheLiveSignedPolicy(): void
    {
        $values = (new Settings())->values();
        $values['environment'] = 'development';
        $values['keys']['endpointUrl'] = 'https://sdk.example.test:8443';
        update_option(Settings::OPTION, $values);
        $config = $this->pageConfig();
        self::assertSame('fixture-site', $config['publicKey']);
        self::assertSame('https://hideguard.io', $config['endpointUrl']);
        self::assertArrayNotHasKey('environment', $config);
        self::assertSame(200, $this->verdict($config, SignedPayload::payload())[0]);
    }

    public function testAdminResetRequiresCapabilityAndCsrf(): void
    {
        global $wpdb;
        $config = $this->pageConfig();
        self::assertSame(401, $this->verdict($config, SignedPayload::payload('block'))[0]);
        $this->request('/wp-login.php');
        self::assertSame(302, $this->request('/wp-login.php', [
            'log' => 'hg_test_admin', 'pwd' => getenv('HG_TEST_ADMIN_PASSWORD'), 'testcookie' => '1',
        ])[0]);
        [$status, $admin] = $this->request('/wp-admin/options-general.php?page=hachitech_hideguard');
        self::assertSame(200, $status);
        self::assertSame(0, substr_count($admin, 'data-hachitech-environment='));
        self::assertStringNotContainsString('name="hachitech_hideguard[environment]"', $admin);
        self::assertStringContainsString('https://hideguard.io/dashboard/', $admin);
        self::assertSame(1, preg_match(
            '#name="action" value="hachitech_hideguard_reset".*?name="_wpnonce" value="([^"]+)"#s',
            $admin, $match
        ));
        self::assertSame(403, $this->request('/wp-admin/admin-post.php', [
            'action' => 'hachitech_hideguard_reset', '_wpnonce' => 'invalid',
        ])[0]);
        self::assertGreaterThan(0, (int)$wpdb->get_var('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'hachitech_block'));
        self::assertSame(302, $this->request('/wp-admin/admin-post.php', [
            'action' => 'hachitech_hideguard_reset', '_wpnonce' => $match[1],
        ])[0]);
        self::assertSame(0, (int)$wpdb->get_var('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'hachitech_block'));
        $this->cookies = [];
        self::assertNotSame(302, $this->request('/wp-admin/admin-post.php', [
            'action' => 'hachitech_hideguard_reset', '_wpnonce' => $match[1],
        ])[0]);
    }

    public function testClassicCheckoutDeniesUnverifiedOrderAndConsumesSuccessfulGrant(): void
    {
        $values = (new Settings())->values();
        $values['points'] = ['complete_order'];
        $values['paths'] = [];
        update_option(Settings::OPTION, $values);
        $initial = $this->pageConfig();
        self::assertSame(200, $this->verdict($initial, SignedPayload::payload())[0]);
        [, $added] = $this->request('/?wc-ajax=add_to_cart', [
            'product_id' => (int)get_option('hg_fixture_product_id'), 'quantity' => 1,
        ]);
        self::assertStringNotContainsString('"error":true', $added, $added);
        [, $cartBody] = $this->request('/wp-json/wc/store/v1/cart');
        self::assertSame(1, json_decode($cartBody, true)['items_count'], $cartBody);
        $storage = \Hachitechs\HideguardWordPress\WpdbStorage::forSite();
        $scope = (string)get_current_blog_id();
        $storage->run($scope, fn() => $storage->revokeScope($scope));
        [$status, $checkout] = $this->request('/checkout/');
        self::assertSame(200, $status);
        self::assertSame(1, preg_match(
            '#name="woocommerce-process-checkout-nonce"[^>]*value="([^"]+)"#', $checkout, $match
        ));
        $data = [
            'woocommerce-process-checkout-nonce' => $match[1], 'billing_first_name' => 'Fixture',
            'billing_last_name' => 'Customer', 'billing_country' => 'IT', 'billing_state' => 'RM',
            'billing_address_1' => 'Via Test 1', 'billing_city' => 'Roma', 'billing_postcode' => '00100',
            'billing_phone' => '+390612345678', 'billing_email' => 'customer@example.test',
            'terms' => '1', 'terms-field' => '1', 'payment_method' => '',
        ];
        $before = $this->orderCount();
        [, $denied] = $this->request('/?wc-ajax=checkout', $data);
        self::assertSame('failure', json_decode($denied, true)['result']);
        self::assertSame($before, $this->orderCount());
        $config = $this->extractConfig($checkout);
        [, $body] = $this->request('/wp-json/hachitech-hideguard/v1/check', $this->tokens($config));
        $config['verificationId'] = json_decode($body, true)['verificationId'];
        self::assertSame(200, $this->verdict($config, SignedPayload::payload())[0]);
        [, $completed] = $this->request('/?wc-ajax=checkout', $data);
        self::assertSame('success', json_decode($completed, true)['result'], $completed);
        self::assertSame($before + 1, $this->orderCount());
        [, $body] = $this->request('/wp-json/hachitech-hideguard/v1/check', $this->tokens($config));
        self::assertSame('verification_required', json_decode($body, true)['status']);
    }

    public function testWooCommerceStoreApiAndClassicCartRequireVerifiedBrowser(): void
    {
        $values = (new Settings())->values();
        $values['points'] = ['cart', 'complete_order'];
        $values['paths'] = [];
        update_option(Settings::OPTION, $values);
        [, $public] = $this->request('/public/');
        self::assertStringContainsString('wp-script-module-data-hachitech-hideguard', $public);
        self::assertMatchesRegularExpression('/<body[^>]*\binert\b/', $public);
        $config = $this->extractConfig($public);
        $product = (int)get_option('hg_fixture_product_id');
        [, , $cartHeaders] = $this->request('/wp-json/wc/store/v1/cart');
        $initialNonce = '';
        foreach ($cartHeaders as $header) {
            if (stripos($header, 'Nonce:') === 0) {
                $initialNonce = trim(substr($header, 6));
            }
        }
        self::assertNotSame('', $initialNonce);
        [$status, $body] = $this->request('/wp-json/wc/store/v1/cart/add-item',
            ['id' => $product, 'quantity' => 1], ['Nonce: ' . $initialNonce]);
        self::assertSame(403, $status, $body);
        self::assertSame('verification_required', json_decode($body, true)['status']);
        $orderData = ['billing_address' => [
            'first_name' => 'Fixture', 'last_name' => 'Customer', 'country' => 'IT', 'state' => 'RM',
            'address_1' => 'Via Test 1', 'city' => 'Roma', 'postcode' => '00100',
            'phone' => '+390612345678', 'email' => 'customer@example.test',
        ]];
        self::assertSame(403, $this->request('/wp-json/wc/store/v1/checkout',
            $orderData, ['Nonce: ' . $initialNonce])[0]);
        [$status] = $this->request('/?wc-ajax=add_to_cart', ['product_id' => $product, 'quantity' => 1]);
        self::assertSame(200, $status);
        [, $body] = $this->request('/?wc-ajax=get_refreshed_fragments', []);
        self::assertStringNotContainsString('HideGuard fixture product', $body);
        [$status, $body] = $this->request('/wp-json/hachitech-hideguard/v1/check', $this->tokens($config));
        self::assertSame(200, $status);
        $config['verificationId'] = json_decode($body, true)['verificationId'];
        self::assertSame(200, $this->verdict($config, SignedPayload::payload())[0]);
        [, $body] = $this->request('/?wc-ajax=add_to_cart', ['product_id' => $product, 'quantity' => 1]);
        self::assertStringNotContainsString('"error":true', $body);
        [$status, $cart, $headers] = $this->request('/wp-json/wc/store/v1/cart');
        self::assertSame(200, $status);
        $cart = json_decode($cart, true);
        self::assertSame(1, $cart['items_count']);
        $nonce = '';
        foreach ($headers as $header) {
            if (stripos($header, 'Nonce:') === 0) {
                $nonce = trim(substr($header, 6));
            }
        }
        self::assertNotSame('', $nonce);
        [$status, $body] = $this->request('/wp-json/wc/store/v1/cart/add-item',
            ['id' => $product, 'quantity' => 1], ['Nonce: ' . $nonce]);
        self::assertSame(201, $status, $body);
        $itemKey = json_decode($body, true)['items'][0]['key'];
        global $wpdb;
        $wpdb->query('UPDATE ' . $wpdb->prefix . 'hachitech_session SET granted_until = UNIX_TIMESTAMP() - 1');
        [$status, $body] = $this->request('/wp-json/WC/STORE/V1/CART/UPDATE-ITEM',
            ['key' => $itemKey, 'quantity' => 3], ['Nonce: ' . $nonce]);
        self::assertSame(403, $status, $body);
        foreach (['update-item' => ['key' => $itemKey, 'quantity' => 4],
            'remove-item' => ['key' => $itemKey]] as $action => $data) {
            [$status, $body] = $this->request('/wp-json/wc/store/cart/' . $action, $data, ['Nonce: ' . $nonce]);
            self::assertSame(403, $status, $body);
            self::assertSame('verification_required', json_decode($body, true)['status']);
        }
        [$status, $body] = $this->request('/wp-json/wc/store/batch', ['requests' => [[
            'method' => 'POST', 'path' => '/wc/store/cart/remove-item',
            'headers' => ['Nonce' => $nonce], 'body' => ['key' => $itemKey],
        ]]]);
        self::assertSame(207, $status, $body);
        self::assertSame(403, json_decode($body, true)['responses'][0]['status']);
        [, $body] = $this->request('/wp-json/wc/store/cart');
        self::assertSame(2, json_decode($body, true)['items_count']);
        [$status, $body] = $this->request('/wp-json/wc/store/checkout', $orderData, ['Nonce: ' . $nonce]);
        self::assertSame(403, $status, $body);
        [, $body] = $this->request('/wp-json/hachitech-hideguard/v1/check', $this->tokens($config));
        $config['verificationId'] = json_decode($body, true)['verificationId'];
        self::assertSame(200, $this->verdict($config, SignedPayload::payload())[0]);
        [$status, $body] = $this->request('/wp-json/wc/store/v1/checkout', $orderData, ['Nonce: ' . $nonce]);
        self::assertSame(200, $status, $body);
        self::assertGreaterThan(0, json_decode($body, true)['order_id']);
        [, $body] = $this->request('/wp-json/hachitech-hideguard/v1/check', $this->tokens($config));
        self::assertSame('verification_required', json_decode($body, true)['status']);
    }

}
