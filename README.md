# HideGuard Bot & Checkout Guard for WordPress

[HideGuard Bot & Checkout Guard](https://hideguard.io/) is a WordPress bot
protection and WooCommerce checkout security plugin. It combines signed browser
verification with server-side checks for public pages and supported cart,
checkout, payment, login and registration actions.

## What HideGuard protects

- **Public WordPress pages.** Verify guest and signed-in browsers using Ed25519
  signatures. Confirmed blocks expire after the configured block duration.
- **WooCommerce carts and checkout.** Check supported cart changes and order
  placement through classic checkout and the WooCommerce Store API.
- **Account and payment actions.** Add an API key to enable backend verdict
  checks for supported WordPress and WooCommerce login and registration flows,
  as well as checkout, order creation and payment flows. This includes order
  creation and approval through WooCommerce PayPal Payments.

When protection is enabled, HideGuard temporarily locks interaction on protected
pages while the server verifies access. Page content can remain visible during
this check. Supported WooCommerce cart and checkout actions require a valid browser
pass, and a configured API key adds a separate backend verdict check.

## System requirements

| Requirement | Details |
| --- | --- |
| WordPress | 6.9 or later, single-site installation |
| WooCommerce | Optional, version 10.3 or later for store protection |
| PHP | 8.1 or later in the 8.x series, with `mysqli` and `sodium` |
| Database | MySQL 8 with InnoDB |
| HideGuard | Your site's Public Key and Ed25519 Signature Public Key, plus an optional backend API key |
| Connectivity | HTTPS access to `hideguard.io` from WordPress and visitors' browsers |

The source installation uses Composer for the PHP dependencies listed in
[composer.json](composer.json).

## Install the WordPress plugin

If you have an installable plugin ZIP with bundled dependencies, upload it under
**Plugins → Add Plugin → Upload Plugin**, then activate **Hachitech Hideguard**.

To install from source, copy the plugin to `wp-content/plugins/hachitechs-hideguard/`
and run these commands from that directory.

```sh
composer install --no-dev --prefer-dist
composer assets-check
```

Composer installs the [HideGuard PHP SDK from Packagist](https://packagist.org/packages/hachitechs/hideguard-php)
using the checked-in lock file. Activate **Hachitech Hideguard** in WordPress to
create its database tables.

## Configure HideGuard in WordPress

Open **Settings → Hachitech Hideguard**. Copy your site's keys from the
[HideGuard Dashboard](https://hideguard.io/dashboard/). The SDK and backend API
use `https://hideguard.io`.

| Setting | Purpose |
| --- | --- |
| Public Key | Identifies your site to the browser SDK. |
| Signature Public Key | Verifies browser verdicts on the server. Enter the site's Ed25519 PEM public key. |
| API key | Enables backend action verdicts. Leave the field blank to keep an existing key or select **Remove the saved API key** to remove it. |
| HideGuard recheck interval | Controls how often browsers verify again. Defaults to 5 minutes. |
| Block duration | Controls how long confirmed blocks last. Defaults to 1 hour. |
| Reset block list | Clears active blocks while keeping settings and existing passes. |
| Debug logging | Writes backend verdict diagnostics to the PHP error log. Defaults to off. |

Protection starts disabled. Configure your keys and page-cache rules, then select
**Enable** and save. Check a public page and your site's protected actions in a
guest browser session.

## Cache, upgrades and integrations

- **Page caches and CDNs.** Configure your cache plugin, reverse proxy and CDN to
  bypass protected HTML and verification responses. Purge existing cached pages
  before enabling protection so requests reach WordPress for verification.
- **Upgrade an existing site.** Back up the database, replace the plugin files
  and install the locked dependencies if deploying from source. If WordPress
  reports a HideGuard storage upgrade, let requests and jobs using the previous
  release finish, then run `wp hideguard upgrade` from the WordPress root before
  reopening traffic. Sites already on schema 3 can skip this command.
- **Scheduled cleanup.** Keep WP-Cron running. If your site uses a system scheduler,
  run the `hachitech_hideguard_cleanup` event through WP-CLI.
- **Custom integrations.** Custom endpoints, merchant APIs and payment webhooks
  need their own authorization. Test the checkout and payment methods your store uses.

Learn more about [HideGuard bot protection and checkout security](https://hideguard.io/).
