<?php
/**
 * Display helpers: Persian digits, grouped amounts, amber unique digits.
 *
 * @package Varizet\WooCommerce
 */

namespace Varizet\WooCommerce\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Pure formatting (no WordPress calls) so it can be unit tested; callers decide the locale.
 */
final class Format {

	private const LATIN   = array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' );
	private const PERSIAN = array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' );
	private const ARABIC  = array( '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩' );

	/**
	 * Latin digits to Persian digits.
	 *
	 * @param string|int $value Text.
	 */
	public static function to_persian_digits( $value ): string {
		return str_replace( self::LATIN, self::PERSIAN, (string) $value );
	}

	/**
	 * Persian and Arabic-Indic digits to Latin digits.
	 *
	 * @param string $value Text.
	 */
	public static function to_latin_digits( string $value ): string {
		return str_replace( self::ARABIC, self::LATIN, str_replace( self::PERSIAN, self::LATIN, $value ) );
	}

	/**
	 * Groups an integer by thousands: 8,500,430 or ۸٬۵۰۰٬۴۳۰.
	 *
	 * @param int  $value   Amount.
	 * @param bool $persian Persian digits and the Arabic thousands separator.
	 */
	public static function number( int $value, bool $persian ): string {
		$grouped = number_format( $value, 0, '.', $persian ? '٬' : ',' );
		return $persian ? self::to_persian_digits( $grouped ) : $grouped;
	}

	/**
	 * HTML for an amount whose last $digits digits are wrapped in `.vz-uniq` (amber).
	 * Every part is escaped here; the result is safe to echo.
	 *
	 * @param int  $amount  Unique amount in rial.
	 * @param int  $digits  Trailing digits to highlight.
	 * @param bool $persian Persian digits.
	 */
	public static function unique_amount_html( int $amount, int $digits, bool $persian ): string {
		$grouped = number_format( $amount, 0, '.', ',' );
		$chars   = str_split( $grouped );
		$out     = '';
		$seen    = 0;
		$tail    = '';
		for ( $i = count( $chars ) - 1; $i >= 0; $i-- ) {
			$c = $chars[ $i ];
			if ( $seen < $digits ) {
				$tail = $c . $tail;
				if ( ',' !== $c ) {
					++$seen;
				}
				continue;
			}
			$out = $c . $out;
		}
		$conv = static function ( string $s ) use ( $persian ): string {
			$s = $persian ? self::to_persian_digits( str_replace( ',', '٬', $s ) ) : $s;
			return htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' );
		};
		// A leading separator belongs to the plain part so the amber run starts with a digit.
		if ( str_starts_with( $tail, ',' ) ) {
			$out .= ',';
			$tail = substr( $tail, 1 );
		}
		return '<span class="vz-amt" dir="ltr">' . $conv( $out ) . '<span class="vz-uniq">' . $conv( $tail ) . '</span></span>';
	}

	/**
	 * Masks a card number to its last four digits: "…1234".
	 *
	 * @param string $pan Card number or last4.
	 */
	public static function last4( string $pan ): string {
		$digits = preg_replace( '/\D+/', '', self::to_latin_digits( $pan ) );
		return '…' . substr( (string) $digits, -4 );
	}

	/**
	 * Groups a 16-digit PAN as "6104 3378 9012 4521".
	 *
	 * @param string $pan Card number.
	 */
	public static function pan( string $pan ): string {
		$digits = (string) preg_replace( '/\D+/', '', $pan );
		return trim( implode( ' ', str_split( $digits, 4 ) ) );
	}
}
