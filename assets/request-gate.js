(function (browser) {
    'use strict';
    if (browser.HachitechsHideguardNetwork) {
        return;
    }
    let installed;

    function install(config) {
        if (installed || !config.enabled) {
            return installed;
        }
        const pageOrigin = new URL(browser.location.href).origin;
        const sdkOrigin = new URL(config.sdkOrigin).origin;
        // A shared origin needs an explicit SDK transport boundary; never exempt the entire application origin.
        if (sdkOrigin === pageOrigin) {
            browser.console.warn('HideGuard request queue requires the SDK on a separate origin.');
            return;
        }
        const sdkOrigins = new Set([sdkOrigin, ...(config.sdkOrigins || [])].map(value => new URL(value).origin));
        sdkOrigins.delete(pageOrigin);
        const verificationUrls = config.verificationUrls.map(value => new URL(value, browser.location.href));
        const nativeFetch = browser.fetch;
        const NativeXHR = browser.XMLHttpRequest;
        const jobs = new Set();
        const xhrs = new WeakMap();
        let status = config.initialState || 'pending';
        let deadline;
        let navigation = false;
        const stateError = () => new DOMException('HideGuard access is ' + status + '.', 'NetworkError');

        function bypass(value) {
            try {
                const url = new URL(typeof value?.url === 'string' ? value.url : value, browser.location.href);
                return !['http:', 'https:'].includes(url.protocol) || sdkOrigins.has(url.origin)
                    || verificationUrls.some(endpoint => endpoint.origin === url.origin
                        && endpoint.pathname === url.pathname
                        && endpoint.searchParams.get('rest_route') === url.searchParams.get('rest_route'));
            } catch {
                return false;
            }
        }

        function current() {
            if (navigation) {
                return 'error';
            }
            if (status === 'allowed') {
                const state = browser.HideGuardAccess?.getState();
                if (!state || state.status !== 'allowed' || !(state.expiresAt > Date.now())) {
                    return 'pending';
                }
            }
            return status;
        }

        function armDeadline() {
            browser.clearTimeout(deadline);
            deadline = browser.setTimeout(() => change('error'), config.maxWaitMs || 45000);
        }

        function change(next) {
            status = next;
            browser.clearTimeout(deadline);
            deadline = null;
            if (next === 'pending') {
                armDeadline();
                return;
            }
            // Remove first: callbacks can enqueue another operation or abort a later queued request.
            for (const job of Array.from(jobs)) {
                if (next === 'allowed' && current() !== 'allowed') {
                    change(current());
                    break;
                }
                if (jobs.delete(job)) {
                    job.finish(next === 'allowed', next === 'allowed' ? null : stateError());
                }
            }
        }

        function schedule(start, fail, signal) {
            let queued = true;
            let job;
            const reason = () => signal.reason === undefined
                ? new DOMException('The operation was aborted.', 'AbortError') : signal.reason;
            const abort = () => finish(false, reason());
            function finish(allowed, error) {
                if (!queued) {
                    return;
                }
                queued = false;
                jobs.delete(job);
                signal?.removeEventListener('abort', abort);
                if (allowed) {
                    start();
                } else {
                    fail(error);
                }
            }
            job = {finish};
            if (signal?.aborted) {
                finish(false, reason());
            } else {
                const state = current();
                if (state === 'allowed') {
                    finish(true);
                } else if (state !== 'pending') {
                    finish(false, stateError());
                } else if (jobs.size >= 512) {
                    finish(false, new DOMException('HideGuard request queue is full.', 'QuotaExceededError'));
                } else {
                    jobs.add(job);
                    if (!deadline) {
                        armDeadline();
                    }
                    signal?.addEventListener('abort', abort, {once: true});
                }
            }
            return () => {
                queued = false;
                jobs.delete(job);
                signal?.removeEventListener('abort', abort);
            };
        }

        if (typeof nativeFetch === 'function') {
            browser.fetch = function (input, options) {
                if (bypass(input) || current() === 'allowed') {
                    return nativeFetch.apply(this, arguments);
                }
                const receiver = this;
                return new Promise((resolve, reject) => {
                    let request;
                    try {
                        // Snapshot headers/body now, as fetch does; send this request once after permission settles.
                        request = new browser.Request(input, options);
                    } catch (error) {
                        reject(error);
                        return;
                    }
                    schedule(() => {
                        try {
                            resolve(nativeFetch.call(receiver, request));
                        } catch (error) {
                            reject(error);
                        }
                    }, reject, request.signal);
                });
            };
        }

        function snapshot(body) {
            const type = Object.prototype.toString.call(body);
            if (body == null || typeof body === 'string' || ['[object Blob]', '[object File]'].includes(type)) {
                return body;
            }
            if (type === '[object FormData]') {
                const copy = new browser.FormData();
                browser.FormData.prototype.forEach.call(body, (value, name) => copy.append(name, value));
                return copy;
            }
            if (type === '[object URLSearchParams]') {
                return new browser.URLSearchParams(body);
            }
            if (type === '[object ArrayBuffer]') {
                return browser.ArrayBuffer.prototype.slice.call(body, 0);
            }
            if (browser.ArrayBuffer.isView(body)) {
                return body.buffer.slice(body.byteOffset, body.byteOffset + body.byteLength);
            }
            if (['[object Document]', '[object HTMLDocument]', '[object XMLDocument]'].includes(type)) {
                return body.cloneNode(true);
            }
            return String(body);
        }

        if (NativeXHR) {
            const prototype = NativeXHR.prototype;
            const open = prototype.open;
            const send = prototype.send;
            const abort = prototype.abort;
            const setRequestHeader = prototype.setRequestHeader;
            const readyState = Object.getOwnPropertyDescriptor(prototype, 'readyState');
            const credentials = Object.getOwnPropertyDescriptor(prototype, 'withCredentials');
            Object.defineProperty(prototype, 'readyState', {
                ...readyState,
                get() { return xhrs.get(this)?.virtualState ?? readyState.get.call(this); }
            });
            if (credentials) {
                Object.defineProperty(prototype, 'withCredentials', {
                    ...credentials,
                    set(value) {
                        if (xhrs.get(this)?.cancel || xhrs.get(this)?.virtualState === 4) {
                            throw new DOMException('The request has already been sent.', 'InvalidStateError');
                        }
                        credentials.set.call(this, value);
                    }
                });
            }
            prototype.open = function (method, url, ...rest) {
                const old = xhrs.get(this);
                const restoreOpened = old?.virtualState != null && readyState.get.call(this) === 1;
                const value = String(url);
                // open() dispatches readystatechange synchronously; track it before a handler can call send().
                const verb = String(method);
                xhrs.set(this, {url: value, method: verb.toUpperCase(), async: rest[0] === undefined || Boolean(rest[0])});
                try {
                    const result = open.call(this, verb, value, ...rest);
                    old?.cancel?.();
                    if (restoreOpened) {
                        this.dispatchEvent(new browser.Event('readystatechange'));
                    }
                    return result;
                } catch (error) {
                    if (old) {
                        xhrs.set(this, old);
                    } else {
                        xhrs.delete(this);
                    }
                    throw error;
                }
            };
            prototype.setRequestHeader = function (...args) {
                if (xhrs.get(this)?.cancel || xhrs.get(this)?.virtualState != null) {
                    throw new DOMException('The request has already been sent.', 'InvalidStateError');
                }
                return setRequestHeader.apply(this, args);
            };
            function reject(xhr, state, type) {
                state.cancel?.();
                state.cancel = null;
                abort.call(xhr);
                state.virtualState = 4;
                xhr.dispatchEvent(new browser.Event('readystatechange'));
                if (state.hasBody) {
                    xhr.upload.dispatchEvent(new browser.ProgressEvent(type));
                    xhr.upload.dispatchEvent(new browser.ProgressEvent('loadend'));
                }
                xhr.dispatchEvent(new browser.ProgressEvent(type));
                xhr.dispatchEvent(new browser.ProgressEvent('loadend'));
                if (type === 'abort') {
                    state.virtualState = 0;
                }
            }
            prototype.send = function (body) {
                const state = xhrs.get(this);
                if (!state || bypass(state.url)) {
                    return send.apply(this, arguments);
                }
                if (state.sent || this.readyState !== 1) {
                    throw new DOMException('The request cannot be sent in its current state.', 'InvalidStateError');
                }
                if (current() === 'allowed') {
                    state.sent = true;
                    try {
                        return send.apply(this, arguments);
                    } catch (error) {
                        state.sent = false;
                        throw error;
                    }
                }
                if (!state.async) {
                    throw new DOMException('Synchronous XHR cannot wait for HideGuard verification.', 'NotSupportedError');
                }
                const payload = ['GET', 'HEAD'].includes(state.method) ? null : snapshot(body);
                state.sent = true;
                state.hasBody = payload != null;
                // Set before schedule: an already denied request can settle immediately.
                state.cancel = () => {};
                const cancel = schedule(() => {
                    state.cancel = null;
                    try {
                        send.call(this, payload);
                    } catch {
                        reject(this, state, 'error');
                    }
                }, () => reject(this, state, 'error'));
                if (state.cancel) {
                    state.cancel = cancel;
                }
            };
            prototype.abort = function () {
                const state = xhrs.get(this);
                if (state?.cancel) {
                    reject(this, state, 'abort');
                    return;
                }
                if (state?.virtualState != null) {
                    state.virtualState = 0;
                }
                return abort.call(this);
            };
        }

        const jqueryVersions = new WeakSet();
        function attachJQuery(jquery) {
            if (!jquery?.ajaxPrefilter || jqueryVersions.has(jquery)) {
                return;
            }
            jqueryVersions.add(jquery);
            jquery.ajaxPrefilter('+*', (settings, _original, xhr) => {
                // Script/JSONP transports insert a script element and are outside fetch/XHR.
                if (settings.async === false || bypass(settings.url)
                    || settings.dataTypes[0] === 'script' && (settings.crossDomain || settings.scriptAttrs)) {
                    return;
                }
                const duration = Number(settings.timeout);
                if (!(duration > 0)) {
                    return;
                }
                settings.timeout = 0;
                const controller = new browser.AbortController();
                let timer;
                xhr.always(() => {
                    browser.clearTimeout(timer);
                    controller.abort();
                });
                new Promise((resolve, reject) => schedule(resolve, reject, controller.signal)).then(() => {
                    if (xhr.state() === 'pending') {
                        timer = browser.setTimeout(() => xhr.abort('timeout'), duration);
                    }
                }, () => {
                    if (xhr.state() === 'pending') {
                        xhr.abort('abort');
                    }
                });
            });
        }

        installed = {attachJQuery};
        attachJQuery(browser.jQuery);
        browser.addEventListener('hachitech:access-state', () => {
            const state = browser.HideGuardAccess?.getState();
            if (state) {
                change(state.status === 'allowed' && !(state.expiresAt > Date.now()) ? 'pending' : state.status);
            }
        });
        browser.addEventListener('pagehide', () => {
            navigation = true;
            change('error');
        });
        if (status === 'pending') {
            armDeadline();
        }
        return installed;
    }

    browser.HachitechsHideguardNetwork = {install, getGate: () => installed};
})(window);
