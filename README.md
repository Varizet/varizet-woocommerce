# Varizet for WooCommerce — واریزت

<div dir="rtl">

افزونه وردپرس «واریزت» پرداخت کارت‌به‌کارت را برای **ووکامرس** و **Easy Digital Downloads** خودکار می‌کند. مشتری مبلغ یکتای سفارش را از اپ بانک خودش به کارت فروشگاه واریز می‌کند؛ واریزت پیامک یا اعلان بانک را تشخیص می‌دهد و سفارش در همان چند ثانیه تایید می‌شود. نیازی به ارسال رسید یا بررسی دستی نیست.

- وب‌هوک امضاشده (HMAC-SHA256 با پنجره ۵ دقیقه) و همگام‌سازی پشتیبان هر ۲ دقیقه با Action Scheduler
- ویجت وضعیت زنده در صفحه تشکر: مبلغ یکتا، کارت مقصد با دکمه کپی، شمارش معکوس و «پرداخت تایید شد» بدون رفرش
- محیط تست (Sandbox) با کلید جدا
- سازگار با HPOS، تسویه‌حساب بلوکی و کلاسیک، و چندسایته
- ستون و فیلتر «واریزت» در فهرست سفارش‌ها، جعبه واریزت در صفحه سفارش، همگام‌سازی دستی و دسته‌جمعی
- صفحه «وضعیت سیستم» و تست‌های «سلامت سایت»
- ریال، تومان، هزار ریال و هزار تومان؛ مبلغ همیشه به‌صورت ریال صحیح به واریزت فرستاده می‌شود

</div>

Card-to-card payments with automatic confirmation for WooCommerce and Easy Digital Downloads. The
customer transfers the order's unique amount from their own bank app to the store's card; Varizet
detects the bank SMS or notification and the order is confirmed within seconds.

**Requirements:** PHP 8.1+, WordPress 6.4+, WooCommerce 8.5+ (or Easy Digital Downloads 3). No
Composer at runtime.

## Install

1. Download `varizet-woocommerce-<version>.zip` from the
   [releases](https://github.com/Varizet/varizet-woocommerce/releases) page.
2. WordPress → Plugins → Add New → Upload Plugin, then activate.
3. **Varizet → Connection**: paste an API key from the Varizet panel (Developers → API keys, scopes
   `orders:read` and `orders:write`).
4. In the Varizet panel, add a webhook endpoint with the URL shown on the connection page
   (`https://your-site/wp-json/varizet/v1/webhook`) and the order events, then paste its signing
   secret on the connection page.
5. Review **Varizet → Gateway settings** (environment, unique amount mode, payment window, status
   after payment, display mode) and check that **Varizet → System status** is all green.

Use a test key (`vz_test_…`) first: the Varizet sandbox simulates deposits without real money.

## External service

The plugin talks to [Varizet](https://varizet.ir) to create payment requests and read their status:

- `https://api.varizet.ir/v1/orders`: creates and reads payment requests with your API key (order
  total in rial, order number, customer name, mobile and email when present, return URL).
- `https://api.varizet.ir/pay/v1/checkouts/{token}`: the public payment status shown on the
  thank-you page.
- Varizet calls `https://your-site/wp-json/varizet/v1/webhook` with signed order events.

Terms: https://varizet.ir/terms · Privacy: https://varizet.ir/privacy

## How it works

- **Checkout:** the order total becomes integer rial (IRR ×1, IRT ×10, IRHR ×1 000, IRHT ×10 000,
  string maths). The attempt number is stored *before* the API call, and every create carries
  `Idempotency-Key: wc-{order_id}-{attempt}-{site8}`, so a retry never creates a second payment. An
  open request with the same amount is reused.
- **Webhook:** rate limit, then `Varizet-Signature` verification against the live and test secrets
  (constant time, ±300 s, secret rotation), then the single writer `OrderSync`. It takes a lock,
  dedupes the event and refuses regressions.
- **Polling fallback:** every 2 minutes Action Scheduler re-reads open orders by their Varizet id
  (never by date range) until they settle.
- **Status mapping:**

  | Varizet | WooCommerce |
  | --- | --- |
  | awaiting | pending |
  | needs_review / underpaid | on-hold, with a note |
  | paid | `payment_complete()`, then processing or completed (setting) |
  | expired | cancelled with restock, or failed (setting) |
  | paid on a cancelled order | reopened, with a stock warning |
  | refunded | refunded |

  Settled states never regress.
- **Privacy:** no card data is stored. Only the last 4 digits are kept: the ones the customer
  optionally types and the ones Varizet matched. Keys and secrets are encrypted with the site's
  salts, and logs are redacted.

```
varizet-woocommerce.php   bootstrap: constants, autoloader, HPOS + Blocks compatibility
src/Api/                  wp_remote client: retries with backoff + jitter, Idempotency-Key, Varizet-Plugin header
src/Gateway/              WC_Payment_Gateway, PaymentStarter, Checkout block support
src/Webhook/              SignatureVerifier (pure) and the REST controller
src/Order/                StatusMapper (pure), OrderSync (single writer), meta box, list column
src/Sync/                 Action Scheduler polling
src/Edd/                  Easy Digital Downloads gateway sharing the connection
src/ThankYou/             live status widget + same-origin status proxy
src/Admin/ src/Health/    settings pages, system status, Site Health
src/Support/              Amount, Format, Redactor, Logger, Crypto, Lock, IdempotencyKey
templates/ assets/ languages/   widget markup, scoped CSS/JS, fa_IR translation
```

## Development

```bash
composer install
vendor/bin/phpunit          # pure classes: signature, status mapping, amounts, idempotency, …
vendor/bin/phpcs            # WordPress coding standard
# without a local PHP:
# docker run --rm -v "$PWD":/app -w /app composer:2 sh -c 'composer install && vendor/bin/phpunit'
```

A local WordPress + WooCommerce runs in Docker; the plugin folder is mounted, so edits are live:

```bash
docker compose -p wptest -f dev/docker-compose.yml up -d
dev/setup.sh                          # WordPress fa_IR, WooCommerce (IRR), sample products, activates the plugin
open http://localhost:8090/wp-admin   # admin / admin
```

Point it at the sandbox with `VARIZET_API_BASE_URL=https://api.varizet.ir` and a test key.
`bin/build-zip.sh` builds `dist/varizet-woocommerce-<version>.zip` and its `.sha256`.

---

## Contributing and security

Issues and pull requests are welcome. This repository is published from Varizet's main repository:
accepted changes are applied there and appear here with the next release.

Please report security vulnerabilities privately to support@varizet.ir, not in public issues.

## License

GPL-2.0-or-later, see [LICENSE](LICENSE).
