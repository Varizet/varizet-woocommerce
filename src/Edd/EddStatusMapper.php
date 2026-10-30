<?php
/**
 * Varizet order status -> Easy Digital Downloads order status.
 *
 * @package Varizet\WooCommerce
 */

namespace Varizet\WooCommerce\Edd;

use Varizet\WooCommerce\Order\StatusMapper;

defined( 'ABSPATH' ) || exit;

/**
 * The docs/10 §1.4 mapping for EDD 3 order statuses:
 *
 * | Varizet                    | EDD                                                      |
 * |----------------------------|----------------------------------------------------------|
 * | awaiting_payment           | pending (only to undo our own on_hold)                   |
 * | needs_review / underpaid   | on_hold + note                                           |
 * | paid                       | complete (transaction id = Varizet code)                 |
 * | paid after abandoned/revoked | complete + warning note                                |
 * | expired                    | abandoned                                                |
 * | canceled                   | failed                                                   |
 * | refunded                   | refunded when complete, else note                        |
 *
 * Statuses a human set (complete, refunded, revoked) are never overwritten by a non-payment
 * event. Pure: no WordPress calls, unit tested.
 */
final class EddStatusMapper {

	public const NONE     = 'none';
	public const NOTE     = 'note';
	public const SET      = 'set';
	public const COMPLETE = 'complete';

	private const UNPAID = array( 'pending', 'processing', 'on_hold', 'failed' );
	private const CLOSED = array( 'abandoned', 'cancelled', 'revoked' );

	/**
	 * Decides what to do with the EDD order.
	 *
	 * @param string $varizet_status Varizet status.
	 * @param string $edd_status     Current EDD order status.
	 * @param string $hold_status    `on_hold`, or `processing` where EDD has no on_hold status.
	 * @return array{action: string, status: ?string, note: ?string}
	 */
	public static function decide( string $varizet_status, string $edd_status, string $hold_status = 'on_hold' ): array {
		$unpaid = in_array( $edd_status, self::UNPAID, true );
		$closed = in_array( $edd_status, self::CLOSED, true );

		switch ( $varizet_status ) {
			case StatusMapper::PAID:
				if ( $unpaid ) {
					return self::d( self::COMPLETE, 'complete', 'paid' );
				}
				if ( $closed ) {
					return self::d( self::COMPLETE, 'complete', 'paid_after_cancel' );
				}
				return 'refunded' === $edd_status ? self::d( self::NOTE, null, 'paid_after_refund' ) : self::d( self::NONE );

			case StatusMapper::NEEDS_REVIEW:
			case StatusMapper::UNDERPAID:
				$note = StatusMapper::UNDERPAID === $varizet_status ? 'underpaid' : 'needs_review';
				if ( in_array( $edd_status, array( 'pending', 'processing', 'failed' ), true ) && $hold_status !== $edd_status ) {
					return self::d( self::SET, $hold_status, $note );
				}
				if ( $closed ) {
					return self::d( self::SET, $hold_status, $note . '_after_cancel' );
				}
				return self::d( self::NOTE, null, $note );

			case StatusMapper::AWAITING:
				return 'on_hold' === $edd_status ? self::d( self::SET, 'pending', null ) : self::d( self::NONE );

			case StatusMapper::EXPIRED:
				return in_array( $edd_status, array( 'pending', 'processing', 'on_hold' ), true )
					? self::d( self::SET, 'abandoned', 'expired' )
					: self::d( self::NONE );

			case StatusMapper::CANCELED:
				return in_array( $edd_status, array( 'pending', 'processing', 'on_hold' ), true )
					? self::d( self::SET, 'failed', 'canceled' )
					: self::d( self::NONE );

			case StatusMapper::REFUNDED:
				return 'complete' === $edd_status ? self::d( self::SET, 'refunded', 'refunded' ) : self::d( self::NOTE, null, 'refunded' );

			case StatusMapper::PARTIALLY_REFUNDED:
				return self::d( self::NOTE, null, 'partially_refunded' );
		}
		return self::d( self::NONE );
	}

	/**
	 * Idempotency-Key for an EDD order attempt: `edd-{order}-{attempt}-{site8}`.
	 *
	 * @param int    $order_id EDD order id.
	 * @param int    $attempt  Attempt (1-based).
	 * @param string $site_id  Site fingerprint.
	 * @throws \InvalidArgumentException When the order id or attempt is not positive.
	 */
	public static function idempotency_key( int $order_id, int $attempt, string $site_id ): string {
		if ( $order_id <= 0 || $attempt <= 0 ) {
			throw new \InvalidArgumentException( 'invalid_attempt' );
		}
		$site = substr( (string) preg_replace( '/[^a-z0-9]/', '', strtolower( $site_id ) ), 0, 8 );
		return sprintf( 'edd-%d-%d', $order_id, $attempt ) . ( '' === $site ? '' : '-' . $site );
	}

	/**
	 * Store currency code as the Amount helper knows it (Persian EDD add-ons use several names).
	 *
	 * @param string $currency EDD currency code.
	 */
	public static function currency( string $currency ): string {
		$code = strtoupper( trim( $currency ) );
		$map  = array(
			'RIAL'  => 'IRR',
			'TOMAN' => 'IRT',
			'TOM'   => 'IRT',
			'TMN'   => 'IRT',
		);
		return $map[ $code ] ?? $code;
	}

	/**
	 * Decision shape.
	 *
	 * @param string      $action Action.
	 * @param string|null $status EDD status to set.
	 * @param string|null $note   Note key.
	 * @return array{action: string, status: ?string, note: ?string}
	 */
	private static function d( string $action, ?string $status = null, ?string $note = null ): array {
		return array(
			'action' => $action,
			'status' => $status,
			'note'   => $note,
		);
	}
}
