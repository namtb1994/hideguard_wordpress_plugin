import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import test from 'node:test';

const source = await readFile(new URL('../assets/action-state.js', import.meta.url), 'utf8');
const {isBackendActionRequest, observeBackendActions} = await import(
    'data:text/javascript;base64,' + Buffer.from(source).toString('base64')
);
const page = 'https://ipediatrilocal.com/shop/';
const config = {
    enabled: true, backendEvents: true, nonce: 'browser-csrf', restNonce: 'rest-csrf',
    stateUrl: '/wp-json/hachitech-hideguard/v1/state', gateUrl: '/?hachitech_hideguard_gate=1',
    publicKey: 'site-public', apiKey: 'never-send-api-key', visitorId: 'never-send-visitor'
};
const flush = () => new Promise(resolve => setImmediate(resolve));
const blocked = {status: 'blocked', blocked: true, blockedUntil: Date.now() + 60000};

function fixture(probe = () => Promise.resolve(Response.json(blocked))) {
    const calls = [];
    const navigations = [];
    const nativeResponse = Response.json({result: 'failure', messages: 'Native WooCommerce response'}, {status: 400});
    const browser = {
        location: {href: page, assign: url => navigations.push(url)}, document: {},
        fetch(input, options) {
            calls.push([input, options]);
            return input === new URL(config.stateUrl, page).href ? probe() : Promise.resolve(nativeResponse);
        }
    };
    return {browser, calls, navigations, nativeResponse};
}

test('classifies native Woo writes, Store API aliases, uppercase paths and conservative batches', () => {
    for (const url of [
        '/?wc-ajax=add_to_cart', '/?wc-ajax=checkout',
        '/?wc-ajax=ppc-create-order', '/?wc-ajax=ppc-approve-order',
        '/wp-json/wc/store/v1/cart/add-item', '/wp-json/wc/store/cart/add-item/',
        '/wp-json/WC/STORE/V1/CHECKOUT', '/wp-json/wc/store/checkout/123',
        '/wp-json/wc/store/v1/batch', '/wp-json/wc/store/batch',
        '/?rest_route=/wc/store/v1/checkout'
    ]) {
        assert.equal(isBackendActionRequest(url, {method: 'POST'}, page), true, url);
    }
    assert.equal(isBackendActionRequest('/wp-json/wc/store/v1/checkout', {method: 'PATCH'}, page), true);
});

test('skips unrelated, cross-origin and read requests', () => {
    for (const url of [
        '/wp-json/wc/store/v1/products', '/wp-json/wc/store/v1/cart',
        '/wp-json/wc/store/v1/cart/update-item', '/wp-json/wc/store/v1/checkout-other',
        '/?wc-ajax=get_refreshed_fragments', '/wp-json/hachitech-hideguard/v1/state',
        'https://other.example/wp-json/wc/store/v1/checkout', 'http://ipediatrilocal.com/?wc-ajax=checkout'
    ]) {
        assert.equal(isBackendActionRequest(url, {method: 'POST'}, page), false, url);
    }
    for (const method of ['GET', 'HEAD', 'OPTIONS']) {
        assert.equal(isBackendActionRequest('/?wc-ajax=checkout', {method}, page), false, method);
    }
    assert.equal(isBackendActionRequest('/?wc-ajax=checkout', undefined, page), false);
    assert.equal(isBackendActionRequest('https://[invalid', {method: 'POST'}, page), false);
});

test('honors Request method and fetch init overrides', () => {
    const request = new Request(new URL('/wp-json/wc/store/v1/cart/add-item', page), {method: 'POST'});
    assert.equal(isBackendActionRequest(request, undefined, page), true);
    assert.equal(isBackendActionRequest(request, {method: 'GET'}, page), false);
    assert.equal(isBackendActionRequest(new URL('/?wc-ajax=checkout', page), {method: 'post'}, page), true);
});

test('observes exact Store checkout GET and HEAD because they persist draft orders', () => {
    for (const method of ['GET', 'HEAD']) {
        for (const url of [
            '/wp-json/wc/store/checkout', '/wp-json/wc/store/v1/checkout/',
            '/wp-json/WC/STORE/V1/CHECKOUT', '/?rest_route=/WC/STORE/CHECKOUT/'
        ]) {
            assert.equal(isBackendActionRequest(url, {method}, page), true, method + ' ' + url);
        }
        for (const url of [
            '/wp-json/wc/store/checkout/123', '/wp-json/wc/store/v1/cart/add-item',
            '/wp-json/wc/store/v1/cart', '/wp-json/wc/store/batch',
            '/wp-json/wc/store/v1/products', 'https://other.example/wp-json/wc/store/checkout'
        ]) {
            assert.equal(isBackendActionRequest(url, {method}, page), false, method + ' ' + url);
        }
    }
    assert.equal(isBackendActionRequest('/wp-json/wc/store/v1/checkout', undefined, page), true);
    assert.equal(isBackendActionRequest('/wp-json/wc/store/checkout', {method: 'OPTIONS'}, page), false);
});

test('preserves native fetch responses and navigates only after reading a persisted block', async () => {
    const {browser, calls, navigations, nativeResponse} = fixture();
    observeBackendActions(config, browser);
    const response = await browser.fetch('/?wc-ajax=checkout', {method: 'POST', body: 'native=data'});
    assert.equal(response, nativeResponse);
    assert.deepEqual(await response.json(), {result: 'failure', messages: 'Native WooCommerce response'});
    await flush();
    assert.equal(calls.length, 2);
    assert.deepEqual(calls[0], ['/?wc-ajax=checkout', {method: 'POST', body: 'native=data'}]);
    assert.equal(calls[1][0], new URL(config.stateUrl, page).href);
    assert.equal(calls[1][1].method, 'POST');
    assert.equal(calls[1][1].credentials, 'same-origin');
    assert.equal(calls[1][1].cache, 'no-store');
    assert.deepEqual(Object.fromEntries(new URLSearchParams(calls[1][1].body)), {
        _hg_nonce: 'browser-csrf', _wpnonce: 'rest-csrf'
    });
    assert.doesNotMatch(JSON.stringify(calls), /never-send|apiKey|visitorId/);
    assert.deepEqual(navigations, [new URL(config.gateUrl, page).href]);
});

test('accepts the native Guard HTTP 401 block state response', async () => {
    const {browser, navigations} = fixture(() => Promise.resolve(Response.json(blocked, {status: 401})));
    observeBackendActions(config, browser);
    await browser.fetch('/wp-json/wc/store/v1/checkout', {method: 'POST'});
    await flush();
    assert.deepEqual(navigations, [new URL(config.gateUrl, page).href]);
});

test('draft checkout reads probe state while preserving the original response and arguments', async () => {
    for (const method of ['GET', 'HEAD']) {
        const {browser, calls, nativeResponse, navigations} = fixture();
        observeBackendActions(config, browser);
        const options = {method, headers: {'Native-Header': 'preserved'}};
        const response = await browser.fetch('/wp-json/wc/store/checkout', options);
        assert.equal(response, nativeResponse);
        await flush();
        assert.equal(calls.length, 2);
        assert.equal(calls[0][1], options);
        assert.deepEqual(navigations, [new URL(config.gateUrl, page).href]);
    }
});

test('does not delay a business response while its state read is pending and deduplicates probes', async () => {
    let finish;
    const {browser, calls, navigations} = fixture(() => new Promise(resolve => { finish = resolve; }));
    observeBackendActions(config, browser);
    await Promise.all([
        browser.fetch('/?wc-ajax=checkout', {method: 'POST'}),
        browser.fetch('/wp-json/wc/store/cart/add-item', {method: 'POST'})
    ]);
    assert.equal(calls.length, 3);
    assert.equal(navigations.length, 0);
    finish(Response.json({status: 'allowed', allowed: true}));
    await flush();
    await browser.fetch('/?wc-ajax=checkout', {method: 'POST'});
    assert.equal(calls.length, 5);
    finish(Response.json({status: 'allowed', allowed: true}));
    await flush();
});

test('rechecks after a later action completes during an older state read', async () => {
    let finish;
    const {browser, calls, navigations} = fixture(() => new Promise(resolve => { finish = resolve; }));
    observeBackendActions(config, browser);
    await browser.fetch('/?wc-ajax=add_to_cart', {method: 'POST'});
    await flush();
    await browser.fetch('/?wc-ajax=checkout', {method: 'POST'});
    assert.equal(calls.length, 3);
    finish(Response.json({status: 'allowed', allowed: true}));
    await flush();
    assert.equal(calls.length, 4);
    finish(Response.json(blocked, {status: 401}));
    await flush();
    assert.deepEqual(navigations, [new URL(config.gateUrl, page).href]);
});

test('business errors, malformed state and failed probes cannot synthesize a ban', async () => {
    for (const probe of [
        () => Promise.resolve(Response.json({status: 'allowed', allowed: true})),
        () => Promise.resolve(Response.json({error: 'Access denied'})),
        () => Promise.resolve(Response.json({status: 'blocked', blocked: false, blockedUntil: Date.now()})),
        () => Promise.resolve(Response.json({status: 'blocked', blocked: true, blockedUntil: '12345'})),
        () => Promise.resolve(new Response('not json')),
        () => Promise.resolve(new Response('', {status: 503})),
        () => Promise.resolve(Response.json(blocked, {status: 503})),
        () => Promise.resolve(undefined),
        () => Promise.reject(new Error('Offline')),
        () => { throw new Error('Native fetch failure'); }
    ]) {
        const {browser, nativeResponse, navigations} = fixture(probe);
        observeBackendActions(config, browser);
        assert.equal(await browser.fetch('/?wc-ajax=checkout', {method: 'POST'}), nativeResponse);
        await flush();
        assert.deepEqual(navigations, []);
    }
});

test('does not observe disabled events, unrelated writes, cross-origin requests or reads', async () => {
    const disabled = fixture();
    const originalFetch = disabled.browser.fetch;
    observeBackendActions({...config, backendEvents: false}, disabled.browser);
    assert.equal(disabled.browser.fetch, originalFetch);
    observeBackendActions({...config, enabled: false}, disabled.browser);
    assert.equal(disabled.browser.fetch, originalFetch);
    const {browser, calls, navigations} = fixture();
    observeBackendActions(config, browser);
    await browser.fetch('/?wc-ajax=checkout');
    await browser.fetch('https://other.example/?wc-ajax=checkout', {method: 'POST'});
    await browser.fetch('/wp-json/example/v1/business', {method: 'POST'});
    await flush();
    assert.equal(calls.length, 3);
    assert.deepEqual(navigations, []);
});

test('rejects off-origin state endpoints and gate destinations', async () => {
    const offOrigin = fixture();
    const originalFetch = offOrigin.browser.fetch;
    observeBackendActions({...config, stateUrl: 'https://other.example/state'}, offOrigin.browser);
    assert.equal(offOrigin.browser.fetch, originalFetch);
    const {browser, navigations} = fixture();
    observeBackendActions({...config, gateUrl: 'https://other.example/gate'}, browser);
    await browser.fetch('/?wc-ajax=checkout', {method: 'POST'});
    await flush();
    assert.deepEqual(navigations, []);
});

test('observes jQuery classic Woo completion without changing its payload', async () => {
    const {browser, calls, navigations} = fixture();
    let completed;
    browser.jQuery = document => {
        assert.equal(document, browser.document);
        return {on(event, handler) {
            assert.equal(event, 'ajaxComplete.hachitechHideguard');
            completed = handler;
        }};
    };
    observeBackendActions(config, browser);
    const xhr = {responseText: 'unchanged native response'};
    const settings = {url: '/?wc-ajax=add_to_cart', type: 'POST', data: 'product_id=17'};
    completed({}, xhr, settings);
    await flush();
    assert.equal(calls.length, 1);
    assert.equal(xhr.responseText, 'unchanged native response');
    assert.deepEqual(settings, {url: '/?wc-ajax=add_to_cart', type: 'POST', data: 'product_id=17'});
    assert.deepEqual(navigations, [new URL(config.gateUrl, page).href]);
});

for (const endpoint of ['ppc-create-order', 'ppc-approve-order']) {
    test(`PayPal ${endpoint} fetch and jQuery errors redirect through the shared Block page`, async () => {
        const {browser, navigations, nativeResponse} = fixture();
        let completed;
        browser.jQuery = () => ({on(_event, handler) { completed = handler; }});
        observeBackendActions(config, browser);
        const response = await browser.fetch(`/?wc-ajax=${endpoint}`, {method: 'POST'});
        assert.equal(response, nativeResponse);
        await flush();
        assert.deepEqual(navigations, [new URL(config.gateUrl, page).href]);
        navigations.length = 0;
        const xhr = {status: 401, responseText: '{"success":false}'};
        completed({}, xhr, {url: `/?wc-ajax=${endpoint}`, type: 'POST'});
        await flush();
        assert.equal(xhr.responseText, '{"success":false}');
        assert.deepEqual(navigations, [new URL(config.gateUrl, page).href]);
    });
}
