<?php
/**
 * HTTP client for the Varizet public API (`/v1`) and payment page API (`/pay/v1`).
 *
 * @package Varizet\WooCommerce
 */

namespace Varizet\WooCommerce\Api;

use Varizet\WooCommerce\Connect\Credentials;
use Varizet\WooCommerce\Platform;
use Varizet\WooCommerce\Settings;
use Varizet\WooCommerce\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * `wp_remote_request` with a timeout, bounded retries with exponential backoff and jitter on
 * transport errors / 429 / 5xx (honouring Retry-After), the `Idempotency-Key` header on every
 * POST, and the `Varizet-Plugin` header so the panel can show the plugin version (docs/12 §2.19).
 * Never logs keys or bodies; failures raise ApiException.
 */
class Client {

	private const UA_PRODUCT = 'VarizetWooCommerce';

	/**
	 * API key, or null for public endpoints only.
	 *
	 * @var string|null
	 */
	private ?string $api_key;

	/**
	 * Base URL without trailing slash.
	 *
	 * @var string
	 */
	private string $base_url;

	/**
	 * Latency of the last request in ms.
	 *
	 * @var int|null
	 */
	public ?int $last_latency_ms = null;

	/**
	 * Request id of the last response.
	 *
	 * @var string|null
	 */
	public ?string $last_request_id = null;

	/**
	 * Constructor.
	 *
	 * @param string|null $api_key  API key (vz_live_… / vz_test_…).
	 * @param string|null $base_url Base URL; defaults to Settings::api_base_url().
	 */
	public function __construct( ?string $api_key, ?string $base_url = null ) {
		$this->api_key  = $api_key;
		$this->base_url = rtrim( $base_url ?? Settings::api_base_url(), '/' );
	}

	/**
	 * Client for a mode's stored key, or null when that mode is not connected.
	 *
	 * @param string $mode live|test.
	 */
	public static function for_mode( string $mode ): ?self {
		$key = Credentials::api_key( $mode );
		return null === $key ? null : new self( $key );
	}

	/**
	 * Client without a key (payment page API, connect token exchange).
	 */
	public static function public_client(): self {
		return new self( null );
	}

	// ---- /v1 ----------------------------------------------------------------------------

	/**
	 * POST /v1/orders.
	 *
	 * @param array<string, mixed> $body            Request body.
	 * @param string               $idempotency_key Idempotency-Key.
	 * @return array<string, mixed> Order.
	 */
	public function create_order( array $body, string $idempotency_key ): array {
		return $this->request( 'POST', '/v1/orders', $body, array( 'Idempotency-Key' => $idempotency_key ), true, 2, 20 );
	}

	/**
	 * GET /v1/orders/{id} (id, code or merchant order id).
	 *
	 * @param string $id      Order reference.
	 * @param int    $timeout Seconds.
	 * @return array<string, mixed> Order.
	 */
	public function get_order( string $id, int $timeout = 10 ): array {
		return $this->request( 'GET', '/v1/orders/' . rawurlencode( $id ), null, array(), true, 2, $timeout );
	}

	/**
	 * GET /v1/orders?… (cursor pagination).
	 *
	 * @param array<string, scalar> $query Query.
	 * @return array{data: array<int, array<string, mixed>>, has_more: bool, next_cursor: string|null}
	 */
	public function list_orders( array $query = array() ): array {
		$path = '/v1/orders' . ( array() === $query ? '' : '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ) );
		return $this->request( 'GET', $path, null, array(), true, 2, 10 );
	}

	/**
	 * POST /v1/orders/{id}/cancel.
	 *
	 * @param string $id              Order id.
	 * @param string $idempotency_key Idempotency-Key.
	 * @return array<string, mixed> Order.
	 */
	public function cancel_order( string $id, string $idempotency_key ): array {
		return $this->request( 'POST', '/v1/orders/' . rawurlencode( $id ) . '/cancel', array(), array( 'Idempotency-Key' => $idempotency_key ), true, 2, 10 );
	}

	/**
	 * GET /v1/me: key, store and scopes. Not served by the API yet (see README "API changes"):
	 * callers treat 404 as "unknown" and fall back to list_orders(limit=1).
	 *
	 * @return array<string, mixed>
	 */
	public function me(): array {
		return $this->request( 'GET', '/v1/me', null, array(), true, 1, 8 );
	}

	/**
	 * POST /v1/integrations/connect/token (public + PKCE, docs/12 §2.19).
	 *
	 * @param string $code          One-time code.
	 * @param string $code_verifier PKCE verifier.
	 * @param string $site_url      Site URL.
	 * @return array<string, mixed> { api_key, webhook_endpoint_id, webhook_secret, store, livemode }.
	 */
	public function exchange_connect_code( string $code, string $code_verifier, string $site_url ): array {
		return $this->request(
			'POST',
			'/v1/integrations/connect/token',
			array(
				'code'          => $code,
				'code_verifier' => $code_verifier,
				'site_url'      => $site_url,
			),
			array( 'Idempotency-Key' => 'connect-' . substr( hash( 'sha256', $code ), 0, 32 ) ),
			false,
			1,
			15
		);
	}

	// ---- /pay/v1 (public) ---------------------------------------------------------------

	/**
	 * GET /pay/v1/checkouts/{token}: destination card, amounts, state.
	 *
	 * @param string $token Payment token.
	 * @return array<string, mixed> CheckoutView (camelCase).
	 */
	public function checkout_view( string $token ): array {
		return $this->request( 'GET', '/pay/v1/checkouts/' . rawurlencode( $token ), null, array(), false, 1, 6 );
	}

	/**
	 * GET /pay/v1/checkouts/{token}/status.
	 *
	 * @param string      $token Payment token.
	 * @param string|null $since ISO time.
	 * @return array<string, mixed> CheckoutStatus.
	 */
	public function checkout_status( string $token, ?string $since = null ): array {
		$path = '/pay/v1/checkouts/' . rawurlencode( $token ) . '/status' . ( $since ? '?since=' . rawurlencode( $since ) : '' );
		return $this->request( 'GET', $path, null, array(), false, 1, 5 );
	}

	/**
	 * GET /healthz (no auth).
	 *
	 * @return array<string, mixed>
	 */
	public function healthz(): array {
		return $this->request( 'GET', '/healthz', null, array(), false, 1, 5 );
	}

	// ---- transport ----------------------------------------------------------------------

	/**
	 * Sends a request with retries.
	 *
	 * @param string                    $method       HTTP method.
	 * @param string                    $path         Path with query.
	 * @param array<string, mixed>|null $body         JSON body.
	 * @param array<string, string>     $headers      Extra headers.
	 * @param bool                      $auth         Send the API key.
	 * @param int                       $max_attempts Attempts including the first.
	 * @param int                       $timeout      Per-attempt timeout in seconds.
	 * @return array<string, mixed>
	 * @throws ApiException On API or transport errors.
	 */
	protected function request( string $method, string $path, ?array $body, array $headers, bool $auth, int $max_attempts, int $timeout ): array {
		if ( $auth && ( null === $this->api_key || '' === $this->api_key ) ) {
			throw new ApiException( 401, 'authentication_error', 'not_connected', __( 'Varizet is not connected.', 'varizet-woocommerce' ) );
		}
		if ( 'POST' === $method && ! isset( $headers['Idempotency-Key'] ) ) {
			// Every public POST supports Idempotency-Key (AGENTS.md rule 3); retries need it.
			$headers['Idempotency-Key'] = wp_generate_uuid4();
		}

		$args = array(
			'method'      => $method,
			'timeout'     => $timeout,
			'redirection' => 0,
			'httpversion' => '1.1',
			'user-agent'  => self::user_agent(),
			'headers'     => array_merge(
				array(
					'Accept'         => 'application/json',
					'Varizet-Plugin' => self::plugin_header(),
				),
				$headers
			),
		);
		if ( $auth ) {
			$args['headers']['Authorization'] = 'Bearer ' . $this->api_key;
		}
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json; charset=utf-8';
			$args['body']                    = wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}

		$url     = $this->base_url . $path;
		$attempt = 0;
		while ( true ) {
			++$attempt;
			$started               = microtime( true );
			$response              = wp_remote_request( $url, $args );
			$this->last_latency_ms = (int) round( ( microtime( true ) - $started ) * 1000 );

			try {
				return $this->parse( $response, $method, $path );
			} catch ( ApiException $e ) {
				if ( $attempt >= $max_attempts || ! $e->is_retryable() ) {
					throw $e;
				}
				$delay = $this->backoff_ms( $attempt, $response );
				Logger::debug(
					sprintf( 'API %s %s failed (%s), retry %d in %d ms', $method, $path, $e->error_code, $attempt, $delay ),
					array( 'component' => 'api' ) + $e->context()
				);
				usleep( $delay * 1000 );
			}
		}
	}

	/**
	 * Decodes a response or throws.
	 *
	 * @param array|\WP_Error $response wp_remote_request result.
	 * @param string          $method   Method (for logs).
	 * @param string          $path     Path (for logs).
	 * @return array<string, mixed>
	 * @throws ApiException On errors.
	 */
	private function parse( $response, string $method, string $path ): array {
		if ( is_wp_error( $response ) ) {
			throw new ApiException(
				0,
				'transport_error',
				'network_error',
				/* translators: %s: transport error message. */
				sprintf( __( 'Could not reach Varizet: %s', 'varizet-woocommerce' ), $response->get_error_message() )
			);
		}
		$status                = (int) wp_remote_retrieve_response_code( $response );
		$raw                   = (string) wp_remote_retrieve_body( $response );
		$request_id            = wp_remote_retrieve_header( $response, 'x-request-id' );
		$this->last_request_id = is_string( $request_id ) && '' !== $request_id ? $request_id : null;
		$data                  = '' === $raw ? array() : json_decode( $raw, true );

		Logger::debug(
			sprintf( 'API %s %s -> %d (%d ms)', $method, $path, $status, (int) $this->last_latency_ms ),
			array(
				'component'  => 'api',
				'request_id' => $this->last_request_id,
			)
		);

		if ( $status >= 200 && $status < 300 ) {
			if ( ! is_array( $data ) ) {
				throw new ApiException( $status, 'api_error', 'invalid_response', __( 'Varizet returned an unreadable response.', 'varizet-woocommerce' ), null, $this->last_request_id );
			}
			return $data;
		}

		$error = is_array( $data ) && isset( $data['error'] ) && is_array( $data['error'] ) ? $data['error'] : array();
		throw new ApiException(
			$status,
			isset( $error['type'] ) ? (string) $error['type'] : ( $status >= 500 ? 'api_error' : 'invalid_request' ),
			isset( $error['code'] ) ? (string) $error['code'] : 'http_' . $status,
			isset( $error['message'] ) ? (string) $error['message'] : sprintf( 'HTTP %d', $status ),
			isset( $error['param'] ) ? (string) $error['param'] : null,
			isset( $error['request_id'] ) ? (string) $error['request_id'] : $this->last_request_id
		);
	}

	/**
	 * Exponential backoff with full jitter, honouring Retry-After (capped at 5 s).
	 *
	 * @param int             $attempt  Attempt that failed (1-based).
	 * @param array|\WP_Error $response Response.
	 */
	private function backoff_ms( int $attempt, $response ): int {
		if ( ! is_wp_error( $response ) ) {
			$retry_after = wp_remote_retrieve_header( $response, 'retry-after' );
			if ( is_string( $retry_after ) && ctype_digit( $retry_after ) ) {
				return min( 5000, (int) $retry_after * 1000 );
			}
		}
		$cap = min( 4000, 400 * ( 2 ** ( $attempt - 1 ) ) );
		return wp_rand( (int) ( $cap / 2 ), $cap );
	}

	/**
	 * User-Agent: VarizetWooCommerce/1.0.0 (WordPress/6.6; WooCommerce/9.3; PHP/8.2).
	 */
	public static function user_agent(): string {
		return sprintf(
			'%s/%s (WordPress/%s; WooCommerce/%s; EDD/%s; PHP/%s; +%s)',
			self::UA_PRODUCT,
			VARIZET_WC_VERSION,
			get_bloginfo( 'version' ),
			defined( 'WC_VERSION' ) ? WC_VERSION : '-',
			defined( 'EDD_VERSION' ) ? EDD_VERSION : '-',
			PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
			home_url( '/' )
		);
	}

	/**
	 * `Varizet-Plugin: woocommerce/1.1.0; wp=6.6; wc=9.3; edd=3.3; php=8.2` (docs/12 §2.19 step 5).
	 * The kind is the client that connected (`woocommerce` or `edd`): the API records the version
	 * only when it matches the integration.
	 */
	public static function plugin_header(): string {
		return sprintf(
			'%s/%s; wp=%s; wc=%s; edd=%s; php=%s',
			Platform::plugin_kind(),
			VARIZET_WC_VERSION,
			get_bloginfo( 'version' ),
			defined( 'WC_VERSION' ) ? WC_VERSION : '-',
			defined( 'EDD_VERSION' ) ? EDD_VERSION : '-',
			PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION
		);
	}
}
