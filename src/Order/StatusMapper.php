<?php
/**
 * Varizet order status -> WooCommerce order action.
 *
 * @package Varizet\WooCommerce
 */

namespace Varizet\WooCommerce\Order;

defined( 'ABSPATH' ) || exit;

/**
 * The docs/10 §1.4 status mapping as a pure decision table:
 *
 * | Varizet                         | WooCommerce                                            |
 * |---------------------------------|--------------------------------------------------------|
 * | awaiting_payment                | pending                                                |
 * | needs_review / underpaid        | on-hold + note                                         |
 * | paid                            | processing or completed (setting)                      |
 * | expired                         | cancelled + restock, or failed without restock (setting)|
 * | paid after WooCommerce cancelled| reopen -> paid + stock warning                         |
 * | refunded                        | refunded (manual record)                               |
 *
 * WooCommerce statuses a merchant set by hand (processing, completed, refunded) are never
 * overwritten by a non-payment event. Pure: no WordPress calls, unit tested.
 */
final class StatusMapper {

	public const AWAITING           = 'awaiting_payment';
	public const NEEDS_REVIEW       = 'needs_review';
	public const UNDERPAID          = 'underpaid';
	public const PAID               = 'paid';
	public const EXPIRED            = 'expired';
	public const CANCELED           = 'canceled';
	public const REFUNDED           = 'refunded';
	public const PARTIALLY_REFUNDED = 'partially_refunded';

	public const STATUSES = array(
		self::AWAITING,
		self::NEEDS_REVIEW,
		self::UNDERPAID,
		self::PAID,
		self::EXPIRED,
		self::CANCELED,
		self::REFUNDED,
		self::PARTIALLY_REFUNDED,
	);

	/** Statuses the polling fallback keeps checking. */
	public const OPEN = array( self::AWAITING, self::NEEDS_REVIEW, self::UNDERPAID );

	/** Money arrived: never regress to an unpaid status from here. */
	public const SETTLED = array( self::PAID, self::REFUNDED, self::PARTIALLY_REFUNDED );

	// Actions.
	public const NONE                = 'none';
	public const NOTE                = 'note';
	public const SET_PENDING         = 'set_pending';
	public const SET_ON_HOLD         = 'set_on_hold';
	public const REOPEN_ON_HOLD      = 'reopen_on_hold';
	public const PAYMENT_COMPLETE    = 'payment_complete';
	public const REOPEN_AND_COMPLETE = 'reopen_and_complete';
	public const CANCEL              = 'cancel';
	public const FAIL                = 'fail';
	public const REFUND              = 'refund';

	/** WooCommerce statuses that mean "not paid yet". */
	private const WC_UNPAID = array( 'pending', 'on-hold', 'failed', 'checkout-draft' );

	/** WooCommerce statuses that mean "paid / fulfilled". */
	private const WC_PAID = array( 'processing', 'completed' );

	/**
	 * Decides what to do with the WooCommerce order.
	 *
	 * @param string $varizet_status New Varizet status.
	 * @param string $wc_status      Current WooCommerce status, without the "wc-" prefix.
	 * @param array  $options        { restock_on_expire: bool }.
	 * @return array{action:string, status:string|null, note:string|null}
	 */
	public static function decide( string $varizet_status, string $wc_status, array $options = array() ): array {
		$wc_status = self::strip_prefix( $wc_status );
		$restock   = (bool) ( $options['restock_on_expire'] ?? true );
		$unpaid    = in_array( $wc_status, self::WC_UNPAID, true );
		$paid      = in_array( $wc_status, self::WC_PAID, true );
		$cancelled = 'cancelled' === $wc_status;

		switch ( $varizet_status ) {
			case self::AWAITING:
				// Only undo our own review hold; a human decision is never reverted.
				return 'on-hold' === $wc_status
					? self::decision( self::SET_PENDING, 'pending', 'awaiting_again' )
					: self::decision( self::NONE );

			case self::NEEDS_REVIEW:
			case self::UNDERPAID:
				$note = self::UNDERPAID === $varizet_status ? 'underpaid' : 'needs_review';
				if ( 'pending' === $wc_status || 'failed' === $wc_status || 'checkout-draft' === $wc_status ) {
					return self::decision( self::SET_ON_HOLD, 'on-hold', $note );
				}
				if ( $cancelled ) {
					// A deposit arrived after WooCommerce gave up: the merchant must see it.
					return self::decision( self::REOPEN_ON_HOLD, 'on-hold', $note . '_after_cancel' );
				}
				return self::decision( self::NOTE, null, $note );

			case self::PAID:
				if ( $unpaid ) {
					return self::decision( self::PAYMENT_COMPLETE, null, 'paid' );
				}
				if ( $cancelled ) {
					return self::decision( self::REOPEN_AND_COMPLETE, null, 'paid_after_cancel' );
				}
				if ( 'refunded' === $wc_status ) {
					return self::decision( self::NOTE, null, 'paid_after_refund' );
				}
				return self::decision( self::NONE );

			case self::EXPIRED:
				if ( ! $unpaid || 'failed' === $wc_status ) {
					return self::decision( self::NONE );
				}
				return $restock
					? self::decision( self::CANCEL, 'cancelled', 'expired_restocked' )
					: self::decision( self::FAIL, 'failed', 'expired_kept_stock' );

			case self::CANCELED:
				return $unpaid
					? self::decision( self::CANCEL, 'cancelled', 'canceled' )
					: self::decision( self::NONE );

			case self::REFUNDED:
				if ( $paid || 'on-hold' === $wc_status ) {
					return self::decision( self::REFUND, 'refunded', 'refunded' );
				}
				return 'refunded' === $wc_status ? self::decision( self::NONE ) : self::decision( self::NOTE, null, 'refunded' );

			case self::PARTIALLY_REFUNDED:
				return self::decision( self::NOTE, null, 'partially_refunded' );
		}
		return self::decision( self::NONE );
	}

	/**
	 * Whether moving from $current to $next would undo a settled payment (late or replayed
	 * webhooks; delivery order is not guaranteed, docs/05 §6).
	 *
	 * @param string|null $current Stored Varizet status.
	 * @param string      $next    Incoming Varizet status.
	 */
	public static function is_regression( ?string $current, string $next ): bool {
		if ( null === $current || '' === $current || $current === $next ) {
			return false;
		}
		$rank = array(
			self::PAID               => 1,
			self::PARTIALLY_REFUNDED => 2,
			self::REFUNDED           => 3,
		);
		if ( ! isset( $rank[ $current ] ) ) {
			return false;
		}
		return ! isset( $rank[ $next ] ) || $rank[ $next ] < $rank[ $current ];
	}

	/**
	 * Whether the polling fallback should keep watching this status.
	 *
	 * @param string $status Varizet status.
	 */
	public static function is_open( string $status ): bool {
		return in_array( $status, self::OPEN, true );
	}

	/**
	 * Whether the thank-you widget can stop polling.
	 *
	 * @param string $status Varizet status.
	 */
	public static function is_terminal( string $status ): bool {
		return in_array( $status, array( self::PAID, self::EXPIRED, self::CANCELED, self::REFUNDED, self::PARTIALLY_REFUNDED ), true );
	}

	/**
	 * Strips the "wc-" prefix WooCommerce uses in some places.
	 *
	 * @param string $status Status.
	 */
	private static function strip_prefix( string $status ): string {
		return str_starts_with( $status, 'wc-' ) ? substr( $status, 3 ) : $status;
	}

	/**
	 * Decision shape.
	 *
	 * @param string      $action Action.
	 * @param string|null $status Target WooCommerce status for status actions.
	 * @param string|null $note   Note key, rendered by OrderSync.
	 * @return array{action:string, status:string|null, note:string|null}
	 */
	private static function decision( string $action, ?string $status = null, ?string $note = null ): array {
		return array(
			'action' => $action,
			'status' => $status,
			'note'   => $note,
		);
	}
}
