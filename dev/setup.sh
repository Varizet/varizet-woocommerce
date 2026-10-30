#!/usr/bin/env bash
# Installs WordPress + WooCommerce (fa_IR, IRR) and activates the plugin in the wptest stack.
# Idempotent: safe to run again. Downloads from wordpress.org (long timeouts).
set -euo pipefail
cd "$(dirname "$0")"
COMPOSE=(docker compose -p wptest -f docker-compose.yml)
wp() { "${COMPOSE[@]}" run --rm -T cli wp --path=/var/www/html "$@" 2> >(grep -v -E "Container|^ *$" >&2); }
# "Could not update option" only means the value was already set.
opt() { wp option update "$@" >/dev/null 2>&1 || true; }

echo "== waiting for WordPress files"
for _ in $(seq 1 60); do
  "${COMPOSE[@]}" exec -T wordpress test -f /var/www/html/wp-config.php && break
  sleep 2
done

if ! wp core is-installed 2>/dev/null; then
  wp core install --url=http://localhost:8090 --title="کتاب‌نما" \
    --admin_user=admin --admin_password=admin --admin_email=admin@example.test --skip-email
fi

opt timezone_string Asia/Tehran
wp rewrite structure '/%postname%/' --hard
wp language core install fa_IR --activate || echo "warn: fa_IR core language download failed"

wp plugin is-installed woocommerce || WP_CLI_HTTP_TIMEOUT=600 wp plugin install woocommerce
wp plugin activate woocommerce
wp language plugin install woocommerce fa_IR || echo "warn: WooCommerce fa_IR download failed"

# Store: Iran, rial, no decimals, guest checkout, no onboarding / coming-soon.
opt woocommerce_default_country "IR:THR"
opt woocommerce_store_address "خیابان ولیعصر"
opt woocommerce_store_city "تهران"
opt woocommerce_store_postcode "1598765432"
opt woocommerce_currency IRR
opt woocommerce_currency_pos right_space
opt woocommerce_price_num_decimals 0
opt woocommerce_allowed_countries specific
opt woocommerce_specific_allowed_countries '["IR"]' --format=json
opt woocommerce_ship_to_countries disabled
opt woocommerce_enable_guest_checkout yes
opt woocommerce_checkout_phone_field required
opt woocommerce_coming_soon no
opt woocommerce_onboarding_profile '{"skipped":true}' --format=json
opt woocommerce_task_list_hidden yes
wp wc tool run install_pages --user=admin >/dev/null || true

if [ -z "$(wp post list --post_type=product --name=sea-house --field=ID)" ]; then
  wp wc product create --user=admin --name="خانه‌ای کنار دریا" --slug=sea-house --type=simple \
    --regular_price=8500000 --manage_stock=true --stock_quantity=10 \
    --short_description="رمان، جلد سخت" --porcelain
fi
if [ -z "$(wp post list --post_type=product --name=leather-notebook --field=ID)" ]; then
  wp wc product create --user=admin --name="دفتر یادداشت چرمی" --slug=leather-notebook --type=simple \
    --regular_price=2400000 --manage_stock=true --stock_quantity=1 --porcelain
fi

wp plugin activate varizet-woocommerce
echo "== ready: http://localhost:8090/wp-admin (admin / admin)"
