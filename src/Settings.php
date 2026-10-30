<?php
/**
 * Typed access to the gateway settings and plugin-wide options.
 *
 * @package Varizet\WooCommerce
 */

namespace Varizet\WooCommerce;

use Varizet\WooCommerce\Support\IdempotencyKey;

defined( 'ABSPATH' ) || exit;

/**
 * Gateway settings live in `woocommerce_varizet_settings` (managed by WC_Settings_API); this
 * class reads them with defaults and validation so no other class touches raw option arrays.
 */
final class Settings {

	public const OPTION         = 'woocommerce_varizet_settings';
	public const INSTALL_ID     = 'varizet_install_id';
	public const DEFAULT_API    = 'https://api.varizet.ir';
	public const DEFAULT_APP    = 'https://app.varizet.ir';
	public const EXPIRY_CHOICES = array( 900, 1800, 3600, 7200, 86400 );

	/**
	 * Cached settings for this request.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $cache = null;

	/**
	 * Raw value with a default.
	 *
	 * @param string $key      Setting key.
	 * @param mixed  $fallback Default.
	 * @return mixed
	 */
	public static function get( string $key, $fallback = null ) {
		if ( null === self::$cache ) {
			$value       = get_option( self::OPTION, array() );
			self::$cache = is_array( $value ) ? $value : array();
		}
		return array_key_exists( $key, self::$cache ) ? self::$cache[ $key ] : $fallback;
	}

	/**
	 * Drops the per-request cache (after saving).
	 */
	public static function flush(): void {
		self::$cache = null;
	}

	/**
	 * Gateway enabled.
	 */
	public static function enabled(): bool {
		return 'yes' === self::get( 'enabled', 'no' );
	}

	/**
	 * Active environment: "live" or "test".
	 */
	public static function environment(): string {
		if ( ! Platform::has_wc() && Platform::has_edd() ) {
			return Edd\EddGateway::mode(); // EDD's own test mode picks the key.
		}
		return 'test' === self::get( 'environment', 'live' ) ? 'test' : 'live';
	}

	/**
	 * Unique amount mode sent to the API.
	 */
	public static function unique_mode(): string {
		return 'decrease' === self::get( 'unique_mode', 'increase' ) ? 'decrease' : 'increase';
	}

	/**
	 * Payment window in seconds (API accepts 300..86400).
	 */
	public static function expires_in(): int {
		$value = (int) self::get( 'expires_in', 1800 );
		return in_array( $value, self::EXPIRY_CHOICES, true ) ? $value : 1800;
	}

	/**
	 * Ask for the payer's last 4 card digits: off|optional|required.
	 */
	public static function payer_last4(): string {
		$value = (string) self::get( 'payer_last4', 'optional' );
		return in_array( $value, array( 'off', 'optional', 'required' ), true ) ? $value : 'optional';
	}

	/**
	 * Order status after payment: processing|completed|auto.
	 */
	public static function paid_status(): string {
		$value = (string) self::get( 'paid_status', 'processing' );
		return in_array( $value, array( 'processing', 'completed', 'auto' ), true ) ? $value : 'processing';
	}

	/**
	 * Cancel and restock after the grace period (otherwise mark failed and keep stock).
	 */
	public static function restock_on_expire(): bool {
		return 'no' !== self::get( 'restock_on_expire', 'yes' );
	}

	/**
	 * Ask Varizet to text the payment link to the customer.
	 */
	public static function sms_link(): bool {
		return 'yes' === self::get( 'sms_link', 'no' );
	}

	/**
	 * Display mode: "inline" (thank-you widget) or "redirect" (pay.varizet.ir).
	 */
	public static function display_mode(): string {
		return 'redirect' === self::get( 'display_mode', 'inline' ) ? 'redirect' : 'inline';
	}

	/**
	 * Minimum cart total in the store currency (0 = none).
	 */
	public static function min_amount(): float {
		return max( 0.0, (float) self::get( 'min_amount', '' ) );
	}

	/**
	 * Maximum cart total in the store currency (0 = none).
	 */
	public static function max_amount(): float {
		return max( 0.0, (float) self::get( 'max_amount', '' ) );
	}

	/**
	 * Prefix for merchant_order_id.
	 */
	public static function order_prefix(): string {
		return (string) self::get( 'order_prefix', '' );
	}

	/**
	 * Debug logging enabled.
	 */
	public static function debug(): bool {
		return 'yes' === self::get( 'debug', 'no' );
	}

	/**
	 * Remove settings and order metadata on uninstall.
	 */
	public static function delete_data_on_uninstall(): bool {
		return 'yes' === self::get( 'delete_data', 'no' );
	}

	/**
	 * API base URL. Overridable with the VARIZET_API_BASE_URL constant (local development and
	 * self-hosted staging) or the `varizet_api_base_url` filter.
	 */
	public static function api_base_url(): string {
		$url = defined( 'VARIZET_API_BASE_URL' ) ? (string) constant( 'VARIZET_API_BASE_URL' ) : self::DEFAULT_API;
		return rtrim( (string) apply_filters( 'varizet_api_base_url', $url ), '/' );
	}

	/**
	 * Merchant panel URL (VARIZET_APP_URL / `varizet_app_url`).
	 */
	public static function app_url(): string {
		$url = defined( 'VARIZET_APP_URL' ) ? (string) constant( 'VARIZET_APP_URL' ) : self::DEFAULT_APP;
		return rtrim( (string) apply_filters( 'varizet_app_url', $url ), '/' );
	}

	/**
	 * One-click connect is feature-flagged off until the API ships
	 * `POST /v1/integrations/connect/token` (docs/12 §2.19).
	 * TODO(api): flip the default once the connect endpoints are live.
	 */
	public static function one_click_connect_enabled(): bool {
		$on = defined( 'VARIZET_ONE_CLICK_CONNECT' ) && (bool) constant( 'VARIZET_ONE_CLICK_CONNECT' );
		return (bool) apply_filters( 'varizet_one_click_connect_enabled', $on );
	}

	/**
	 * Random per-install id (created on activation).
	 */
	public static function install_id(): string {
		$id = (string) get_option( self::INSTALL_ID, '' );
		if ( '' === $id ) {
			$id = bin2hex( random_bytes( 16 ) );
			update_option( self::INSTALL_ID, $id, false );
		}
		return $id;
	}

	/**
	 * Fingerprint of this site, used in idempotency keys and order metadata.
	 */
	public static function site_fingerprint(): string {
		return IdempotencyKey::site_fingerprint( self::install_id(), home_url() );
	}

	/**
	 * Webhook URL to register in Varizet.
	 */
	public static function webhook_url(): string {
		return rest_url( 'varizet/v1/webhook' );
	}
}
