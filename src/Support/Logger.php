<?php
/**
 * WC_Logger wrapper with redaction and a small in-database ring buffer for the status page.
 *
 * @package Varizet\WooCommerce
 */

namespace Varizet\WooCommerce\Support;

use Varizet\WooCommerce\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Every message and context value goes through Redactor first. Debug lines are written only
 * when «لاگ دیباگ» is on; info and above always. The last BUFFER_SIZE entries are also kept
 * in a non-autoloaded option so «وضعیت سیستم» can show and filter them without reading files.
 */
final class Logger {

	public const SOURCE       = 'varizet';
	public const BUFFER       = 'varizet_log_buffer';
	private const BUFFER_SIZE = 200;
	private const LEVELS      = array( 'debug', 'info', 'notice', 'warning', 'error', 'critical' );

	/**
	 * Debug log line (only with debug logging enabled).
	 *
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	public static function debug( string $message, array $context = array() ): void {
		if ( Settings::debug() ) {
			self::log( 'debug', $message, $context );
		}
	}

	/**
	 * Info log line.
	 *
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	public static function info( string $message, array $context = array() ): void {
		self::log( 'info', $message, $context );
	}

	/**
	 * Warning log line.
	 *
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	public static function warning( string $message, array $context = array() ): void {
		self::log( 'warning', $message, $context );
	}

	/**
	 * Error log line.
	 *
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	public static function error( string $message, array $context = array() ): void {
		self::log( 'error', $message, $context );
	}

	/**
	 * Writes a redacted line to WC_Logger and the ring buffer.
	 *
	 * @param string $level   PSR-3 level.
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	public static function log( string $level, string $message, array $context = array() ): void {
		$level   = in_array( $level, self::LEVELS, true ) ? $level : 'info';
		$message = Redactor::text( $message );
		$context = Redactor::context( $context );

		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( $level, $message, array_merge( $context, array( 'source' => self::SOURCE ) ) );
		}
		self::buffer( $level, $message, $context );
	}

	/**
	 * Recent entries, newest first.
	 *
	 * @return array<int, array{t:int, level:string, msg:string, ctx:array, src:string}>
	 */
	public static function recent(): array {
		$entries = get_option( self::BUFFER, array() );
		return is_array( $entries ) ? array_reverse( $entries ) : array();
	}

	/**
	 * Empties the ring buffer.
	 */
	public static function clear(): void {
		delete_option( self::BUFFER );
	}

	/**
	 * Appends to the ring buffer.
	 *
	 * @param string $level   Level.
	 * @param string $message Redacted message.
	 * @param array  $context Redacted context.
	 */
	private static function buffer( string $level, string $message, array $context ): void {
		$entries = get_option( self::BUFFER, array() );
		if ( ! is_array( $entries ) ) {
			$entries = array();
		}
		$component = isset( $context['component'] ) && is_string( $context['component'] ) ? $context['component'] : 'core';
		unset( $context['component'] );
		$entries[] = array(
			't'     => time(),
			'level' => $level,
			'src'   => $component,
			'msg'   => $message,
			'ctx'   => $context,
		);
		if ( count( $entries ) > self::BUFFER_SIZE ) {
			$entries = array_slice( $entries, -self::BUFFER_SIZE );
		}
		update_option( self::BUFFER, $entries, false );
	}
}
