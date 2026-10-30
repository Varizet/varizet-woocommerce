<?php
/**
 * Iranian mobile number normalisation.
 *
 * @package Varizet\WooCommerce
 */

namespace Varizet\WooCommerce\Support;

defined( 'ABSPATH' ) || exit;

/**
 * The API accepts mobiles only as `09xxxxxxxxx` (IranMobileSchema). Checkout forms receive
 * Persian/Arabic digits, `+98`, `0098`, spaces and dashes; anything that is not a mobile is
 * dropped rather than failing the order.
 *
 * Pure: no WordPress calls, unit tested.
 */
final class Phone {

	/**
	 * Returns the canonical 11-digit mobile or null.
	 *
	 * @param string|null $raw Phone as typed by the customer.
	 */
	public static function normalize_mobile( ?string $raw ): ?string {
		if ( null === $raw || '' === trim( $raw ) ) {
			return null;
		}
		$digits = preg_replace( '/\D+/', '', Format::to_latin_digits( $raw ) );
		if ( null === $digits || '' === $digits ) {
			return null;
		}
		if ( str_starts_with( $digits, '0098' ) ) {
			$digits = '0' . substr( $digits, 4 );
		} elseif ( str_starts_with( $digits, '98' ) && 12 === strlen( $digits ) ) {
			$digits = '0' . substr( $digits, 2 );
		} elseif ( str_starts_with( $digits, '9' ) && 10 === strlen( $digits ) ) {
			$digits = '0' . $digits;
		}
		return preg_match( '/^09\d{9}$/', $digits ) ? $digits : null;
	}

	/**
	 * Masks a mobile for display or logs: 0912•••4567.
	 *
	 * @param string $mobile Canonical mobile.
	 */
	public static function mask( string $mobile ): string {
		if ( strlen( $mobile ) < 8 ) {
			return '•••';
		}
		return substr( $mobile, 0, 4 ) . '•••' . substr( $mobile, -4 );
	}
}
