<?php
/**
 * At-rest encryption of API keys and webhook secrets.
 *
 * @package Varizet\WooCommerce
 */

namespace Varizet\WooCommerce\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Per docs/10 §1.6, keys are encrypted in wp_options with a key derived from the site's AUTH_KEY
 * and SECURE_AUTH_SALT. XSalsa20-Poly1305 via libsodium (WordPress ships sodium_compat, so it
 * is always available). A database dump alone does not reveal the credentials; changing the
 * salts makes them unreadable, and the connection page then asks to reconnect.
 */
final class Crypto {

	private const PREFIX = 'vzs1:';

	/**
	 * Encrypts a value.
	 *
	 * @param string $plain Plain text.
	 */
	public static function encrypt( string $plain ): string {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$box   = sodium_crypto_secretbox( $plain, $nonce, self::key() );
		return self::PREFIX . base64_encode( $nonce . $box ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary ciphertext encoding.
	}

	/**
	 * Decrypts a value; null when it was written with other salts or is corrupt.
	 *
	 * @param string $value Stored value.
	 */
	public static function decrypt( string $value ): ?string {
		if ( ! str_starts_with( $value, self::PREFIX ) ) {
			return null;
		}
		$raw = base64_decode( substr( $value, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary ciphertext encoding.
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}
		$nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$box   = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		try {
			$plain = sodium_crypto_secretbox_open( $box, $nonce, self::key() );
		} catch ( \SodiumException $e ) {
			return null;
		}
		return false === $plain ? null : $plain;
	}

	/**
	 * 32-byte key derived from the site salts.
	 */
	private static function key(): string {
		$material  = defined( 'AUTH_KEY' ) ? (string) AUTH_KEY : '';
		$material .= defined( 'SECURE_AUTH_SALT' ) ? (string) SECURE_AUTH_SALT : '';
		if ( '' === $material ) {
			// No salts configured (unusual): fall back to the per-install id so values are at least not plain.
			$material = (string) get_option( 'varizet_install_id', 'varizet' );
		}
		return hash_hmac( 'sha256', 'varizet-woocommerce/credentials/v1', $material, true );
	}
}
