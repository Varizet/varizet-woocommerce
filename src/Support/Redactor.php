<?php
/**
 * Removes secrets and personal data before anything is logged.
 *
 * @package Varizet\WooCommerce
 */

namespace Varizet\WooCommerce\Support;

defined( 'ABSPATH' ) || exit;

/**
 * AGENTS.md rule 6: never log full card numbers, SMS bodies, tokens, passwords or API keys.
 * Applied to every message and context value by Logger. Pure and unit tested.
 */
final class Redactor {

	private const MASK = '[redacted]';

	/** Context keys whose values are always replaced. */
	private const SECRET_KEYS = array(
		'authorization',
		'api_key',
		'apikey',
		'secret',
		'webhook_secret',
		'password',
		'pass',
		'token',
		'access_token',
		'connect_code',
		'code_verifier',
		'signature',
		'varizet-signature',
		'varizet_signature',
		'cookie',
		'pan',
		'iban',
		'body',
		'sms',
		'order_key',
	);

	/**
	 * Redacts a string.
	 *
	 * @param string $text Message.
	 */
	public static function text( string $text ): string {
		$rules = array(
			// API keys: keep the mode prefix and last 4 characters.
			'/\b(vz_(?:live|test)_)[A-Za-z0-9_]*([A-Za-z0-9]{4})\b/' => '$1••••$2',
			// Device credentials and connect codes.
			'/\b(vzd_|vz_dev_|vzc_|whsec_)[A-Za-z0-9_\-]+/' => '$1••••',
			// Bearer / Device authorization values.
			'/\b(Bearer|Device)\s+[A-Za-z0-9._\-]+/i' => '$1 ' . self::MASK,
			// Signature headers.
			'/\bv1=[0-9a-f]{16,}/i'                   => 'v1=' . self::MASK,
			// Payment page tokens in URLs: keep 4 characters, like the API access log.
			'#(/(?:p|checkouts)/)([0-9A-Za-z]{4})[0-9A-Za-z]{12,}#' => '$1$2…',
			// IBAN.
			'/\bIR\d{2}(?:\s?\d){18}(\s?\d{4})\b/i'   => 'IR••••$1',
			// Card numbers: 16-19 digits, optionally grouped by spaces or dashes.
			'/\b(?:\d[ \-]?){12,15}(\d{4})\b/'        => '****$1',
			// Iranian mobiles.
			'/\b(09\d{2})\d{3}(\d{4})\b/'             => '$1•••$2',
		);
		foreach ( $rules as $pattern => $replacement ) {
			$text = (string) preg_replace( $pattern, $replacement, $text );
		}
		return $text;
	}

	/**
	 * Recursively redacts a context array or scalar.
	 *
	 * @param mixed $value Context value.
	 * @param int   $depth Recursion guard.
	 * @return mixed
	 */
	public static function context( $value, int $depth = 0 ) {
		if ( $depth > 6 ) {
			return '[depth]';
		}
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $item ) {
				if ( is_string( $key ) && self::is_secret_key( $key ) && ! is_array( $item ) ) {
					$out[ $key ] = null === $item || '' === $item ? $item : self::MASK;
					continue;
				}
				$out[ $key ] = self::context( $item, $depth + 1 );
			}
			return $out;
		}
		if ( is_string( $value ) ) {
			return self::text( $value );
		}
		if ( is_object( $value ) ) {
			return self::context( get_object_vars( $value ), $depth + 1 );
		}
		return $value;
	}

	/**
	 * Whether a context key names a secret.
	 *
	 * @param string $key Key.
	 */
	private static function is_secret_key( string $key ): bool {
		$k = strtolower( $key );
		if ( in_array( $k, self::SECRET_KEYS, true ) ) {
			return true;
		}
		return (bool) preg_match( '/(secret|password|api_key|token|authorization)$/', $k );
	}
}
