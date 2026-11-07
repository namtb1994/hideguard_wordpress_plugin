<?php

declare(strict_types=1);

namespace Hachitechs\HideguardWordPress;

use Hachitechs\HideguardPhp\Access\ResourceRef;
use Hachitechs\HideguardPhp\Exceptions\FailureReason;
use Hachitechs\HideguardPhp\Exceptions\GuardException;

/** Enforce public WooCommerce operations independently of the page/JavaScript lock. */
final class Commerce
{
    private ?ResourceRef $checkoutResource = null;

    public function __construct(private readonly Services $services)
    {
    }

    public function boot(): void
    {
        add_filter('woocommerce_add_to_cart_validation', [$this, 'validateCart'], 1);
        add_filter('woocommerce_add_cart_item_data', [$this, 'guardCartItem'], 1);
        add_action('woocommerce_after_checkout_validation', [$this, 'validateCheckout'], 1, 2);
        add_action('woocommerce_checkout_create_order', [$this, 'guardCheckout'], 1);
        add_action('woocommerce_store_api_validate_add_to_cart', [$this, 'guardStoreCart'], 1);
        add_action('woocommerce_store_api_checkout_update_order_from_request', [$this, 'guardStoreCheckout'], 1, 2);
        add_action('woocommerce_checkout_order_processed', [$this, 'consumeOrder'], PHP_INT_MAX);
        add_action('woocommerce_store_api_checkout_order_processed', [$this, 'consumeOrder'], PHP_INT_MAX);
        add_filter('rest_dispatch_request', [$this, 'guardStoreRequest'], 10, 4);
    }

    public function enabled(): bool
    {
        if (!function_exists('WC')) {
            return false;
        }
        $policy = $this->services->settings->policy();
        return $policy->protects('cart') || $policy->protects('checkout') || $policy->protects('complete_order');
    }

    public function bindCurrent(): void
    {
        if ($this->enabled()) {
            $this->services->guard()->bindGrant(
                $this->services->identity->context(),
                $this->services->settings->policy(),
                $this->resource()
            );
        }
    }

    public function validateCart(bool $passed): bool
    {
        if (!$passed || !$this->publicRequest()) {
            return $passed;
        }
        try {
            $this->assertPoint('cart');
            return true;
        } catch (\Throwable $error) {
            wc_add_notice($this->message($error), 'error');
            return false;
        }
    }

    public function guardCartItem(array $data): array
    {
        if ($this->publicRequest()) {
            try {
                $this->assertPoint('cart');
            } catch (\Throwable $error) {
                // WC_Cart::add_to_cart catches exceptions before adding the item.
                throw new \RuntimeException($this->message($error), 0, $error);
            }
        }
        return $data;
    }

    public function validateCheckout(array $data, \WP_Error $errors): void
    {
        try {
            $this->guardCheckout();
        } catch (\Throwable $error) {
            $errors->add('hideguard_access', $this->message($error));
        }
    }

    public function guardCheckout(): void
    {
        if (!$this->publicRequest()) {
            return;
        }
        $policy = $this->services->settings->policy();
        $point = $policy->protects('checkout') ? 'checkout' : 'complete_order';
        try {
            if ($policy->protects($point)) {
                $this->checkoutResource = $this->assertPoint($point);
            }
        } catch (\Throwable $error) {
            throw new \RuntimeException($this->message($error), 0, $error);
        }
    }

    public function guardStoreCart(): void
    {
        $this->assertPoint('cart');
    }

    public function guardStoreCheckout(\WC_Order $order, \WP_REST_Request $request): void
    {
        if ($request->get_method() === 'POST') {
            $this->guardCheckout();
        }
    }

    public function guardStoreRequest(
        mixed $response,
        \WP_REST_Request $request,
        string $route,
        array $handler
    ): mixed {
        if ($response !== null || in_array($request->get_method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $response;
        }
        $route = strtolower($request->get_route());
        if (!preg_match('#^/wc/store(?:/v[0-9]+)?/(cart|checkout)(?:/|$)#', $route, $match)) {
            return $response;
        }
        try {
            $policy = $this->services->settings->policy();
            $protected = $match[1] === 'cart' ? $policy->protects('cart')
                : ($policy->protects('checkout') || $policy->protects('complete_order'));
            if (!$protected) {
                return $response;
            }
            $decision = $this->services->guard()->inspect($this->services->identity->context(), $policy);
            if ($decision->status === 'allowed') {
                return $response;
            }
            $status = $decision->status === 'blocked' ? 401 : 403;
            $denied = new \WP_REST_Response($decision->toBrowserArray(), $status);
            $denied->header('Cache-Control', 'private, no-store, max-age=0');
            if ($status === 401) {
                $denied->header('Retry-After', (string)max(1, $decision->retryAfterSeconds));
            }
            return $denied;
        } catch (\Throwable $error) {
            return RestErrors::response($error);
        }
    }

    public function consumeOrder(mixed $order): void
    {
        if ($this->checkoutResource !== null) {
            if ($this->services->settings->backendEnabled()) {
                $id = $order instanceof \WC_Order ? $order->get_id() : (int)$order;
                $this->services->backend()->consumeOrder($this->checkoutResource, $id);
            } else {
                $this->services->guard()->consumeGrant((string)get_current_blog_id(), $this->checkoutResource);
            }
            $this->checkoutResource = null;
        }
    }

    private function assertPoint(string $point): ?ResourceRef
    {
        $policy = $this->services->settings->policy();
        if (!$policy->protects($point)) {
            return null;
        }
        $context = $this->services->identity->context();
        $guard = $this->services->guard();
        if ($guard->inspect($context, $policy)->status !== 'allowed') {
            throw new GuardException(FailureReason::PermissionDenied, 'Verify access before continuing.');
        }
        $resource = $this->resource();
        // Binding never extends a pass; it associates the native cart selected by WooCommerce.
        $guard->bindGrant($context, $policy, $resource);
        $guard->assertResourceAllowed($context->scopeId, $policy, $resource);
        return $resource;
    }

    private function resource(): ResourceRef
    {
        if (!WC()->session) {
            WC()->initialize_session();
        }
        if (WC()->session instanceof \WC_Session_Handler) {
            WC()->session->set_customer_session_cookie(true);
        }
        $id = (string)WC()->session->get_customer_id();
        if ($id === '') {
            throw new GuardException(FailureReason::InvalidIdentity, 'A WooCommerce session is required.');
        }
        return new ResourceRef('woocommerce_cart', $id, get_current_user_id() ? (string)get_current_user_id() : null);
    }

    private function publicRequest(): bool
    {
        return !(is_admin() && !wp_doing_ajax()) && !wp_doing_cron() && !(defined('WP_CLI') && WP_CLI);
    }

    private function message(\Throwable $error): string
    {
        if (!$error instanceof GuardException && !$error->getPrevious() instanceof GuardException) {
            error_log('HideGuard commerce check failed: ' . $error->getMessage());
        }
        return __('Verify your access before continuing. Reload the page and try again.', 'hachitechs-hideguard');
    }
}
