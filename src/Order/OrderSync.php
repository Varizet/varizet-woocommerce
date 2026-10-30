<?php
/**
 * Applies a Varizet order snapshot to its WooCommerce order.
 *
 * @package Varizet\WooCommerce
 */

namespace Varizet\WooCommerce\Order;

use Varizet\WooCommerce\Api\ApiException;
use Varizet\WooCommerce\Api\Client;
use Varizet\WooCommerce\Settings;
use Varizet\WooCommerce\Support\Format;
use Varizet\WooCommerce\Support\Lock;
use Varizet\WooCommerce\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * The single writer of Varizet state into WooCommerce, used by the webhook, the polling
 * fallback, the thank-you proxy and the admin «همگام‌سازی» button. Serialised per order with
 * a MySQL lock; idempotent per event id; ignores stale or regressing snapshots.
 */
final class OrderSync {

	public const APPLIED    = 'applied';
	public const UNCHANGED  = 'unchanged';
	public const DUPLICATE  = 'duplicate';
	public const STALE      = 'stale';
	public const SUPERSEDED = 'superseded';
	public const BUSY       = 'busy';

	/**
	 * Applies a snapshot.
	 *
	 * @param \WC_Order            $order      WooCommerce order.
	 * @param array<string, mixed> $vz         Varizet order (public API shape).
	 * @param string               $source     webhook|poll|widget|admin.
	 * @param string|null          $event_id   Webhook event id.
	 * @param string|null          $event_at   Webhook event created_at (ISO).
	 * @param string|null          $event_type Webhook event type.
	 * @return string One of the result constants.
	 */
	public static function apply( \WC_Order $order, array $vz, string $source, ?string $event_id = null, ?string $event_at = null, ?string $event_type = null ): string {
		$order_id = $order->get_id();
		$result   = Lock::with(
			'order_' . $order_id,
			static function () use ( $order_id, $vz, $source, $event_id, $event_at, $event_type ) {
				$fresh = wc_get_order( $order_id );
				if ( ! $fresh instanceof \WC_Order ) {
					return self::UNCHANGED;
				}
				return self::apply_locked( $fresh, $vz, $source, $event_id, $event_at, $event_type );
			},
			8
		);
		return null === $result ? self::BUSY : (string) $result;
	}

	/**
	 * Fetches the current state from the API and applies it.
	 *
	 * @param \WC_Order $order  Order.
	 * @param string    $source poll|widget|admin.
	 * @return string Result constant.
	 * @throws ApiException When the API call fails.
	 */
	public static function refresh( \WC_Order $order, string $source ): string {
		$vz_id = (string) $order->get_meta( OrderMeta::ORDER_ID );
		if ( '' === $vz_id ) {
			return self::UNCHANGED;
		}
		$client = Client::for_mode( OrderMeta::mode( $order ) );
		if ( null === $client ) {
			throw new ApiException( 401, 'authentication_error', 'not_connected', __( 'Varizet is not connected for this order\'s environment.', 'varizet-woocommerce' ) );
		}
		$vz = $client->get_order( $vz_id, 'widget' === $source ? 5 : 10 );
		return self::apply( $order, $vz, $source );
	}

	/**
	 * Body of apply() while holding the lock.
	 *
	 * @param \WC_Order            $order      Fresh order.
	 * @param array<string, mixed> $vz         Snapshot.
	 * @param string               $source     Source.
	 * @param string|null          $event_id   Event id.
	 * @param string|null          $event_at   Event time.
	 * @param string|null          $event_type Event type.
	 */
	private static function apply_locked( \WC_Order $order, array $vz, string $source, ?string $event_id, ?string $event_at, ?string $event_type ): string {
		if ( null !== $event_id && OrderMeta::has_event( $order, $event_id ) ) {
			return self::DUPLICATE;
		}

		$vz_id      = (string) ( $vz['id'] ?? '' );
		$new        = (string) ( $vz['status'] ?? '' );
		$current_id = (string) $order->get_meta( OrderMeta::ORDER_ID );
		$previous   = (string) $order->get_meta( OrderMeta::STATUS );
		$is_current = '' === $current_id || $vz_id === $current_id;

		if ( ! in_array( $new, StatusMapper::STATUSES, true ) ) {
			Logger::warning(
				'Unknown Varizet status ignored',
				array(
					'component' => 'sync',
					'order_id'  => $order->get_id(),
					'status'    => $new,
				)
			);
			return self::UNCHANGED;
		}

		// Out-of-order webhooks: compare event times from the same (API) clock.
		$last_event_at = (string) $order->get_meta( OrderMeta::LAST_EVENT_AT );
		if ( null !== $event_at && '' !== $last_event_at && strtotime( $event_at ) < strtotime( $last_event_at ) ) {
			OrderMeta::remember_event( $order, (string) $event_id );
			$order->save();
			return self::STALE;
		}

		if ( ! $is_current ) {
			return self::apply_superseded( $order, $vz, $source, $event_id );
		}

		if ( StatusMapper::is_regression( $previous, $new ) ) {
			if ( null !== $event_id ) {
				OrderMeta::remember_event( $order, $event_id );
				$order->save();
			}
			Logger::info(
				sprintf( 'Ignored %s -> %s regression for order #%d', $previous, $new, $order->get_id() ),
				array(
					'component' => 'sync',
					'source'    => $source,
				)
			);
			return self::STALE;
		}

		OrderMeta::store_order( $order, $vz );
		if ( null !== $event_id ) {
			OrderMeta::remember_event( $order, $event_id );
			OrderMeta::timeline(
				$order,
				'webhook',
				/* translators: %s: webhook event type such as order.paid. */
				sprintf( __( 'Webhook %s received and verified', 'varizet-woocommerce' ), (string) $event_type ),
				'webhook'
			);
		}
		if ( null !== $event_at ) {
			$order->update_meta_data( OrderMeta::LAST_EVENT_AT, $event_at );
		}

		$changed = $previous !== $new;
		if ( $changed ) {
			OrderMeta::timeline( $order, 'status', self::status_line( $new, $vz ), $source );
		}

		$decision = StatusMapper::decide( $new, $order->get_status(), array( 'restock_on_expire' => Settings::restock_on_expire() ) );
		// A note-only decision for an unchanged status would repeat on every poll.
		if ( ! $changed && in_array( $decision['action'], array( StatusMapper::NOTE, StatusMapper::SET_ON_HOLD, StatusMapper::REOPEN_ON_HOLD ), true ) ) {
			$decision['action'] = StatusMapper::NONE;
		}

		self::execute( $order, $decision, $vz, $source );
		$order->save();

		if ( StatusMapper::NONE !== $decision['action'] || $changed ) {
			Logger::info(
				sprintf(
					'Order #%d: Varizet %s -> %s, WooCommerce %s (%s)',
					$order->get_id(),
					'' === $previous ? '-' : $previous,
					$new,
					$order->get_status(),
					$decision['action']
				),
				array(
					'component' => 'sync',
					'source'    => $source,
					'code'      => (string) ( $vz['code'] ?? '' ),
				)
			);
			return self::APPLIED;
		}
		return self::UNCHANGED;
	}

	/**
	 * An event for an older attempt. Only a payment matters: the customer paid the old unique
	 * amount, so the order is paid and the newer attempt is cancelled to free its amount.
	 *
	 * @param \WC_Order            $order    Order.
	 * @param array<string, mixed> $vz       Snapshot of the old attempt.
	 * @param string               $source   Source.
	 * @param string|null          $event_id Event id.
	 */
	private static function apply_superseded( \WC_Order $order, array $vz, string $source, ?string $event_id ): string {
		if ( null !== $event_id ) {
			OrderMeta::remember_event( $order, $event_id );
		}
		if ( StatusMapper::PAID !== ( $vz['status'] ?? '' ) || $order->is_paid() ) {
			$order->save();
			return self::SUPERSEDED;
		}
		$newer = (string) $order->get_meta( OrderMeta::ORDER_ID );
		$order->add_order_note(
			sprintf(
				/* translators: 1: old Varizet order code, 2: newer Varizet order code. */
				__( 'Varizet: the customer paid the earlier payment request %1$s. The newer request %2$s is being cancelled.', 'varizet-woocommerce' ),
				(string) ( $vz['code'] ?? '' ),
				(string) $order->get_meta( OrderMeta::CODE )
			)
		);
		OrderMeta::store_order( $order, $vz );
		$decision = StatusMapper::decide( StatusMapper::PAID, $order->get_status() );
		self::execute( $order, $decision, $vz, $source );
		$order->save();

		$client = Client::for_mode( OrderMeta::mode( $order ) );
		if ( null !== $client && '' !== $newer ) {
			try {
				$client->cancel_order( $newer, 'wc-cancel-' . $newer );
			} catch ( ApiException $e ) {
				Logger::warning( 'Could not cancel the superseded Varizet order', array( 'component' => 'sync' ) + $e->context() );
			}
		}
		return self::APPLIED;
	}

	/**
	 * Carries out a StatusMapper decision.
	 *
	 * @param \WC_Order            $order    Order.
	 * @param array                $decision Decision.
	 * @param array<string, mixed> $vz       Snapshot.
	 * @param string               $source   Source.
	 */
	private static function execute( \WC_Order $order, array $decision, array $vz, string $source ): void {
		$note = null === $decision['note'] ? '' : self::note( (string) $decision['note'], $vz, $source );

		switch ( $decision['action'] ) {
			case StatusMapper::SET_PENDING:
			case StatusMapper::SET_ON_HOLD:
			case StatusMapper::REOPEN_ON_HOLD:
			case StatusMapper::CANCEL:
			case StatusMapper::FAIL:
			case StatusMapper::REFUND:
				$order->update_status( (string) $decision['status'], $note );
				break;

			case StatusMapper::PAYMENT_COMPLETE:
			case StatusMapper::REOPEN_AND_COMPLETE:
				if ( StatusMapper::REOPEN_AND_COMPLETE === $decision['action'] ) {
					$order->add_order_note( self::note( 'reopened', $vz, $source ) );
				}
				$order->add_order_note( $note );
				$order->payment_complete( (string) ( $vz['code'] ?? '' ) );
				if ( StatusMapper::REOPEN_AND_COMPLETE === $decision['action'] ) {
					self::stock_warning( $order );
				}
				break;

			case StatusMapper::NOTE:
				if ( '' !== $note ) {
					$order->add_order_note( $note );
				}
				break;
		}
	}

	/**
	 * After reopening a cancelled order WooCommerce reduces stock again; flag oversold items.
	 *
	 * @param \WC_Order $order Order.
	 */
	private static function stock_warning( \WC_Order $order ): void {
		$problems = array();
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$product = $item->get_product();
			if ( $product && $product->managing_stock() && (int) $product->get_stock_quantity() < 0 ) {
				$problems[] = sprintf( '%s (%d)', $product->get_name(), (int) $product->get_stock_quantity() );
			}
		}
		$order->update_meta_data( OrderMeta::STOCK_WARNING, array() === $problems ? 'check' : implode( '، ', $problems ) );
		$order->add_order_note(
			array() === $problems
				? __( 'Varizet: stock warning — this order was cancelled by WooCommerce and reopened after payment. Stock was reduced again; check that the items are still available.', 'varizet-woocommerce' )
				/* translators: %s: comma separated list of products with negative stock. */
				: sprintf( __( 'Varizet: stock warning — after reopening, these products are oversold: %s', 'varizet-woocommerce' ), implode( '، ', $problems ) ),
			false,
			false
		);
	}

	/**
	 * Human note for a decision.
	 *
	 * @param string               $key    Note key from StatusMapper.
	 * @param array<string, mixed> $vz     Snapshot.
	 * @param string               $source Source.
	 */
	private static function note( string $key, array $vz, string $source ): string {
		$persian = self::persian();
		$code    = (string) ( $vz['code'] ?? '' );
		$unique  = isset( $vz['unique_amount'] ) ? Format::number( (int) $vz['unique_amount'], $persian ) : '-';
		$paid    = isset( $vz['paid_amount'] ) ? Format::number( (int) $vz['paid_amount'], $persian ) : $unique;
		$score   = isset( $vz['match']['score'] ) ? (int) $vz['match']['score'] : null;
		$channel = isset( $vz['match']['channel'] ) ? self::channel_label( (string) $vz['match']['channel'] ) : '';
		$via     = self::source_label( $source );

		switch ( $key ) {
			case 'paid':
			case 'paid_after_cancel':
				$text = null === $score
					/* translators: 1: paid amount in rial, 2: Varizet order code, 3: how the update arrived. */
					? sprintf( __( 'Varizet: payment of %1$s rial confirmed (order %2$s, via %3$s).', 'varizet-woocommerce' ), $paid, $code, $via )
					/* translators: 1: paid amount in rial, 2: match score, 3: detection channel, 4: Varizet order code, 5: how the update arrived. */
					: sprintf( __( 'Varizet: payment of %1$s rial confirmed with score %2$d. Channel: %3$s; order %4$s (via %5$s).', 'varizet-woocommerce' ), $paid, $score, $channel, $code, $via );
				if ( ! empty( $vz['paid_late'] ) ) {
					$text .= ' ' . __( 'Paid during the grace period.', 'varizet-woocommerce' );
				}
				return $text;
			case 'reopened':
				return __( 'Varizet: this order was cancelled by WooCommerce (unpaid order timeout) but the customer paid afterwards. The order was reopened.', 'varizet-woocommerce' );
			case 'needs_review':
				/* translators: %s: Varizet order code. */
				return sprintf( __( 'Varizet: a deposit was received but needs a manual review (order %s). Approve or reject it in the Varizet panel.', 'varizet-woocommerce' ), $code );
			case 'needs_review_after_cancel':
				/* translators: %s: Varizet order code. */
				return sprintf( __( 'Varizet: a deposit arrived after this order was cancelled and needs a review (order %s). The order was reopened and put on hold.', 'varizet-woocommerce' ), $code );
			case 'underpaid':
			case 'underpaid_after_cancel':
				/* translators: 1: received amount in rial, 2: expected amount in rial, 3: Varizet order code. */
				return sprintf( __( 'Varizet: underpaid — %1$s rial received of %2$s rial (order %3$s). The customer can pay the difference from the payment page.', 'varizet-woocommerce' ), $paid, $unique, $code );
			case 'awaiting_again':
				/* translators: %s: Varizet order code. */
				return sprintf( __( 'Varizet: the review was closed and order %s is awaiting payment again.', 'varizet-woocommerce' ), $code );
			case 'expired_restocked':
				/* translators: %s: Varizet order code. */
				return sprintf( __( 'Varizet: the payment window and grace period of order %s ended without a payment. The order was cancelled and stock was restored.', 'varizet-woocommerce' ), $code );
			case 'expired_kept_stock':
				/* translators: %s: Varizet order code. */
				return sprintf( __( 'Varizet: the payment window and grace period of order %s ended without a payment. Marked as failed; stock was kept.', 'varizet-woocommerce' ), $code );
			case 'canceled':
				/* translators: %s: Varizet order code. */
				return sprintf( __( 'Varizet: order %s was cancelled in Varizet.', 'varizet-woocommerce' ), $code );
			case 'refunded':
				/* translators: %s: Varizet order code. */
				return sprintf( __( 'Varizet: a refund was recorded for order %s. The money transfer itself is done by the merchant.', 'varizet-woocommerce' ), $code );
			case 'partially_refunded':
				/* translators: %s: Varizet order code. */
				return sprintf( __( 'Varizet: a partial refund was recorded for order %s. Record it in WooCommerce if needed.', 'varizet-woocommerce' ), $code );
			case 'paid_after_refund':
				/* translators: %s: Varizet order code. */
				return sprintf( __( 'Varizet: a payment for order %s was confirmed but the order is already refunded in WooCommerce. Check it manually.', 'varizet-woocommerce' ), $code );
		}
		return '';
	}

	/**
	 * Timeline line for a status change.
	 *
	 * @param string               $status Varizet status.
	 * @param array<string, mixed> $vz     Snapshot.
	 */
	private static function status_line( string $status, array $vz ): string {
		$labels = self::status_labels();
		$label  = $labels[ $status ] ?? $status;
		if ( StatusMapper::PAID === $status && isset( $vz['match']['score'] ) ) {
			/* translators: 1: status label, 2: match score. */
			return sprintf( __( '%1$s, score %2$d', 'varizet-woocommerce' ), $label, (int) $vz['match']['score'] );
		}
		return $label;
	}

	/**
	 * Translated Varizet status labels.
	 *
	 * @return array<string, string>
	 */
	public static function status_labels(): array {
		return array(
			StatusMapper::AWAITING           => __( 'Awaiting payment', 'varizet-woocommerce' ),
			StatusMapper::NEEDS_REVIEW       => __( 'Needs review', 'varizet-woocommerce' ),
			StatusMapper::UNDERPAID          => __( 'Underpaid', 'varizet-woocommerce' ),
			StatusMapper::PAID               => __( 'Paid', 'varizet-woocommerce' ),
			StatusMapper::EXPIRED            => __( 'Expired', 'varizet-woocommerce' ),
			StatusMapper::CANCELED           => __( 'Cancelled', 'varizet-woocommerce' ),
			StatusMapper::REFUNDED           => __( 'Refunded', 'varizet-woocommerce' ),
			StatusMapper::PARTIALLY_REFUNDED => __( 'Partially refunded', 'varizet-woocommerce' ),
		);
	}

	/**
	 * Translated detection channel.
	 *
	 * @param string $channel Channel.
	 */
	public static function channel_label( string $channel ): string {
		$labels = array(
			'android_sms'          => __( 'bank SMS (Android app)', 'varizet-woocommerce' ),
			'android_notification' => __( 'bank app notification (Android)', 'varizet-woocommerce' ),
			'ios_shortcut'         => __( 'iOS Shortcut', 'varizet-woocommerce' ),
			'gsm_modem'            => __( 'GSM modem', 'varizet-woocommerce' ),
			'open_banking'         => __( 'open banking', 'varizet-woocommerce' ),
			'bot_forward'          => __( 'SMS forwarded to the bot', 'varizet-woocommerce' ),
			'receipt_upload'       => __( 'uploaded receipt', 'varizet-woocommerce' ),
			'manual'               => __( 'manual confirmation', 'varizet-woocommerce' ),
		);
		return $labels[ $channel ] ?? $channel;
	}

	/**
	 * Translated update source.
	 *
	 * @param string $source Source.
	 */
	private static function source_label( string $source ): string {
		$labels = array(
			'webhook' => __( 'signed webhook', 'varizet-woocommerce' ),
			'poll'    => __( 'background sync', 'varizet-woocommerce' ),
			'widget'  => __( 'thank-you page', 'varizet-woocommerce' ),
			'admin'   => __( 'manual sync', 'varizet-woocommerce' ),
		);
		return $labels[ $source ] ?? $source;
	}

	/**
	 * Whether to print Persian digits.
	 */
	public static function persian(): bool {
		return str_starts_with( determine_locale(), 'fa' );
	}
}
