<?php
/**
 * Varizet metadata on WooCommerce orders (HPOS and CPT alike, through the WC_Order CRUD).
 *
 * @package Varizet\WooCommerce
 */

namespace Varizet\WooCommerce\Order;

use Automattic\WooCommerce\Utilities\OrderUtil;
use Varizet\WooCommerce\Gateway\Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * Meta keys and helpers. Only the WC_Order API is used (never update_post_meta), so the data
 * lands in `wc_orders_meta` with HPOS and in `postmeta` without it.
 */
final class OrderMeta {
	/*
	 * Keys: ORDER_ID is the current attempt (ord_…); ORDER_REF holds every attempt's id as
	 * separate meta rows for webhook lookups; AMOUNT/UNIQUE_AMOUNT are rial; POLL_UNTIL is the
	 * unix horizon of the polling fallback; LIVEMODE is yes|no; CLAIMED_LAST4 is what the customer
	 * typed at checkout and PAYER_LAST4 what Varizet matched; EVENTS keeps processed event ids.
	 */

	public const ORDER_ID      = '_varizet_order_id';
	public const ORDER_REF     = '_varizet_order_ref';
	public const CODE          = '_varizet_code';
	public const STATUS        = '_varizet_status';
	public const ATTEMPT       = '_varizet_attempt';
	public const AMOUNT        = '_varizet_amount';
	public const UNIQUE_AMOUNT = '_varizet_unique_amount';
	public const UNIQUE_MODE   = '_varizet_unique_mode';
	public const PAYMENT_URL   = '_varizet_payment_url';
	public const TOKEN         = '_varizet_token';
	public const EXPIRES_AT    = '_varizet_expires_at';
	public const GRACE_UNTIL   = '_varizet_grace_until';
	public const POLL_UNTIL    = '_varizet_poll_until';
	public const LIVEMODE      = '_varizet_livemode';
	public const DESTINATION   = '_varizet_destination';
	public const PAID_AT       = '_varizet_paid_at';
	public const PAID_AMOUNT   = '_varizet_paid_amount';
	public const PAID_LATE     = '_varizet_paid_late';
	public const MATCH         = '_varizet_match';
	public const PAYER_LAST4   = '_varizet_payer_last4';    // From the API (who paid).
	public const CLAIMED_LAST4 = '_varizet_claimed_last4';  // Typed by the customer at checkout.
	public const LAST_EVENT_AT = '_varizet_last_event_at';
	public const EVENTS        = '_varizet_events';
	public const SYNCED_AT     = '_varizet_synced_at';
	public const TIMELINE      = '_varizet_timeline';
	public const STOCK_WARNING = '_varizet_stock_warning';

	private const MAX_EVENTS   = 30;
	private const MAX_TIMELINE = 40;

	/**
	 * Whether the order was paid (or is being paid) with Varizet.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function is_varizet( \WC_Order $order ): bool {
		return Gateway::ID === $order->get_payment_method() || '' !== (string) $order->get_meta( self::ORDER_ID );
	}

	/**
	 * Livemode of the order's Varizet order.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function mode( \WC_Order $order ): string {
		return 'no' === $order->get_meta( self::LIVEMODE ) ? 'test' : 'live';
	}

	/** Private wc_get_orders() var carrying a meta query for the posts data store. */
	public const QUERY_VAR = 'varizet_meta_query';

	/**
	 * Adds a meta query to wc_get_orders() args for either order storage. HPOS supports
	 * `meta_query` natively; the posts data store ignores it (WC_Data_Store_WP skips the key and
	 * WooCommerce >= 9.2 flags it as doing it wrong), so there it travels in QUERY_VAR and
	 * cpt_meta_query() maps it into the WP_Query args.
	 *
	 * @param array<string, mixed>     $args       wc_get_orders() args.
	 * @param array<int|string, mixed> $meta_query Meta query clauses.
	 * @return array<string, mixed>
	 */
	public static function with_meta_query( array $args, array $meta_query ): array {
		if ( class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- exact-value lookups on plugin meta.
		} else {
			$args[ self::QUERY_VAR ] = $meta_query;
		}
		return $args;
	}

	/**
	 * Maps QUERY_VAR into the posts data store query.
	 * Hooked on `woocommerce_order_data_store_cpt_get_orders_query`.
	 *
	 * @param array $query      WP_Query args.
	 * @param array $query_vars wc_get_orders() args.
	 * @return array
	 */
	public static function cpt_meta_query( $query, $query_vars ) {
		if ( empty( $query_vars[ self::QUERY_VAR ] ) || ! is_array( $query_vars[ self::QUERY_VAR ] ) ) {
			return $query;
		}
		$query['meta_query']   = isset( $query['meta_query'] ) && is_array( $query['meta_query'] ) ? $query['meta_query'] : array(); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- mirrors the HPOS query.
		$query['meta_query'][] = $query_vars[ self::QUERY_VAR ];
		return $query;
	}

	/**
	 * Finds the WooCommerce order that owns a Varizet order id (any attempt).
	 *
	 * @param string $varizet_id ord_….
	 */
	public static function find_by_varizet_id( string $varizet_id ): ?\WC_Order {
		if ( ! preg_match( '/^ord_[0-9A-Za-z]{10,40}$/', $varizet_id ) ) {
			return null;
		}
		$orders = wc_get_orders(
			self::with_meta_query(
				array(
					'limit'  => 1,
					'type'   => 'shop_order',
					'status' => array_keys( wc_get_order_statuses() ),
				),
				array(
					array(
						'key'   => self::ORDER_REF,
						'value' => $varizet_id,
					),
				)
			)
		);
		return isset( $orders[0] ) && $orders[0] instanceof \WC_Order ? $orders[0] : null;
	}

	/**
	 * Stores the API order on the WooCommerce order (no save()).
	 *
	 * @param \WC_Order            $order Order.
	 * @param array<string, mixed> $vz    Varizet order (public API shape).
	 */
	public static function store_order( \WC_Order $order, array $vz ): void {
		$id = (string) ( $vz['id'] ?? '' );
		if ( '' !== $id ) {
			$order->update_meta_data( self::ORDER_ID, $id );
			$refs = array_map( static fn ( $m ) => $m instanceof \WC_Meta_Data ? (string) $m->value : (string) $m, (array) $order->get_meta( self::ORDER_REF, false ) );
			if ( ! in_array( $id, $refs, true ) ) {
				$order->add_meta_data( self::ORDER_REF, $id, false );
			}
		}
		$map = array(
			'code'          => self::CODE,
			'status'        => self::STATUS,
			'amount'        => self::AMOUNT,
			'unique_amount' => self::UNIQUE_AMOUNT,
			'unique_mode'   => self::UNIQUE_MODE,
			'payment_url'   => self::PAYMENT_URL,
			'expires_at'    => self::EXPIRES_AT,
			'grace_until'   => self::GRACE_UNTIL,
			'paid_at'       => self::PAID_AT,
			'paid_amount'   => self::PAID_AMOUNT,
		);
		foreach ( $map as $field => $key ) {
			if ( array_key_exists( $field, $vz ) && null !== $vz[ $field ] ) {
				$order->update_meta_data( $key, is_scalar( $vz[ $field ] ) ? (string) $vz[ $field ] : wp_json_encode( $vz[ $field ] ) );
			}
		}
		if ( isset( $vz['livemode'] ) ) {
			$order->update_meta_data( self::LIVEMODE, $vz['livemode'] ? 'yes' : 'no' );
		}
		if ( isset( $vz['paid_late'] ) ) {
			$order->update_meta_data( self::PAID_LATE, $vz['paid_late'] ? 'yes' : 'no' );
		}
		if ( isset( $vz['payment_url'] ) && is_string( $vz['payment_url'] ) ) {
			$token = self::token_from_url( $vz['payment_url'] );
			if ( null !== $token ) {
				$order->update_meta_data( self::TOKEN, $token );
			}
		}
		if ( isset( $vz['destination'] ) && is_array( $vz['destination'] ) ) {
			$dest = array_intersect_key( $vz['destination'], array_flip( array( 'card_id', 'bank', 'last4', 'owner_name' ) ) );
			$order->update_meta_data( self::DESTINATION, $dest );
		}
		if ( isset( $vz['match'] ) && is_array( $vz['match'] ) ) {
			$match = array_intersect_key( $vz['match'], array_flip( array( 'score', 'channel', 'reasons', 'deposit_id' ) ) );
			$order->update_meta_data( self::MATCH, $match );
		}
		if ( isset( $vz['payer']['card_last4'] ) && preg_match( '/^\d{4}$/', (string) $vz['payer']['card_last4'] ) ) {
			$order->update_meta_data( self::PAYER_LAST4, (string) $vz['payer']['card_last4'] );
		}
		$order->update_meta_data( self::SYNCED_AT, (string) time() );
		self::update_poll_horizon( $order, (string) ( $vz['status'] ?? '' ), isset( $vz['grace_until'] ) ? (string) $vz['grace_until'] : null );
	}

	/**
	 * Keeps the order in the polling set while it can still change.
	 * Awaiting: until grace_until + 6 h (catches a missed `order.expired`).
	 * Review / underpaid: 7 days (a human decides in the panel).
	 * Anything else: removed from the set.
	 *
	 * @param \WC_Order   $order       Order.
	 * @param string      $status      Varizet status.
	 * @param string|null $grace_until ISO time.
	 */
	public static function update_poll_horizon( \WC_Order $order, string $status, ?string $grace_until ): void {
		if ( StatusMapper::AWAITING === $status ) {
			$grace = $grace_until ? strtotime( $grace_until ) : false;
			$until = ( false === $grace ? time() + DAY_IN_SECONDS : $grace ) + 6 * HOUR_IN_SECONDS;
			$order->update_meta_data( self::POLL_UNTIL, (string) $until );
			return;
		}
		if ( StatusMapper::is_open( $status ) ) {
			$current = (int) $order->get_meta( self::POLL_UNTIL );
			$order->update_meta_data( self::POLL_UNTIL, (string) max( $current, time() + 7 * DAY_IN_SECONDS ) );
			return;
		}
		$order->update_meta_data( self::POLL_UNTIL, '0' );
	}

	/**
	 * Payment token from `https://pay.varizet.ir/p/<token>`.
	 *
	 * @param string $url Payment URL.
	 */
	public static function token_from_url( string $url ): ?string {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( preg_match( '#/([0-9A-Za-z]{16,64})/?$#', $path, $m ) ) {
			return $m[1];
		}
		return null;
	}

	/**
	 * Whether an event id was already processed for this order.
	 *
	 * @param \WC_Order $order    Order.
	 * @param string    $event_id evt_….
	 */
	public static function has_event( \WC_Order $order, string $event_id ): bool {
		$events = $order->get_meta( self::EVENTS );
		return is_array( $events ) && in_array( $event_id, $events, true );
	}

	/**
	 * Remembers a processed event id (bounded list).
	 *
	 * @param \WC_Order $order    Order.
	 * @param string    $event_id evt_….
	 */
	public static function remember_event( \WC_Order $order, string $event_id ): void {
		$events   = $order->get_meta( self::EVENTS );
		$events   = is_array( $events ) ? $events : array();
		$events[] = $event_id;
		$order->update_meta_data( self::EVENTS, array_slice( array_values( array_unique( $events ) ), -self::MAX_EVENTS ) );
	}

	/**
	 * Adds a timeline entry (shown in the order meta box).
	 *
	 * @param \WC_Order $order  Order.
	 * @param string    $type   Machine type (created, webhook, poll, status, …).
	 * @param string    $text   Already translated text.
	 * @param string    $source webhook|poll|checkout|admin|widget.
	 */
	public static function timeline( \WC_Order $order, string $type, string $text, string $source = '' ): void {
		$items   = $order->get_meta( self::TIMELINE );
		$items   = is_array( $items ) ? $items : array();
		$items[] = array(
			't'    => time(),
			'type' => $type,
			'text' => $text,
			'src'  => $source,
		);
		$order->update_meta_data( self::TIMELINE, array_slice( $items, -self::MAX_TIMELINE ) );
	}

	/**
	 * Decoded array meta (destination, match).
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $key   Meta key.
	 * @return array<string, mixed>
	 */
	public static function array_meta( \WC_Order $order, string $key ): array {
		$value = $order->get_meta( $key );
		if ( is_string( $value ) && '' !== $value ) {
			$value = json_decode( $value, true );
		}
		return is_array( $value ) ? $value : array();
	}
}
