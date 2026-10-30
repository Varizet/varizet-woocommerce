# Changelog

All notable changes to «واریزت برای ووکامرس» / Varizet for WooCommerce. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the plugin uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.1.0] - 2026-10-29

### Added

- Easy Digital Downloads 3 support (ADR-0025): gateway «واریزت» (`src/Edd/`), pending order →
  `POST /v1/orders` (`Idempotency-Key: edd-{order}-{attempt}-{site8}`) → payment page; the success
  page syncs once; webhooks reach EDD orders through the same `/wp-json/varizet/v1/webhook`
  (`EddSync::dispatch` when no WooCommerce order matches); a 5-minute WP-Cron poll; status mapping
  in `EddStatusMapper` (unit tested). EDD test mode selects the Varizet test key.
- The plugin also boots on EDD-only sites (connection page, webhook route, Site Health); admin
  screens use `manage_shop_settings` there.

### Changed

- No longer `Requires Plugins: woocommerce` (WooCommerce or EDD is enough).
- `Varizet-Plugin` reports the connected client (`woocommerce` or `edd`) and the EDD version;
  one-click connect sends `client=edd` when WooCommerce is inactive.

### Fixed

- Store-currency → rial conversion rounded twice when a total had more than four decimals
  (`1000.49999` IRR became 1001 rial); the fraction is now truncated to 12 digits and rounded once,
  half up (`Support\Amount`).

## [1.0.0] - 2026-10-28

### Added

- Payment gateway «کارت‌به‌کارت با تایید خودکار — واریزت» for the classic checkout and the
  Checkout block (plain JS, no build step), with HPOS and Blocks compatibility declared.
- Varizet orders created with `POST /v1/orders`, integer rial converted from IRR, IRT, IRHR or IRHT,
  and an `Idempotency-Key` per payment attempt (`wc-{order}-{attempt}-{site}`). «پرداخت مجدد» reuses
  an open request or moves to a new attempt (expired request, changed total, 24 h old key).
- Signed webhooks at `POST /wp-json/varizet/v1/webhook`: constant-time HMAC-SHA256 over
  `t.body`, ±300 s window, live/test secrets, rotation (several `v1=`), per-IP rate limit,
  idempotent per event id, out-of-order and regression guards.
- Polling fallback with Action Scheduler every 2 minutes, by Varizet order id through a
  `meta_query` that works on HPOS and the posts table, with no date filters.
- Status mapping per docs/10 §1.4, including reopening an order WooCommerce cancelled (hold stock)
  when it is paid later, with a stock warning. Unpaid-order cancellation waits for the grace period.
- Live thank-you widget (unique amount with amber digits, destination card with copy, countdown,
  live steps, «پرداخت تایید شد» without reload) through a same-origin proxy to
  `/pay/v1/checkouts/{token}/status`.
- Order meta box (status, unique amount, card, link, match score, timeline, manual sync), a
  «واریزت» column, filter and bulk sync on the orders list.
- «واریزت» admin: connection (manual API key + webhook secret; one-click PKCE connect behind a
  feature flag), gateway settings with health and preview cards, «وضعیت سیستم» with checks, log
  viewer and system report, and WordPress Site Health tests.
- Redacting logger (keys, secrets, card numbers, IBANs, mobiles, payment tokens) on `WC_Logger`.
- Persian (fa_IR) translation; English source strings.
- `uninstall.php`: always removes keys; removes settings and order metadata only when chosen.
