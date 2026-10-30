<?php
/**
 * Polling fallback with Action Scheduler.
 *
 * @package Varizet\WooCommerce
 */

namespace Varizet\WooCommerce\Sync;

use Varizet\WooCommerce\Api\ApiException;
use Varizet\WooCommerce\Connect\Credentials;
use Varizet\WooCommerce\Gateway\Gateway;
use Varizet\WooCommerce\Order\OrderMeta;
use Varizet\WooCommerce\Order\OrderSync;
use Varizet\WooCommerce\Order\StatusMapper;
use Varizet\WooCommerce\Platform;
use Varizet\WooCommerce\Support\Logger;


defined( 'ABSPATH' ) || exit;

/**
 * Implements docs/10 §1.4 step 5: every 2 minutes, only orders whose `_varizet_status` is still open and
 * whose `_varizet_poll_until` horizon has not passed are re-read by their Varizet id. There is
 * no day-based or created-at filter anywhere (the competitor's «وضعیت هرگز همگام نمی‌شد» bug):
 * an order leaves the set only when Varizet says it is settled or its horizon ends.
 * `meta_query` through wc_get_orders() works on HPOS and on the posts table alike.
 */
final class Scheduler {

	public const HOOK       = 'varizet_sync_open_orders';
	public const ORDER_HOOK = 'varizet_sync_order';
	public const GROUP      = 'varizet';
	public const INTERVAL   = 120;
	public const BATCH      = 40;
	public const STATE      = 'varizet_sync_state';

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( 'action_scheduler_init', array( self::class, 'ensure_scheduled' ) );
		add_action( self::HOOK, array( self::class, 'run' ) );
		add_action( self::ORDER_HOOK, array( self::class, 'run_one' ) );
	}

	/**
	 * Schedules the recurring action once.
	 */
	public static function ensure_scheduled(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) || as_has_scheduled_action( self::HOOK, array(), self::GROUP ) ) {
			return;
		}
		as_schedule_recurring_action( time() + 30, self::INTERVAL, self::HOOK, array(), self::GROUP, true );
	}

	/**
	 * Removes all scheduled actions (deactivation).
	 */
	public static function unschedule(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array(), self::GROUP );
			as_unschedule_all_actions( self::ORDER_HOOK, null, self::GROUP );
		}
	}

	/**
	 * Queues an immediate check of one order (thank-you page, admin).
	 *
	 * @param int $order_id Order id.
	 */
	public static function enqueue_order( int $order_id ): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::ORDER_HOOK, array( 'order_id' => $order_id ), self::GROUP, true );
		}
	}

	/**
	 * Open orders that still need checking, round-robin through the set in batches.
	 *
	 * @param int $offset Offset into the set.
	 * @param int $limit  Batch size.
	 * @return \WC_Order[]
	 */
	public static function open_orders( int $offset = 0, int $limit = self::BATCH ): array {
		if ( ! Platform::has_wc() ) {
			return array(); // EDD-only site: EddGateway polls its own orders.
		}
		// The polling set is defined by order meta only: no date filters.
		$orders = wc_get_orders(
			OrderMeta::with_meta_query(
				array(
					'limit'          => $limit,
					'offset'         => $offset,
					'type'           => 'shop_order',
					'status'         => array_keys( wc_get_order_statuses() ),
					'payment_method' => Gateway::ID,
					'orderby'        => 'ID',
					'order'          => 'ASC',
				),
				array(
					'relation' => 'AND',
					array(
						'key'     => OrderMeta::STATUS,
						'value'   => StatusMapper::OPEN,
						'compare' => 'IN',
					),
					array(
						'key'     => OrderMeta::POLL_UNTIL,
						'value'   => time(),
						'compare' => '>',
						'type'    => 'NUMERIC',
					),
				)
			)
		);
		return array_values( array_filter( (array) $orders, static fn ( $o ) => $o instanceof \WC_Order ) );
	}

	/**
	 * Recurring job.
	 */
	public static function run(): void {
		$state = self::state();
		if ( array() === array_filter( array_map( array( Credentials::class, 'is_connected' ), Credentials::MODES ) ) ) {
			return;
		}
		$offset  = (int) ( $state['offset'] ?? 0 );
		$orders  = self::open_orders( $offset );
		$checked = 0;
		$changed = 0;
		$errors  = 0;
		$started = microtime( true );

		foreach ( $orders as $order ) {
			try {
				$result = OrderSync::refresh( $order, 'poll' );
				++$checked;
				if ( OrderSync::APPLIED === $result ) {
					++$changed;
				}
			} catch ( ApiException $e ) {
				++$errors;
				if ( 404 === $e->http_status ) {
					// Deleted or never existed for this key: stop polling it.
					$order->update_meta_data( OrderMeta::POLL_UNTIL, '0' );
					$order->save_meta_data();
				}
				Logger::warning(
					sprintf( 'Sync of order #%d failed (%s)', $order->get_id(), $e->error_code ),
					array( 'component' => 'sync' ) + $e->context()
				);
				if ( 429 === $e->http_status || 0 === $e->http_status ) {
					break; // Rate limited or offline: try again next run.
				}
			}
			if ( microtime( true ) - $started > 45 ) {
				break; // Stay well inside the Action Scheduler time limit.
			}
		}

		$next = count( $orders ) < self::BATCH ? 0 : $offset + self::BATCH;
		update_option(
			self::STATE,
			array(
				'last_run_at' => time(),
				'checked'     => $checked,
				'changed'     => $changed,
				'errors'      => $errors,
				'open'        => count( $orders ),
				'offset'      => $next,
				'duration_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ),
			),
			false
		);
		if ( $checked > 0 || $errors > 0 ) {
			Logger::debug( sprintf( 'Sync run: %d checked, %d changed, %d errors', $checked, $changed, $errors ), array( 'component' => 'sync' ) );
		}
	}

	/**
	 * Single-order job.
	 *
	 * @param int $order_id Order id.
	 */
	public static function run_one( $order_id ): void {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		try {
			OrderSync::refresh( $order, 'poll' );
		} catch ( ApiException $e ) {
			Logger::warning( sprintf( 'Sync of order #%d failed (%s)', (int) $order_id, $e->error_code ), array( 'component' => 'sync' ) + $e->context() );
		}
	}

	/**
	 * Last run state.
	 *
	 * @return array<string, int>
	 */
	public static function state(): array {
		$state = get_option( self::STATE, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * Next scheduled run (unix) or null.
	 */
	public static function next_run(): ?int {
		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			return null;
		}
		$next = as_next_scheduled_action( self::HOOK, array(), self::GROUP );
		return is_int( $next ) ? $next : null;
	}

	/**
	 * Failed and pending action counts for the status page.
	 *
	 * @return array{pending:int, failed:int}
	 */
	public static function queue_counts(): array {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return array(
				'pending' => 0,
				'failed'  => 0,
			);
		}
		$count = static function ( string $status, string $hook ): int {
			$args = array(
				'hook'     => $hook,
				'group'    => self::GROUP,
				'status'   => $status,
				'per_page' => 100,
			);
			if ( 'failed' === $status ) {
				$args['date']         = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );
				$args['date_compare'] = '>=';
			}
			return count( as_get_scheduled_actions( $args, 'ids' ) );
		};
		return array(
			'pending' => $count( 'pending', self::ORDER_HOOK ),
			'failed'  => $count( 'failed', self::HOOK ) + $count( 'failed', self::ORDER_HOOK ),
		);
	}
}
