<?php
/**
 * Encrypted storage of API keys and webhook secrets per environment.
 *
 * @package Varizet\WooCommerce
 */

namespace Varizet\WooCommerce\Connect;

use Varizet\WooCommerce\Support\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * One record per environment (`live`, `test`): each has its own API key (`vz_live_…` /
 * `vz_test_…`) and its own webhook endpoint secret, because Varizet endpoints are per mode.
 */
final class Credentials {

	public const OPTION = 'varizet_credentials';
	public const MODES  = array( 'live', 'test' );

	/**
	 * Decrypted values for this request.
	 *
	 * @var array<string, string|null>
	 */
	private static array $plain = array();

	/**
	 * Mode implied by a key prefix, or null for a malformed key.
	 *
	 * @param string $api_key API key.
	 */
	public static function mode_from_key( string $api_key ): ?string {
		if ( preg_match( '/^vz_(live|test)_[A-Za-z0-9_]{8,200}$/', $api_key, $m ) ) {
			return $m[1];
		}
		return null;
	}

	/**
	 * Masked key for display: vz_live_••••3f9a.
	 *
	 * @param string $api_key API key.
	 */
	public static function hint( string $api_key ): string {
		$mode = self::mode_from_key( $api_key ) ?? 'live';
		return 'vz_' . $mode . '_••••' . substr( $api_key, -4 );
	}

	/**
	 * Stored record for a mode.
	 *
	 * @param string $mode live|test.
	 * @return array<string, mixed>
	 */
	public static function record( string $mode ): array {
		$all = get_option( self::OPTION, array() );
		return is_array( $all ) && isset( $all[ $mode ] ) && is_array( $all[ $mode ] ) ? $all[ $mode ] : array();
	}

	/**
	 * Decrypted API key for a mode.
	 *
	 * @param string $mode live|test.
	 */
	public static function api_key( string $mode ): ?string {
		return self::decrypted( $mode, 'api_key' );
	}

	/**
	 * Decrypted webhook secret for a mode.
	 *
	 * @param string $mode live|test.
	 */
	public static function webhook_secret( string $mode ): ?string {
		return self::decrypted( $mode, 'webhook_secret' );
	}

	/**
	 * All configured webhook secrets keyed by mode.
	 *
	 * @return array<string, string>
	 */
	public static function webhook_secrets(): array {
		$out = array();
		foreach ( self::MODES as $mode ) {
			$secret = self::webhook_secret( $mode );
			if ( null !== $secret && '' !== $secret ) {
				$out[ $mode ] = $secret;
			}
		}
		return $out;
	}

	/**
	 * Whether a mode has a usable API key.
	 *
	 * @param string $mode live|test.
	 */
	public static function is_connected( string $mode ): bool {
		return null !== self::api_key( $mode );
	}

	/**
	 * Whether a record exists but cannot be decrypted (salts changed).
	 *
	 * @param string $mode live|test.
	 */
	public static function is_unreadable( string $mode ): bool {
		$record = self::record( $mode );
		return ! empty( $record['api_key'] ) && null === self::api_key( $mode );
	}

	/**
	 * Saves credentials for a mode (encrypted).
	 *
	 * @param string               $mode           live|test.
	 * @param string               $api_key        API key.
	 * @param string|null          $webhook_secret Webhook secret (null keeps the stored one).
	 * @param array<string, mixed> $info           Extra info: store, scopes, method, webhook_endpoint_id.
	 */
	public static function save( string $mode, string $api_key, ?string $webhook_secret, array $info = array() ): void {
		$all = get_option( self::OPTION, array() );
		$all = is_array( $all ) ? $all : array();
		$old = isset( $all[ $mode ] ) && is_array( $all[ $mode ] ) ? $all[ $mode ] : array();

		$record = array_merge(
			$old,
			array(
				'api_key'      => Crypto::encrypt( $api_key ),
				'key_hint'     => self::hint( $api_key ),
				'connected_at' => time(),
				'connected_by' => get_current_user_id(),
				'method'       => 'manual',
			),
			$info
		);
		if ( null !== $webhook_secret && '' !== $webhook_secret ) {
			$record['webhook_secret'] = Crypto::encrypt( $webhook_secret );
			$record['secret_hint']    = '••••' . substr( $webhook_secret, -4 );
		}
		$all[ $mode ] = $record;
		update_option( self::OPTION, $all, true );
		self::$plain = array();
	}

	/**
	 * Updates non-secret info for a mode (e.g. store name from /v1/me).
	 *
	 * @param string               $mode live|test.
	 * @param array<string, mixed> $info Fields.
	 */
	public static function update_info( string $mode, array $info ): void {
		$all = get_option( self::OPTION, array() );
		if ( ! is_array( $all ) || empty( $all[ $mode ] ) ) {
			return;
		}
		unset( $info['api_key'], $info['webhook_secret'] );
		$all[ $mode ] = array_merge( $all[ $mode ], $info );
		update_option( self::OPTION, $all, true );
	}

	/**
	 * Removes a mode's credentials.
	 *
	 * @param string $mode live|test.
	 */
	public static function forget( string $mode ): void {
		$all = get_option( self::OPTION, array() );
		if ( is_array( $all ) ) {
			unset( $all[ $mode ] );
			update_option( self::OPTION, $all, true );
		}
		self::$plain = array();
	}

	/**
	 * Decrypts one field, memoized per request.
	 *
	 * @param string $mode  live|test.
	 * @param string $field Field.
	 */
	private static function decrypted( string $mode, string $field ): ?string {
		$k = $mode . '.' . $field;
		if ( array_key_exists( $k, self::$plain ) ) {
			return self::$plain[ $k ];
		}
		$record = self::record( $mode );
		$value  = isset( $record[ $field ] ) && is_string( $record[ $field ] ) ? Crypto::decrypt( $record[ $field ] ) : null;
		if ( null !== $value && '' === $value ) {
			$value = null;
		}
		self::$plain[ $k ] = $value;
		return $value;
	}
}
