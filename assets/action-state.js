/** Classify Woo actions that can persist a backend verdict without changing their responses. */
export function isBackendActionRequest(input, options = {}, currentUrl = window.location.href) {
    try {
        const url = new URL(typeof input?.url === 'string' ? input.url : input, currentUrl);
        const method = String(options?.method ?? input?.method ?? 'GET').toUpperCase();
        if (url.origin !== new URL(currentUrl).origin || method === 'OPTIONS') {
            return false;
        }
        const route = url.searchParams.get('rest_route') ?? url.pathname;
        // Native checkout reads create/update a draft order before returning it.
        if (['GET', 'HEAD'].includes(method)) {
            return /(?:^|\/)wc\/store(?:\/v[0-9]+)?\/checkout\/?$/i.test(route);
        }
        if (['add_to_cart', 'checkout', 'ppc-create-order', 'ppc-approve-order'].includes(url.searchParams.get('wc-ajax')?.toLowerCase())) {
            return true;
        }
        return /(?:^|\/)wc\/store(?:\/v[0-9]+)?\/(?:cart\/add-item\/?$|checkout(?:\/|$)|batch\/?$)/i.test(route);
    } catch {
        return false;
    }
}

/** Observe persisted Guard blocks; business responses alone never establish a ban. */
export function observeBackendActions(config, browser = window) {
    if (!config.enabled || !config.backendEvents || typeof browser.fetch !== 'function') {
        return;
    }
    let stateUrl;
    try {
        stateUrl = new URL(config.stateUrl, browser.location.href);
        if (!config.stateUrl || stateUrl.origin !== new URL(browser.location.href).origin) {
            return;
        }
    } catch {
        return;
    }
    const originalFetch = browser.fetch;
    let pending;
    let fetching = false;
    let probeAgain = false;
    function probeState() {
        if (pending) {
            probeAgain ||= fetching;
            return;
        }
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 8000);
        pending = Promise.resolve().then(() => {
            fetching = true;
            return originalFetch.call(browser, stateUrl.href, {
                method: 'POST', credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
                headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', Accept: 'application/json'},
                body: new URLSearchParams({_hg_nonce: config.nonce, _wpnonce: config.restNonce}).toString()
            });
        }).then(response => response?.ok || response?.status === 401 ? response.json() : null).then(state => {
            if (state?.status !== 'blocked' || state.blocked !== true || !Number.isFinite(state.blockedUntil)) {
                return;
            }
            if (typeof config.gateUrl !== 'string' || !config.gateUrl) {
                return;
            }
            const gate = new URL(config.gateUrl, browser.location.href);
            if (gate.origin === new URL(browser.location.href).origin) {
                probeAgain = false;
                browser.location.assign(gate.href);
            }
        }).catch(() => {
            // A failed read cannot replace a native cart/checkout result or invent a block.
        }).finally(() => {
            clearTimeout(timeout);
            pending = undefined;
            fetching = false;
            if (probeAgain) {
                probeAgain = false;
                probeState();
            }
        });
    }
    browser.fetch = function (...args) {
        return originalFetch.apply(this, args).then(response => {
            if (isBackendActionRequest(args[0], args[1], browser.location.href)) {
                probeState();
            }
            return response;
        });
    };
    if (typeof browser.jQuery === 'function') {
        browser.jQuery(browser.document).on('ajaxComplete.hachitechHideguard', (_event, _xhr, settings) => {
            if (isBackendActionRequest(settings.url, {method: settings.type ?? settings.method}, browser.location.href)) {
                probeState();
            }
        });
    }
}
