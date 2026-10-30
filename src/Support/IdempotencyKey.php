<?php
/**
 * Idempotency keys and merchant order ids per payment attempt.
 *
 * @package Varizet\WooCommerce
 */

namespace Varizet\WooCommerce\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Per docs/10 §1.4, `Idempotency-Key = wc-{order_id}-{attempt}`; the attempt grows on «پرداخت مجدد»
 * (pay again after expiry, a changed total, or a 24 h old key). A short site fingerprint is
 * appended because the API scopes keys per store, and a staging clone connected to the same
 * store reuses order ids: without it, staging order #123 would replay production's order.
 *
 * Pure: no WordPress calls, unit tested.
 */
final class IdempotencyKey {

	/** The API caps keys at 255 characters and merchant ids at 64. */
	private const MAX_MERCHANT_ID = 64;

	/**
	 * Builds the Idempotency-Key header for one attempt.
	 *
	 * @param int    $order_id WooCommerce order id.
	 * @param int    $attempt  Attempt number, starting at 1.
	 * @param string $site_id  Site fingerprint (see site_fingerprint()).
	 * @throws \InvalidArgumentException For a non-positive order id or attempt.
	 */
	public static function for_attempt( int $order_id, int $attempt, string $site_id ): string {
		if ( $order_id <= 0 || $attempt <= 0 ) {
			throw new \InvalidArgumentException( 'invalid_attempt' );
		}
		$site = substr( (string) preg_replace( '/[^a-z0-9]/', '', strtolower( $site_id ) ), 0, 8 );
		return sprintf( 'wc-%d-%d', $order_id, $attempt ) . ( '' === $site ? '' : '-' . $site );
	}

	/**
	 * Builds `merchant_order_id`: the order number shoppers and merchants see, unique per store
	 * across attempts (the API enforces a unique index on it).
	 *
	 * @param string $prefix       Optional merchant prefix (settings).
	 * @param string $order_number WooCommerce order number.
	 * @param int    $attempt      Attempt number, starting at 1.
	 */
	public static function merchant_order_id( string $prefix, string $order_number, int $attempt ): string {
		$clean  = static fn ( string $s ): string => (string) preg_replace( '/[^A-Za-z0-9_\-#.\/]/', '', $s );
		$suffix = $attempt > 1 ? '-' . $attempt : '';
		$base   = $clean( $prefix ) . $clean( $order_number );
		if ( '' === $base ) {
			$base = 'wc';
		}
		return substr( $base, 0, self::MAX_MERCHANT_ID - strlen( $suffix ) ) . $suffix;
	}

	/**
	 * Stable fingerprint of a site: random install id bound to the home URL.
	 *
	 * @param string $install_id Random id stored at activation.
	 * @param string $home_url   Site home URL.
	 */
	public static function site_fingerprint( string $install_id, string $home_url ): string {
		$host = strtolower( (string) preg_replace( '#^https?://#i', '', rtrim( $home_url, '/' ) ) );
		return substr( hash( 'sha256', $install_id . '|' . $host ), 0, 8 );
	}
}
