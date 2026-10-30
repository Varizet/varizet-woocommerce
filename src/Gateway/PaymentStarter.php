<?php
/**
 * Creates (or reuses) the Varizet order behind a WooCommerce order.
 *
 * @package Varizet\WooCommerce
 */

namespace Varizet\WooCommerce\Gateway;

use Varizet\WooCommerce\Api\ApiException;
use Varizet\WooCommerce\Api\Client;
use Varizet\WooCommerce\Order\OrderMeta;
use Varizet\WooCommerce\Order\StatusMapper;
use Varizet\WooCommerce\Settings;
use Varizet\WooCommerce\Support\Amount;
use Varizet\WooCommerce\Support\IdempotencyKey;
use Varizet\WooCommerce\Support\Logger;
use Varizet\WooCommerce\Support\Phone;

defined( 'ABSPATH' ) || exit;

/**
 * Implements docs/10 §1.4 «پرداخت» steps 1-2. Attempts make «پرداخت مجدد» safe (the competitor's 1.1.2
 * dead end): an open Varizet order with the same amount is reused; an expired one, a changed
 * total, an `idempotency_mismatch` or a `merchant_order_id_duplicate` moves to a new attempt.
 * The attempt number is persisted before the API call, so a timed-out request retried by the
 * customer replays the same order instead of reserving a second unique amount.
 */
final class PaymentStarter {

	private const MAX_ATTEMPT_BUMPS = 3;

	/**
	 * Returns the Varizet order to pay for this WooCommerce order.
	 *
	 * @param \WC_Order   $order       Order.
	 * @param string|null $payer_last4 Last 4 digits typed at checkout.
	 * @return array<string, mixed> Varizet order.
	 * @throws ApiException On API errors (amount conversion may also throw \InvalidArgumentException).
	 */
	public static function start( \WC_Order $order, ?string $payer_last4 ): array {
		$mode   = Settings::environment();
		$client = Client::for_mode( $mode );
		if ( null === $client ) {
			throw new ApiException( 401, 'authentication_error', 'not_connected', __( 'This payment method is not available right now.', 'varizet-woocommerce' ) );
		}

		$amount = Amount::to_rial( $order->get_total( 'edit' ), $order->get_currency() );
		if ( $amount < Amount::MIN_ORDER_RIAL ) {
			throw new ApiException( 400, 'invalid_request', 'amount_too_small', __( 'The order total is below the minimum for card-to-card payment.', 'varizet-woocommerce' ) );
		}

		$reusable = self::reusable( $order, $amount, $mode );
		if ( null !== $reusable ) {
			Logger::info( sprintf( 'Order #%d: reusing open Varizet order %s', $order->get_id(), (string) $order->get_meta( OrderMeta::CODE ) ), array( 'component' => 'checkout' ) );
			return $reusable;
		}

		$attempt = max( 1, (int) $order->get_meta( OrderMeta::ATTEMPT ) );
		if ( '' !== (string) $order->get_meta( OrderMeta::ORDER_ID ) ) {
			++$attempt; // The previous attempt exists but cannot be reused.
		}

		$bumps = 0;
		while ( true ) {
			$order->update_meta_data( OrderMeta::ATTEMPT, (string) $attempt );
			$order->save_meta_data();

			$body = self::body( $order, $amount, $attempt, $payer_last4 );
			$key  = IdempotencyKey::for_attempt( $order->get_id(), $attempt, Settings::site_fingerprint() );
			try {
				$vz = $client->create_order( $body, $key );
				break;
			} catch ( ApiException $e ) {
				$bump = in_array( $e->error_code, array( 'idempotency_mismatch', 'merchant_order_id_duplicate' ), true );
				if ( ! $bump || ++$bumps > self::MAX_ATTEMPT_BUMPS ) {
					Logger::error(
						sprintf( 'Order #%d: creating the Varizet order failed (%s)', $order->get_id(), $e->error_code ),
						array( 'component' => 'checkout' ) + $e->context()
					);
					throw $e;
				}
				Logger::info( sprintf( 'Order #%d: %s, moving to attempt %d', $order->get_id(), $e->error_code, $attempt + 1 ), array( 'component' => 'checkout' ) );
				++$attempt;
			}
		}

		if ( ! is_array( $vz ) || empty( $vz['id'] ) || empty( $vz['payment_url'] ) ) {
			throw new ApiException( 502, 'api_error', 'invalid_response', __( 'Varizet returned an incomplete order.', 'varizet-woocommerce' ) );
		}

		OrderMeta::store_order( $order, $vz );
		if ( null !== $payer_last4 ) {
			$order->update_meta_data( OrderMeta::CLAIMED_LAST4, $payer_last4 );
		}
		$order->update_meta_data( OrderMeta::LAST_EVENT_AT, '' );
		OrderMeta::timeline(
			$order,
			'created',
			sprintf(
				/* translators: 1: Varizet order code, 2: attempt number. */
				__( 'Payment request %1$s created and unique amount reserved (attempt %2$d)', 'varizet-woocommerce' ),
				(string) $vz['code'],
				$attempt
			),
			'checkout'
		);
		$order->save();

		Logger::info(
			sprintf( 'Order #%d: Varizet order %s created (attempt %d)', $order->get_id(), (string) $vz['code'], $attempt ),
			array(
				'component'     => 'checkout',
				'unique_amount' => (int) ( $vz['unique_amount'] ?? 0 ),
				'livemode'      => (bool) ( $vz['livemode'] ?? true ),
			)
		);
		return $vz;
	}

	/**
	 * The current Varizet order if it is still payable for the same amount and mode.
	 *
	 * @param \WC_Order $order  Order.
	 * @param int       $amount Current total in rial.
	 * @param string    $mode   Environment.
	 * @return array<string, mixed>|null
	 */
	private static function reusable( \WC_Order $order, int $amount, string $mode ): ?array {
		$id = (string) $order->get_meta( OrderMeta::ORDER_ID );
		if ( '' === $id
			|| StatusMapper::AWAITING !== (string) $order->get_meta( OrderMeta::STATUS )
			|| (int) $order->get_meta( OrderMeta::AMOUNT ) !== $amount
			|| OrderMeta::mode( $order ) !== $mode ) {
			return null;
		}
		$expires = strtotime( (string) $order->get_meta( OrderMeta::EXPIRES_AT ) );
		if ( false === $expires || $expires < time() + 5 * MINUTE_IN_SECONDS ) {
			return null;
		}
		return array(
			'id'            => $id,
			'code'          => (string) $order->get_meta( OrderMeta::CODE ),
			'status'        => StatusMapper::AWAITING,
			'payment_url'   => (string) $order->get_meta( OrderMeta::PAYMENT_URL ),
			'unique_amount' => (int) $order->get_meta( OrderMeta::UNIQUE_AMOUNT ),
			'expires_at'    => (string) $order->get_meta( OrderMeta::EXPIRES_AT ),
		);
	}

	/**
	 * Request body for POST /v1/orders.
	 *
	 * @param \WC_Order   $order       Order.
	 * @param int         $amount      Rial.
	 * @param int         $attempt     Attempt.
	 * @param string|null $payer_last4 Last 4 digits.
	 * @return array<string, mixed>
	 */
	public static function body( \WC_Order $order, int $amount, int $attempt, ?string $payer_last4 ): array {
		$name = trim( $order->get_formatted_billing_full_name() );
		if ( '' === $name ) {
			$name = trim( $order->get_formatted_shipping_full_name() );
		}
		$customer = array_filter(
			array(
				'name'  => '' === $name ? null : mb_substr( $name, 0, 120 ),
				'phone' => Phone::normalize_mobile( $order->get_billing_phone() ),
				'email' => is_email( $order->get_billing_email() ) ? $order->get_billing_email() : null,
			),
			static fn ( $v ) => null !== $v
		);

		$body = array(
			'amount'            => $amount,
			'merchant_order_id' => IdempotencyKey::merchant_order_id( Settings::order_prefix(), (string) $order->get_order_number(), $attempt ),
			'description'       => mb_substr(
				sprintf(
					/* translators: 1: site name, 2: order number. */
					__( '%1$s order #%2$s', 'varizet-woocommerce' ),
					wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
					$order->get_order_number()
				),
				0,
				255
			),
			'expires_in'        => Settings::expires_in(),
			'unique_mode'       => Settings::unique_mode(),
			'metadata'          => array(
				'wc_order_id' => (string) $order->get_id(),
				'wc_attempt'  => (string) $attempt,
				'site'        => mb_substr( (string) wp_parse_url( home_url(), PHP_URL_HOST ), 0, 200 ),
				'site_id'     => Settings::site_fingerprint(),
				'plugin'      => 'woocommerce/' . VARIZET_WC_VERSION,
			),
		);
		if ( array() !== $customer ) {
			$body['customer'] = $customer;
		}
		$return_url = $order->get_checkout_order_received_url();
		// The API accepts https URLs (and http://localhost for development) only.
		if ( str_starts_with( $return_url, 'https://' ) || str_starts_with( $return_url, 'http://localhost' ) ) {
			$body['return_url'] = $return_url;
		}
		if ( null !== $payer_last4 ) {
			// TODO(api): `payer_last4` on POST /v1/orders (orders.payer_last4 exists; README "API changes").
			$body['payer_last4']             = $payer_last4;
			$body['metadata']['payer_last4'] = $payer_last4;
		}
		if ( Settings::sms_link() && isset( $customer['phone'] ) ) {
			// TODO(api): `notify.sms` on POST /v1/orders (README "API changes"). Ignored until served.
			$body['notify'] = array( 'sms' => true );
		}
		return $body;
	}
}
