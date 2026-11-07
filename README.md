# Hachitechs HideGuard for WordPress

Uses **hachitechs/hideguard-php ^1.0** (locked to **v1.0.0**). Plugin **1.5.0**. Adapted from Hachitech_AntidetectBrowser
and its WordPress persistence/runtime adapter. Supports WordPress **6.9+**,
PHP **8.1+** with mysqli/sodium, MySQL **8.0/InnoDB**, single-site installations.
WooCommerce integration targets **10.3+**.

## Install

Place this folder at wp-content/plugins/hachitechs-hideguard, then run:

    composer install --no-dev
    php bin/publish-assets.php

For an existing installation, follow [the 1.5.0 upgrade procedure](docs/PACKAGE-1.0-UPGRADE.md)
before reopening traffic. Schema migration is explicit; normal verification requests
never create tables. The package and WordPress adapter must be deployed together.

Activate it in Plugins. The private dependency needs GitHub read access through
Composer authentication or SSH. The core uses a source install for the private
repository; public PSR dependencies use dist archives. Runtime does not depend on Magento files.
To create an installable ZIP when needed, run `php bin/build-zip.php`.
It bundles production dependencies and the prebuilt browser runtime; no ZIP is
generated automatically by Composer or normal development commands.

## Settings

Open **Settings → HideGuard** or the plugin's Settings link.

Protection starts **disabled**, matching Magento defaults:

- Testing (`development`) uses `https://dev.hideguard.io`; Production uses
  `https://hideguard.io`. Each has its own SDK public/site key, backend API key
  and Ed25519 PEM signature public key. The origins are fixed.
- All public pages and order completion are always protected when enabled.
  Legacy URL/access-point fields and posted overrides cannot narrow this scope.
- Legacy Staging requires an explicit switch to Testing or Production with the
  corresponding host's keys; saved Staging credentials are retained for rollback.
- Additional paths: relative to the home URL, one per line, with * wildcards;
  maximum 50 rules and 4096 bytes.
- Block duration: 15 minutes, 1 hour (default), 6 hours or 24 hours.
- Recheck interval: Always, 1 minute, 5 minutes (default), 15 minutes or 1 hour.
- Reset block list: clears current-site blocks, preserving settings and passes.

Every enabled environment verifies Ed25519 signatures, including Development.
A valid signature public key is required; no private key should be configured.
Only the active SDK key and endpoint are published. Changing protection settings
revokes passes without removing blocks. Changing only debug logging preserves passes.

## Flow and WooCommerce

Protected page GETs start/reuse a fixed 35-second server verification window.
Pending public content remains visible under a transparent interaction lock.
The shared runtime preflights before loading the SDK. Timed passes skip the SDK.
Signed results and a separate attempt ID are verified on the server. Allow
grants a fixed lifetime; Block returns 401/Retry-After without original content.
Repeated reports cannot extend bans. SDK failures show a 403 gate with explicit
retry. Infrastructure failures return 503.

The signed HttpOnly/SameSite browser cookie binds guest CSRF tokens to the browser.
Authenticated REST also uses the native wp_rest nonce. Requests are bounded to
64 KiB; decoded SDK payloads to 16 KiB. Gate return URLs must use the site's origin.

WooCommerce integration validates classic/AJAX adds, WC_Cart adds before insertion,
classic checkout validation/order creation, and Store API cart/checkout writes
(including batch subrequests). Native cart/customer identity is selected by the
server and bound to the verified browser grant. Order creation consumes the pass.
Admin editing, cron and WP-CLI preserve native authorization. Merchant REST APIs,
custom endpoints and third-party payment handlers need their own authorization
and, where applicable, Guard resource assertions.

The plugin also works without WooCommerce for ordinary page protection.

## Backend action verdicts

Enter a **Backend API key** in the active environment under Settings → HideGuard.
This activates server checks for the six events registered in
`ActionEvents::EVENTS`: payment, checkout, create_order, add_to_cart, login and
register. Without a backend API key, the existing page/SDK protection continues.
Keys are stored only in the server-side option; the password fields never render
saved keys. Leave a field blank to retain its key, or explicitly choose Remove.

For an actionable request, the browser must already have a valid signed SDK
verification. The server obtains visitorId from that stored verification and calls
`Hachitechs\HideguardPhp\VerdictClient::getVerdict()` with the environment's key and
HTTPS origin. It sends only visitorId to `/api/v1/verdict`, never passwords,
payment fields or arbitrary visitor IDs supplied by the action request.
The shared `PrivateVerdictChecker` validates the top-level response: matching
visitorId, known verdict, safe nonnegative integer score and at most 64 reason
objects with protocol-token codes. A supplied environment must also match. Example:

```json
{"visitorId":"a313d75f78dc6dabb6e5138cbf341fe4","verdict":"block","score":100,"source":"visit","reasons":[]}
```

Only `block` creates a timed ban. It goes through the same Guard/storage,
resource revocation, fixed deadline, 401/Retry-After and existing frontend gate as
SDK blocks. Allow does not create or extend a pass. HTTP, malformed-response and
identity errors stop the action without inventing a timed ban. An existing ban
skips the API. Overlapping checkout/order/payment hooks share one lookup per
verified identity in that PHP request. PayPal order flows also reuse approval across
HTTP requests as described below; unrelated later requests fetch a fresh verdict.

Native checkout signup can carry the already verified guest pass to the exact
new customer authenticated in that request, preserving its deadline. Order
creation still consumes the persisted grant and cancels its SDK attempt immediately. A checked payment
continuation normally stays in that same PHP request. The PayPal continuation below
may finish that exact unpaid order across HTTP requests; neither can authorize
another order or restore a browser pass.

Native hooks cover WordPress and WooCommerce login/registration, classic cart
and checkout, existing-order payment and versioned/unversioned Store API routes,
including batch dispatch. Store API checkout GET/HEAD is also checked before it
can create/update a draft order. WordPress login has its own module/CSS bridge;
native AJAX/fetch results are preserved while a nonce-bound state read moves
persisted blocks to the existing gate. Ordinary WordPress login/registration
does not require WooCommerce. Application passwords, merchant/admin APIs,
cron/CLI and third-party custom action handlers retain their own authorization.

## Debug logging

Under **Settings → HideGuard → Debug logging**, enable **Log backend verdict
requests** and save. It defaults to off. Each actual backend `/api/v1/verdict`
attempt writes one JSON line prefixed `[HideGuard debug]` to PHP's error log,
independently of WP_DEBUG. On this local Docker stack, view it with:

```sh
docker compose logs --tail=200 -f php 2>&1 | grep '\[HideGuard debug\]'
```

Fields include a request ID, the first triggering event, environment, method/URL,
a site-salted visitor hash, HTTP status, HTTP duration in milliseconds, known
API verdict and the guard check outcome (`allowed`, `blocked`, `rejected`).
`allowed` describes this guard check; a later native action can still fail.
Transport/validation failures use fixed error codes. A timeout has no HTTP status.
The API verdict and outcome are separate: a stale API Block can be rejected
without applying a ban. Raw bodies, headers, API keys, raw visitor IDs, passwords,
payment fields and provider reason details are never logged.

Cached checks, existing bans, unverified visitors and disabled backend checks
produce no API request log. Logging requires a configured backend API key and
an actual request. Turning the switch off stops future entries; existing entries
remain subject to the server's log retention. The PHP/server configuration
controls the log destination and rotation; no public plugin log file is created.

## Storage and cache

Explicit activation/`wp hideguard upgrade` creates five dedicated dbDelta/InnoDB
tables: wp-prefix plus hachitech_session, hachitech_block, hachitech_rate,
hachitech_control and hachitech_circuit. Writes use dedicated wpdb connections,
browser/resource mutexes and a shared/exclusive scope fence. Reports/revocations
retain the exclusive fence; admission still serializes on the scope quota row.
Lost connections cannot reconnect outside the transaction. Deactivation retains
settings and security data.

Session capacity uses a transactional counter after initial seeding. Repair it
with `wp hideguard sessions reconcile` after draining writers. Cleanup deletes
at most 500 rows per batch, up to 10 batches per cron run, releasing the fence
between batches. Active attempts, grants and blocks are preserved.

Backend verdict requests use a shared MySQL circuit: 5 consecutive transport,
429 or 5xx failures in 60 seconds open it for 30 seconds, followed by one leased
15-second recovery probe. It never retries HTTP or uses cached Allow on errors.
A valid Block response is not an infrastructure failure. The HTTP timeout remains
10 seconds, below the probe lease. Circuit state has its own DB connection;
no Guard/circuit transaction is held during HTTP.

Hourly cleanup uses WP-Cron. If WP-Cron is disabled, run the
hachitech_hideguard_cleanup event through the deployment scheduler or WP-CLI.

Responses are private/no-store and protection sets DONOTCACHEPAGE. **Bypass and
purge protected HTML in page caches, CDN and reverse proxy before enabling it.**
A cached response delivered before plugins run cannot be intercepted. Verify cache bypass on the target deployment before enabling protection.

Apache .htaccess denies direct access to source, dependencies, tests and builds.
Add equivalent rules for an Nginx-only server; assets/ must remain public.

## Development

    composer install
    php bin/publish-assets.php --check
    composer lint
    HG_WP_ROOT=/path/to/disposable/wordpress composer test
    php bin/build-zip.php

Tests require an isolated installed WordPress/WooCommerce fixture and refuse
databases outside hg_wp_test_* or URLs other than http://127.0.0.1:18889.
tests/install.php refuses an existing wp-config.php or a nonempty database.
Use HG_WP_DB_HOST/NAME/USER/PASSWORD and HG_TEST_ADMIN_PASSWORD for installation.
Tests use generated signing keys and never contact HideGuard.

The ZIP bundles native core/PSR namespaces for this integration. Sites sharing
Composer dependencies must resolve compatible versions; test collisions before
distribution to unrelated plugin ecosystems.
PSR Log is constrained to 1.1, supported by the core and already used by the
site's existing unscoped WordPress plugin dependencies.

## Rename compatibility (1.2.0)

The plugin folder/main file is `hachitechs-hideguard/hachitechs-hideguard.php`.
The adapter namespace is `Hachitechs\HideguardWordPress`; the core uses
`Hachitechs\HideguardPhp` from `hachitechs/hideguard-php`.
Existing `hachitech_hideguard` settings, `hachitech_` database tables, browser
cookies, REST routes, scheduled hooks and frontend identifiers remain compatible.
For another existing site, deactivate the old plugin, replace its directory with
this release and activate Hachitechs HideGuard. Do not run both copies together.

## Package v0.1.7 compatibility (plugin 1.2.1)

Scope/identity/resource revocation clears verification IDs and deadlines as well
as grants/bindings, retaining blocks. Late reports cannot finish cancelled attempts.
Same-order payment continuation retains its prior API approval only in the current
PHP request after consumption; it requires the original identity/policy/key and
deadline, no active ban and no newly started SDK attempt. It does not restore a pass.

The WordPress transport streams at most 64 KiB of encoded data to a private temporary
file and bounds gzip/deflate decoding to 64 KiB. Both cURL and Fsockopen stop oversized
responses; temporary files and per-request hooks are released on success or failure.
Policy fingerprint v4 requires existing page grants to be verified again; block
deadlines are retained and no schema migration is needed.

## PayPal order flows (1.3.0)

WooCommerce PayPal Payments 3.2.1 is checked at its native
`woocommerce_paypal_payments_create_order_request_started` and
`woocommerce_paypal_payments_approve_order_request_started` hooks, after native
nonce validation and before PayPal API calls or direct Woo order creation. These
hooks are registered in `ActionEvents::EVENTS`. The backend uses the same
`VerdictClient`, private-response validation and shared Guard blocking flow.

A successful verdict is stored server-side for this browser/visitor, actor,
cart contents/prices/coupons/currency, SDK attempt, policy and API-key hash.
PayPal-first, Woo-first and Store API draft-first flows reuse that approval;
o additional HideGuard request is needed in the same valid order flow. Native
PayPal order-created hooks bind the remote ID, and native Woo hooks bind the WC
order ID. After cart grant consumption, only that exact unpaid WC order can
continue. Approval never extends the original SDK deadline or restores its grant.
Expiry, changed bindings, another remote/native order or a new create attempt
require fresh verification. Native checkout signup can carry its proof only to
the customer it just created.

An in-flight lookup reserves the flow under the existing atomic storage lock;
HTTP runs outside that lock. Concurrent requests receive a retryable busy error
rather than issuing duplicate lookups. API failures stop processing and release
the claim. Block stops the native endpoint before PayPal/order/payment side
effects and returns HTTP 401. The frontend observes both PayPal endpoints and
reads the existing persisted Guard state before redirecting to the existing
Block page. PayPal's temporary `ppc-simulate-cart` does not trigger a business
add-to-cart verdict; actual cart mutations retain their checks.

Schema version 2 adds nullable PayPal flow metadata and a revocation counter to
the existing session table. The original 1.3.0 upgrade ran on plugin load. In 1.5.0, the explicit schema 3
upgrade includes these columns and preserves settings and grants. Scope/identity/resource
revocations clear flow approval; the counter prevents a concurrent revocation
from being undone during order consumption. No PayPal credentials or raw API
keys are stored in flow metadata. No ZIP is generated automatically.


## Request queue while verifying (1.4.0)

Protected pages now install a synchronous bootstrap before WordPress widget
scripts. Page HTML, styles, browser-loaded scripts/images/fonts and component
initialization continue normally; main-window fetch/async XHR wait for backend
Allow, then send once. Static files explicitly fetched by JS also wait. Block or
verification failure cancels queued work and uses the existing gate. Backend
authorization and PayPal order-flow approvals are unchanged.

The queue bypasses HideGuard verification endpoints, the configured SDK origin
and the SDK SYN origin. Native WordPress jQuery keeps its jqXHR callbacks/abort,
with network timeout starting after release. No per-widget action/URL allowlist
is used. Disabled/exempt pages do not install the queue; in 1.5.0 all public pages are protected.

See [feasibility, contracts and limits](docs/REQUEST-GATE.md): the scope excludes
iframe/worker transports and synchronous XHR while pending. Caller AbortSignal
still applies. A separate SDK origin and early bootstrap ordering are required.
No ZIP is generated automatically.


## Package 1.0 and high-traffic adapter (1.5.0)

Two-mode settings and deployment-only limits match the current Magento criteria.
The native WordPress storage implements partitioned mutations, transactional
session counting and bounded cleanup; backend verdict transport uses shared
circuit state. SDK assets match the installed v1.0.0 package byte-for-byte.

See [criteria, migration/rollback, limits and test evidence](docs/PACKAGE-1.0-UPGRADE.md).
Magento's managed Varnish snapshot protocol requires a separate WordPress/proxy
design; this release retains private/no-store HTML and page-cache bypass.
