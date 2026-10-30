<?php
/**
 * Live payment widget on the thank-you page and the status proxy route.
 *
 * @package Varizet\WooCommerce
 */

namespace Varizet\WooCommerce\ThankYou;

use Varizet\WooCommerce\Api\ApiException;
use Varizet\WooCommerce\Api\Client;
use Varizet\WooCommerce\Gateway\Gateway;
use Varizet\WooCommerce\Order\OrderMeta;
use Varizet\WooCommerce\Order\OrderSync;
use Varizet\WooCommerce\Order\StatusMapper;
use Varizet\WooCommerce\Support\Amount;
use Varizet\WooCommerce\Support\Format;
use Varizet\WooCommerce\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * The widget shows the unique amount (amber digits), the destination card with copy buttons, a
 * countdown and live steps, then switches to «پرداخت تایید شد» without a reload.
 *
 * The browser polls a same-origin route, `GET /wp-json/varizet/v1/orders/{id}/status?key=…`
 * (authorised by the WooCommerce order key, like the order-received page itself), which reads
 * `/pay/v1/checkouts/{token}/status` server-side: no CORS, no payment token in the browser.
 * Upstream answers are cached for a few seconds per order because every shopper's poll leaves
 * from this server's single IP (the API limits `/status` per IP). When the upstream says the
 * order moved, the WooCommerce order is synced immediately so both change in the same second.
 */
final class LiveStatus {

	private const CACHE_TTL  = 3;
	private const VIEW_TTL   = 300;
	private const RATE_LIMIT = 40;

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( 'woocommerce_thankyou_' . Gateway::ID, array( self::class, 'render' ), 5 );
		add_action( 'woocommerce_view_order', array( self::class, 'render_on_view_order' ), 5 );
		add_action( 'rest_api_init', array( self::class, 'register_route' ) );
	}

	/**
	 * Proxy route.
	 */
	public static function register_route(): void {
		register_rest_route(
			'varizet/v1',
			'/orders/(?P<id>\d+)/status',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'status' ),
				// Authorised by the order key inside the callback (guest checkouts have no user).
				'permission_callback' => '__return_true',
				'args'                => array(
					'id'  => array(
						'type'     => 'integer',
						'required' => true,
					),
					'key' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * My Account → View order, for unpaid Varizet orders.
	 *
	 * @param int $order_id Order id.
	 */
	public static function render_on_view_order( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( $order instanceof \WC_Order && Gateway::ID === $order->get_payment_method() && ! $order->is_paid() ) {
			self::render( $order_id );
		}
	}

	/**
	 * Renders the widget.
	 *
	 * @param int $order_id Order id.
	 */
	public static function render( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || '' === (string) $order->get_meta( OrderMeta::ORDER_ID ) ) {
			return;
		}
		$persian = str_starts_with( determine_locale(), 'fa' );
		$status  = (string) $order->get_meta( OrderMeta::STATUS );
		$view    = StatusMapper::AWAITING === $status ? self::view( $order ) : array();
		$model   = self::model( $order, $view );

		wp_enqueue_style( 'varizet-front', VARIZET_WC_URL . 'assets/css/front.css', array(), VARIZET_WC_VERSION );
		wp_enqueue_script( 'varizet-thankyou', VARIZET_WC_URL . 'assets/js/thankyou.js', array(), VARIZET_WC_VERSION, true );

		$endpoint = add_query_arg(
			array( 'key' => $order->get_order_key() ),
			rest_url( 'varizet/v1/orders/' . $order->get_id() . '/status' )
		);
		$strings  = array(
			'awaiting'   => __( 'Awaiting transfer', 'varizet-woocommerce' ),
			'reviewing'  => __( 'Being reviewed', 'varizet-woocommerce' ),
			'paid'       => __( 'Payment confirmed', 'varizet-woocommerce' ),
			'underpaid'  => __( 'Underpaid', 'varizet-woocommerce' ),
			'expired'    => __( 'Expired', 'varizet-woocommerce' ),
			'canceled'   => __( 'Cancelled', 'varizet-woocommerce' ),
			'grace'      => __( 'Grace period', 'varizet-woocommerce' ),
			'copied'     => __( 'Copied', 'varizet-woocommerce' ),
			'copy'       => __( 'Copy', 'varizet-woocommerce' ),
			'offline'    => __( 'Reconnecting…', 'varizet-woocommerce' ),
			'live'       => __( 'Live connection', 'varizet-woocommerce' ),
			'fromCard'   => __( 'From card', 'varizet-woocommerce' ),
			'deposit2'   => __( 'Deposit received; matching it with your order…', 'varizet-woocommerce' ),
			'reviewNote' => __( 'Your transfer was received and is being reviewed by the store. No need to transfer again.', 'varizet-woocommerce' ),
		);

		include VARIZET_WC_DIR . 'templates/thankyou-widget.php';

		wp_add_inline_script(
			'varizet-thankyou',
			'window.varizetThankYou = ' . wp_json_encode(
				array(
					'endpoint' => esc_url_raw( $endpoint ),
					'persian'  => $persian,
					'strings'  => $strings,
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Data for the template.
	 *
	 * @param \WC_Order            $order Order.
	 * @param array<string, mixed> $view  Checkout view (may be empty).
	 * @return array<string, mixed>
	 */
	private static function model( \WC_Order $order, array $view ): array {
		$amount  = (int) $order->get_meta( OrderMeta::AMOUNT );
		$unique  = (int) $order->get_meta( OrderMeta::UNIQUE_AMOUNT );
		$dest    = OrderMeta::array_meta( $order, OrderMeta::DESTINATION );
		$vdest   = isset( $view['destination'] ) && is_array( $view['destination'] ) ? $view['destination'] : array();
		$digits  = isset( $view['uniqueDigits'] ) ? (int) $view['uniqueDigits'] : Amount::unique_digits( $amount, $unique );
		$status  = (string) $order->get_meta( OrderMeta::STATUS );
		$display = self::display_status( $status, $order );

		$pan = isset( $vdest['pan'] ) && is_string( $vdest['pan'] ) && preg_match( '/^\d{16}$/', $vdest['pan'] ) ? $vdest['pan'] : '';

		$currency = $order->get_currency();
		$store    = Amount::is_supported( $currency ) ? Amount::from_rial( $unique, $currency ) : '';
		return array(
			'order'        => $order,
			'code'         => (string) $order->get_meta( OrderMeta::CODE ),
			'status'       => $status,
			'display'      => $display,
			'unique'       => $unique,
			'digits'       => max( 1, min( 6, $digits ) ),
			'store_amount' => $store,
			'currency'     => $currency,
			'bank'         => (string) ( $vdest['bankName'] ?? self::bank_name( (string) ( $dest['bank'] ?? '' ) ) ),
			'bank_code'    => (string) ( $vdest['bank'] ?? ( $dest['bank'] ?? '' ) ),
			'owner'        => (string) ( $vdest['ownerName'] ?? ( $dest['owner_name'] ?? '' ) ),
			'verified'     => ! empty( $vdest['ownerVerified'] ),
			'pan'          => $pan,
			'last4'        => (string) ( $dest['last4'] ?? substr( $pan, -4 ) ),
			'expires_at'   => (string) ( $view['expiresAt'] ?? $order->get_meta( OrderMeta::EXPIRES_AT ) ),
			'grace_until'  => (string) ( $view['graceUntil'] ?? $order->get_meta( OrderMeta::GRACE_UNTIL ) ),
			'created_at'   => (string) ( $view['createdAt'] ?? '' ),
			'server_time'  => (string) ( $view['serverTime'] ?? gmdate( 'c' ) ),
			'payment_url'  => (string) $order->get_meta( OrderMeta::PAYMENT_URL ),
			'paid_at'      => (string) $order->get_meta( OrderMeta::PAID_AT ),
			'paid_amount'  => (int) $order->get_meta( OrderMeta::PAID_AMOUNT ),
			'payer_last4'  => (string) $order->get_meta( OrderMeta::PAYER_LAST4 ),
			'test'         => 'test' === OrderMeta::mode( $order ),
			'pay_url'      => $order->needs_payment() ? $order->get_checkout_payment_url() : '',
		);
	}

	/**
	 * Cached checkout view (destination card for copying). The PAN is the store's own card,
	 * shown to every payer; it is never logged.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<string, mixed>
	 */
	private static function view( \WC_Order $order ): array {
		$token = (string) $order->get_meta( OrderMeta::TOKEN );
		if ( '' === $token ) {
			return array();
		}
		$cache_key = 'varizet_view_' . $order->get_id() . '_' . substr( md5( $token ), 0, 8 );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		try {
			$view = Client::public_client()->checkout_view( $token );
		} catch ( ApiException $e ) {
			Logger::warning( sprintf( 'Checkout view for order #%d unavailable (%s)', $order->get_id(), $e->error_code ), array( 'component' => 'thankyou' ) + $e->context() );
			return array();
		}
		$keep = array_intersect_key( $view, array_flip( array( 'code', 'status', 'displayStatus', 'uniqueAmount', 'uniqueDigits', 'destination', 'expiresAt', 'graceUntil', 'createdAt', 'serverTime', 'inGrace', 'livemode' ) ) );
		set_transient( $cache_key, $keep, self::VIEW_TTL );
		return $keep;
	}

	/**
	 * GET /varizet/v1/orders/{id}/status?key=…
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function status( \WP_REST_Request $request ): \WP_REST_Response {
		$order = wc_get_order( (int) $request['id'] );
		$key   = (string) $request['key'];
		if ( ! $order instanceof \WC_Order || '' === $key || ! hash_equals( $order->get_order_key(), $key ) || '' === (string) $order->get_meta( OrderMeta::ORDER_ID ) ) {
			return self::json( array( 'error' => 'not_found' ), 404 );
		}
		if ( ! self::within_rate_limit( $order->get_id() ) ) {
			return self::json( array( 'error' => 'too_many_requests' ), 429 );
		}

		$status = (string) $order->get_meta( OrderMeta::STATUS );
		$step   = null;
		if ( ! StatusMapper::is_terminal( $status ) ) {
			$upstream = self::upstream_status( $order );
			if ( null !== $upstream ) {
				$step = isset( $upstream['step'] ) ? (string) $upstream['step'] : null;
				if ( isset( $upstream['status'] ) && $upstream['status'] !== $status ) {
					// The API moved first (webhook not here yet): sync now so WooCommerce matches.
					try {
						OrderSync::refresh( $order, 'widget' );
					} catch ( ApiException $e ) {
						Logger::warning( sprintf( 'Widget sync of order #%d failed (%s)', $order->get_id(), $e->error_code ), array( 'component' => 'thankyou' ) + $e->context() );
					}
					$order  = wc_get_order( $order->get_id() );
					$status = (string) $order->get_meta( OrderMeta::STATUS );
				}
			}
		}

		$paid_amount = (int) $order->get_meta( OrderMeta::PAID_AMOUNT );
		return self::json(
			array(
				'status'      => $status,
				'display'     => self::display_status( $status, $order ),
				'step'        => $step ?? self::step_for( $status ),
				'terminal'    => StatusMapper::is_terminal( $status ),
				'wc_status'   => $order->get_status(),
				'wc_label'    => wc_get_order_status_name( $order->get_status() ),
				'paid_at'     => (string) $order->get_meta( OrderMeta::PAID_AT ),
				'paid_amount' => $paid_amount > 0 ? $paid_amount : null,
				'payer_last4' => (string) $order->get_meta( OrderMeta::PAYER_LAST4 ),
				'pay_url'     => $order->needs_payment() ? $order->get_checkout_payment_url() : '',
				'server_time' => gmdate( 'c' ),
			)
		);
	}

	/**
	 * Upstream /status with a short per-order cache.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<string, mixed>|null
	 */
	private static function upstream_status( \WC_Order $order ): ?array {
		$token = (string) $order->get_meta( OrderMeta::TOKEN );
		if ( '' === $token ) {
			return null;
		}
		$cache_key = 'varizet_st_' . $order->get_id();
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		try {
			$status = Client::public_client()->checkout_status( $token );
		} catch ( ApiException $e ) {
			Logger::debug( sprintf( 'Upstream status for order #%d failed (%s)', $order->get_id(), $e->error_code ), array( 'component' => 'thankyou' ) + $e->context() );
			set_transient( $cache_key, array(), self::CACHE_TTL );
			return null;
		}
		$keep = array_intersect_key( $status, array_flip( array( 'status', 'displayStatus', 'step', 'inGrace' ) ) );
		set_transient( $cache_key, $keep, self::CACHE_TTL );
		return $keep;
	}

	/**
	 * Widget status (awaiting|reviewing|paid|underpaid|expired|canceled).
	 *
	 * @param string    $status Varizet status.
	 * @param \WC_Order $order  Order.
	 */
	private static function display_status( string $status, \WC_Order $order ): string {
		switch ( $status ) {
			case StatusMapper::PAID:
			case StatusMapper::REFUNDED:
			case StatusMapper::PARTIALLY_REFUNDED:
				return 'paid';
			case StatusMapper::NEEDS_REVIEW:
				return 'reviewing';
			case StatusMapper::UNDERPAID:
				return 'underpaid';
			case StatusMapper::EXPIRED:
				return 'expired';
			case StatusMapper::CANCELED:
				return 'canceled';
		}
		return $order->is_paid() ? 'paid' : 'awaiting';
	}

	/**
	 * Step for a status when the upstream has none.
	 *
	 * @param string $status Varizet status.
	 */
	private static function step_for( string $status ): string {
		if ( StatusMapper::PAID === $status ) {
			return 'confirmed';
		}
		if ( StatusMapper::NEEDS_REVIEW === $status || StatusMapper::UNDERPAID === $status ) {
			return 'matching';
		}
		return 'awaiting_deposit';
	}

	/**
	 * Fixed-window limit per client and order.
	 *
	 * @param int $order_id Order id.
	 */
	private static function within_rate_limit( int $order_id ): bool {
		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key   = 'varizet_tyrl_' . md5( $ip . '|' . $order_id . '|' . (int) floor( time() / 60 ) );
		$count = (int) get_transient( $key );
		if ( $count >= self::RATE_LIMIT ) {
			return false;
		}
		set_transient( $key, $count + 1, 65 );
		return true;
	}

	/**
	 * Uncached JSON response.
	 *
	 * @param array<string, mixed> $data   Body.
	 * @param int                  $status HTTP status.
	 */
	private static function json( array $data, int $status = 200 ): \WP_REST_Response {
		$response = new \WP_REST_Response( $data, $status );
		$response->set_headers(
			array(
				'Cache-Control' => 'no-store, max-age=0',
				'X-Robots-Tag'  => 'noindex',
			)
		);
		return $response;
	}

	/**
	 * Persian bank name for a bank code (fallback when the checkout view is unavailable).
	 *
	 * @param string $code Bank code.
	 */
	public static function bank_name( string $code ): string {
		$names = array(
			'melli'          => __( 'Bank Melli', 'varizet-woocommerce' ),
			'mellat'         => __( 'Bank Mellat', 'varizet-woocommerce' ),
			'saderat'        => __( 'Bank Saderat', 'varizet-woocommerce' ),
			'tejarat'        => __( 'Bank Tejarat', 'varizet-woocommerce' ),
			'saman'          => __( 'Saman Bank', 'varizet-woocommerce' ),
			'pasargad'       => __( 'Pasargad Bank', 'varizet-woocommerce' ),
			'sepah'          => __( 'Bank Sepah', 'varizet-woocommerce' ),
			'refah'          => __( 'Refah Bank', 'varizet-woocommerce' ),
			'maskan'         => __( 'Bank Maskan', 'varizet-woocommerce' ),
			'keshavarzi'     => __( 'Bank Keshavarzi', 'varizet-woocommerce' ),
			'parsian'        => __( 'Parsian Bank', 'varizet-woocommerce' ),
			'shahr'          => __( 'Shahr Bank', 'varizet-woocommerce' ),
			'ayandeh'        => __( 'Ayandeh Bank', 'varizet-woocommerce' ),
			'eghtesad_novin' => __( 'EN Bank', 'varizet-woocommerce' ),
			'blu'            => __( 'Blu Bank', 'varizet-woocommerce' ),
			'resalat'        => __( 'Resalat Bank', 'varizet-woocommerce' ),
		);
		return $names[ $code ] ?? ( '' === $code ? '' : ucfirst( str_replace( '_', ' ', $code ) ) );
	}

	/**
	 * Localised number.
	 *
	 * @param int  $n       Number.
	 * @param bool $persian Persian digits.
	 */
	public static function num( int $n, bool $persian ): string {
		return Format::number( $n, $persian );
	}
}
