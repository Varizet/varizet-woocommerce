<?php
/**
 * Which commerce plugins are active, and what follows from that.
 *
 * @package Varizet\WooCommerce
 */

namespace Varizet\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * One plugin serves WooCommerce, Easy Digital Downloads or both (ADR-0025): the API connects one
 * key and one webhook endpoint (`/wp-json/varizet/v1/webhook`) per site and mode, so the two shops
 * of a site must share them.
 */
final class Platform {

	/** Which connect client last connected this site (sent as the `Varizet-Plugin` kind). */
	public const CLIENT_OPTION = 'varizet_connect_client';

	/**
	 * WooCommerce is active.
	 */
	public static function has_wc(): bool {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_order' );
	}

	/**
	 * Easy Digital Downloads 3.x is active (orders API).
	 */
	public static function has_edd(): bool {
		return function_exists( 'edd_get_order' ) && function_exists( 'edd_update_payment_status' );
	}

	/**
	 * Capability for the Varizet admin screens.
	 */
	public static function cap(): string {
		return self::has_wc() || ! self::has_edd() ? 'manage_woocommerce' : 'manage_shop_settings';
	}

	/**
	 * `client` of the one-click connect flow (INTEGRATION_CONNECT_CLIENTS: woocommerce, edd).
	 */
	public static function connect_client(): string {
		return self::has_wc() || ! self::has_edd() ? 'woocommerce' : 'edd';
	}

	/**
	 * Store currency code: WooCommerce's, else EDD's (normalised: toman → IRT, rial → IRR).
	 */
	public static function store_currency(): string {
		if ( self::has_wc() && function_exists( 'get_woocommerce_currency' ) ) {
			return (string) get_woocommerce_currency();
		}
		return function_exists( 'edd_get_currency' ) ? Edd\EddStatusMapper::currency( (string) edd_get_currency() ) : '';
	}

	/**
	 * Kind in `Varizet-Plugin`: the client that connected, so the panel records the version.
	 */
	public static function plugin_kind(): string {
		$stored = (string) get_option( self::CLIENT_OPTION, '' );
		return in_array( $stored, array( 'woocommerce', 'edd' ), true ) ? $stored : self::connect_client();
	}
}
