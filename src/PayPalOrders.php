<?php

declare(strict_types=1);

namespace Hachitechs\HideguardWordPress;

use Automattic\WooCommerce\StoreApi\SessionHandler;
use Automattic\WooCommerce\StoreApi\Utilities\CartController;
use Automattic\WooCommerce\StoreApi\Utilities\CartTokenUtils;
use Hachitechs\HideguardPhp\Access\BrowserState;
use Hachitechs\HideguardPhp\Exceptions\FailureReason;
use Hachitechs\HideguardPhp\Exceptions\GuardException;

/** One server-side verdict per PayPal order flow, across native HTTP requests. */
final class PayPalOrders
{
    private const PAYMENT_METHODS = [
        'ppcp-gateway', 'ppcp-card-button-gateway', 'ppcp-credit-card-gateway',
        'ppcp-oxxo-gateway', 'ppcp-pay-upon-invoice-gateway', 'ppcp-axo-gateway',
        'ppcp-googlepay', 'ppcp-applepay', 'ppcp-blik', 'ppcp-multibanco', 'ppcp-bancontact',
        'ppcp-p24', 'ppcp-ideal', 'ppcp-eps', 'ppcp-mybank', 'ppcp-trustly',
    ];

    private ?string $entry = null;
    private ?string $remoteId = null;
    private ?int $orderId = null;
    private ?string $method = null;
    private ?string $flowId = null;
    private bool $ownsClaim = false;
    private bool $createsOrder = false;

    public function __construct(private readonly Services $services)
    {
    }

    public function boot(): void
    {
        add_action('woocommerce_paypal_payments_paypal_order_created', [$this, 'remoteCreated']);
        add_action('woocommerce_checkout_create_order', [$this, 'beforeOrder'], -90);
        add_action('woocommerce_checkout_order_created', [$this, 'orderCreated']);
        add_action('woocommerce_store_api_checkout_update_order_from_request', [$this, 'orderCreated'], -90);
        add_action('woocommerce_store_api_checkout_update_order_meta', [$this, 'orderCreated'], -90);
        add_action('woocommerce_paypal_payments_woocommerce_order_created_from_cart', [$this, 'orderCreated']);
        add_action('woocommerce_paypal_payments_woocommerce_order_created', [$this, 'orderCreated']);
    }

    /** Called only by the PayPal request-started hooks, after their native nonce validation. */
    public function enter(string $entry, array $data): void
    {
        $this->entry = $entry;
        $this->createsOrder = $entry === 'approve' && !empty($data['should_create_wc_order']);
        $this->remoteId = $entry === 'approve' ? (string)($data['order_id'] ?? '') : null;
        $this->orderId = ($data['context'] ?? '') === 'pay-now' ? absint($data['order_id'] ?? 0) : null;
    }

    public function checkout(mixed $method, ?int $orderId = null): void
    {
        $this->method = is_string($method) ? $method : null;
        $this->orderId = $orderId ?: $this->orderId;
    }

    public function storeRequest(\WP_REST_Request $request): void
    {
        if (!$this->services->settings->backendEnabled()) {
            return;
        }
        $this->checkout($request->get_param('payment_method'));
        if (!$this->applies('create_order')) {
            return;
        }
        // Match AbstractCartRoute::load_cart_session: select the native token handler before loading its cart.
        if (CartTokenUtils::validate_cart_token($request->get_header('Cart-Token') ?? '')) {
            add_filter('woocommerce_session_handler', static fn() => SessionHandler::class);
        }
        (new CartController())->load_cart();
    }

    private function applies(string $event): bool
    {
        if (!in_array($event, ['payment', 'checkout', 'create_order'], true) || !function_exists('WC')) {
            return false;
        }
        return $this->canReuse() || ($event === 'create_order' && empty($this->paymentMethod()));
    }

    /** Claim a lookup without holding a DB lock during HTTP; competing requests fail closed as busy. */
    public function approved(string $event, BrowserState $state, ?int $orderId): bool
    {
        if (!$this->applies($event)) {
            return false;
        }
        $this->orderId = $orderId ?: $this->orderId;
        if (WC()->cart === null) {
            wc_load_cart(); // Store API's early dispatch hook runs before its native cart loader.
        }
        WC()->cart->get_cart();
        return $this->mutate(function (?array $flow) use ($state): ?array {
            if ($flow !== null && $flow['status'] === 'pending' && $flow['pendingUntil'] > time()) {
                throw new GuardException(FailureReason::Busy, 'PayPal access verification is in progress.', 1);
            }
            if ($this->createsOrder && $flow !== null && $flow['orderId'] > 0) {
                $order = wc_get_order($flow['orderId']);
                if ($order && !$order->has_status('checkout-draft')) {
                    throw new GuardException(FailureReason::PermissionDenied, 'This PayPal flow already has an order.');
                }
                $flow['orderId'] = 0; // Express approval creates its own native order instead of the unused draft.
            }
            $valid = $this->canReuse() && $flow !== null && $this->matches($flow, $state);
            if ($valid && $this->entry === 'approve' && $flow['remoteId'] !== $this->remoteId) {
                $valid = false; // An unrecognized remote order requires a fresh verdict, never the cached approval.
            }
            // A second create endpoint request starts a new attempt; overlapping hooks in this PHP request do not.
            if ($valid && $this->entry === 'create' && $flow['createSeen'] && $this->flowId !== $flow['id']) {
                $valid = false;
            }
            if (!$valid) {
                $flow = [
                    'id' => bin2hex(random_bytes(16)), 'status' => 'pending', 'pendingUntil' => time() + 15,
                    'expiresAt' => $state->grantedUntil,
                    'visitor' => $state->visitorId, 'actor' => $state->actorId,
                    'policy' => $state->policyHash, 'key' => $this->keyHash(), 'attempt' => $state->verificationId,
                    'cart' => $this->cartHash(), 'createSeen' => false, 'remoteId' => null,
                    'orderId' => $this->orderId ?: 0, 'consumed' => false,
                ];
                $this->ownsClaim = true;
            }
            $this->flowId = $flow['id'];
            if ($this->entry === 'create') {
                $flow['createSeen'] = true;
            }
            return $flow;
        })['status'] === 'approved';
    }

    public function complete(): void
    {
        if (!$this->ownsClaim) {
            return;
        }
        $this->mutate(function (?array $flow): ?array {
            if ($flow === null || $flow['id'] !== $this->flowId || $flow['pendingUntil'] <= time()) {
                throw new GuardException(FailureReason::PermissionDenied, 'PayPal verification changed. Try again.');
            }
            $flow['status'] = 'approved';
            return $flow;
        });
        $this->ownsClaim = false;
    }

    public function cancel(): void
    {
        if ($this->ownsClaim) {
            $this->mutate(fn(?array $flow): ?array => $flow !== null && $flow['id'] === $this->flowId ? null : $flow);
            $this->ownsClaim = false;
        }
    }

    /** Only the exact native, unpaid Woo order may finish after its SDK cart grant has been consumed. */
    public function continuePayment(string $event, ?int $orderId): bool
    {
        if (!$this->applies($event) || !$this->canReuse()) {
            return false;
        }
        $this->orderId = $orderId ?: $this->orderId;
        $flow = $this->mutate(function (?array $flow): ?array {
            $context = $this->services->identity->context();
            $state = $this->services->storage()->session($context->scopeId, $context->browserId);
            if ($flow === null || $state === null || !$flow['consumed'] || !$this->matches($flow, $state)) {
                return null;
            }
            if ($this->entry === 'approve' && $flow['remoteId'] !== $this->remoteId) {
                return null;
            }
            if ($this->entry === 'create' && $flow['createSeen'] && $this->flowId !== $flow['id']) {
                return null;
            }
            $this->flowId = $flow['id'];
            $flow['createSeen'] = $flow['createSeen'] || $this->entry === 'create';
            return $flow;
        });
        return $flow !== null;
    }

    public function remoteCreated(object $order): void
    {
        if ($this->flowId === null || !method_exists($order, 'id')) {
            return;
        }
        $id = (string)$order->id();
        $this->mutate(function (?array $flow) use ($id): ?array {
            if ($flow !== null && $flow['id'] === $this->flowId && $flow['status'] === 'approved') {
                $flow['remoteId'] = $id;
                $flow['createSeen'] = true;
            }
            return $flow;
        });
    }

    public function beforeOrder(\WC_Order $order): void
    {
        $this->updateOrder($order, false);
    }

    public function orderCreated(\WC_Order $order): void
    {
        $this->updateOrder($order, true);
    }

    private function updateOrder(\WC_Order $order, bool $bind): void
    {
        if ($this->flowId === null) {
            return;
        }
        $this->mutate(function (?array $flow) use ($order, $bind): ?array {
            if ($flow === null || $flow['id'] !== $this->flowId || $flow['status'] !== 'approved') {
                throw new \RuntimeException('Verify access before creating this PayPal order.');
            }
            if ($flow['orderId'] > 0 && $flow['orderId'] !== $order->get_id()) {
                throw new \RuntimeException('This PayPal verification belongs to another order.');
            }
            if ($bind) {
                $flow['orderId'] = $order->get_id();
            }
            return $flow;
        });
    }

    /** Snapshot before Guard consumes the grant; restoration requires exactly that one revocation. */
    public function beforeConsume(int $orderId): ?array
    {
        if ($this->flowId === null) {
            return null;
        }
        $this->orderId = $orderId;
        $context = $this->services->identity->context();
        $storage = $this->services->storage();
        return $storage->run($context->scopeId, function () use ($storage, $context, $orderId): ?array {
            $flow = $storage->paypalFlow($context->scopeId, $context->browserId);
            if (
                $flow === null || $flow['id'] !== $this->flowId || $flow['status'] !== 'approved'
                || $flow['orderId'] !== $orderId
            ) {
                return null;
            }
            return [
                'flow' => $flow, 'version' => $storage->revocationVersion($context->scopeId, $context->browserId),
            ];
        });
    }

    public function afterConsume(?array $snapshot): void
    {
        if ($snapshot === null) {
            return;
        }
        ['flow' => $flow, 'version' => $version] = $snapshot;
        $context = $this->services->identity->context();
        $storage = $this->services->storage();
        $storage->run($context->scopeId, function () use ($storage, $context, $flow, $version): void {
            $state = $storage->session($context->scopeId, $context->browserId);
            $flow['consumed'] = true;
            if (
                $state !== null && $storage->revocationVersion($context->scopeId, $context->browserId) === $version + 1
                && $storage->paypalFlow($context->scopeId, $context->browserId) === null
                && $this->matches($flow, $state)
            ) {
                $storage->savePaypalFlow($context->scopeId, $context->browserId, $flow);
            }
        });
    }

    public function adoptCustomer(BrowserState $state): void
    {
        if ($this->flowId !== null) {
            $this->mutate(function (?array $flow) use ($state): ?array {
                if ($flow !== null && $flow['id'] === $this->flowId) {
                    $flow['actor'] = $state->actorId;
                }
                return $flow;
            });
        }
    }

    private function matches(array $flow, BrowserState $state): bool
    {
        $context = $this->services->identity->context();
        $storage = $this->services->storage();
        if (
            $flow['status'] !== 'approved' || $flow['expiresAt'] <= time()
            || $flow['visitor'] !== $state->visitorId || $flow['actor'] !== $context->actorId
            || $state->actorId !== $context->actorId || $flow['policy'] !== $state->policyHash
            || $flow['policy'] !== $this->services->settings->policy()->fingerprint()
            || $flow['key'] !== $this->keyHash() || $state->verification !== 'verified'
            || $state->blockedUntil > time()
            || $storage->blockExpiry($context->scopeId, $state->visitorId, $context->actorId) > time()
        ) {
            return false;
        }
        if ($flow['orderId'] > 0 && $this->orderId !== null && $flow['orderId'] !== $this->orderId) {
            return false;
        }
        if ($flow['orderId'] > 0) {
            $order = wc_get_order($flow['orderId']);
            if (!$order || (!$order->needs_payment() && !$order->has_status('checkout-draft'))) {
                return false;
            }
        }
        $nativeOrder = WC()->session?->get('ppcp')['order'] ?? null;
        if (
            $flow['remoteId'] !== null && is_object($nativeOrder) && method_exists($nativeOrder, 'id')
            && $flow['remoteId'] !== $nativeOrder->id()
        ) {
            return false;
        }
        if (!$flow['consumed']) {
            return $state->grantedUntil > time() && $flow['attempt'] === $state->verificationId
                && $flow['cart'] === $this->cartHash();
        }
        $id = $this->orderId ?: (int)WC()->session?->get('order_awaiting_payment');
        $order = $id > 0 && $id === $flow['orderId'] ? wc_get_order($id) : false;
        return $state->grantedUntil === 0 && $state->resource === null && $state->verificationId === null
            && $state->verificationUntil === 0 && $order && $order->needs_payment()
            && $order->get_customer_id() === (int)$context->actorId
            && ($flow['cart'] === $this->cartHash() || $this->orderId === $id);
    }

    private function mutate(callable $change): ?array
    {
        $context = $this->services->identity->context();
        $storage = $this->services->storage();
        return $storage->run($context->scopeId, function () use ($storage, $context, $change): ?array {
            $flow = $change($storage->paypalFlow($context->scopeId, $context->browserId));
            $storage->savePaypalFlow($context->scopeId, $context->browserId, $flow);
            return $flow;
        });
    }

    private function canReuse(): bool
    {
        return $this->entry !== null || in_array($this->paymentMethod(), self::PAYMENT_METHODS, true);
    }

    private function paymentMethod(): mixed
    {
        return $this->method ?? ($_POST['payment_method'] ?? WC()->session?->get('chosen_payment_method'));
    }

    private function keyHash(): string
    {
        return hash('sha256', $this->services->settings->apiKey());
    }

    private function cartHash(): string
    {
        $items = [];
        foreach (WC()->cart?->get_cart() ?? [] as $key => $item) {
            $items[$key] = [$item['product_id'], $item['variation_id'], $item['variation'],
                $item['quantity'], $item['data']->get_price()];
        }
        ksort($items);
        $coupons = WC()->cart?->get_applied_coupons() ?? [];
        sort($coupons);
        return hash('sha256', json_encode([$items, $coupons, get_woocommerce_currency()], JSON_THROW_ON_ERROR));
    }
}
