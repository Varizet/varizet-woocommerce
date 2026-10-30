<?php
/**
 * PKCE (RFC 7636) helpers for the one-click connect flow.
 *
 * @package Varizet\WooCommerce
 */

namespace Varizet\WooCommerce\Connect;

defined( 'ABSPATH' ) || exit;

/**
 * Pure: no WordPress calls, unit tested.
 */
final class Pkce {

	/**
	 * A 43-128 character verifier from the unreserved alphabet.
	 *
	 * @param int $bytes Random bytes (32 -> 43 characters).
	 */
	public static function verifier( int $bytes = 48 ): string {
		return self::base64url( random_bytes( max( 32, min( 96, $bytes ) ) ) );
	}

	/**
	 * S256 challenge for a verifier.
	 *
	 * @param string $verifier Code verifier.
	 */
	public static function challenge( string $verifier ): string {
		return self::base64url( hash( 'sha256', $verifier, true ) );
	}

	/**
	 * Opaque CSRF state.
	 */
	public static function state(): string {
		return self::base64url( random_bytes( 24 ) );
	}

	/**
	 * Base64url without padding.
	 *
	 * @param string $raw Binary.
	 */
	public static function base64url( string $raw ): string {
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- RFC 7636 encoding, not obfuscation.
	}
}
