<?php
/**
 * Store currency <-> integer rial conversion.
 *
 * @package Varizet\WooCommerce
 */

namespace Varizet\WooCommerce\Support;

defined( 'ABSPATH' ) || exit;

/**
 * The Varizet API only speaks integer rial (ADR-0012). WooCommerce stores may price in rial
 * (IRR), toman (IRT) or the thousand-rial / thousand-toman units some Persian plugins add
 * (IRHR, IRHT). Conversion uses string arithmetic so float artefacts never move money.
 *
 * Pure: no WordPress calls, unit tested.
 */
final class Amount {

	/** Rial per one unit of each supported currency. */
	public const FACTORS = array(
		'IRR'  => 1,
		'IRT'  => 10,
		'IRHR' => 1000,
		'IRHT' => 10000,
	);

	/** Minimum order accepted by the API (docs/05 §3), in rial. */
	public const MIN_ORDER_RIAL = 10000;

	/**
	 * Fractional digits kept (truncated, never rounded). Every half-rial boundary has at most five
	 * decimals (0.5 / 10000), so twelve truncated digits decide half-up exactly; rounding them first
	 * would round twice (1000.49999 → 1000.5 → 1001).
	 */
	private const KEPT_DECIMALS = 12;

	/**
	 * Whether the currency can be converted to rial.
	 *
	 * @param string $currency ISO-like WooCommerce currency code.
	 */
	public static function is_supported( string $currency ): bool {
		return isset( self::FACTORS[ strtoupper( $currency ) ] );
	}

	/**
	 * Rial per unit of the currency.
	 *
	 * @param string $currency Currency code.
	 * @throws \InvalidArgumentException When the currency is not supported.
	 */
	public static function factor( string $currency ): int {
		$code = strtoupper( $currency );
		if ( ! isset( self::FACTORS[ $code ] ) ) {
			throw new \InvalidArgumentException( 'unsupported_currency' );
		}
		return self::FACTORS[ $code ];
	}

	/**
	 * Converts a WooCommerce amount (string, int or float) to integer rial, rounding half up.
	 *
	 * @param string|int|float $amount   Amount in the store currency.
	 * @param string           $currency Store currency code.
	 * @throws \InvalidArgumentException For negative, malformed or overflowing amounts.
	 */
	public static function to_rial( $amount, string $currency ): int {
		$factor = self::factor( $currency );
		$text   = self::normalize( $amount );

		if ( ! preg_match( '/^(\d+)(?:\.(\d+))?$/', $text, $m ) ) {
			throw new \InvalidArgumentException( 'invalid_amount' );
		}
		$integer  = ltrim( $m[1], '0' );
		$integer  = '' === $integer ? '0' : $integer;
		$fraction = isset( $m[2] ) ? rtrim( $m[2], '0' ) : '';

		if ( strlen( $fraction ) > self::KEPT_DECIMALS ) {
			$fraction = rtrim( substr( $fraction, 0, self::KEPT_DECIMALS ), '0' );
		}

		if ( strlen( $integer ) > 15 || (int) $integer > intdiv( PHP_INT_MAX, $factor ) - 1 ) {
			throw new \InvalidArgumentException( 'amount_overflow' );
		}

		$rial = (int) $integer * $factor;
		if ( '' !== $fraction ) {
			$scale = 10 ** strlen( $fraction );
			$part  = (int) $fraction * $factor;
			$rial += intdiv( $part, $scale ) + ( ( $part % $scale ) * 2 >= $scale ? 1 : 0 );
		}
		return $rial;
	}

	/**
	 * Converts integer rial back to the store currency as a decimal string ("850043.0").
	 *
	 * @param int    $rial     Amount in rial.
	 * @param string $currency Store currency code.
	 */
	public static function from_rial( int $rial, string $currency ): string {
		$factor = self::factor( $currency );
		$sign   = $rial < 0 ? '-' : '';
		$rial   = abs( $rial );
		$whole  = intdiv( $rial, $factor );
		$rest   = $rial % $factor;
		if ( 0 === $rest ) {
			return $sign . $whole;
		}
		$digits = strlen( (string) $factor ) - 1;
		return $sign . $whole . '.' . rtrim( str_pad( (string) $rest, $digits, '0', STR_PAD_LEFT ), '0' );
	}

	/**
	 * Number of trailing digits that identify a unique amount (rendered amber).
	 *
	 * @param int $amount        Base amount in rial.
	 * @param int $unique_amount Unique amount in rial.
	 */
	public static function unique_digits( int $amount, int $unique_amount ): int {
		$diff = abs( $unique_amount - $amount );
		if ( 0 === $diff ) {
			return 3;
		}
		return max( 1, min( 6, strlen( (string) $diff ) ) );
	}

	/**
	 * Turns an int/float/string into a plain decimal string without exponent or grouping.
	 *
	 * @param string|int|float $amount Raw amount.
	 * @throws \InvalidArgumentException For negative or non-numeric input.
	 */
	private static function normalize( $amount ): string {
		if ( is_int( $amount ) ) {
			if ( $amount < 0 ) {
				throw new \InvalidArgumentException( 'negative_amount' );
			}
			return (string) $amount;
		}
		if ( is_float( $amount ) ) {
			if ( ! is_finite( $amount ) || $amount < 0 ) {
				throw new \InvalidArgumentException( 'invalid_amount' );
			}
			// 6 decimals is beyond any WooCommerce price precision and kills binary noise.
			return rtrim( rtrim( number_format( $amount, 6, '.', '' ), '0' ), '.' );
		}
		if ( ! is_string( $amount ) ) {
			throw new \InvalidArgumentException( 'invalid_amount' );
		}
		$text = trim( str_replace( array( ',', '٬', ' ' ), '', $amount ) );
		if ( '' === $text ) {
			throw new \InvalidArgumentException( 'invalid_amount' );
		}
		if ( '-' === $text[0] ) {
			throw new \InvalidArgumentException( 'negative_amount' );
		}
		return $text;
	}
}
