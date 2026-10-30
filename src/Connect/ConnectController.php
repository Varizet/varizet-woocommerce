<?php
/**
 * Connecting the site to Varizet: manual keys today, one-click (PKCE) behind a feature flag.
 *
 * @package Varizet\WooCommerce
 */

namespace Varizet\WooCommerce\Connect;

use Varizet\WooCommerce\Admin\SettingsPage;
use Varizet\WooCommerce\Api\ApiException;
use Varizet\WooCommerce\Api\Client;
use Varizet\WooCommerce\Health\HealthChecks;
use Varizet\WooCommerce\Platform;
use Varizet\WooCommerce\Settings;
use Varizet\WooCommerce\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * One-click connect per docs/10 §1.4 «اتصال یک‌کلیکی» and docs/12 §2.19:
 *   1. «اتصال به واریزت» → app.varizet.ir/connect?client=woocommerce&site&state&code_challenge(S256)&redirect_uri
 *   2-3. The merchant approves in the panel; Varizet creates a scoped key + webhook endpoint.
 *   4. The browser returns with ?code&state; the plugin exchanges the code with its verifier at
 *      POST /v1/integrations/connect/token and stores { api_key, webhook_secret } encrypted.
 * Until the API serves step 4 the button is disabled (Settings::one_click_connect_enabled()).
 * Manual connect stores an API key and webhook secret typed by the merchant after checking the
 * key against the API.
 */
final class ConnectController {

	private const FLOW_TTL = 600;

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( 'admin_post_varizet_manual_connect', array( self::class, 'manual_connect' ) );
		add_action( 'admin_post_varizet_disconnect', array( self::class, 'disconnect' ) );
		add_action( 'admin_post_varizet_connect_start', array( self::class, 'start' ) );
		add_action( 'admin_init', array( self::class, 'maybe_callback' ) );
	}

	/**
	 * Manual connect: API key (+ webhook secret) from the form.
	 */
	public static function manual_connect(): void {
		self::require_cap();
		check_admin_referer( 'varizet_manual_connect' );

		$api_key = isset( $_POST['varizet_api_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['varizet_api_key'] ) ) ) : '';
		$secret  = isset( $_POST['varizet_webhook_secret'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['varizet_webhook_secret'] ) ) ) : '';
		$target  = isset( $_POST['varizet_mode'] ) ? sanitize_key( wp_unslash( $_POST['varizet_mode'] ) ) : '';

		// Updating only the webhook secret of an existing connection.
		if ( '' === $api_key && '' !== $secret && in_array( $target, Credentials::MODES, true ) && Credentials::is_connected( $target ) ) {
			Credentials::save( $target, (string) Credentials::api_key( $target ), $secret, array( 'method' => Credentials::record( $target )['method'] ?? 'manual' ) );
			Logger::info( sprintf( 'Webhook secret updated for %s', $target ), array( 'component' => 'connect' ) );
			SettingsPage::flash( 'success', __( 'Webhook secret saved.', 'varizet-woocommerce' ) );
			self::back();
		}

		$mode = Credentials::mode_from_key( $api_key );
		if ( null === $mode ) {
			SettingsPage::flash( 'error', __( 'The API key looks wrong. It starts with vz_live_ or vz_test_ and is shown once when you create it in the Varizet panel.', 'varizet-woocommerce' ) );
			self::back();
		}

		$info = self::verify_key( $api_key );
		if ( isset( $info['error'] ) ) {
			SettingsPage::flash( 'error', (string) $info['error'] );
			self::back();
		}

		Credentials::save( $mode, $api_key, '' === $secret ? null : $secret, array_merge( $info, array( 'method' => 'manual' ) ) );
		self::after_connect( $mode );
		Logger::info( sprintf( 'Connected manually (%s)', $mode ), array( 'component' => 'connect' ) );

		$message = '' === $secret && null === Credentials::webhook_secret( $mode )
			? __( 'Connected. Now register the webhook URL below in the Varizet panel and save its secret here for instant updates.', 'varizet-woocommerce' )
			: __( 'Connected. Card-to-card payment with automatic confirmation is active at checkout.', 'varizet-woocommerce' );
		SettingsPage::flash( 'success', $message );
		self::back();
	}

	/**
	 * Removes the keys of one environment. One-click connections also revoke the key in Varizet
	 * once the API offers it (TODO(api): `DELETE /v1/integrations/self`).
	 */
	public static function disconnect(): void {
		self::require_cap();
		check_admin_referer( 'varizet_disconnect' );
		$mode = isset( $_POST['varizet_mode'] ) ? sanitize_key( wp_unslash( $_POST['varizet_mode'] ) ) : '';
		if ( in_array( $mode, Credentials::MODES, true ) ) {
			Credentials::forget( $mode );
			delete_transient( HealthChecks::API_CACHE );
			Logger::info( sprintf( 'Disconnected (%s)', $mode ), array( 'component' => 'connect' ) );
			SettingsPage::flash( 'success', __( 'Disconnected. The keys were removed from this site; revoke the key in the Varizet panel too if it is no longer needed.', 'varizet-woocommerce' ) );
		}
		self::back();
	}

	/**
	 * Step 1: redirect to the Varizet consent page with state and a PKCE challenge.
	 */
	public static function start(): void {
		self::require_cap();
		check_admin_referer( 'varizet_connect_start' );
		if ( ! Settings::one_click_connect_enabled() ) {
			SettingsPage::flash( 'error', __( 'One-click connect is not available yet. Enter your API key manually.', 'varizet-woocommerce' ) );
			self::back();
		}
		$verifier = Pkce::verifier();
		$state    = Pkce::state();
		set_transient(
			'varizet_connect_' . get_current_user_id(),
			array(
				'state'    => $state,
				'verifier' => $verifier,
				'at'       => time(),
			),
			self::FLOW_TTL
		);
		$url = add_query_arg(
			array(
				'client'                => Platform::connect_client(),
				'site'                  => rawurlencode( home_url() ),
				'state'                 => $state,
				'code_challenge'        => Pkce::challenge( $verifier ),
				'code_challenge_method' => 'S256',
				'redirect_uri'          => rawurlencode( self::redirect_uri() ),
				'plugin_version'        => VARIZET_WC_VERSION,
			),
			Settings::app_url() . '/connect'
		);
		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- external Varizet consent page.
		exit;
	}

	/**
	 * Step 4: the browser came back with ?code&state.
	 */
	public static function maybe_callback(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- OAuth callback: CSRF is prevented by the single-use state bound to the user.
		if ( ! isset( $_GET['page'], $_GET['varizet_connect'] ) || 'varizet' !== $_GET['page'] || 'callback' !== $_GET['varizet_connect'] ) {
			return;
		}
		if ( ! current_user_can( Platform::cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to connect this store.', 'varizet-woocommerce' ), 403 );
		}
		$flow = get_transient( 'varizet_connect_' . get_current_user_id() );
		delete_transient( 'varizet_connect_' . get_current_user_id() );
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$error = isset( $_GET['error'] ) ? sanitize_key( wp_unslash( $_GET['error'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( '' !== $error ) {
			SettingsPage::flash( 'warning', __( 'The connection was cancelled in Varizet.', 'varizet-woocommerce' ) );
			self::back();
		}
		if ( ! is_array( $flow ) || '' === $state || ! hash_equals( (string) $flow['state'], $state ) || '' === $code ) {
			Logger::warning( 'Connect callback rejected: state mismatch or expired', array( 'component' => 'connect' ) );
			SettingsPage::flash( 'error', __( 'The connection request expired or was not started from this page. Please try again.', 'varizet-woocommerce' ) );
			self::back();
		}

		try {
			$res = Client::public_client()->exchange_connect_code( $code, (string) $flow['verifier'], home_url() );
		} catch ( ApiException $e ) {
			Logger::error( 'Connect code exchange failed', array( 'component' => 'connect' ) + $e->context() );
			SettingsPage::flash( 'error', __( 'Varizet did not accept the connection code. Please try again.', 'varizet-woocommerce' ) );
			self::back();
		}

		$api_key = (string) ( $res['api_key'] ?? '' );
		$mode    = Credentials::mode_from_key( $api_key );
		if ( null === $mode || empty( $res['webhook_secret'] ) ) {
			SettingsPage::flash( 'error', __( 'Varizet returned an incomplete connection. Please try again.', 'varizet-woocommerce' ) );
			self::back();
		}
		Credentials::save(
			$mode,
			$api_key,
			(string) $res['webhook_secret'],
			array(
				'method'              => 'oneclick',
				'webhook_endpoint_id' => isset( $res['webhook_endpoint_id'] ) ? sanitize_text_field( (string) $res['webhook_endpoint_id'] ) : null,
				'store'               => isset( $res['store'] ) && is_array( $res['store'] ) ? array_map( 'sanitize_text_field', array_intersect_key( $res['store'], array_flip( array( 'id', 'name', 'slug' ) ) ) ) : null,
				'scopes'              => array( 'orders:read', 'orders:write' ),
			)
		);
		self::after_connect( $mode );
		// The integration's kind decides which `Varizet-Plugin` versions the panel records.
		update_option( Platform::CLIENT_OPTION, Platform::connect_client(), false );
		Logger::info( sprintf( 'Connected with one-click (%s)', $mode ), array( 'component' => 'connect' ) );
		// TODO(api): ask Varizet to ping the webhook (POST /v1/webhook_endpoints/{id}/ping) to prove reachability.
		SettingsPage::flash( 'success', __( 'Connected. Card-to-card payment with automatic confirmation is active at checkout.', 'varizet-woocommerce' ) );
		self::back();
	}

	/**
	 * Checks a key: GET /v1/me when served, else GET /v1/orders?limit=1.
	 *
	 * @param string $api_key Key.
	 * @return array<string, mixed> Store info, or { error }.
	 */
	private static function verify_key( string $api_key ): array {
		$client = new Client( $api_key );
		try {
			$me = $client->me();
			return array(
				'store'  => isset( $me['store'] ) && is_array( $me['store'] ) ? array_map( 'sanitize_text_field', array_intersect_key( $me['store'], array_flip( array( 'id', 'name', 'slug' ) ) ) ) : null,
				'scopes' => isset( $me['scopes'] ) && is_array( $me['scopes'] ) ? array_map( 'sanitize_text_field', $me['scopes'] ) : array(),
			);
		} catch ( ApiException $e ) {
			if ( 404 !== $e->http_status && 405 !== $e->http_status ) {
				return array( 'error' => self::key_error( $e ) );
			}
		}
		try {
			$client->list_orders( array( 'limit' => 1 ) );
		} catch ( ApiException $e ) {
			return array( 'error' => self::key_error( $e ) );
		}
		return array(
			'store'  => null,
			'scopes' => array(),
		);
	}

	/**
	 * Message for a failed key check.
	 *
	 * @param ApiException $e Error.
	 */
	private static function key_error( ApiException $e ): string {
		if ( 401 === $e->http_status ) {
			return __( 'Varizet rejected this API key (invalid or revoked).', 'varizet-woocommerce' );
		}
		if ( 403 === $e->http_status ) {
			return __( 'This API key lacks the orders:read / orders:write scopes or this server\'s IP is not allowed.', 'varizet-woocommerce' );
		}
		if ( 0 === $e->http_status ) {
			/* translators: %s: transport error message. */
			return sprintf( __( 'Could not reach Varizet: %s', 'varizet-woocommerce' ), $e->getMessage() );
		}
		/* translators: 1: HTTP status, 2: error code. */
		return sprintf( __( 'Varizet answered %1$d (%2$s).', 'varizet-woocommerce' ), $e->http_status, $e->error_code );
	}

	/**
	 * First connection: use that environment and turn the gateway on if it was never configured.
	 *
	 * @param string $mode Connected mode.
	 */
	private static function after_connect( string $mode ): void {
		delete_transient( HealthChecks::API_CACHE );
		$settings = get_option( Settings::OPTION, array() );
		$settings = is_array( $settings ) ? $settings : array();
		$changed  = false;
		$current  = 'test' === ( $settings['environment'] ?? 'live' ) ? 'test' : 'live';
		if ( ! Credentials::is_connected( $current ) || ! isset( $settings['environment'] ) ) {
			$settings['environment'] = $mode;
			$changed                 = true;
		}
		if ( ! isset( $settings['enabled'] ) ) {
			$settings['enabled'] = 'yes';
			$changed             = true;
		}
		if ( $changed ) {
			update_option( Settings::OPTION, $settings );
			Settings::flush();
		}
	}

	/**
	 * Return URL after consent.
	 */
	private static function redirect_uri(): string {
		return admin_url( 'admin.php?page=varizet&varizet_connect=callback' );
	}

	/**
	 * Capability check for admin-post handlers (each handler verifies its own nonce).
	 */
	private static function require_cap(): void {
		if ( ! current_user_can( Platform::cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to manage Varizet.', 'varizet-woocommerce' ), 403 );
		}
	}

	/**
	 * Back to the connection page.
	 */
	private static function back(): void {
		wp_safe_redirect( admin_url( 'admin.php?page=varizet' ) );
		exit;
	}
}
