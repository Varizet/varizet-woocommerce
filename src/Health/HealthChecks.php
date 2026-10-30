<?php
/**
 * System checks shared by «وضعیت سیستم» and WordPress Site Health.
 *
 * @package Varizet\WooCommerce
 */

namespace Varizet\WooCommerce\Health;

use Automattic\WooCommerce\Utilities\OrderUtil;
use Varizet\WooCommerce\Api\ApiException;
use Varizet\WooCommerce\Api\Client;
use Varizet\WooCommerce\Connect\Credentials;
use Varizet\WooCommerce\Platform;
use Varizet\WooCommerce\Settings;
use Varizet\WooCommerce\Support\Amount;
use Varizet\WooCommerce\Sync\Scheduler;
use Varizet\WooCommerce\Webhook\Controller as WebhookController;

defined( 'ABSPATH' ) || exit;

/**
 * Each check returns { id, group, label, status: good|recommended|critical, text, action? }.
 * Network checks (API reachability) are cached for 5 minutes and can be re-run.
 */
final class HealthChecks {

	public const API_CACHE = 'varizet_health_api';

	/**
	 * Runs every check.
	 *
	 * @param bool $fresh Ignore the API cache.
	 * @return array<int, array<string, mixed>>
	 */
	public static function all( bool $fresh = false ): array {
		return array_merge( self::connection( $fresh ), self::environment() );
	}

	/**
	 * Connection and sync checks.
	 *
	 * @param bool $fresh Ignore the API cache.
	 * @return array<int, array<string, mixed>>
	 */
	public static function connection( bool $fresh = false ): array {
		$checks = array();
		$mode   = Settings::environment();
		$api    = self::api( $fresh );

		$checks[] = array(
			'id'     => 'api',
			'group'  => 'connection',
			'label'  => __( 'API connection', 'varizet-woocommerce' ),
			'status' => $api['ok'] ? 'good' : 'critical',
			'text'   => $api['ok']
				/* translators: 1: API host, 2: latency in milliseconds. */
				? sprintf( __( 'Answered 200 from %1$s in %2$d ms.', 'varizet-woocommerce' ), (string) wp_parse_url( Settings::api_base_url(), PHP_URL_HOST ), (int) $api['latency'] )
				/* translators: %s: error message. */
				: sprintf( __( 'Not reachable: %s', 'varizet-woocommerce' ), (string) $api['error'] ),
			'value'  => $api['latency'],
		);

		$record = Credentials::record( $mode );
		if ( Credentials::is_unreadable( $mode ) ) {
			$key_status = 'critical';
			$key_text   = __( 'The stored key cannot be decrypted (the site salts changed). Enter the key again on the connection page.', 'varizet-woocommerce' );
		} elseif ( ! Credentials::is_connected( $mode ) ) {
			$key_status = 'critical';
			/* translators: %s: environment name (live or test). */
			$key_text = sprintf( __( 'No API key for the %s environment.', 'varizet-woocommerce' ), $mode );
		} elseif ( $api['ok'] && false === $api['key_ok'] ) {
			$key_status = 'critical';
			/* translators: %s: API error code. */
			$key_text = sprintf( __( 'The API key was rejected (%s).', 'varizet-woocommerce' ), (string) $api['key_error'] );
		} else {
			$key_status = 'good';
			$scopes     = isset( $record['scopes'] ) && is_array( $record['scopes'] ) && array() !== $record['scopes'] ? implode( '، ', $record['scopes'] ) : 'orders:read، orders:write';
			/* translators: 1: masked key, 2: scopes. */
			$key_text = sprintf( __( '%1$s, scopes %2$s', 'varizet-woocommerce' ), (string) ( $record['key_hint'] ?? '' ), $scopes );
		}
		$checks[] = array(
			'id'     => 'key',
			'group'  => 'connection',
			'label'  => __( 'API key', 'varizet-woocommerce' ),
			'status' => $key_status,
			'text'   => $key_text,
		);

		$stats   = WebhookController::stats();
		$day     = WebhookController::last_24h();
		$secret  = null !== Credentials::webhook_secret( $mode );
		$last_ok = (int) $stats['last_ok_at'];
		if ( ! $secret ) {
			$wh_status = 'critical';
			$wh_text   = __( 'No webhook secret: payments are synced only by the 2-minute fallback. Register the webhook URL in the Varizet panel and save its secret here.', 'varizet-woocommerce' );
		} elseif ( $day['fail'] > 0 && 0 === $day['ok'] ) {
			$wh_status = 'critical';
			/* translators: %d: number of failed deliveries. */
			$wh_text = sprintf( __( '%d failed deliveries in the last 24 hours and none succeeded.', 'varizet-woocommerce' ), $day['fail'] );
		} elseif ( 0 === $last_ok ) {
			$wh_status = 'recommended';
			$wh_text   = __( 'Configured; no webhook received yet. It appears here after the first order event.', 'varizet-woocommerce' );
		} else {
			$wh_status = $day['fail'] > 0 ? 'recommended' : 'good';
			/* translators: 1: successful deliveries, 2: total deliveries, 3: time since the last delivery. */
			$wh_text = sprintf( __( '%1$d of %2$d deliveries succeeded in the last 24 hours; last one %3$s ago.', 'varizet-woocommerce' ), $day['ok'], $day['ok'] + $day['fail'], human_time_diff( $last_ok ) );
		}
		$checks[] = array(
			'id'     => 'webhook',
			'group'  => 'connection',
			'label'  => __( 'Webhook delivery', 'varizet-woocommerce' ),
			'status' => $wh_status,
			'text'   => $wh_text,
		);

		$skew     = $stats['last_skew'];
		$checks[] = array(
			'id'     => 'signature',
			'group'  => 'connection',
			'label'  => __( 'Webhook signature', 'varizet-woocommerce' ),
			'status' => null === $skew ? 'good' : ( abs( (int) $skew ) > 120 ? 'recommended' : 'good' ),
			'text'   => null === $skew
				? __( 'HMAC-SHA256 with a 300-second window; verified on every delivery.', 'varizet-woocommerce' )
				/* translators: %d: clock difference in seconds. */
				: sprintf( __( 'HMAC-SHA256 valid; server clock differs from Varizet by %d seconds (limit 300).', 'varizet-woocommerce' ), abs( (int) $skew ) ),
		);

		$next     = Scheduler::next_run();
		$queue    = Scheduler::queue_counts();
		$state    = Scheduler::state();
		$last_run = (int) ( $state['last_run_at'] ?? 0 );
		$as_ok    = null !== $next && ( 0 === $last_run || time() - $last_run < 15 * MINUTE_IN_SECONDS );
		$checks[] = array(
			'id'     => 'scheduler',
			'group'  => 'connection',
			'label'  => __( 'Action Scheduler', 'varizet-woocommerce' ),
			'status' => ! $as_ok ? 'critical' : ( $queue['failed'] > 0 ? 'recommended' : 'good' ),
			'text'   => null === $next
				? __( 'The 2-minute sync is not scheduled. Deactivate and activate the plugin, or check WP-Cron.', 'varizet-woocommerce' )
				: sprintf(
					/* translators: 1: failed actions in 7 days, 2: time since the last run. */
					__( 'Scheduled every 2 minutes; %1$d failed actions in 7 days; last run %2$s.', 'varizet-woocommerce' ),
					$queue['failed'],
					0 === $last_run ? __( 'not yet', 'varizet-woocommerce' ) : sprintf(
						/* translators: %s: human time difference. */
						__( '%s ago', 'varizet-woocommerce' ),
						human_time_diff( $last_run )
					)
				),
		);

		$open     = count( Scheduler::open_orders( 0, 100 ) );
		$checks[] = array(
			'id'     => 'sync',
			'group'  => 'connection',
			'label'  => __( 'Order sync', 'varizet-woocommerce' ),
			'status' => (int) ( $state['errors'] ?? 0 ) > 0 ? 'recommended' : 'good',
			'text'   => sprintf(
				/* translators: 1: open orders, 2: errors in the last run. */
				__( '%1$d open orders watched by their Varizet id (no date filters); %2$d errors in the last run.', 'varizet-woocommerce' ),
				$open,
				(int) ( $state['errors'] ?? 0 )
			),
			'action' => 'sync_all',
		);
		return $checks;
	}

	/**
	 * Server environment checks.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function environment(): array {
		$checks = array();
		$wc     = defined( 'WC_VERSION' ) ? WC_VERSION : '0';
		$wp     = get_bloginfo( 'version' );

		$checks[] = array(
			'id'     => 'version',
			'group'  => 'environment',
			'label'  => __( 'Plugin version', 'varizet-woocommerce' ),
			'status' => 'good',
			/* translators: %s: plugin version. */
			'text'   => sprintf( __( 'Varizet for WooCommerce %s is installed.', 'varizet-woocommerce' ), VARIZET_WC_VERSION ),
		);

		$missing  = array_values( array_filter( array( 'json', 'openssl', 'mbstring' ), static fn ( $ext ) => ! extension_loaded( $ext ) ) );
		$checks[] = array(
			'id'     => 'php',
			'group'  => 'environment',
			'label'  => 'PHP',
			'status' => array() === $missing ? 'good' : 'critical',
			'text'   => array() === $missing
				/* translators: %s: PHP version. */
				? sprintf( __( '%s (minimum 8.1); json, openssl and mbstring are enabled.', 'varizet-woocommerce' ), PHP_VERSION )
				/* translators: 1: PHP version, 2: missing extensions. */
				: sprintf( __( '%1$s; missing extensions: %2$s', 'varizet-woocommerce' ), PHP_VERSION, implode( ', ', $missing ) ),
		);

		if ( ! Platform::has_wc() && Platform::has_edd() ) {
			$edd      = defined( 'EDD_VERSION' ) ? (string) EDD_VERSION : '0';
			$checks[] = array(
				'id'     => 'platform',
				'group'  => 'environment',
				'label'  => __( 'WordPress and Easy Digital Downloads', 'varizet-woocommerce' ),
				'status' => version_compare( $edd, '3.0', '>=' ) && version_compare( $wp, '6.4', '>=' ) ? 'good' : 'critical',
				/* translators: 1: WordPress version, 2: EDD version. */
				'text'   => sprintf( __( 'WordPress %1$s, Easy Digital Downloads %2$s (requires 6.4 and 3.0 or newer).', 'varizet-woocommerce' ), $wp, $edd ),
			);
			return $checks;
		}

		$wc_ok    = version_compare( $wc, '8.5', '>=' ) && version_compare( $wp, '6.4', '>=' );
		$checks[] = array(
			'id'     => 'platform',
			'group'  => 'environment',
			'label'  => __( 'WordPress and WooCommerce', 'varizet-woocommerce' ),
			'status' => $wc_ok ? 'good' : 'critical',
			/* translators: 1: WordPress version, 2: WooCommerce version. */
			'text'   => sprintf( __( 'WordPress %1$s, WooCommerce %2$s (requires 6.4 and 8.5 or newer).', 'varizet-woocommerce' ), $wp, $wc ),
		);

		$hpos     = class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled();
		$checks[] = array(
			'id'     => 'hpos',
			'group'  => 'environment',
			'label'  => __( 'HPOS compatibility', 'varizet-woocommerce' ),
			'status' => 'good',
			'text'   => $hpos
				? __( 'Compatible; high-performance order storage is enabled and the Varizet column shows in the new orders table.', 'varizet-woocommerce' )
				: __( 'Compatible; orders are stored as posts (legacy storage). Switching to HPOS is supported.', 'varizet-woocommerce' ),
		);

		$blocks   = class_exists( \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class );
		$checks[] = array(
			'id'     => 'blocks',
			'group'  => 'environment',
			'label'  => __( 'Checkout block', 'varizet-woocommerce' ),
			'status' => $blocks ? 'good' : 'recommended',
			'text'   => $blocks
				? __( 'The payment method is registered for the Checkout block and the classic checkout.', 'varizet-woocommerce' )
				: __( 'Blocks are unavailable; the classic checkout is used.', 'varizet-woocommerce' ),
		);

		$currency = Platform::store_currency();
		$checks[] = array(
			'id'     => 'currency',
			'group'  => 'environment',
			'label'  => __( 'Store currency', 'varizet-woocommerce' ),
			'status' => Amount::is_supported( $currency ) ? 'good' : 'critical',
			'text'   => Amount::is_supported( $currency )
				/* translators: 1: currency code, 2: rial per unit. */
				? sprintf( __( '%1$s; amounts are sent to Varizet as integer rial (× %2$d).', 'varizet-woocommerce' ), $currency, Amount::factor( $currency ) )
				/* translators: %s: currency code. */
				: sprintf( __( '%s is not supported. Use Iranian rial (IRR) or toman (IRT).', 'varizet-woocommerce' ), $currency ),
		);

		$tz       = wp_timezone_string();
		$checks[] = array(
			'id'     => 'clock',
			'group'  => 'environment',
			'label'  => __( 'Server clock', 'varizet-woocommerce' ),
			'status' => 'good',
			/* translators: 1: UTC time, 2: site timezone. */
			'text'   => sprintf( __( 'UTC %1$s; site timezone %2$s.', 'varizet-woocommerce' ), gmdate( 'H:i:s' ), $tz ),
		);

		$https    = str_starts_with( Settings::webhook_url(), 'https://' );
		$checks[] = array(
			'id'     => 'https',
			'group'  => 'environment',
			'label'  => __( 'HTTPS', 'varizet-woocommerce' ),
			'status' => $https ? 'good' : 'recommended',
			'text'   => $https
				? __( 'The webhook URL uses HTTPS.', 'varizet-woocommerce' )
				: __( 'The site is not served over HTTPS; Varizet accepts only HTTPS webhook URLs in production.', 'varizet-woocommerce' ),
		);
		return $checks;
	}

	/**
	 * API reachability and key validity (cached).
	 *
	 * @param bool $fresh Ignore cache.
	 * @return array{ok:bool, latency:int|null, error:string|null, key_ok:bool|null, key_error:string|null}
	 */
	public static function api( bool $fresh = false ): array {
		$cached = get_transient( self::API_CACHE );
		if ( ! $fresh && is_array( $cached ) ) {
			return $cached;
		}
		$result = array(
			'ok'        => false,
			'latency'   => null,
			'error'     => null,
			'key_ok'    => null,
			'key_error' => null,
		);
		$public = Client::public_client();
		try {
			$public->healthz();
			$result['ok']      = true;
			$result['latency'] = $public->last_latency_ms;
		} catch ( ApiException $e ) {
			$result['error'] = $e->getMessage();
		}
		$client = Client::for_mode( Settings::environment() );
		if ( $result['ok'] && null !== $client ) {
			try {
				$client->list_orders( array( 'limit' => 1 ) );
				$result['key_ok'] = true;
			} catch ( ApiException $e ) {
				$result['key_ok']    = $e->http_status >= 500 ? null : false;
				$result['key_error'] = $e->error_code;
			}
		}
		set_transient( self::API_CACHE, $result, 5 * MINUTE_IN_SECONDS );
		return $result;
	}

	/**
	 * Plain-text system report for support tickets (no secrets).
	 */
	public static function report(): string {
		$lines   = array();
		$lines[] = sprintf( 'Varizet %s | WP %s | WC %s | PHP %s', VARIZET_WC_VERSION, get_bloginfo( 'version' ), defined( 'WC_VERSION' ) ? WC_VERSION : '-', PHP_VERSION );
		$lines[] = sprintf( 'Site %s | env %s | currency %s | API %s', home_url(), Settings::environment(), Platform::store_currency(), Settings::api_base_url() );
		$day     = WebhookController::last_24h();
		$state   = Scheduler::state();
		$lines[] = sprintf( 'Webhook 24h %d ok / %d failed | AS next %s | last sync %s', $day['ok'], $day['fail'], (string) ( Scheduler::next_run() ? gmdate( 'c', (int) Scheduler::next_run() ) : '-' ), isset( $state['last_run_at'] ) ? gmdate( 'c', (int) $state['last_run_at'] ) : '-' );
		foreach ( self::all() as $check ) {
			$lines[] = sprintf( '[%s] %s: %s', $check['status'], $check['label'], wp_strip_all_tags( (string) $check['text'] ) );
		}
		return implode( "\n", $lines );
	}
}
