<?php
/**
 * PHPUnit bootstrap for the pure classes (no WordPress needed).
 *
 * @package Varizet\WooCommerce
 */

define( 'ABSPATH', __DIR__ . '/' );

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'Varizet\\WooCommerce\\';
		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}
		$file = dirname( __DIR__ ) . '/src/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);
