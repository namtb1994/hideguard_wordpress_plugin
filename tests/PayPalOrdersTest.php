<?php

declare(strict_types=1);

use Hachitechs\HideguardPhp\Access\ResourceRef;
use Hachitechs\HideguardPhp\Exceptions\GuardException;
use Hachitechs\HideguardWordPress\ActionDenied;
use Hachitechs\HideguardWordPress\Identity;
use Hachitechs\HideguardWordPress\Services;
use Hachitechs\HideguardWordPress\Settings;

final class PayPalOrdersTest extends AdapterTestCase
{
    private Services $services;
    private Closure $mock;
    private int $calls = 0;
    private ?Closure $during = null;
    private mixed $originalCart;
    private mixed $originalSession;
    private array $originalPost;

    protected function setUp(): void
    {
        parent::setUp();
        $values = (new Settings())->values();
        $values['keys']['development']['apiKey'] = 'fixture-secret';
        update_option(Settings::OPTION, $values);
        $this->originalPost = $_POST;
        $_POST = [];
        $this->originalCart = WC()->cart;
        $this->originalSession = WC()->session;
        WC()->session = new WC_Session_Handler();
        WC()->session->init();
        WC()->cart = new WC_Cart();
        $this->services = $this->request();
        $context = $this->services->identity->context();
        $policy = $this->services->settings->policy();
        $attempt = $this->services->guard()->preflight($context, $policy);
        $this->services->guard()->report($context, $policy, SignedPayload::payload(), $attempt->verificationId);
        $this->mock = function () {
            $this->calls++;
            if ($this->during !== null) {
                ($this->during)();
            }
            return ['headers' => [], 'response' => ['code' => 200], 'body' => json_encode([
                'visitorId' => 'fixture-visitor', 'verdict' => 'allow', 'score' => 0, 'reasons' => [],
            ])];
        };
        add_filter('pre_http_request', $this->mock, 1);
    }

    protected function tearDown(): void
    {
        remove_filter('pre_http_request', $this->mock, 1);
        WC()->cart = $this->originalCart;
        WC()->session = $this->originalSession;
        $_POST = $this->originalPost;
        unset($this->services);
        parent::tearDown();
    }

    public function testConcurrentRequestCannotSendADuplicateLookupWhileFirstIsInFlight(): void
    {
        $this->during = function (): void {
            $concurrent = $this->request();
            $concurrent->paypal()->enter('create', []);
            try {
                $concurrent->backend()->assertAllowed('create_order');
                self::fail('An in-flight order verdict must fail closed as busy.');
            } catch (GuardException $error) {
                self::assertSame('busy', $error->reason->value);
            }
        };
        $this->services->paypal()->enter('create', []);
        $this->services->backend()->assertAllowed('create_order');
        self::assertSame(1, $this->calls);
    }

    /** @dataProvider invalidations */
    public function testApprovalCannotCrossChangedBindings(string $change): void
    {
        $this->services->paypal()->enter('create', []);
        $this->services->backend()->assertAllowed('create_order');
        $context = $this->services->identity->context();
        $storage = $this->services->storage();
        if ($change === 'cart') {
            WC()->cart->applied_coupons = ['different-coupon'];
        } elseif ($change === 'key') {
            $values = (new Settings())->values();
            $values['keys']['development']['apiKey'] = 'changed-secret';
            update_option(Settings::OPTION, $values);
        } else {
            $storage->run($context->scopeId, function () use ($storage, $context, $change): void {
                if ($change === 'expiry') {
                    $flow = $storage->paypalFlow($context->scopeId, $context->browserId);
                    $flow['expiresAt'] = time() - 1;
                    $storage->savePaypalFlow($context->scopeId, $context->browserId, $flow);
                    return;
                }
                $state = $storage->session($context->scopeId, $context->browserId);
                if ($change === 'visitor') {
                    $state->visitorId = 'changed-visitor';
                } elseif ($change === 'actor') {
                    $state->actorId = '42';
                } elseif ($change === 'attempt') {
                    $state->verificationId = bin2hex(random_bytes(16));
                } else {
                    $state->grantedUntil = time() - 1;
                }
                $storage->saveSession($context->scopeId, $state);
            });
        }
        $next = $this->request();
        $next->paypal()->checkout('ppcp-gateway');
        try {
            $next->backend()->assertAllowed('checkout');
        } catch (ActionDenied|GuardException $error) {
            self::assertNotNull($error);
        }
        self::assertSame(in_array($change, ['cart', 'expiry', 'attempt', 'visitor'], true) ? 2 : 1, $this->calls);
    }

    public static function invalidations(): array
    {
        return array_map(static fn(string $change): array => [$change], [
            'cart', 'key', 'expiry', 'visitor', 'actor', 'attempt', 'grant-expiry',
        ]);
    }

    public function testConsumedFlowCannotBeRestoredAfterAnotherRevocation(): void
    {
        $this->services->paypal()->enter('create', []);
        $this->services->backend()->assertAllowed('create_order');
        $order = wc_create_order();
        $order->set_total(10);
        $order->save();
        $this->services->paypal()->orderCreated($order);
        $snapshot = $this->services->paypal()->beforeConsume($order->get_id());
        self::assertNotNull($snapshot);
        $context = $this->services->identity->context();
        $storage = $this->services->storage();
        $resource = new ResourceRef('woocommerce_cart', 'test-consumption');
        $this->services->guard()->bindGrant($context, $this->services->settings->policy(), $resource);
        $this->services->guard()->consumeGrant($context->scopeId, $resource);
        $this->services->guard()->revokeScope($context->scopeId);
        $this->services->paypal()->afterConsume($snapshot);
        self::assertNull($storage->paypalFlow($context->scopeId, $context->browserId));
    }

    public function testNewSdkAttemptCancelsThePersistedSameOrderContinuation(): void
    {
        $this->services->paypal()->enter('create', []);
        $this->services->backend()->assertAllowed('create_order');
        $order = wc_create_order();
        $order->set_total(10);
        $order->save();
        $this->services->paypal()->orderCreated($order);
        $context = $this->services->identity->context();
        $policy = $this->services->settings->policy();
        $resource = new ResourceRef('woocommerce_cart', 'persisted-continuation');
        $this->services->guard()->bindGrant($context, $policy, $resource);
        $this->services->backend()->consumeOrder($resource, $order->get_id());
        WC()->session->set('order_awaiting_payment', $order->get_id());
        $next = $this->request();
        $next->paypal()->checkout('ppcp-gateway', $order->get_id());
        $next->backend()->assertAllowed('payment', $order->get_id());
        self::assertSame(1, $this->calls);
        $next->guard()->preflight($context, $policy);
        $this->expectException(ActionDenied::class);
        $next->backend()->assertAllowed('payment', $order->get_id());
    }

    public function testOnlyTheNativeTemporarySimulationSkipsTheCartLookup(): void
    {
        $events = new Hachitechs\HideguardWordPress\ActionEvents($this->services);
        $simulate = static fn() => $events->cartData('add_to_cart', []);
        add_action('wc_ajax_ppc-simulate-cart', $simulate);
        try {
            do_action('wc_ajax_ppc-simulate-cart');
            self::assertSame(0, $this->calls);
        } finally {
            remove_action('wc_ajax_ppc-simulate-cart', $simulate);
        }
        $events->cartData('add_to_cart', []);
        self::assertSame(1, $this->calls, 'Real cart mutations retain their backend verdict.');
    }

    public function testVersionedUpgradePreservesExistingBrowserGrantAndSettings(): void
    {
        global $wpdb;
        $context = $this->services->identity->context();
        $before = $this->services->storage()->session($context->scopeId, $context->browserId);
        $settings = get_option(Settings::OPTION);
        $wpdb->query('ALTER TABLE ' . $wpdb->prefix . 'hachitech_session DROP COLUMN paypal_flow, '
            . 'DROP COLUMN revocation_version');
        update_option('hachitechs_hideguard_schema', 1);
        try {
            $plugin = new Hachitechs\HideguardWordPress\Plugin(dirname(__DIR__) . '/hachitechs-hideguard.php');
            $plugin->activate();
            self::assertSame(3, (int)get_option('hachitechs_hideguard_schema'));
            self::assertEquals($before, $this->services->storage()->session($context->scopeId, $context->browserId));
            self::assertSame($settings, get_option(Settings::OPTION));
            self::assertNull($this->services->storage()->paypalFlow($context->scopeId, $context->browserId));
            self::assertSame(0, $this->services->storage()->revocationVersion($context->scopeId, $context->browserId));
            $plugin->activate();
            self::assertEquals($before, $this->services->storage()->session($context->scopeId, $context->browserId));
        } finally {
            $this->services->storage()->initializeSchema();
            update_option('hachitechs_hideguard_schema', 3);
        }
    }

    private function request(): Services
    {
        return new Services(new Settings(), new Identity());
    }
}
