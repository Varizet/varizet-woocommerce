<?php
/**
 * WordPress Site Health integration.
 *
 * @package Varizet\WooCommerce
 */

namespace Varizet\WooCommerce\Health;

use Varizet\WooCommerce\Connect\Credentials;
use Varizet\WooCommerce\Platform;
use Varizet\WooCommerce\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Adds Varizet tests to Tools → Site Health (direct configuration tests and an async API
 * test over REST) and a «Varizet» section to the Info tab.
 */
final class SiteHealth {

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_filter( 'site_status_tests', array( self::class, 'tests' ) );
		add_filter( 'debug_information', array( self::class, 'debug_information' ) );
		add_action( 'rest_api_init', array( self::class, 'register_route' ) );
	}

	/**
	 * Registers tests.
	 *
	 * @param array $tests Tests.
	 */
	public static function tests( $tests ) {
		$tests['direct']['varizet_configuration'] = array(
			'label' => __( 'Varizet configuration', 'varizet-woocommerce' ),
			'test'  => array( self::class, 'test_configuration' ),
		);
		$tests['direct']['varizet_sync']          = array(
			'label' => __( 'Varizet webhooks and sync', 'varizet-woocommerce' ),
			'test'  => array( self::class, 'test_sync' ),
		);
		$tests['async']['varizet_api']            = array(
			'label'             => __( 'Varizet API', 'varizet-woocommerce' ),
			'test'              => rest_url( 'varizet/v1/site-health/api' ),
			'has_rest'          => true,
			'async_direct_test' => array( self::class, 'test_api' ),
		);
		return $tests;
	}

	/**
	 * REST route for the async test.
	 */
	public static function register_route(): void {
		register_rest_route(
			'varizet/v1',
			'/site-health/api',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static fn () => new \WP_REST_Response( self::test_api() ),
				'permission_callback' => static fn () => current_user_can( 'view_site_health_checks' ) && current_user_can( Platform::cap() ),
			)
		);
	}

	/**
	 * Keys, currency and environment.
	 *
	 * @return array<string, mixed>
	 */
	public static function test_configuration(): array {
		$bad = array_filter(
			array_merge( self::by_id( array( 'key', 'currency', 'php', 'platform' ) ) ),
			static fn ( $c ) => 'good' !== $c['status']
		);
		return self::result( 'varizet_configuration', __( 'Varizet is configured', 'varizet-woocommerce' ), __( 'Varizet needs attention', 'varizet-woocommerce' ), $bad );
	}

	/**
	 * Webhooks and Action Scheduler.
	 *
	 * @return array<string, mixed>
	 */
	public static function test_sync(): array {
		if ( ! Credentials::is_connected( Settings::environment() ) ) {
			return self::result( 'varizet_sync', __( 'Varizet sync is idle (not connected)', 'varizet-woocommerce' ), '', array() );
		}
		$bad = array_filter( self::by_id( array( 'webhook', 'scheduler', 'sync' ) ), static fn ( $c ) => 'good' !== $c['status'] );
		return self::result( 'varizet_sync', __( 'Varizet webhooks and background sync work', 'varizet-woocommerce' ), __( 'Varizet payment sync needs attention', 'varizet-woocommerce' ), $bad );
	}

	/**
	 * API reachability.
	 *
	 * @return array<string, mixed>
	 */
	public static function test_api(): array {
		HealthChecks::api( true );
		$bad = array_filter( self::by_id( array( 'api' ) ), static fn ( $c ) => 'good' !== $c['status'] );
		return self::result( 'varizet_api', __( 'The Varizet API is reachable', 'varizet-woocommerce' ), __( 'The Varizet API is not reachable', 'varizet-woocommerce' ), $bad );
	}

	/**
	 * Info tab section.
	 *
	 * @param array $info Info.
	 */
	public static function debug_information( $info ) {
		$fields = array();
		foreach ( HealthChecks::all() as $check ) {
			$fields[ 'varizet_' . $check['id'] ] = array(
				'label' => (string) $check['label'],
				'value' => wp_strip_all_tags( (string) $check['text'] ),
			);
		}
		$fields['varizet_environment'] = array(
			'label' => __( 'Environment', 'varizet-woocommerce' ),
			'value' => Settings::environment(),
		);
		$fields['varizet_webhook_url'] = array(
			'label' => __( 'Webhook URL', 'varizet-woocommerce' ),
			'value' => Settings::webhook_url(),
		);
		$info['varizet']               = array(
			'label'  => __( 'Varizet', 'varizet-woocommerce' ),
			'fields' => $fields,
		);
		return $info;
	}

	/**
	 * Checks by id (uses the cached API result).
	 *
	 * @param string[] $ids Check ids.
	 * @return array<int, array<string, mixed>>
	 */
	private static function by_id( array $ids ): array {
		return array_values( array_filter( HealthChecks::all(), static fn ( $c ) => in_array( $c['id'], $ids, true ) ) );
	}

	/**
	 * Site Health result shape.
	 *
	 * @param string $test     Test id.
	 * @param string $good     Label when fine.
	 * @param string $bad      Label when not.
	 * @param array  $problems Failing checks.
	 * @return array<string, mixed>
	 */
	private static function result( string $test, string $good, string $bad, array $problems ): array {
		$critical = array_filter( $problems, static fn ( $c ) => 'critical' === $c['status'] );
		$status   = array() === $problems ? 'good' : ( array() === $critical ? 'recommended' : 'critical' );
		$desc     = '<p>' . esc_html__( 'Varizet confirms card-to-card payments with signed webhooks and a 2-minute background sync.', 'varizet-woocommerce' ) . '</p>';
		if ( array() !== $problems ) {
			$desc .= '<ul>';
			foreach ( $problems as $p ) {
				$desc .= '<li><strong>' . esc_html( (string) $p['label'] ) . ':</strong> ' . esc_html( (string) $p['text'] ) . '</li>';
			}
			$desc .= '</ul>';
		}
		return array(
			'label'       => 'good' === $status ? $good : $bad,
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'Varizet', 'varizet-woocommerce' ),
				'color' => 'good' === $status ? 'green' : ( 'critical' === $status ? 'red' : 'orange' ),
			),
			'description' => $desc,
			'actions'     => sprintf(
				'<p><a href="%s">%s</a></p>',
				esc_url( admin_url( 'admin.php?page=varizet&tab=status' ) ),
				esc_html__( 'Open Varizet system status', 'varizet-woocommerce' )
			),
			'test'        => $test,
		);
	}
}
