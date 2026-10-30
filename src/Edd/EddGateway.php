<?php
/**
 * Easy Digital Downloads gateway «واریزت».
 *
 * @package Varizet\WooCommerce
 */

namespace Varizet\WooCommerce\Edd;

use Varizet\WooCommerce\Api\ApiException;
use Varizet\WooCommerce\Api\Client;
use Varizet\WooCommerce\Connect\Credentials;
use Varizet\WooCommerce\Order\StatusMapper;
use Varizet\WooCommerce\Settings;
use Varizet\WooCommerce\Support\Amount;
use Varizet\WooCommerce\Support\IdempotencyKey;
use Varizet\WooCommerce\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * EDD 3 gateway sharing the plugin's connection, API client and webhook route with WooCommerce:
 * purchase → pending EDD order → `POST /v1/orders` (`Idempotency-Key: edd-{order}-{attempt}-{site8}`)
 * → hosted payment page; the return to the success page syncs once; webhooks and a 5-minute
 * WP-Cron poll do the rest. EDD's test mode selects the Varizet test key.
 */
final class EddGateway {

	public const ID         = 'varizet';
	public const POLL_HOOK  = 'varizet_edd_poll';
	private const SCHEDULE  = 'varizet_five_minutes';
	private const POLL_SIZE = 40;

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_filter( 'edd_payment_gateways', array( self::class, 'register' ) );
		add_action( 'edd_' . self::ID . '_cc_form', '__return_false' );
		add_action( 'edd_gateway_' . self::ID, array( self::class, 'process_purchase' ) );
		add_filter( 'edd_settings_sections_gateways', array( self::class, 'settings_section' ) );
		add_filter( 'edd_settings_gateways', array( self::class, 'settings' ) );
		add_action( 'template_redirect', array( self::class, 'maybe_return' ) );
		add_filter( 'cron_schedules', array( self::class, 'cron_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- 5 minutes, like the WooCommerce poll.
		add_action( self::POLL_HOOK, array( self::class, 'poll' ) );
		add_action( 'init', array( self::class, 'ensure_scheduled' ) );
	}

	/**
	 * Adds the gateway.
	 *
	 * @param array<string, mixed> $gateways Gateways.
	 * @return array<string, mixed>
	 */
	public static function register( $gateways ): array {
		$gateways             = (array) $gateways;
		$gateways[ self::ID ] = array(
			'admin_label'    => __( 'Varizet (card-to-card)', 'varizet-woocommerce' ),
			'checkout_label' => __( 'Card-to-card with automatic confirmation', 'varizet-woocommerce' ),
			'supports'       => array(),
		);
		return $gateways;
	}

	/**
	 * Varizet mode for EDD: its global test mode.
	 */
	public static function mode(): string {
		return function_exists( 'edd_is_test_mode' ) && edd_is_test_mode() ? 'test' : 'live';
	}

	/**
	 * `edd_gateway_varizet`: create the pending order, the Varizet order, and redirect.
	 *
	 * @param array<string, mixed> $purchase_data EDD purchase data.
	 */
	public static function process_purchase( $purchase_data ): void {
		if ( ! wp_verify_nonce( (string) ( $purchase_data['gateway_nonce'] ?? '' ), 'edd-gateway' ) ) {
			wp_die( esc_html__( 'Nonce verification failed.', 'varizet-woocommerce' ), '', array( 'response' => 403 ) );
		}
		$client = Client::for_mode( self::mode() );
		if ( null === $client ) {
			self::back( __( 'This payment method is not available right now.', 'varizet-woocommerce' ) );
		}

		$order_id = (int) edd_insert_payment(
			array(
				'price'        => $purchase_data['price'],
				'date'         => $purchase_data['date'],
				'user_email'   => $purchase_data['user_email'],
				'purchase_key' => $purchase_data['purchase_key'],
				'currency'     => edd_get_currency(),
				'downloads'    => $purchase_data['downloads'],
				'user_info'    => $purchase_data['user_info'],
				'cart_details' => $purchase_data['cart_details'],
				'gateway'      => self::ID,
				'status'       => 'pending',
			)
		);
		if ( $order_id <= 0 ) {
			edd_record_gateway_error( __( 'Payment error', 'varizet-woocommerce' ), __( 'EDD could not create the order.', 'varizet-woocommerce' ) );
			self::back( __( 'Your order could not be created. Please try again.', 'varizet-woocommerce' ) );
		}

		try {
			$vz = self::start( $order_id, $client, (array) ( $purchase_data['user_info'] ?? array() ) );
		} catch ( ApiException $e ) {
			edd_record_gateway_error( __( 'Payment error', 'varizet-woocommerce' ), sprintf( '%s (%s)', $e->error_code, (string) $e->request_id ), $order_id );
			edd_update_payment_status( $order_id, 'failed' );
			self::back( $e->getMessage() );
		}

		edd_set_purchase_session( $purchase_data );
		edd_empty_cart();
		wp_redirect( (string) $vz['payment_url'] ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- the Varizet payment page.
		exit;
	}

	/**
	 * Creates (or reuses) the Varizet order of an EDD order.
	 *
	 * @param int                  $order_id  EDD order id.
	 * @param Client               $client    API client.
	 * @param array<string, mixed> $user_info EDD user info.
	 * @return array<string, mixed> Varizet order.
	 * @throws ApiException On API errors.
	 */
	public static function start( int $order_id, Client $client, array $user_info ): array {
		$order = edd_get_order( $order_id );
		if ( ! $order ) {
			throw new ApiException( 404, 'not_found', 'order_not_found', __( 'Your order could not be created. Please try again.', 'varizet-woocommerce' ) );
		}
		$currency = EddStatusMapper::currency( '' !== (string) $order->currency ? (string) $order->currency : (string) edd_get_currency() );
		if ( ! Amount::is_supported( $currency ) ) {
			/* translators: %s: currency code. */
			throw new ApiException( 400, 'invalid_request', 'unsupported_currency', sprintf( __( 'Varizet accepts rial and toman prices only (store currency: %s).', 'varizet-woocommerce' ), $currency ) );
		}
		$amount = Amount::to_rial( (string) $order->total, $currency );
		if ( $amount < Amount::MIN_ORDER_RIAL ) {
			throw new ApiException( 400, 'invalid_request', 'amount_too_small', __( 'The order total is below the minimum for card-to-card payment.', 'varizet-woocommerce' ) );
		}

		$attempt = max( 1, (int) edd_get_order_meta( $order_id, EddSync::ATTEMPT, true ) );
		if ( '' !== (string) edd_get_order_meta( $order_id, EddSync::ORDER_ID, true ) ) {
			++$attempt;
		}
		$bumps = 0;
		while ( true ) {
			edd_update_order_meta( $order_id, EddSync::ATTEMPT, (string) $attempt );
			try {
				$vz = $client->create_order(
					self::body( $order_id, $amount, $attempt, $user_info ),
					EddStatusMapper::idempotency_key( $order_id, $attempt, Settings::site_fingerprint() )
				);
				break;
			} catch ( ApiException $e ) {
				$bump = in_array( $e->error_code, array( 'idempotency_mismatch', 'merchant_order_id_duplicate' ), true );
				if ( ! $bump || ++$bumps > 3 ) {
					throw $e;
				}
				++$attempt;
			}
		}
		if ( empty( $vz['id'] ) || empty( $vz['payment_url'] ) ) {
			throw new ApiException( 502, 'api_error', 'invalid_response', __( 'Varizet returned an incomplete order.', 'varizet-woocommerce' ) );
		}
		EddSync::store( $order_id, $vz, $attempt );
		edd_insert_payment_note(
			$order_id,
			/* translators: 1: Varizet order code, 2: attempt number. */
			sprintf( __( 'Payment request %1$s created and unique amount reserved (attempt %2$d)', 'varizet-woocommerce' ), (string) ( $vz['code'] ?? '' ), $attempt )
		);
		Logger::info( sprintf( 'EDD order #%d: Varizet order %s created (attempt %d)', $order_id, (string) ( $vz['code'] ?? '' ), $attempt ), array( 'component' => 'edd' ) );
		return $vz;
	}

	/**
	 * `POST /v1/orders` body.
	 *
	 * @param int                  $order_id  EDD order id.
	 * @param int                  $amount    Rial.
	 * @param int                  $attempt   Attempt.
	 * @param array<string, mixed> $user_info EDD user info.
	 * @return array<string, mixed>
	 */
	public static function body( int $order_id, int $amount, int $attempt, array $user_info ): array {
		$number   = (string) edd_get_payment_number( $order_id );
		$name     = trim( (string) ( $user_info['first_name'] ?? '' ) . ' ' . (string) ( $user_info['last_name'] ?? '' ) );
		$email    = (string) ( $user_info['email'] ?? '' );
		$body     = array(
			'amount'            => $amount,
			'merchant_order_id' => IdempotencyKey::merchant_order_id( 'EDD-', '' === $number ? (string) $order_id : $number, $attempt ),
			'description'       => mb_substr(
				/* translators: 1: site name, 2: order number. */
				sprintf( __( '%1$s order #%2$s', 'varizet-woocommerce' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), '' === $number ? (string) $order_id : $number ),
				0,
				255
			),
			'expires_in'        => Settings::expires_in(),
			'metadata'          => array(
				'edd_order_id' => (string) $order_id,
				'edd_attempt'  => (string) $attempt,
				'site'         => mb_substr( (string) wp_parse_url( home_url(), PHP_URL_HOST ), 0, 200 ),
				'site_id'      => Settings::site_fingerprint(),
				'plugin'       => 'edd/' . VARIZET_WC_VERSION,
			),
		);
		$customer = array_filter(
			array(
				'name'  => '' === $name ? null : mb_substr( $name, 0, 120 ),
				'email' => is_email( $email ) ? $email : null,
			),
			static fn ( $v ) => null !== $v
		);
		if ( array() !== $customer ) {
			$body['customer'] = $customer;
		}
		$return_url = self::return_url( $order_id );
		if ( str_starts_with( $return_url, 'https://' ) || str_starts_with( $return_url, 'http://localhost' ) ) {
			$body['return_url'] = $return_url;
		}
		return $body;
	}

	/**
	 * Success page with the confirmation marker.
	 *
	 * @param int $order_id EDD order id.
	 */
	public static function return_url( int $order_id ): string {
		return add_query_arg(
			array(
				'payment-confirmation' => self::ID,
				'payment-id'           => $order_id,
			),
			edd_get_success_page_uri()
		);
	}

	/**
	 * The customer is back on the success page: sync once before the receipt renders.
	 */
	public static function maybe_return(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only sync of the buyer's own order.
		if ( ! isset( $_GET['payment-confirmation'], $_GET['payment-id'] ) || self::ID !== $_GET['payment-confirmation'] ) {
			return;
		}
		$order_id = absint( $_GET['payment-id'] );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$session = function_exists( 'edd_get_purchase_session' ) ? edd_get_purchase_session() : null;
		$order   = $order_id > 0 ? edd_get_order( $order_id ) : null;
		// Only the buyer's browser (EDD purchase session) may trigger the sync.
		if ( ! $order || self::ID !== $order->gateway || ! is_array( $session ) || ( $session['purchase_key'] ?? '' ) !== $order->payment_key ) {
			return;
		}
		EddSync::refresh( $order_id );
	}

	/**
	 * EDD settings section under Payments.
	 *
	 * @param array<string, string> $sections Sections.
	 * @return array<string, string>
	 */
	public static function settings_section( $sections ): array {
		$sections             = (array) $sections;
		$sections[ self::ID ] = __( 'Varizet', 'varizet-woocommerce' );
		return $sections;
	}

	/**
	 * EDD settings: the connection lives on the Varizet page; EDD's test mode picks the key.
	 *
	 * @param array<string, mixed> $settings Gateway settings.
	 * @return array<string, mixed>
	 */
	public static function settings( $settings ): array {
		$settings = (array) $settings;
		$mode     = self::mode();
		$state    = Credentials::is_connected( $mode )
			/* translators: %s: Live or Test. */
			? sprintf( __( 'Connected (%s).', 'varizet-woocommerce' ), 'test' === $mode ? 'Test' : 'Live' )
			/* translators: %s: Live or Test. */
			: sprintf( __( 'Not connected in %s mode.', 'varizet-woocommerce' ), 'test' === $mode ? 'Test' : 'Live' );

		$settings[ self::ID ] = array(
			'varizet_edd_header'     => array(
				'id'   => 'varizet_edd_header',
				'name' => '<strong>' . esc_html__( 'Varizet', 'varizet-woocommerce' ) . '</strong>',
				'type' => 'header',
			),
			'varizet_edd_connection' => array(
				'id'   => 'varizet_edd_connection',
				'name' => __( 'Connection', 'varizet-woocommerce' ),
				'type' => 'descriptive_text',
				'desc' => esc_html( $state ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=varizet' ) ) . '">' . esc_html__( 'Manage the Varizet connection', 'varizet-woocommerce' ) . '</a><br>'
					. esc_html__( 'EDD test mode uses the Varizet test key; the payment window and order prefix follow the Varizet settings.', 'varizet-woocommerce' )
					. '<br>' . esc_html__( 'Webhook URL', 'varizet-woocommerce' ) . ': <code dir="ltr">' . esc_html( Settings::webhook_url() ) . '</code>',
			),
		);
		return $settings;
	}

	/**
	 * Adds the 5-minute schedule.
	 *
	 * @param array<string, mixed> $schedules Schedules.
	 * @return array<string, mixed>
	 */
	public static function cron_schedules( $schedules ): array {
		$schedules                   = (array) $schedules;
		$schedules[ self::SCHEDULE ] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 5 minutes (Varizet)', 'varizet-woocommerce' ),
		);
		return $schedules;
	}

	/**
	 * Keeps the poll scheduled while EDD is active.
	 */
	public static function ensure_scheduled(): void {
		if ( ! wp_next_scheduled( self::POLL_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::SCHEDULE, self::POLL_HOOK );
		}
	}

	/**
	 * Removes the schedule (deactivation).
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::POLL_HOOK );
	}

	/**
	 * Polling fallback: open Varizet orders of pending / on-hold EDD orders, one GET each, until
	 * `grace_until` + 6 h. No calendar-day filters.
	 */
	public static function poll(): void {
		$ids  = edd_get_orders(
			array(
				'gateway'    => self::ID,
				'status__in' => array( 'pending', 'processing', 'on_hold' ),
				'number'     => 200,
				'fields'     => 'ids',
				'orderby'    => 'date_modified',
				'order'      => 'ASC',
			)
		);
		$done = 0;
		foreach ( (array) $ids as $order_id ) {
			$order_id = (int) $order_id;
			$status   = (string) edd_get_order_meta( $order_id, EddSync::STATUS, true );
			$grace    = strtotime( (string) edd_get_order_meta( $order_id, EddSync::GRACE_UNTIL, true ) );
			if ( ! StatusMapper::is_open( $status ) || false === $grace || $grace + 6 * HOUR_IN_SECONDS < time() ) {
				continue;
			}
			$result = EddSync::refresh( $order_id );
			if ( 'error' === $result || ++$done >= self::POLL_SIZE ) {
				break;
			}
		}
	}

	/**
	 * Back to checkout with an error.
	 *
	 * @param string $message Message.
	 * @return never
	 */
	private static function back( string $message ): void {
		edd_set_error( 'varizet_error', $message );
		edd_send_back_to_checkout( '?payment-mode=' . self::ID );
		exit;
	}
}
