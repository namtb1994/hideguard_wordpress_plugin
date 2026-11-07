<?php

declare(strict_types=1);

namespace Hachitechs\HideguardWordPress;

/** Native hooks enforce actions even when JavaScript is disabled or bypassed. */
final class ActionEvents
{
    /** event => [WordPress hook, adapter callback, accepted arguments, priority]. */
    public const EVENTS = [
        'payment' => [
            ['woocommerce_paypal_payments_approve_order_request_started', 'paypal', 1, -100],
            ['woocommerce_before_pay_action', 'pay', 1, -100],
            ['woocommerce_after_checkout_validation', 'checkoutErrors', 2, -100],
            ['woocommerce_rest_checkout_process_payment_with_context', 'storePayment', 2, -100],
            ['rest_dispatch_request', 'storeRequest', 4, -100],
        ],
        'checkout' => [
            ['woocommerce_after_checkout_validation', 'checkoutErrors', 2, -100],
            ['rest_dispatch_request', 'storeRequest', 4, -100],
        ],
        'create_order' => [
            ['woocommerce_paypal_payments_create_order_request_started', 'paypal', 1, -100],
            ['woocommerce_after_checkout_validation', 'checkoutErrors', 2, -100],
            ['woocommerce_checkout_create_order', 'guard', 2, -100],
            ['rest_dispatch_request', 'storeRequest', 4, -100],
        ],
        'add_to_cart' => [
            ['woocommerce_add_to_cart_validation', 'cartValidation', 1, -100],
            ['woocommerce_add_cart_item_data', 'cartData', 1, -100],
            ['woocommerce_store_api_validate_add_to_cart', 'guard', 1, -100],
            ['rest_dispatch_request', 'storeRequest', 4, -100],
        ],
        'login' => [
            ['authenticate', 'authenticate', 3, PHP_INT_MAX],
        ],
        'register' => [
            ['registration_errors', 'registrationErrors', 3, PHP_INT_MAX],
            ['woocommerce_registration_errors', 'registrationErrors', 3, PHP_INT_MAX],
            ['woocommerce_created_customer', 'createdCustomer', 1, PHP_INT_MAX],
            ['set_auth_cookie', 'authCookie', 4, -100],
        ],
    ];

    public function __construct(private readonly Services $services)
    {
    }

    public function boot(): void
    {
        foreach (self::EVENTS as $event => $hooks) {
            foreach ($hooks as [$hook, $callback, $arguments, $priority]) {
                add_filter($hook, fn(...$args) => $this->$callback($event, ...$args), $priority, $arguments);
            }
        }
    }

    public function cartValidation(string $event, bool $passed): bool
    {
        if (!$passed) {
            return false;
        }
        try {
            $this->check($event);
            return true;
        } catch (\Throwable $error) {
            wc_add_notice($this->message(), 'error');
            return false;
        }
    }

    public function cartData(string $event, array $data): array
    {
        $this->guard($event);
        return $data;
    }

    public function checkoutErrors(string $event, array $data, \WP_Error $errors): void
    {
        if (!$errors->has_errors()) {
            $this->services->paypal()->checkout($data['payment_method'] ?? null);
            $this->registrationErrors($event, $errors);
        }
    }

    public function registrationErrors(string $event, \WP_Error $errors): \WP_Error
    {
        if ($errors->has_errors()) {
            return $errors;
        }
        try {
            $this->check($event);
        } catch (\Throwable $error) {
            $errors->add('hideguard_access', $this->message());
        }
        return $errors;
    }

    public function authenticate(string $event, mixed $user): mixed
    {
        // Application-password/merchant API authentication retains its native authorization.
        if (
            !$user instanceof \WP_User || (defined('REST_REQUEST') && REST_REQUEST)
            || (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST)
        ) {
            return $user;
        }
        try {
            $this->check($event);
            return $user;
        } catch (\Throwable $error) {
            return new \WP_Error('hideguard_access', $this->message());
        }
    }

    public function createdCustomer(string $event, int $customerId): void
    {
        $this->services->backend()->rememberCreatedCustomer($customerId);
    }

    public function authCookie(string $event, string $cookie, int $expire, int $expiration, int $customerId): void
    {
        $this->services->backend()->adoptCreatedCustomer($customerId);
    }

    public function guard(string $event): void
    {
        try {
            $this->check($event);
        } catch (\Throwable $error) {
            // Native WooCommerce handlers catch Exceptions before insertion/payment.
            throw new \RuntimeException($this->message(), 0, $error);
        }
    }

    public function pay(string $event, \WC_Order $order): void
    {
        try {
            $this->services->paypal()->checkout(
                $_POST['payment_method'] ?? $order->get_payment_method(),
                $order->get_id()
            );
            $this->check($event, $order->get_id());
        } catch (ActionDenied $error) {
            // This hook is outside WooCommerce's payment try/catch.
            wp_safe_redirect($this->gateUrl());
            exit;
        } catch (\Throwable $error) {
            wp_die(wp_kses_post($this->message()), '', ['response' => 503]);
        }
    }

    public function storePayment(string $event, \Automattic\WooCommerce\StoreApi\Payments\PaymentContext $context): void
    {
        try {
            $this->services->paypal()->checkout($context->payment_method, $context->order->get_id());
            $this->check($event, $context->order->get_id());
        } catch (\Throwable $error) {
            throw new \RuntimeException($this->message(), 0, $error);
        }
    }

    public function storeRequest(
        string $event,
        mixed $response,
        \WP_REST_Request $request,
        string $route,
        array $handler
    ): mixed {
        if ($response !== null || $request->get_method() === 'OPTIONS') {
            return $response;
        }
        $route = strtolower($request->get_route());
        $draftRead = $event === 'create_order'
            && preg_match('#^/wc/store(?:/v[0-9]+)?/checkout/?$#', $route);
        if (in_array($request->get_method(), ['GET', 'HEAD'], true) && !$draftRead) {
            return $response;
        }
        $path = $event === 'add_to_cart' ? 'cart/add-item' : 'checkout';
        if (!preg_match('#^/wc/store(?:/v[0-9]+)?/' . $path . '(?:/|$)#', $route)) {
            return $response;
        }
        try {
            if ($event !== 'add_to_cart') {
                $this->services->paypal()->storeRequest($request);
            }
            $this->check($event);
            return $response;
        } catch (\Throwable $error) {
            $denied = RestErrors::response($error);
            $denied->header('Cache-Control', 'private, no-store, max-age=0');
            return $denied;
        }
    }

    public function paypal(string $event, array $data): void
    {
        if (!$this->services->settings->backendEnabled()) {
            return;
        }
        $this->services->paypal()->enter($event === 'create_order' ? 'create' : 'approve', $data);
        try {
            $this->check($event);
        } catch (\Throwable $error) {
            $denied = RestErrors::response($error);
            nocache_headers();
            foreach ($denied->get_headers() as $name => $value) {
                header($name . ': ' . $value);
            }
            // Preserve PayPal's native error envelope; the shared browser state observer opens the Block page.
            wp_send_json_error([
                'message' => $this->message(), 'code' => 'hideguard_access', 'redirect' => $this->gateUrl(),
            ], $denied->get_status());
        }
    }

    private function check(string $event, ?int $orderId = null): void
    {
        if (
            (is_admin() && !wp_doing_ajax()) || wp_doing_cron()
            || (defined('WP_CLI') && WP_CLI)
        ) {
            return;
        }
        if ($event === 'add_to_cart' && doing_action('wc_ajax_ppc-simulate-cart')) {
            return; // PayPal's temporary cloned cart is discarded; the real order endpoints are checked separately.
        }
        $this->services->backend()->assertAllowed($event, $orderId);
    }

    private function message(): string
    {
        return esc_html__('Unable to verify access. Reload the page and try again.', 'hachitechs-hideguard')
            . ' <a href="' . esc_url($this->gateUrl()) . '">'
            . esc_html__('Verify access', 'hachitechs-hideguard') . '</a>';
    }

    private function gateUrl(): string
    {
        // The existing gate validates this return path before using it.
        return add_query_arg([
            'hachitech_hideguard_gate' => '1',
            'return' => wp_unslash($_SERVER['REQUEST_URI'] ?? '/'),
        ], home_url('/'));
    }
}
