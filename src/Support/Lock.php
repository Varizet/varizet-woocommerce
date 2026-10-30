<?php
/**
 * Cross-request mutex backed by MySQL GET_LOCK.
 *
 * @package Varizet\WooCommerce
 */

namespace Varizet\WooCommerce\Support;

defined( 'ABSPATH' ) || exit;

/**
 * A webhook, the polling job, the thank-you proxy and an admin «همگام‌سازی» may update the
 * same order at once. GET_LOCK is atomic on every MySQL/MariaDB host and released when the
 * connection closes, so a crashed request never leaves a stale lock (unlike option flags).
 */
final class Lock {

	/**
	 * Tries to take a named lock.
	 *
	 * @param string $name    Lock name (prefixed and hashed to fit the 64-char limit).
	 * @param int    $timeout Seconds to wait.
	 */
	public static function acquire( string $name, int $timeout = 5 ): bool {
		global $wpdb;
		$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::key( $name ), $timeout ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a lock must not be cached.
		return '1' === (string) $got;
	}

	/**
	 * Releases a named lock.
	 *
	 * @param string $name Lock name.
	 */
	public static function release( string $name ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::key( $name ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a lock must not be cached.
	}

	/**
	 * Runs $callback while holding the lock. Returns null when the lock was not obtained.
	 *
	 * @param string   $name     Lock name.
	 * @param callable $callback Work.
	 * @param int      $timeout  Seconds to wait.
	 * @return mixed
	 */
	public static function with( string $name, callable $callback, int $timeout = 5 ) {
		if ( ! self::acquire( $name, $timeout ) ) {
			return null;
		}
		try {
			return $callback();
		} finally {
			self::release( $name );
		}
	}

	/**
	 * Database-unique lock key.
	 *
	 * @param string $name Lock name.
	 */
	private static function key( string $name ): string {
		global $wpdb;
		return substr( 'vz_' . md5( $wpdb->prefix . '|' . $name ), 0, 64 );
	}
}
