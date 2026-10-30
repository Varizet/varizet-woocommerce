<?php
/**
 * REST route POST /wp-json/varizet/v1/webhook.
 *
 * @package Varizet\WooCommerce
 */

namespace Varizet\WooCommerce\Webhook;

use Varizet\WooCommerce\Connect\Credentials;
use Varizet\WooCommerce\Edd\EddSync;
use Varizet\WooCommerce\Order\OrderMeta;
use Varizet\WooCommerce\Order\OrderSync;
use Varizet\WooCommerce\Platform;
use Varizet\WooCommerce\Settings;
use Varizet\WooCommerce\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Receives signed webhooks (docs/05 §6): rate limit → signature (constant time, ±300 s, every
 * configured secret) → shape → idempotent apply through OrderSync. Responds within the 10 s
 * budget. Status codes tell Varizet whether to retry:
 *   200 applied / duplicate / ignored, 401 bad signature, 400 malformed, 409 order not ready
 *   yet (retry), 429 rate limited, 503 not configured or order locked (retry).
 */
final class Controller {

	public const NAMESPACE      = 'varizet/v1';
	public const ROUTE          = '/webhook';
	private const MAX_BODY      = 262144;
	private const RATE_LIMIT    = 300;
	private const RATE_WINDOW   = 60;
	private const STATS_OPTION  = 'varizet_webhook_stats';
	private const STATS_HISTORY = 30;

	/**
	 * Registers the route.
	 */
	public static function register(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'handle' ),
				// Authenticated by the HMAC signature inside handle(), not by a WordPress user.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Handles one delivery.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function handle( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! self::within_rate_limit() ) {
			return self::respond( 429, array( 'error' => 'too_many_requests' ), array( 'Retry-After' => (string) self::RATE_WINDOW ) );
		}

		$payload = (string) $request->get_body();
		if ( strlen( $payload ) > self::MAX_BODY ) {
			return self::respond( 413, array( 'error' => 'payload_too_large' ) );
		}

		$secrets = Credentials::webhook_secrets();
		if ( array() === $secrets ) {
			Logger::warning( 'Webhook received but no webhook secret is configured', array( 'component' => 'webhook' ) );
			self::record( 'not_configured', 503, null );
			return self::respond( 503, array( 'error' => 'not_configured' ) );
		}

		$header = $request->get_header( 'varizet_signature' );
		$check  = SignatureVerifier::verify( is_string( $header ) ? $header : null, $payload, $secrets, time() );
		if ( ! $check['ok'] ) {
			Logger::warning(
				sprintf( 'Webhook rejected: signature %s', $check['reason'] ),
				array(
					'component' => 'webhook',
					'skew'      => $check['skew'],
					'event_id'  => (string) $request->get_header( 'varizet_event_id' ),
				)
			);
			self::record( 'invalid_signature', 401, null, array( 'reason' => $check['reason'] ) );
			return self::respond(
				401,
				array(
					'error'  => 'invalid_signature',
					'reason' => $check['reason'],
				)
			);
		}

		$event = json_decode( $payload, true );
		if ( ! self::valid_event( $event ) ) {
			self::record( 'malformed', 400, null );
			return self::respond( 400, array( 'error' => 'malformed_event' ) );
		}
		$mode = $event['livemode'] ? 'live' : 'test';
		if ( ! in_array( $mode, $check['matched'], true ) ) {
			// Signed with the other environment's secret: refuse to cross live and test data.
			self::record( 'mode_mismatch', 400, $event );
			return self::respond( 400, array( 'error' => 'livemode_mismatch' ) );
		}

		$result = self::dispatch( $event );
		$status = 'busy' === $result ? 503 : ( 'not_ready' === $result ? 409 : 200 );
		self::record( $result, $status, $event, array( 'skew' => $check['skew'] ) );
		Logger::info(
			sprintf( 'Webhook %s %s: %s', (string) $event['type'], (string) $event['id'], $result ),
			array(
				'component' => 'webhook',
				'code'      => (string) ( $event['data']['object']['code'] ?? '' ),
				'skew'      => $check['skew'],
			)
		);

		return self::respond(
			$status,
			array(
				'received' => 200 === $status,
				'result'   => $result,
			)
		);
	}

	/**
	 * Routes an event.
	 *
	 * @param array<string, mixed> $event Verified event.
	 * @return string Result.
	 */
	private static function dispatch( array $event ): string {
		$type = (string) $event['type'];
		if ( ! str_starts_with( $type, 'order.' ) ) {
			// deposit.unmatched, device.*, card.cap_reached, refund.created: informative only here.
			return 'ignored';
		}
		$object = $event['data']['object'];
		if ( ! is_array( $object ) || ! isset( $object['id'], $object['status'] ) ) {
			return 'ignored';
		}

		$order = Platform::has_wc() ? OrderMeta::find_by_varizet_id( (string) $object['id'] ) : null;
		if ( null === $order ) {
			// Easy Digital Downloads orders share the site's connection and this route.
			$edd = Platform::has_edd() ? EddSync::dispatch( $event ) : null;
			if ( null !== $edd ) {
				return $edd;
			}
			return Platform::has_wc() && self::is_ours_but_not_saved( $object, (string) $event['created_at'] ) ? 'not_ready' : 'unknown_order';
		}
		if ( 'order.created' === $type ) {
			// We created it; the checkout already stored everything.
			if ( ! OrderMeta::has_event( $order, (string) $event['id'] ) ) {
				OrderMeta::remember_event( $order, (string) $event['id'] );
				$order->save_meta_data();
			}
			return 'ignored';
		}
		return OrderSync::apply( $order, $object, 'webhook', (string) $event['id'], (string) $event['created_at'], $type );
	}

	/**
	 * A payment event for an order this site created moments ago, before process_payment saved
	 * the Varizet id: answer 409 so Varizet retries in 10 s instead of dropping it.
	 *
	 * @param array<string, mixed> $vz_order   Order object.
	 * @param string               $created_at Event time.
	 */
	private static function is_ours_but_not_saved( array $vz_order, string $created_at ): bool {
		$meta = isset( $vz_order['metadata'] ) && is_array( $vz_order['metadata'] ) ? $vz_order['metadata'] : array();
		if ( ( $meta['site_id'] ?? '' ) !== Settings::site_fingerprint() || empty( $meta['wc_order_id'] ) ) {
			return false;
		}
		$age = time() - (int) strtotime( $created_at );
		if ( $age > 10 * MINUTE_IN_SECONDS ) {
			return false;
		}
		$order = wc_get_order( (int) $meta['wc_order_id'] );
		return $order instanceof \WC_Order && '' === (string) $order->get_meta( OrderMeta::ORDER_ID );
	}

	/**
	 * Validates the event envelope.
	 *
	 * @param mixed $event Decoded JSON.
	 */
	private static function valid_event( $event ): bool {
		return is_array( $event )
			&& isset( $event['id'], $event['type'], $event['created_at'], $event['data'] )
			&& is_string( $event['id'] ) && preg_match( '/^evt_[0-9A-Za-z]{10,40}$/', $event['id'] )
			&& is_string( $event['type'] ) && preg_match( '/^[a-z_]+\.[a-z_]+$/', $event['type'] )
			&& is_string( $event['created_at'] ) && false !== strtotime( $event['created_at'] )
			&& array_key_exists( 'livemode', $event ) && is_bool( $event['livemode'] )
			&& is_array( $event['data'] ) && array_key_exists( 'object', $event['data'] );
	}

	/**
	 * Simple fixed-window limit per client IP (docs/10 §1.6).
	 */
	private static function within_rate_limit(): bool {
		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$key   = 'varizet_whrl_' . md5( $ip . '|' . (int) floor( time() / self::RATE_WINDOW ) );
		$count = (int) get_transient( $key );
		if ( $count >= self::RATE_LIMIT ) {
			return false;
		}
		set_transient( $key, $count + 1, self::RATE_WINDOW + 5 );
		return true;
	}

	/**
	 * Delivery statistics for the connection and status pages.
	 *
	 * @param string                    $result Result.
	 * @param int                       $status HTTP status we answer.
	 * @param array<string, mixed>|null $event  Event when verified.
	 * @param array<string, mixed>      $extra  Extra fields.
	 */
	private static function record( string $result, int $status, ?array $event, array $extra = array() ): void {
		$stats = self::stats();
		$now   = time();
		$hour  = (int) floor( $now / HOUR_IN_SECONDS );

		$stats['hours'][ $hour ] = $stats['hours'][ $hour ] ?? array(
			'ok'   => 0,
			'fail' => 0,
		);
		if ( $status < 300 ) {
			++$stats['hours'][ $hour ]['ok'];
			$stats['last_ok_at'] = $now;
		} else {
			++$stats['hours'][ $hour ]['fail'];
			$stats['last_error']    = $result;
			$stats['last_error_at'] = $now;
		}
		// Keep 48 hourly buckets.
		$stats['hours'] = array_filter( $stats['hours'], static fn ( $k ) => $k > $hour - 48, ARRAY_FILTER_USE_KEY );

		$stats['last_received_at'] = $now;
		if ( isset( $extra['skew'] ) && null !== $extra['skew'] ) {
			$stats['last_skew'] = (int) $extra['skew'];
		}
		$stats['recent'][] = array(
			't'      => $now,
			'type'   => $event ? (string) $event['type'] : '',
			'id'     => $event ? (string) $event['id'] : '',
			'code'   => $event ? (string) ( $event['data']['object']['code'] ?? '' ) : '',
			'mode'   => $event ? ( $event['livemode'] ? 'live' : 'test' ) : '',
			'status' => $status,
			'result' => $result,
			'reason' => isset( $extra['reason'] ) ? (string) $extra['reason'] : '',
		);
		$stats['recent']   = array_slice( $stats['recent'], -self::STATS_HISTORY );
		update_option( self::STATS_OPTION, $stats, false );
	}

	/**
	 * Stored statistics.
	 *
	 * @return array<string, mixed>
	 */
	public static function stats(): array {
		$stats = get_option( self::STATS_OPTION, array() );
		$stats = is_array( $stats ) ? $stats : array();
		return array_merge(
			array(
				'hours'            => array(),
				'recent'           => array(),
				'last_received_at' => 0,
				'last_ok_at'       => 0,
				'last_error'       => '',
				'last_error_at'    => 0,
				'last_skew'        => null,
			),
			$stats
		);
	}

	/**
	 * Successful and failed deliveries in the last 24 hours.
	 *
	 * @return array{ok:int, fail:int}
	 */
	public static function last_24h(): array {
		$hour = (int) floor( time() / HOUR_IN_SECONDS );
		$ok   = 0;
		$fail = 0;
		foreach ( self::stats()['hours'] as $h => $bucket ) {
			if ( $h > $hour - 24 ) {
				$ok   += (int) $bucket['ok'];
				$fail += (int) $bucket['fail'];
			}
		}
		return array(
			'ok'   => $ok,
			'fail' => $fail,
		);
	}

	/**
	 * JSON response without caching.
	 *
	 * @param int                   $status  HTTP status.
	 * @param array<string, mixed>  $data    Body.
	 * @param array<string, string> $headers Headers.
	 */
	private static function respond( int $status, array $data, array $headers = array() ): \WP_REST_Response {
		$response = new \WP_REST_Response( $data, $status );
		$response->set_headers( array_merge( array( 'Cache-Control' => 'no-store' ), $headers ) );
		return $response;
	}
}
