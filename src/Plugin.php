<?php
/**
 * Hook registration and lifecycle.
 *
 * @package Varizet\WooCommerce
 */

namespace Varizet\WooCommerce;

use Varizet\WooCommerce\Admin\SettingsPage;
use Varizet\WooCommerce\Connect\ConnectController;
use Varizet\WooCommerce\Edd\EddGateway;
use Varizet\WooCommerce\Gateway\BlocksSupport;
use Varizet\WooCommerce\Gateway\Gateway;
use Varizet\WooCommerce\Health\SiteHealth;
use Varizet\WooCommerce\Order\ListTable;
use Varizet\WooCommerce\Order\MetaBox;
use Varizet\WooCommerce\Order\OrderMeta;
use Varizet\WooCommerce\Order\StatusMapper;
use Varizet\WooCommerce\Sync\Scheduler;
use Varizet\WooCommerce\ThankYou\LiveStatus;
use Varizet\WooCommerce\Webhook\Controller as WebhookController;

defined( 'ABSPATH' ) || exit;

/**
 * Wires every component once WooCommerce is loaded.
 */
final class Plugin {

	public const VERSION_OPTION = 'varizet_version';

	/**
	 * Booted flag.
	 *
	 * @var bool
	 */
	private static bool $booted = false;

	/**
	 * Boots on plugins_loaded (priority 11, after WooCommerce).
	 */
	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		add_action( 'init', array( self::class, 'load_textdomain' ), 0 );

		$has_wc = class_exists( 'WooCommerce' ) && class_exists( 'WC_Payment_Gateway' );
		if ( ! $has_wc ) {
			if ( Platform::has_edd() ) {
				self::boot_edd_only();
			} else {
				add_action( 'admin_notices', array( self::class, 'missing_woocommerce_notice' ) );
			}
			return;
		}

		self::maybe_upgrade();

		add_filter( 'woocommerce_payment_gateways', array( self::class, 'register_gateway' ) );
		// WooCommerce fires woocommerce_blocks_loaded during plugins_loaded (priority 10), before us.
		if ( did_action( 'woocommerce_blocks_loaded' ) ) {
			self::register_blocks();
		} else {
			add_action( 'woocommerce_blocks_loaded', array( self::class, 'register_blocks' ) );
		}
		add_action( 'rest_api_init', array( WebhookController::class, 'register' ) );

		add_filter( 'woocommerce_payment_complete_order_status', array( self::class, 'paid_status' ), 10, 3 );
		add_filter( 'woocommerce_valid_order_statuses_for_payment_complete', array( self::class, 'payable_statuses' ), 10, 2 );
		add_filter( 'woocommerce_cancel_unpaid_order', array( self::class, 'defer_unpaid_cancel' ), 10, 2 );
		add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', array( OrderMeta::class, 'cpt_meta_query' ), 10, 2 );

		Scheduler::init();
		LiveStatus::init();
		SiteHealth::init();
		if ( Platform::has_edd() ) {
			EddGateway::init();
		}

		if ( is_admin() ) {
			SettingsPage::init();
			ConnectController::init();
			MetaBox::init();
			ListTable::init();
		}
	}

	/**
	 * Easy Digital Downloads without WooCommerce: the shared connection page, webhook route and
	 * the EDD gateway (ADR-0025). WooCommerce-only screens stay off.
	 */
	private static function boot_edd_only(): void {
		self::maybe_upgrade();
		add_action( 'rest_api_init', array( WebhookController::class, 'register' ) );
		EddGateway::init();
		SiteHealth::init();
		if ( is_admin() ) {
			SettingsPage::init();
			ConnectController::init();
		}
	}

	/**
	 * Translations from /languages (fa_IR ships with the plugin).
	 */
	public static function load_textdomain(): void {
		load_plugin_textdomain( 'varizet-woocommerce', false, dirname( plugin_basename( VARIZET_WC_FILE ) ) . '/languages' );
	}

	/**
	 * Adds the gateway.
	 *
	 * @param string[] $gateways Gateway classes.
	 * @return string[]
	 */
	public static function register_gateway( $gateways ): array {
		$gateways[] = Gateway::class;
		return $gateways;
	}

	/**
	 * Registers the Checkout block integration.
	 */
	public static function register_blocks(): void {
		if ( ! class_exists( \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class ) ) {
			return;
		}
		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			static function ( $registry ) {
				$registry->register( new BlocksSupport() );
			}
		);
	}

	/**
	 * «وضعیت سفارش پس از پرداخت» for Varizet orders.
	 *
	 * @param string    $status   Status WooCommerce chose.
	 * @param int       $order_id Order id.
	 * @param \WC_Order $order    Order.
	 */
	public static function paid_status( $status, $order_id, $order = null ) {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || Gateway::ID !== $order->get_payment_method() ) {
			return $status;
		}
		$setting = Settings::paid_status();
		return 'auto' === $setting ? $status : $setting;
	}

	/**
	 * A payment that arrives after WooCommerce cancelled the order must still complete it.
	 *
	 * @param string[]       $statuses Statuses.
	 * @param \WC_Order|null $order    Order.
	 * @return string[]
	 */
	public static function payable_statuses( $statuses, $order = null ): array {
		$statuses = (array) $statuses;
		if ( $order instanceof \WC_Order && Gateway::ID === $order->get_payment_method() && ! in_array( 'cancelled', $statuses, true ) ) {
			$statuses[] = 'cancelled';
		}
		return $statuses;
	}

	/**
	 * WooCommerce cancels unpaid orders after «Hold stock» minutes. A Varizet order is still
	 * payable until its grace period ends, so the cancel waits until then.
	 *
	 * @param bool      $cancel Whether to cancel.
	 * @param \WC_Order $order  Order.
	 */
	public static function defer_unpaid_cancel( $cancel, $order ) {
		if ( ! $cancel || ! $order instanceof \WC_Order || Gateway::ID !== $order->get_payment_method() ) {
			return $cancel;
		}
		$status = (string) $order->get_meta( OrderMeta::STATUS );
		$grace  = strtotime( (string) $order->get_meta( OrderMeta::GRACE_UNTIL ) );
		if ( StatusMapper::is_open( $status ) && false !== $grace && $grace > time() ) {
			return false;
		}
		return $cancel;
	}

	/**
	 * Notice when WooCommerce is missing.
	 */
	public static function missing_woocommerce_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Varizet needs WooCommerce or Easy Digital Downloads 3 to be installed and active.', 'varizet-woocommerce' ) . '</p></div>';
	}

	/**
	 * Activation: install id and default options. Action Scheduler may not be loaded yet, so
	 * the recurring sync is scheduled on `action_scheduler_init`.
	 */
	public static function activate(): void {
		Settings::install_id();
		update_option( self::VERSION_OPTION, VARIZET_WC_VERSION, false );
	}

	/**
	 * Deactivation: stop the background sync (settings and keys stay).
	 */
	public static function deactivate(): void {
		Scheduler::unschedule();
		EddGateway::unschedule();
	}

	/**
	 * Version bookkeeping for future migrations.
	 */
	private static function maybe_upgrade(): void {
		$stored = (string) get_option( self::VERSION_OPTION, '' );
		if ( VARIZET_WC_VERSION === $stored ) {
			return;
		}
		Settings::install_id();
		update_option( self::VERSION_OPTION, VARIZET_WC_VERSION, false );
	}
}
