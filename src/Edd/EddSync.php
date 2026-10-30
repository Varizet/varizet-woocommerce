<?php
/**
 * Applies Varizet order state to an Easy Digital Downloads order.
 *
 * @package Varizet\WooCommerce
 */

namespace Varizet\WooCommerce\Edd;

use Varizet\WooCommerce\Api\ApiException;
use Varizet\WooCommerce\Api\Client;
use Varizet\WooCommerce\Order\StatusMapper;
use Varizet\WooCommerce\Settings;
use Varizet\WooCommerce\Support\Format;
use Varizet\WooCommerce\Support\Lock;
use Varizet\WooCommerce\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * The single writer for EDD orders (webhook, return page and polling), with the WooCommerce
 * side's guards: a per-order lock, replayed event ids, late events and settled payments that never
 * regress. State lives in EDD order meta (`_varizet_*`, the same names as on WooCommerce orders).
 */
final class EddSync {

	public const ORDER_ID      = '_varizet_order_id';
	public const CODE          = '_varizet_code';
	public const STATUS        = '_varizet_status';
	public const ATTEMPT       = '_varizet_attempt';
	public const AMOUNT        = '_varizet_amount';
	public const UNIQUE_AMOUNT = '_varizet_unique_amount';
	public const PAYMENT_URL   = '_varizet_payment_url';
	public const EXPIRES_AT    = '_varizet_expires_at';
	public const GRACE_UNTIL   = '_varizet_grace_until';
	public const LIVEMODE      = '_varizet_livemode';
	public const LAST_EVENT_ID = '_varizet_last_event_id';
	public const LAST_EVENT_AT = '_varizet_last_event_at';

	/**
	 * Webhook dispatch for an order the WooCommerce side does not know.
	 *
	 * @param array<string, mixed> $event Verified event.
	 * @return string|null Result, or null when the event is not about an EDD order of this site.
	 */
	public static function dispatch( array $event ): ?string {
		$object = $event['data']['object'] ?? null;
		if ( ! is_array( $object ) || ! isset( $object['id'], $object['status'] ) ) {
			return null;
		}
		$meta     = isset( $object['metadata'] ) && is_array( $object['metadata'] ) ? $object['metadata'] : array();
		$order_id = (int) ( $meta['edd_order_id'] ?? 0 );
		if ( $order_id <= 0 || ( $meta['site_id'] ?? '' ) !== Settings::site_fingerprint() ) {
			return null;
		}
		$order = edd_get_order( $order_id );
		if ( ! $order || 'varizet' !== $order->gateway ) {
			return null;
		}
		$stored = (string) edd_get_order_meta( $order_id, self::ORDER_ID, true );
		if ( '' === $stored ) {
			// Created seconds ago and not saved yet: let Varizet retry.
			return time() - (int) strtotime( (string) $event['created_at'] ) < 10 * MINUTE_IN_SECONDS ? 'not_ready' : 'unknown_order';
		}
		if ( 'order.created' === $event['type'] ) {
			return 'ignored';
		}
		if ( $stored !== $object['id'] && StatusMapper::PAID !== $object['status'] ) {
			// An older attempt changed state; only its payment matters to the order.
			return 'superseded';
		}
		return self::apply( $order_id, $object, 'webhook', (string) $event['id'], (string) $event['created_at'] );
	}

	/**
	 * Reads the order from Varizet once and applies it (return page, polling).
	 *
	 * @param int $order_id EDD order id.
	 * @return string Result.
	 */
	public static function refresh( int $order_id ): string {
		$vz_id = (string) edd_get_order_meta( $order_id, self::ORDER_ID, true );
		$mode  = '1' === (string) edd_get_order_meta( $order_id, self::LIVEMODE, true ) ? 'live' : 'test';
		$api   = Client::for_mode( $mode );
		if ( '' === $vz_id || null === $api ) {
			return 'skipped';
		}
		try {
			return self::apply( $order_id, $api->get_order( $vz_id ), 'poll', null, null );
		} catch ( ApiException $e ) {
			Logger::warning( sprintf( 'EDD order #%d: sync failed (%s)', $order_id, $e->error_code ), array( 'component' => 'edd' ) + $e->context() );
			return 'error';
		}
	}

	/**
	 * Stores a freshly created Varizet order on the EDD order.
	 *
	 * @param int                  $order_id EDD order id.
	 * @param array<string, mixed> $vz       Varizet order.
	 * @param int                  $attempt  Attempt.
	 */
	public static function store( int $order_id, array $vz, int $attempt ): void {
		$values = array(
			self::ORDER_ID      => (string) $vz['id'],
			self::CODE          => (string) ( $vz['code'] ?? '' ),
			self::STATUS        => (string) ( $vz['status'] ?? StatusMapper::AWAITING ),
			self::ATTEMPT       => (string) $attempt,
			self::AMOUNT        => (string) (int) ( $vz['amount'] ?? 0 ),
			self::UNIQUE_AMOUNT => (string) (int) ( $vz['unique_amount'] ?? 0 ),
			self::PAYMENT_URL   => (string) ( $vz['payment_url'] ?? '' ),
			self::EXPIRES_AT    => (string) ( $vz['expires_at'] ?? '' ),
			self::GRACE_UNTIL   => (string) ( $vz['grace_until'] ?? '' ),
			self::LIVEMODE      => ! empty( $vz['livemode'] ) ? '1' : '0',
			self::LAST_EVENT_AT => '',
		);
		foreach ( $values as $key => $value ) {
			edd_update_order_meta( $order_id, $key, $value );
		}
	}

	/**
	 * Applies a Varizet order to the EDD order.
	 *
	 * @param int                  $order_id   EDD order id.
	 * @param array<string, mixed> $vz         Varizet order.
	 * @param string               $source     webhook|poll|return.
	 * @param string|null          $event_id   Event id (webhooks).
	 * @param string|null          $created_at Event time (webhooks).
	 * @return string Result.
	 */
	public static function apply( int $order_id, array $vz, string $source, ?string $event_id, ?string $created_at ): string {
		$lock = 'edd-' . $order_id;
		if ( ! Lock::acquire( $lock ) ) {
			return 'busy';
		}
		try {
			$new     = (string) ( $vz['status'] ?? '' );
			$current = (string) edd_get_order_meta( $order_id, self::STATUS, true );
			if ( null !== $event_id && (string) edd_get_order_meta( $order_id, self::LAST_EVENT_ID, true ) === $event_id ) {
				return 'duplicate';
			}
			if ( StatusMapper::is_regression( $current, $new ) ) {
				return 'stale';
			}
			$last_at = strtotime( (string) edd_get_order_meta( $order_id, self::LAST_EVENT_AT, true ) );
			$at      = null === $created_at ? false : strtotime( $created_at );
			if ( false !== $at && false !== $last_at && $at < $last_at && StatusMapper::PAID !== $new ) {
				return 'stale';
			}

			$order = edd_get_order( $order_id );
			if ( ! $order ) {
				return 'unknown_order';
			}
			$hold     = array_key_exists( 'on_hold', (array) edd_get_payment_statuses() ) ? 'on_hold' : 'processing';
			$decision = EddStatusMapper::decide( $new, (string) $order->status, $hold );
			$code     = (string) ( $vz['code'] ?? edd_get_order_meta( $order_id, self::CODE, true ) );

			if ( EddStatusMapper::COMPLETE === $decision['action'] ) {
				edd_set_payment_transaction_id( $order_id, $code );
			}
			if ( null !== $decision['status'] && $decision['status'] !== $order->status ) {
				edd_update_payment_status( $order_id, $decision['status'] );
			}
			if ( null !== $decision['note'] ) {
				edd_insert_payment_note( $order_id, self::note( $decision['note'], $vz, $code ) );
			}

			edd_update_order_meta( $order_id, self::STATUS, $new );
			if ( null !== $event_id ) {
				edd_update_order_meta( $order_id, self::LAST_EVENT_ID, $event_id );
			}
			if ( null !== $created_at ) {
				edd_update_order_meta( $order_id, self::LAST_EVENT_AT, $created_at );
			}
			if ( isset( $vz['grace_until'] ) && is_string( $vz['grace_until'] ) ) {
				edd_update_order_meta( $order_id, self::GRACE_UNTIL, $vz['grace_until'] );
			}
			Logger::info( sprintf( 'EDD order #%d: %s %s -> %s', $order_id, $source, $new, $decision['action'] ), array( 'component' => 'edd' ) );
			return $decision['action'];
		} finally {
			Lock::release( $lock );
		}
	}

	/**
	 * Note text.
	 *
	 * @param string               $key  Note key.
	 * @param array<string, mixed> $vz   Varizet order.
	 * @param string               $code Varizet code.
	 */
	private static function note( string $key, array $vz, string $code ): string {
		switch ( $key ) {
			case 'paid':
				/* translators: 1: Varizet order code, 2: amount received in rial. */
				return sprintf( __( 'Varizet payment %1$s confirmed (%2$s rial received).', 'varizet-woocommerce' ), $code, Format::number( (int) ( $vz['paid_amount'] ?? $vz['unique_amount'] ?? 0 ), true ) );
			case 'paid_after_cancel':
				/* translators: %s: Varizet order code. */
				return sprintf( __( 'Varizet payment %s arrived after the order was closed; the order was completed. Check the delivery.', 'varizet-woocommerce' ), $code );
			case 'paid_after_refund':
				/* translators: %s: Varizet order code. */
				return sprintf( __( 'Varizet reports %s as paid, but this order is refunded here.', 'varizet-woocommerce' ), $code );
			case 'needs_review':
			case 'needs_review_after_cancel':
				/* translators: %s: Varizet order code. */
				return sprintf( __( 'The deposit for Varizet order %s needs review in the Varizet panel.', 'varizet-woocommerce' ), $code );
			case 'underpaid':
			case 'underpaid_after_cancel':
				/* translators: %s: Varizet order code. */
				return sprintf( __( 'Less than the unique amount arrived for Varizet order %s; review it in the Varizet panel.', 'varizet-woocommerce' ), $code );
			case 'expired':
				/* translators: %s: Varizet order code. */
				return sprintf( __( 'The payment window of Varizet order %s ended.', 'varizet-woocommerce' ), $code );
			case 'canceled':
				/* translators: %s: Varizet order code. */
				return sprintf( __( 'Varizet order %s was canceled.', 'varizet-woocommerce' ), $code );
			case 'refunded':
				/* translators: %s: Varizet order code. */
				return sprintf( __( 'Varizet order %s was refunded in the Varizet panel.', 'varizet-woocommerce' ), $code );
			default:
				/* translators: %s: Varizet order code. */
				return sprintf( __( 'Varizet order %s was partially refunded in the Varizet panel.', 'varizet-woocommerce' ), $code );
		}
	}
}
