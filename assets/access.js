import {initialize} from './runtime.js';
import {observeBackendActions} from './action-state.js?ver=1.4.0';

const container = document.getElementById('wp-script-module-data-hachitech-hideguard');
if (container instanceof HTMLScriptElement) {
    const config = JSON.parse(container.text);
    initialize(config, {
        prepareBody: data => {
            data.set('_hg_nonce', config.nonce);
            data.set('_wpnonce', config.restNonce);
        },
        isProtectedAction: target => Boolean(target && (
            config.protectCart && (
                target.matches('form.cart, [name="add-to-cart"], [name="update_cart"]') ||
                target.closest('.add_to_cart_button, .single_add_to_cart_button')
            ) ||
            config.protectCheckout && target.matches(
                'form.checkout, #place_order, .wc-block-components-checkout-place-order-button'
            ) ||
            config.protectLogin && target.matches(
                '#loginform, #loginform [type="submit"], '
                + 'form.woocommerce-form-login, form.woocommerce-form-login [type="submit"]'
            ) ||
            config.protectRegister && target.matches(
                '#registerform, #registerform [type="submit"], '
                + 'form.woocommerce-form-register, form.woocommerce-form-register [type="submit"]'
            ) ||
            config.protectPayment && target.matches(
                'form#order_review, [name="woocommerce_pay"]'
            )
        ))
    });
    observeBackendActions(config);
}
