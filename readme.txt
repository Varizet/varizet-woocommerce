=== Varizet for WooCommerce — واریزت ===
Contributors: varizet
Tags: woocommerce, easy digital downloads, payment gateway, card to card, کارت به کارت
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
WC requires at least: 8.5
WC tested up to: 11.2
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Card-to-card payments with automatic confirmation for WooCommerce. پرداخت کارت‌به‌کارت با تایید خودکار برای ووکامرس.

== Description ==

= فارسی =

«واریزت» پرداخت کارت‌به‌کارت را خودکار می‌کند. مشتری مبلغ یکتای سفارش را از اپ بانک خودش به کارت فروشگاه واریز می‌کند؛ واریزت پیامک یا اعلان بانک را تشخیص می‌دهد و سفارش ووکامرس در همان چند ثانیه تایید می‌شود. نیازی به ارسال رسید یا بررسی دستی نیست.

* **وب‌هوک امضاشده** (HMAC-SHA256 با پنجره ۵ دقیقه) برای تغییر آنی وضعیت سفارش، به‌علاوه **همگام‌سازی پشتیبان هر ۲ دقیقه** با Action Scheduler که سفارش‌های باز را با شناسه سفارش (نه بازه تاریخ) بررسی می‌کند.
* **ویجت وضعیت زنده** در صفحه تشکر: مبلغ یکتا با رقم‌های کهربایی، کارت مقصد با دکمه کپی، شمارش معکوس و «پرداخت تایید شد» بدون رفرش.
* **محیط تست (Sandbox)** با کلید جدا.
* سازگار با **HPOS** و **تسویه حساب بلوکی** و کلاسیک.
* ستون و فیلتر «واریزت» در فهرست سفارش‌ها، جعبه واریزت در صفحه سفارش با امتیاز اطمینان و تایم‌لاین، همگام‌سازی دستی و دسته‌جمعی.
* صفحه **«وضعیت سیستم»** با بررسی API، وب‌هوک، صف، نسخه‌ها و نمایشگر لاگ، و تست‌های «سلامت سایت».
* پشتیبانی از ریال و تومان (IRR، IRT و هزار ریال/تومان)؛ مبلغ همیشه به‌صورت ریال صحیح به واریزت ارسال می‌شود.
* اگر ووکامرس سفارش پرداخت‌نشده را لغو کرده باشد و مشتری بعداً پرداخت کند، سفارش بازگشایی و هشدار موجودی ثبت می‌شود.

= English =

Varizet automates card-to-card payments. The customer transfers the order's unique amount from their own bank app to the store's card; Varizet detects the bank SMS or notification and the WooCommerce order is confirmed within seconds.

* Signed webhooks (HMAC-SHA256, 5-minute window) plus a 2-minute Action Scheduler fallback that checks open orders by id, never by date range.
* Live thank-you widget with the unique amount, the destination card, a countdown and instant confirmation.
* Test (Sandbox) environment with its own key.
* HPOS, Checkout block and classic checkout.
* Orders list column and filter, order meta box with match score and timeline, manual and bulk sync.
* System status page and Site Health tests, with a redacting log viewer.
* Rial and toman stores (IRR, IRT, IRHR, IRHT); amounts are always sent as integer rial.

= External service =

This plugin connects to the Varizet service (https://varizet.ir) to create payment requests and receive their status:

* `https://api.varizet.ir/v1/orders` — creates and reads payment requests with your API key (order total in rial, order number, customer name, mobile and email when present, return URL).
* `https://api.varizet.ir/pay/v1/checkouts/{token}` — reads the public payment status shown on the thank-you page.
* Varizet calls `https://your-site/wp-json/varizet/v1/webhook` with signed order events.

Terms: https://varizet.ir/terms — Privacy: https://varizet.ir/privacy

== Installation ==

1. Install and activate WooCommerce, then this plugin (Plugins → Add New → Upload).
2. Open **Varizet → Connection** and connect the store. Until one-click connect is available, paste an API key from the Varizet panel (Developers → API keys, scopes `orders:read` and `orders:write`).
3. In the Varizet panel add a webhook endpoint with the URL shown on the connection page and the order events, and paste its secret on the connection page.
4. Review **Varizet → Gateway settings**: environment, unique amount mode, payment window, last 4 digits, status after payment, display mode.
5. Check **Varizet → System status**: every check should be green.

== Frequently Asked Questions ==

= آیا مشتری به درگاه بانکی منتقل می‌شود؟ =

خیر. در حالت «پرداخت درون‌صفحه‌ای» مشتری در صفحه تشکر سایت شما می‌ماند و از اپ بانک خودش واریز می‌کند. در حالت ریدایرکت به صفحه پرداخت `pay.varizet.ir` با نام و لوگوی فروشگاه شما می‌رود.

= Does it work if my server misses a webhook? =

Yes. Every 2 minutes Action Scheduler re-reads open orders by their Varizet id until they settle. You can also press «همگام‌سازی وضعیت» on an order or use the bulk action.

= What happens if WooCommerce cancels an unpaid order and the customer pays later? =

The order is reopened, marked paid, and a stock warning is added to the order. While a Varizet payment is still in its window or grace period, WooCommerce's unpaid-order cancellation waits.

= Which currencies are supported? =

Iranian rial (IRR), toman (IRT) and the thousand-rial / thousand-toman units some Persian plugins add (IRHR, IRHT).

= Is card data stored? =

No. Only the last 4 digits the customer optionally types and the last 4 digits Varizet matched are stored. Keys and webhook secrets are encrypted with your site's salts. Logs are redacted.

== Screenshots ==

1. Connection page.
2. Gateway settings with connection health and live preview.
3. Checkout block.
4. Thank-you page: live payment widget.
5. Thank-you page: payment confirmed.
6. Order meta box.
7. Orders list with the Varizet column.
8. System status.

== Changelog ==

= 1.1.0 =
* Easy Digital Downloads 3: a second «Varizet» gateway sharing the connection, webhook and polling; works with or without WooCommerce. EDD test mode uses the Varizet test key.

= 1.0.0 =
* First release: gateway for classic and block checkout, signed webhooks, Action Scheduler fallback, live thank-you widget, order screens, system status, Site Health, fa_IR translation.

== Upgrade Notice ==

= 1.1.0 =
Adds Easy Digital Downloads support. WooCommerce behaviour is unchanged.

= 1.0.0 =
First release.
