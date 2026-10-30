<?php
/**
 * Cart & Checkout Blocks integration.
 *
 * @package Varizet\WooCommerce
 */

namespace Varizet\WooCommerce\Gateway;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use Varizet\WooCommerce\Settings;
use Varizet\WooCommerce\Support\Amount;

defined( 'ABSPATH' ) || exit;

/**
 * Registers `assets/js/blocks.js` (plain JS on wp.element, no build step) and hands it the
 * gateway settings. Server-side payment processing is the same Gateway::process_payment():
 * Blocks pass `paymentMethodData` into $_POST for legacy gateways.
 */
final class BlocksSupport extends AbstractPaymentMethodType {

	/**
	 * Payment method name (must equal the gateway id).
	 *
	 * @var string
	 */
	protected $name = Gateway::ID;

	/**
	 * Loads settings.
	 */
	public function initialize() {
		$this->settings = get_option( Settings::OPTION, array() );
	}

	/**
	 * Active when the gateway is available for the current cart.
	 */
	public function is_active() {
		$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		return isset( $gateways[ Gateway::ID ] ) && 'yes' === $gateways[ Gateway::ID ]->enabled;
	}

	/**
	 * Script handles for the checkout.
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles() {
		wp_register_script(
			'varizet-blocks',
			VARIZET_WC_URL . 'assets/js/blocks.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ),
			VARIZET_WC_VERSION,
			true
		);
		wp_register_style( 'varizet-front', VARIZET_WC_URL . 'assets/css/front.css', array(), VARIZET_WC_VERSION );
		wp_enqueue_style( 'varizet-front' );
		return array( 'varizet-blocks' );
	}

	/**
	 * Data available to blocks.js through wc.wcSettings.getSetting( 'varizet_data' ).
	 *
	 * @return array<string, mixed>
	 */
	public function get_payment_method_data() {
		$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		$gateway  = $gateways[ Gateway::ID ] ?? null;
		$currency = get_woocommerce_currency();
		$persian  = str_starts_with( determine_locale(), 'fa' );

		return array(
			'title'       => $gateway ? $gateway->title : '',
			'description' => $gateway ? $gateway->description : '',
			'icon'        => VARIZET_WC_URL . 'assets/img/mark.svg',
			'supports'    => $gateway ? array_values( array_filter( $gateway->supports, array( $gateway, 'supports' ) ) ) : array( 'products' ),
			'last4'       => Settings::payer_last4(),
			'testMode'    => 'test' === Settings::environment(),
			'currency'    => $currency,
			'supported'   => Amount::is_supported( $currency ),
			'factor'      => Amount::is_supported( $currency ) ? Amount::factor( $currency ) : 0,
			'minRial'     => Amount::MIN_ORDER_RIAL,
			'minAmount'   => Settings::min_amount(),
			'maxAmount'   => Settings::max_amount(),
			'persian'     => $persian,
			'i18n'        => array(
				'steps'         => array(
					__( 'Copy the amount and card', 'varizet-woocommerce' ),
					__( 'Transfer from your own bank app', 'varizet-woocommerce' ),
					__( 'Automatic confirmation in seconds', 'varizet-woocommerce' ),
				),
				'trustTitle'    => __( 'No redirect to a payment gateway.', 'varizet-woocommerce' ),
				'trustBody'     => __( 'Pay from your own bank app; automatic confirmation, no receipt upload.', 'varizet-woocommerce' ),
				'testMode'      => __( 'Test mode: no real money is transferred.', 'varizet-woocommerce' ),
				'last4Label'    => 'required' === Settings::payer_last4()
					? __( 'Last 4 digits of your card', 'varizet-woocommerce' )
					: __( 'Last 4 digits of your card (optional, for faster confirmation)', 'varizet-woocommerce' ),
				'last4Required' => __( 'Please enter the last 4 digits of the card you will pay from.', 'varizet-woocommerce' ),
				'last4Invalid'  => __( 'The last 4 digits of the card must be exactly 4 digits.', 'varizet-woocommerce' ),
				'brand'         => __( 'Varizet', 'varizet-woocommerce' ),
			),
		);
	}
}
