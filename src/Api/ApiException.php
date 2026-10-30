<?php
/**
 * Error returned by the Varizet API (docs/05 §2) or the transport.
 *
 * @package Varizet\WooCommerce
 */

namespace Varizet\WooCommerce\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Carries the API error envelope `{ error: { type, code, message, param, request_id } }`.
 * `message` is the API's Persian user-facing text; never contains secrets.
 */
final class ApiException extends \RuntimeException {

	/**
	 * HTTP status (0 for transport failures).
	 *
	 * @var int
	 */
	public int $http_status;

	/**
	 * Error type, e.g. invalid_request, authentication_error, transport_error.
	 *
	 * @var string
	 */
	public string $type;

	/**
	 * Stable error code, e.g. amount_too_small, idempotency_mismatch.
	 *
	 * @var string
	 */
	public string $error_code;

	/**
	 * Offending parameter, if any.
	 *
	 * @var string|null
	 */
	public ?string $param;

	/**
	 * Request id for support.
	 *
	 * @var string|null
	 */
	public ?string $request_id;

	/**
	 * Constructor.
	 *
	 * @param int         $http_status HTTP status.
	 * @param string      $type        Error type.
	 * @param string      $error_code  Error code.
	 * @param string      $message     Message.
	 * @param string|null $param       Parameter.
	 * @param string|null $request_id  Request id.
	 */
	public function __construct( int $http_status, string $type, string $error_code, string $message, ?string $param = null, ?string $request_id = null ) {
		parent::__construct( $message, $http_status );
		$this->http_status = $http_status;
		$this->type        = $type;
		$this->error_code  = $error_code;
		$this->param       = $param;
		$this->request_id  = $request_id;
	}

	/**
	 * Whether a retry may succeed (network, rate limit, server errors).
	 */
	public function is_retryable(): bool {
		return 0 === $this->http_status || 429 === $this->http_status || $this->http_status >= 500;
	}

	/**
	 * Log-safe context.
	 *
	 * @return array<string, mixed>
	 */
	public function context(): array {
		return array(
			'http_status' => $this->http_status,
			'type'        => $this->type,
			'error_code'  => $this->error_code,
			'param'       => $this->param,
			'request_id'  => $this->request_id,
		);
	}
}
