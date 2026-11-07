// src/interaction-lock.js
function createInteractionLock() {
  var locked = false, gate, changed = /* @__PURE__ */ new Set(), initialBodyLock = document.body.hasAttribute("data-hachitech-ab-inert");
  function sync() {
    if (!locked) {
      changed.forEach(function(element) {
        element.removeAttribute("inert");
      });
      changed.clear();
      return;
    }
    Array.from(document.body.children).forEach(function(element) {
      if (element === gate || element.contains(gate) || element.hasAttribute("inert")) {
        return;
      }
      element.setAttribute("inert", "");
      changed.add(element);
    });
  }
  function setLocked(value, element) {
    locked = value;
    gate = element;
    document.documentElement.classList.toggle("hachitech-ab-locked", locked);
    sync();
  }
  function guard(event) {
    if (locked && !(gate && gate.contains(event.target))) {
      event.preventDefault();
      event.stopImmediatePropagation();
    }
  }
  ["click", "dblclick", "pointerdown", "touchstart", "submit", "keydown"].forEach(function(name) {
    document.addEventListener(name, guard, { capture: true, passive: false });
  });
  new MutationObserver(sync).observe(document.body, { childList: true });
  gate = document.getElementById("hachitech-ab-gate");
  setLocked(initialBodyLock || Boolean(gate && !gate.hidden), gate);
  if (initialBodyLock) {
    document.body.removeAttribute("inert");
    document.body.removeAttribute("data-hachitech-ab-inert");
  }
  return { setLocked };
}

// src/runtime.js
function initialize(config, options = {}) {
  var status = "pending", expiresAt = 0, revision = 0, verificationStarted = false, verificationId = config.verificationId || "", verificationUntil = 0, queue = Promise.resolve(), timer, sdkTimer, gate = document.getElementById("hachitech-ab-gate"), channel, recheck, pageProtected, waiters = [];
  if (!config.enabled || window.HideGuardAccess) {
    return;
  }
  pageProtected = Boolean(config.pageProtected || config.page === "gate");
  const interactionLock = createInteractionLock();
  const $t = options.translate || ((value) => value);
  function updateGate(next, forceGate) {
    var displayState = config.page === "gate" && next === "allowed" ? "pending" : next, messages = {
      pending: [$t("Verifying access"), $t("Please wait for verification before continuing.")],
      blocked: [$t("Access temporarily blocked"), $t("Please try again after the block period.")],
      error: [$t("Unable to verify access"), $t("Access could not be verified. Please try again.")],
      allowed: ["", ""]
    };
    if ((pageProtected || forceGate) && !gate) {
      gate = document.createElement("section");
      gate.id = "hachitech-ab-gate";
      gate.className = "hachitech-ab-gate";
      gate.setAttribute("role", "dialog");
      gate.setAttribute("aria-modal", "true");
      gate.setAttribute("aria-labelledby", "hachitech-ab-heading");
      gate.setAttribute("aria-describedby", "hachitech-ab-description");
      gate.innerHTML = '<div class="hachitech-ab-message"><div class="hachitech-ab-icon" aria-hidden="true"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="M12 3 4 6v5c0 5.2 3.4 8.6 8 10 4.6-1.4 8-4.8 8-10V6l-8-3Z"/><rect x="9" y="10" width="6" height="5" rx="1"/><path d="M10 10V8a2 2 0 0 1 4 0v2"/></svg></div><span class="hachitech-ab-eyebrow"></span><h2 id="hachitech-ab-heading"></h2><p id="hachitech-ab-description" role="status" aria-live="polite"></p><div class="hachitech-ab-guidance"><span class="hachitech-ab-guidance-title"></span><span class="hachitech-ab-guidance-text"></span></div><button type="button" class="hachitech-ab-retry" data-action="retry"></button></div>';
      gate.querySelector(".hachitech-ab-retry").textContent = $t("Try again");
      gate.querySelector(".hachitech-ab-eyebrow").textContent = $t("Protected access");
      gate.querySelector(".hachitech-ab-guidance-title").textContent = $t("What happens next");
      gate.querySelector(".hachitech-ab-guidance-text").textContent = $t("This page will check your access again automatically when the restriction ends.");
      document.body.appendChild(gate);
    }
    if (!gate) {
      return;
    }
    gate.hidden = displayState === "allowed";
    gate.setAttribute("data-page", config.page === "gate" ? "gate" : "");
    gate.setAttribute("data-state", displayState);
    interactionLock.setLocked(displayState !== "allowed", gate);
    gate.querySelector("h2").textContent = messages[displayState][0];
    gate.querySelector("p").textContent = messages[displayState][1];
  }
  function settleWaiter(waiter) {
    if (status === "allowed") {
      waiter.resolve();
    } else {
      waiter.reject(new Error("HideGuard access is " + status + "."));
    }
  }
  function transition(next, forceGate) {
    status = next;
    if (next !== "pending") {
      clearTimeout(sdkTimer);
    }
    updateGate(next, forceGate);
    window.dispatchEvent(new CustomEvent("hachitech:access-state", { detail: { status: next } }));
    if (next !== "pending") {
      waiters.splice(0).forEach(settleWaiter);
    }
  }
  async function request(url, body) {
    var controller = new AbortController(), deadline = setTimeout(function() {
      controller.abort();
    }, 8e3), data = new URLSearchParams(body || {}), response, result;
    if (options.prepareBody) {
      options.prepareBody(data);
    }
    try {
      response = await window.fetch(url, {
        method: "POST",
        credentials: "same-origin",
        signal: controller.signal,
        headers: {
          "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
          Accept: "application/json"
        },
        body: data.toString()
      });
      result = await response.json();
      if (!response.ok && !(response.status === 401 && result.status === "blocked" && result.blocked === true) && !(response.status === 403 && result.status === "verification_failed")) {
        throw new Error(result.error || "Unable to verify access.");
      }
      return result;
    } finally {
      clearTimeout(deadline);
    }
  }
  function sdkFailed() {
    if (status === "pending") {
      handleFailure(revision);
    }
  }
  function navigateToGate(failed, restartGate = false) {
    var destination = failed ? config.deniedUrl : config.gateUrl;
    if (config.page === "gate" && !restartGate) {
      return;
    }
    if (destination) {
      const url = new URL(destination, window.location.href);
      if (url.origin !== new URL(window.location.href).origin) {
        throw new Error("The verification gate must be on the same origin.");
      }
      window.location.replace(url.href);
    } else if (!failed) {
      window.location.reload();
    }
  }
  async function handleFailure(current) {
    if (current !== revision) {
      return;
    }
    expiresAt = 0;
    transition("pending", true);
    clearTimeout(sdkTimer);
    if (config.failUrl && verificationId) {
      try {
        const result = await request(config.failUrl, { verificationId });
        if (current !== revision) {
          return;
        }
        if (result.status === "allowed" || result.status === "blocked") {
          accept(result, current, true);
          return;
        }
      } catch (error) {
      }
    }
    if (current === revision) {
      transition("error", true);
      navigateToGate(true);
    }
  }
  function loadSdk() {
    var script, endpointValue = config.endpointUrl ?? "https://hideguard.io", endpoint;
    if (!config.publicKey.trim()) {
      throw new Error("A Public key is required to verify access.");
    }
    if (typeof endpointValue !== "string" || endpointValue.length > 2048) {
      throw new Error("HideGuard URL must be an HTTPS origin.");
    }
    endpointValue = endpointValue.trim();
    endpoint = new URL(endpointValue);
    if (!/^https:\/\/[^/?#@]+\/?$/i.test(endpointValue) || /[\s\\\x00-\x1f\x7f]/.test(endpointValue) || endpoint.protocol !== "https:" || endpoint.username || endpoint.password || endpoint.pathname !== "/" || endpoint.search || endpoint.hash) {
      throw new Error("HideGuard URL must be an HTTPS origin without a path, credentials, query or fragment.");
    }
    if (document.getElementById("hachitech-hideguard-sdk")) {
      if (config.page === "gate" && config.gateUrl) {
        navigateToGate(false, true);
      } else {
        window.location.reload();
      }
      return;
    }
    script = document.createElement("script");
    script.id = "hachitech-hideguard-sdk";
    script.src = endpoint.origin + "/sdk/v1.js";
    script.dataset.endpoint = endpoint.origin;
    script.dataset.siteKey = config.publicKey;
    script.async = true;
    script.onerror = sdkFailed;
    document.head.appendChild(script);
    sdkTimer = setTimeout(sdkFailed, verificationUntil ? Math.max(1, verificationUntil - Date.now()) : 35e3);
  }
  function acceptAllowed(result, fromVerdict) {
    if (!fromVerdict && !(Number.isInteger(result.passDuration) && result.passDuration > 0)) {
      expiresAt = 0;
      transition("pending");
      loadSdk();
      return;
    }
    expiresAt = result.expiresAt;
    transition("allowed");
    if (config.page === "gate") {
      window.location.replace(config.returnUrl);
    } else {
      timer = setTimeout(recheck, Math.max(1, expiresAt - Date.now()));
    }
  }
  function acceptBlocked(result, fromVerdict) {
    transition("blocked");
    timer = setTimeout(recheck, Math.max(1e3, result.blockedUntil - Date.now()));
    if (fromVerdict && channel) {
      channel.postMessage({ type: "block" });
    }
    if (fromVerdict && config.page === "gate") {
      window.location.reload();
    } else if (config.gateUrl || fromVerdict) {
      navigateToGate(false);
    }
  }
  function accept(result, current, fromVerdict) {
    if (current !== revision) {
      return;
    }
    clearTimeout(timer);
    if (typeof result.verificationId === "string") {
      verificationId = result.verificationId;
    }
    if (result.status === "allowed" && result.allowed === true && Number.isFinite(result.expiresAt) && result.expiresAt > Date.now()) {
      acceptAllowed(result, fromVerdict);
      return;
    }
    expiresAt = 0;
    if (result.status === "blocked" && result.blocked === true && Number.isFinite(result.blockedUntil)) {
      acceptBlocked(result, fromVerdict);
      return;
    }
    if (result.status === "verification_required") {
      verificationUntil = Number.isFinite(result.verificationUntil) ? result.verificationUntil : 0;
      transition("pending");
      loadSdk();
      return;
    }
    if (result.status === "verification_failed") {
      transition("error", true);
      navigateToGate(true);
      return;
    }
    throw new Error("The backend did not confirm access permission.");
  }
  function enqueue(work, current) {
    queue = queue.catch(function() {
    }).then(work).catch(function() {
      if (current === revision) {
        return handleFailure(current);
      }
    });
    return queue;
  }
  recheck = function(retry = false) {
    var current = ++revision;
    verificationStarted = true;
    if (status !== "blocked") {
      transition("pending");
    }
    return enqueue(async function() {
      accept(await request(config.accessUrl, retry ? { retry: "1" } : {}), current, false);
    }, current);
  };
  window.addEventListener("fpc:verdict", function(event) {
    var detail = event.detail || {}, body, current;
    if (!verificationStarted) {
      return;
    }
    try {
      body = {
        verdict: typeof detail.verdict === "string" ? detail.verdict : "invalid",
        visitorId: typeof detail.visitorId === "string" ? detail.visitorId : "",
        response: JSON.stringify({
          signature: detail.signature,
          siteId: detail.siteId,
          environment: detail.environment,
          requestId: detail.requestId,
          visitorId: detail.visitorId,
          verdict: detail.verdict,
          score: detail.score,
          reasons: Array.isArray(detail.reasons) && detail.reasons.length <= 64 ? detail.reasons.map((reason) => ({ code: reason && reason.code })) : null
        })
      };
    } catch (error) {
      body = { response: "" };
    }
    body.verificationId = verificationId;
    current = ++revision;
    expiresAt = 0;
    transition("pending");
    enqueue(async function() {
      accept(await request(config.verdictUrl, body), current, true);
    }, current);
  });
  function guardAction(event) {
    var target = event.target.closest && event.target.closest("a,button,input,form"), form = target && target.closest("form"), href = target && (target.getAttribute("href") || target.getAttribute("formaction")) || form && form.getAttribute("action") || "", url;
    try {
      url = new URL(href, window.location.href);
    } catch (error) {
      return;
    }
    if (!(options.isProtectedAction && options.isProtectedAction(target, url.pathname)) || status === "allowed" && expiresAt > Date.now()) {
      return;
    }
    event.preventDefault();
    event.stopImmediatePropagation();
    transition(status === "allowed" ? "pending" : status, true);
    if (status === "pending") {
      recheck();
    }
  }
  document.addEventListener("click", guardAction, true);
  document.addEventListener("submit", guardAction, true);
  document.addEventListener("click", function(event) {
    const target = event.target.closest && event.target.closest('[data-action="retry"]');
    if (target && gate && gate.contains(target) && status === "error") {
      event.preventDefault();
      recheck(true);
    }
  });
  window.addEventListener("pageshow", function(event) {
    if (event.persisted) {
      window.location.reload();
    }
  });
  try {
    channel = new BroadcastChannel("hachitech-antidetect");
    channel.onmessage = function(event) {
      if (event.data && event.data.type === "block") {
        if (config.page === "gate") {
          window.location.reload();
        } else {
          navigateToGate(false);
        }
      }
    };
  } catch (error) {
  }
  window.HideGuardAccess = {
    getState: function() {
      return { status, expiresAt };
    },
    whenReady: function() {
      if (status === "allowed" && expiresAt > Date.now()) {
        return Promise.resolve();
      }
      if (status !== "pending") {
        return Promise.reject(new Error("HideGuard access is " + status + "."));
      }
      return new Promise(function(resolve, reject) {
        waiters.push({ resolve, reject });
      });
    }
  };
  transition(config.initialState || "pending");
  if (pageProtected && !(config.page === "gate" && config.initialState === "error")) {
    recheck();
  }
  return window.HideGuardAccess;
}

// entry.js
var styleText = "html.hachitech-ab-locked body,\nbody[data-hachitech-ab-inert] {\n  overflow: hidden;\n}\n.hachitech-ab-gate {\n  align-items: center;\n  background: transparent;\n  box-sizing: border-box;\n  color: #182830;\n  display: flex;\n  flex-direction: column;\n  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;\n  inset: 0;\n  justify-content: center;\n  line-height: 1.6;\n  overflow: auto;\n  padding: 24px;\n  pointer-events: auto;\n  position: fixed;\n  text-align: center;\n  visibility: visible !important;\n  z-index: 2147483647;\n}\n.hachitech-ab-gate[hidden] {\n  display: none;\n}\n.hachitech-ab-gate[data-state='blocked'],\n.hachitech-ab-gate[data-page='gate'] {\n  background: #fff;\n}\n.hachitech-ab-gate .hachitech-ab-message {\n  background: #fff;\n  border: 1px solid #e5eaed;\n  border-radius: 24px;\n  box-shadow: 0 20px 64px rgba(24, 40, 48, 0.08);\n  box-sizing: border-box;\n  flex-shrink: 0;\n  margin: auto 0;\n  max-width: 560px;\n  padding: 48px;\n  width: 100%;\n}\n.hachitech-ab-gate .hachitech-ab-icon {\n  align-items: center;\n  background: #fff1e8;\n  border-radius: 20px;\n  color: #aa562e;\n  display: flex;\n  height: 72px;\n  justify-content: center;\n  margin: 0 auto 24px;\n  width: 72px;\n}\n.hachitech-ab-gate .hachitech-ab-eyebrow {\n  color: #63727a;\n  display: block;\n  font-size: 11px;\n  font-weight: 600;\n  letter-spacing: 0.14em;\n  margin-bottom: 12px;\n  text-transform: uppercase;\n}\n.hachitech-ab-gate h2 {\n  color: #182830;\n  font-size: 30px;\n  font-weight: 600;\n  letter-spacing: -0.025em;\n  line-height: 1.25;\n  margin: 0 0 16px;\n}\n.hachitech-ab-gate p {\n  color: #63727a;\n  font-size: 16px;\n  line-height: 1.7;\n  margin: 0;\n}\n.hachitech-ab-gate .hachitech-ab-guidance {\n  background: #f6f8fa;\n  border-radius: 12px;\n  color: #63727a;\n  display: none;\n  font-size: 13px;\n  line-height: 1.7;\n  margin-top: 28px;\n  padding: 20px 24px;\n}\n.hachitech-ab-gate .hachitech-ab-guidance-title {\n  color: #182830;\n  display: block;\n  font-size: 12px;\n  font-weight: 600;\n  margin-bottom: 4px;\n}\n.hachitech-ab-gate[data-state='blocked'] .hachitech-ab-guidance {\n  display: block;\n}\n.hachitech-ab-gate .hachitech-ab-retry {\n  display: none;\n  margin-top: 24px;\n}\n.hachitech-ab-gate[data-state='error'] .hachitech-ab-retry {\n  display: inline-block;\n}\n.hachitech-ab-gate[data-state='pending']:not([data-page='gate']) .hachitech-ab-message {\n  border: 0;\n  box-shadow: none;\n  clip: rect(0, 0, 0, 0);\n  clip-path: inset(50%);\n  height: 1px;\n  margin: -1px;\n  overflow: hidden;\n  padding: 0;\n  position: absolute;\n  white-space: nowrap;\n  width: 1px;\n}\n.hachitech-ab-gate noscript {\n  color: #63727a;\n  font-size: 13px;\n  margin-top: 20px;\n  max-width: 440px;\n}\n@media (max-width: 480px) {\n  .hachitech-ab-gate {\n    padding: 20px;\n  }\n  .hachitech-ab-gate .hachitech-ab-message {\n    border-radius: 20px;\n    padding: 32px 24px;\n  }\n  .hachitech-ab-gate .hachitech-ab-guidance {\n    padding: 16px;\n  }\n  .hachitech-ab-gate h2 {\n    font-size: 26px;\n  }\n}\n";
export {
  initialize,
  styleText
};
