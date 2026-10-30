<?php
/**
 * Plugin Name:          Varizet for WooCommerce
 * Plugin URI:           https://varizet.ir/integrations/woocommerce
 * Description:          Card-to-card payments with automatic confirmation for WooCommerce and Easy Digital Downloads. Signed webhooks, a polling fallback, a live thank-you widget and a system status page.
 * Version:              1.1.0
 * Requires at least:    6.4
 * Requires PHP:         8.1
 * Author:               Varizet
 * Author URI:           https://varizet.ir
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          varizet-woocommerce
 * Domain Path:          /languages
 * WC requires at least: 8.5
 * WC tested up to:      11.2
 *
 * @package Varizet\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'VARIZET_WC_VERSION', '1.1.0' );
define( 'VARIZET_WC_FILE', __FILE__ );
define( 'VARIZET_WC_DIR', plugin_dir_path( __FILE__ ) );
define( 'VARIZET_WC_URL', plugin_dir_url( __FILE__ ) );
define( 'VARIZET_WC_MIN_PHP', '8.1' );

/*
 * No Composer at runtime: a tiny PSR-4 autoloader for the Varizet\WooCommerce namespace.
 */
spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'Varizet\\WooCommerce\\';
		if ( 0 !== strncmp( $class_name, $prefix, strlen( $prefix ) ) ) {
			return;
		}
		$relative = substr( $class_name, strlen( $prefix ) );
		$file     = VARIZET_WC_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

/*
 * Declare HPOS (custom order tables) and Cart/Checkout Blocks compatibility.
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', VARIZET_WC_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', VARIZET_WC_FILE, true );
		}
	}
);

if ( version_compare( PHP_VERSION, VARIZET_WC_MIN_PHP, '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: required PHP version, 2: current PHP version. */
						__( 'Varizet for WooCommerce needs PHP %1$s or newer. This server runs PHP %2$s.', 'varizet-woocommerce' ),
						VARIZET_WC_MIN_PHP,
						PHP_VERSION
					)
				)
			);
		}
	);
	return;
}

register_activation_hook( __FILE__, array( \Varizet\WooCommerce\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \Varizet\WooCommerce\Plugin::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( \Varizet\WooCommerce\Plugin::class, 'boot' ), 11 );
