<?php
/**
 * «واریزت» column, filter and bulk sync on the orders list (HPOS and legacy).
 *
 * @package Varizet\WooCommerce
 */

namespace Varizet\WooCommerce\Order;

use Varizet\WooCommerce\Admin\SettingsPage;
use Varizet\WooCommerce\Api\ApiException;
use Varizet\WooCommerce\Gateway\Gateway;
use Varizet\WooCommerce\Support\Amount;
use Varizet\WooCommerce\Support\Format;

defined( 'ABSPATH' ) || exit;

/**
 * The column shows the Varizet status badge and the unique amount with amber digits, plus the
 * time left for orders awaiting payment. The filter narrows the list to Varizet orders or one
 * Varizet status; the bulk action re-reads the selected orders from the API.
 */
final class ListTable {

	public const COLUMN = 'varizet';
	public const FILTER = 'varizet_filter';

	/**
	 * Hooks for both order storages.
	 */
	public static function init(): void {
		// HPOS (wc-orders page).
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( self::class, 'columns' ) );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( self::class, 'render_hpos' ), 10, 2 );
		add_action( 'woocommerce_order_list_table_restrict_manage_orders', array( self::class, 'filter_ui' ) );
		add_filter( 'woocommerce_order_list_table_prepare_items_query_args', array( self::class, 'filter_hpos' ) );
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( self::class, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', array( self::class, 'handle_bulk' ), 10, 3 );

		// Legacy posts storage.
		add_filter( 'manage_edit-shop_order_columns', array( self::class, 'columns' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( self::class, 'render_legacy' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( self::class, 'filter_ui_legacy' ) );
		add_action( 'pre_get_posts', array( self::class, 'filter_legacy' ) );
		add_filter( 'bulk_actions-edit-shop_order', array( self::class, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-shop_order', array( self::class, 'handle_bulk' ), 10, 3 );
	}

	/**
	 * Adds the column before the total.
	 *
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public static function columns( $columns ): array {
		$out = array();
		foreach ( $columns as $key => $label ) {
			if ( 'order_total' === $key ) {
				$out[ self::COLUMN ] = __( 'Varizet', 'varizet-woocommerce' );
			}
			$out[ $key ] = $label;
		}
		if ( ! isset( $out[ self::COLUMN ] ) ) {
			$out[ self::COLUMN ] = __( 'Varizet', 'varizet-woocommerce' );
		}
		return $out;
	}

	/**
	 * HPOS cell.
	 *
	 * @param string    $column Column.
	 * @param \WC_Order $order  Order.
	 */
	public static function render_hpos( $column, $order ): void {
		if ( self::COLUMN === $column && $order instanceof \WC_Order ) {
			self::cell( $order );
		}
	}

	/**
	 * Legacy cell.
	 *
	 * @param string $column  Column.
	 * @param int    $post_id Order id.
	 */
	public static function render_legacy( $column, $post_id ): void {
		if ( self::COLUMN !== $column ) {
			return;
		}
		$order = wc_get_order( $post_id );
		if ( $order instanceof \WC_Order ) {
			self::cell( $order );
		}
	}

	/**
	 * Cell HTML.
	 *
	 * @param \WC_Order $order Order.
	 */
	private static function cell( \WC_Order $order ): void {
		$status = (string) $order->get_meta( OrderMeta::STATUS );
		if ( '' === $status ) {
			echo '<span class="vz-subtle">—</span>';
			return;
		}
		$persian = str_starts_with( determine_locale(), 'fa' );
		$labels  = OrderSync::status_labels();
		$amount  = (int) $order->get_meta( OrderMeta::AMOUNT );
		$unique  = (int) $order->get_meta( OrderMeta::UNIQUE_AMOUNT );
		echo '<div class="varizet-admin vz-cell">';
		printf( '<span class="vz-badge %1$s vz-dot">%2$s</span>', esc_attr( MetaBox::badge_class( $status ) ), esc_html( $labels[ $status ] ?? $status ) );
		echo Format::unique_amount_html( $unique, Amount::unique_digits( $amount, $unique ), $persian ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Format.
		if ( StatusMapper::AWAITING === $status ) {
			$expires = strtotime( (string) $order->get_meta( OrderMeta::EXPIRES_AT ) );
			if ( false !== $expires && $expires > time() ) {
				/* translators: %s: time left. */
				printf( '<span class="vz-xs vz-subtle">%s</span>', esc_html( SettingsPage::n( sprintf( __( '%s left', 'varizet-woocommerce' ), human_time_diff( time(), $expires ) ) ) ) );
			} else {
				printf( '<span class="vz-xs vz-subtle">%s</span>', esc_html__( 'Grace period', 'varizet-woocommerce' ) );
			}
		}
		if ( 'test' === OrderMeta::mode( $order ) ) {
			printf( '<span class="vz-badge vz-badge-warning">%s</span>', esc_html__( 'Test', 'varizet-woocommerce' ) );
		}
		echo '</div>';
	}

	/**
	 * Filter dropdown (HPOS).
	 *
	 * @param string $order_type Order type.
	 */
	public static function filter_ui( $order_type = 'shop_order' ): void {
		if ( 'shop_order' !== $order_type ) {
			return;
		}
		self::dropdown();
	}

	/**
	 * Filter dropdown (legacy).
	 *
	 * @param string $post_type Post type.
	 */
	public static function filter_ui_legacy( $post_type ): void {
		if ( 'shop_order' === $post_type ) {
			self::dropdown();
		}
	}

	/**
	 * The dropdown itself.
	 */
	private static function dropdown(): void {
		$current = self::current_filter();
		$options = array(
			''        => __( 'All payment methods', 'varizet-woocommerce' ),
			'all'     => __( 'Varizet orders only', 'varizet-woocommerce' ),
			'open'    => __( 'Varizet: awaiting payment', 'varizet-woocommerce' ),
			'review'  => __( 'Varizet: needs review or underpaid', 'varizet-woocommerce' ),
			'paid'    => __( 'Varizet: paid', 'varizet-woocommerce' ),
			'expired' => __( 'Varizet: expired or cancelled', 'varizet-woocommerce' ),
		);
		echo '<label class="screen-reader-text" for="varizet-filter">' . esc_html__( 'Filter by Varizet', 'varizet-woocommerce' ) . '</label>';
		echo '<select name="' . esc_attr( self::FILTER ) . '" id="varizet-filter">';
		foreach ( $options as $value => $label ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $value ), selected( $current, $value, false ), esc_html( $label ) );
		}
		echo '</select>';
	}

	/**
	 * Current filter value.
	 */
	private static function current_filter(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- list filter, read-only.
		return isset( $_GET[ self::FILTER ] ) ? sanitize_key( wp_unslash( $_GET[ self::FILTER ] ) ) : '';
	}

	/**
	 * Meta query for a filter value.
	 *
	 * @param string $filter Filter.
	 * @return array<int|string, mixed>|null
	 */
	private static function meta_query( string $filter ): ?array {
		$statuses = array(
			'open'    => array( StatusMapper::AWAITING ),
			'review'  => array( StatusMapper::NEEDS_REVIEW, StatusMapper::UNDERPAID ),
			'paid'    => array( StatusMapper::PAID ),
			'expired' => array( StatusMapper::EXPIRED, StatusMapper::CANCELED ),
		);
		if ( ! isset( $statuses[ $filter ] ) ) {
			return null;
		}
		return array(
			array(
				'key'     => OrderMeta::STATUS,
				'value'   => $statuses[ $filter ],
				'compare' => 'IN',
			),
		);
	}

	/**
	 * Applies the filter (HPOS).
	 *
	 * @param array<string, mixed> $args Query args.
	 * @return array<string, mixed>
	 */
	public static function filter_hpos( $args ): array {
		$filter = self::current_filter();
		if ( '' === $filter ) {
			return $args;
		}
		$args['payment_method'] = Gateway::ID;
		$meta                   = self::meta_query( $filter );
		if ( null !== $meta ) {
			$args['meta_query'] = array_merge( isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : array(), $meta ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- admin list filter.
		}
		return $args;
	}

	/**
	 * Applies the filter (legacy).
	 *
	 * @param \WP_Query $query Query.
	 */
	public static function filter_legacy( $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() || 'shop_order' !== $query->get( 'post_type' ) ) {
			return;
		}
		$filter = self::current_filter();
		if ( '' === $filter ) {
			return;
		}
		$meta   = (array) $query->get( 'meta_query' );
		$meta[] = array(
			'key'   => '_payment_method',
			'value' => Gateway::ID,
		);
		$extra  = self::meta_query( $filter );
		if ( null !== $extra ) {
			$meta = array_merge( $meta, $extra );
		}
		$query->set( 'meta_query', $meta );
	}

	/**
	 * Bulk action «همگام‌سازی با واریزت».
	 *
	 * @param array<string, string> $actions Actions.
	 * @return array<string, string>
	 */
	public static function bulk_actions( $actions ): array {
		$actions['varizet_sync'] = __( 'Sync with Varizet', 'varizet-woocommerce' );
		return $actions;
	}

	/**
	 * Handles the bulk action.
	 *
	 * @param string $redirect Redirect URL.
	 * @param string $action   Action.
	 * @param int[]  $ids      Order ids.
	 */
	public static function handle_bulk( $redirect, $action, $ids ): string {
		if ( 'varizet_sync' !== $action ) {
			return (string) $redirect;
		}
		$done   = 0;
		$failed = 0;
		foreach ( array_slice( array_map( 'absint', (array) $ids ), 0, 50 ) as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order instanceof \WC_Order || '' === (string) $order->get_meta( OrderMeta::ORDER_ID ) ) {
				continue;
			}
			try {
				OrderSync::refresh( $order, 'admin' );
				++$done;
			} catch ( ApiException $e ) {
				++$failed;
			}
		}
		SettingsPage::flash(
			0 === $failed ? 'success' : 'error',
			SettingsPage::n(
				sprintf(
					/* translators: 1: synced orders, 2: failed orders. */
					__( '%1$d orders synced with Varizet; %2$d failed.', 'varizet-woocommerce' ),
					$done,
					$failed
				)
			)
		);
		return (string) $redirect;
	}
}
