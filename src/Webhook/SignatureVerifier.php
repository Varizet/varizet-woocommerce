<?php
/**
 * Varizet-Signature verification.
 *
 * @package Varizet\WooCommerce
 */

namespace Varizet\WooCommerce\Webhook;

defined( 'ABSPATH' ) || exit;

/**
 * Verifies docs/05 §6: `Varizet-Signature: t=<unix>,v1=<hex hmac_sha256(secret, t + "." + raw_body)>`.
 * Several `v1=` values may be present while a secret rotates; unknown schemes are ignored.
 * Accepted when one expected HMAC equals one candidate (constant time) and |now - t| <= 300 s.
 * Mirrors apps/api `verifySignatureHeader` so both sides agree on every edge case.
 *
 * Pure: no WordPress calls, unit tested against the shared contracts vector.
 */
final class SignatureVerifier {

	public const TOLERANCE_SEC = 300;

	private const MAX_HEADER_LENGTH = 1024;

	/**
	 * Parses the header into a timestamp and candidate signatures, or null when malformed.
	 *
	 * @param string|null $header Raw header value.
	 * @return array{timestamp:int, signatures:string[]}|null
	 */
	public static function parse( ?string $header ): ?array {
		if ( null === $header || '' === $header || strlen( $header ) > self::MAX_HEADER_LENGTH ) {
			return null;
		}
		$timestamp  = null;
		$signatures = array();
		foreach ( explode( ',', $header ) as $part ) {
			$eq = strpos( $part, '=' );
			if ( false === $eq || 0 === $eq ) {
				return null;
			}
			$key   = trim( substr( $part, 0, $eq ) );
			$value = trim( substr( $part, $eq + 1 ) );
			if ( 't' === $key ) {
				if ( null !== $timestamp || ! preg_match( '/^\d{1,12}$/', $value ) ) {
					return null;
				}
				$timestamp = (int) $value;
			} elseif ( 'v1' === $key ) {
				if ( ! preg_match( '/^[0-9a-f]{64}$/', $value ) ) {
					return null;
				}
				$signatures[] = $value;
			}
		}
		if ( null === $timestamp || array() === $signatures ) {
			return null;
		}
		return array(
			'timestamp'  => $timestamp,
			'signatures' => $signatures,
		);
	}

	/**
	 * Expected signature for a payload.
	 *
	 * @param string $secret    Endpoint secret.
	 * @param int    $timestamp Unix seconds from the header.
	 * @param string $payload   Raw request body, byte for byte.
	 */
	public static function compute( string $secret, int $timestamp, string $payload ): string {
		return hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );
	}

	/**
	 * Builds a header (tests, the health page self-check and local tooling).
	 *
	 * @param string[] $secrets   Secrets, current first.
	 * @param int      $timestamp Unix seconds.
	 * @param string   $payload   Raw body.
	 */
	public static function build_header( array $secrets, int $timestamp, string $payload ): string {
		$parts = array( 't=' . $timestamp );
		foreach ( $secrets as $secret ) {
			$parts[] = 'v1=' . self::compute( $secret, $timestamp, $payload );
		}
		return implode( ',', $parts );
	}

	/**
	 * Verifies the header against every configured secret.
	 *
	 * @param string|null           $header    Raw header.
	 * @param string                $payload   Raw body.
	 * @param array<string, string> $secrets   Secrets keyed by label (e.g. "live", "test").
	 * @param int                   $now       Current unix time.
	 * @param int                   $tolerance Allowed clock difference in seconds.
	 * @return array{ok:bool, reason:string, matched:string[], timestamp:int|null, skew:int|null}
	 */
	public static function verify( ?string $header, string $payload, array $secrets, int $now, int $tolerance = self::TOLERANCE_SEC ): array {
		$parsed = self::parse( $header );
		if ( null === $parsed ) {
			return self::result( false, 'malformed', array(), null, null );
		}
		$skew = $now - $parsed['timestamp'];
		if ( abs( $skew ) > $tolerance ) {
			return self::result( false, 'expired', array(), $parsed['timestamp'], $skew );
		}
		$matched = array();
		foreach ( $secrets as $label => $secret ) {
			if ( ! is_string( $secret ) || '' === $secret ) {
				continue;
			}
			$expected = self::compute( $secret, $parsed['timestamp'], $payload );
			$hit      = false;
			// Compare against every candidate so timing does not reveal which one matched.
			foreach ( $parsed['signatures'] as $candidate ) {
				$hit = hash_equals( $expected, $candidate ) || $hit;
			}
			if ( $hit ) {
				$matched[] = (string) $label;
			}
		}
		if ( array() === $matched ) {
			return self::result( false, 'mismatch', array(), $parsed['timestamp'], $skew );
		}
		return self::result( true, 'ok', $matched, $parsed['timestamp'], $skew );
	}

	/**
	 * Result shape.
	 *
	 * @param bool     $ok        Verified.
	 * @param string   $reason    ok|malformed|expired|mismatch.
	 * @param string[] $matched   Labels of matching secrets.
	 * @param int|null $timestamp Header timestamp.
	 * @param int|null $skew      now - timestamp.
	 * @return array{ok:bool, reason:string, matched:string[], timestamp:int|null, skew:int|null}
	 */
	private static function result( bool $ok, string $reason, array $matched, ?int $timestamp, ?int $skew ): array {
		return array(
			'ok'        => $ok,
			'reason'    => $reason,
			'matched'   => $matched,
			'timestamp' => $timestamp,
			'skew'      => $skew,
		);
	}
}
