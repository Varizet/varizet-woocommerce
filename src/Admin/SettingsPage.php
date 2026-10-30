<?php
/**
 * «واریزت» admin pages: connection, gateway settings layout, system status.
 *
 * @package Varizet\WooCommerce
 */

namespace Varizet\WooCommerce\Admin;

use Automattic\WooCommerce\Utilities\OrderUtil;
use Varizet\WooCommerce\Connect\Credentials;
use Varizet\WooCommerce\Gateway\Gateway;
use Varizet\WooCommerce\Health\HealthChecks;
use Varizet\WooCommerce\Platform;
use Varizet\WooCommerce\Settings;
use Varizet\WooCommerce\Support\Format;
use Varizet\WooCommerce\Support\Logger;
use Varizet\WooCommerce\Sync\Scheduler;
use Varizet\WooCommerce\Webhook\Controller as WebhookController;

defined( 'ABSPATH' ) || exit;

/**
 * WP-native markup (wrap, nav-tab-wrapper, notice, form-table, button) plus a small scoped
 * stylesheet with the Varizet tokens (design/integrations.html: wp-connect, wp-settings,
 * wp-health). Every page requires `manage_woocommerce` (`manage_shop_settings` on EDD-only sites);
 * every form posts to admin-post.php with
 * a nonce.
 */
final class SettingsPage {

	public const SLUG = 'varizet';

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ), 60 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
		add_action( 'admin_post_varizet_test_connection', array( self::class, 'test_connection' ) );
		add_action( 'admin_post_varizet_sync_all', array( self::class, 'sync_all' ) );
		add_action( 'admin_post_varizet_download_log', array( self::class, 'download_log' ) );
		add_action( 'admin_post_varizet_clear_log', array( self::class, 'clear_log' ) );
		add_action( 'admin_notices', array( self::class, 'global_notice' ) );
		add_filter( 'submenu_file', array( self::class, 'submenu_file' ), 10, 2 );
		add_filter( 'plugin_action_links_' . plugin_basename( VARIZET_WC_FILE ), array( self::class, 'action_links' ) );
	}

	/**
	 * Menu: واریزت → اتصال / تنظیمات درگاه / وضعیت سیستم.
	 */
	public static function menu(): void {
		$icon = 'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><path fill="black" d="M9 2h14a7 7 0 0 1 7 7v14a7 7 0 0 1-7 7H9a7 7 0 0 1-7-7V9a7 7 0 0 1 7-7Zm-.2 9.1a1.3 1.3 0 0 0-.6 1.7l5 10a1.3 1.3 0 0 0 2.3.1l4-7.5a1.3 1.3 0 1 0-2.3-1.2l-2.8 5.3-3.9-7.8a1.3 1.3 0 0 0-1.7-.6ZM22.5 8.1a2.4 2.4 0 1 0 0 4.8 2.4 2.4 0 0 0 0-4.8Z"/></svg>' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- SVG data URI for the menu icon.
		add_menu_page(
			__( 'Varizet', 'varizet-woocommerce' ),
			__( 'Varizet', 'varizet-woocommerce' ),
			Platform::cap(),
			self::SLUG,
			array( self::class, 'render' ),
			$icon,
			56
		);
		add_submenu_page( self::SLUG, __( 'Connection', 'varizet-woocommerce' ), __( 'Connection', 'varizet-woocommerce' ), Platform::cap(), self::SLUG, array( self::class, 'render' ) );
		add_submenu_page( self::SLUG, __( 'Gateway settings', 'varizet-woocommerce' ), __( 'Gateway settings', 'varizet-woocommerce' ), Platform::cap(), self::gateway_settings_slug() );
		add_submenu_page( self::SLUG, __( 'System status', 'varizet-woocommerce' ), __( 'System status', 'varizet-woocommerce' ), Platform::cap(), 'admin.php?page=varizet&tab=status' );
	}

	/**
	 * Highlights the right submenu item for tabbed URLs.
	 *
	 * @param string|null $submenu_file Current submenu file.
	 * @param string      $parent_file  Parent.
	 */
	public static function submenu_file( $submenu_file, $parent_file ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab switch.
		if ( self::SLUG === $parent_file && isset( $_GET['tab'] ) && 'status' === $_GET['tab'] ) {
			return 'admin.php?page=varizet&tab=status';
		}
		return $submenu_file;
	}

	/**
	 * Plugins screen links.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public static function action_links( $links ): array {
		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=varizet' ) ), esc_html__( 'Connection', 'varizet-woocommerce' ) ),
			sprintf( '<a href="%s">%s</a>', esc_url( admin_url( self::gateway_settings_slug() ) ), esc_html__( 'Settings', 'varizet-woocommerce' ) )
		);
		return $links;
	}

	/**
	 * WooCommerce settings URL of the gateway.
	 */
	public static function gateway_settings_slug(): string {
		if ( ! Platform::has_wc() ) {
			// Not Gateway::ID: loading that class needs WooCommerce.
			return 'edit.php?post_type=download&page=edd-settings&tab=gateways&section=varizet';
		}
		return 'admin.php?page=wc-settings&tab=checkout&section=' . Gateway::ID;
	}

	/**
	 * Admin CSS/JS on Varizet screens, the gateway section and order screens.
	 *
	 * @param string $hook Hook suffix.
	 */
	public static function assets( $hook ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$id     = $screen ? (string) $screen->id : '';
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- screen detection only.
		$is_gateway = 'woocommerce_page_wc-settings' === $id && isset( $_GET['section'] ) && Gateway::ID === $_GET['section'];
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$is_ours   = str_contains( (string) $hook, 'varizet' );
		$is_orders = in_array( $id, array( 'woocommerce_page_wc-orders', 'shop_order', 'edit-shop_order' ), true );
		if ( ! $is_ours && ! $is_gateway && ! $is_orders ) {
			return;
		}
		wp_enqueue_style( 'varizet-admin', VARIZET_WC_URL . 'assets/css/admin.css', array(), VARIZET_WC_VERSION );
		wp_enqueue_script( 'varizet-admin', VARIZET_WC_URL . 'assets/js/admin.js', array(), VARIZET_WC_VERSION, true );
		wp_localize_script(
			'varizet-admin',
			'varizetAdmin',
			array(
				'copied'     => __( 'Copied', 'varizet-woocommerce' ),
				'confirmOff' => __( 'Disconnect this environment? Checkout stops offering Varizet until you connect again.', 'varizet-woocommerce' ),
			)
		);
	}

	/**
	 * Stores a one-time notice for the current user.
	 *
	 * @param string $type    success|error|warning|info.
	 * @param string $message Message.
	 */
	public static function flash( string $type, string $message ): void {
		set_transient(
			'varizet_flash_' . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			120
		);
	}

	/**
	 * Prints and clears the flash notice.
	 */
	private static function print_flash(): void {
		$flash = get_transient( 'varizet_flash_' . get_current_user_id() );
		if ( ! is_array( $flash ) ) {
			return;
		}
		delete_transient( 'varizet_flash_' . get_current_user_id() );
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( in_array( $flash['type'], array( 'success', 'error', 'warning', 'info' ), true ) ? $flash['type'] : 'info' ),
			esc_html( (string) $flash['message'] )
		);
	}

	/**
	 * «واریزت تقریباً آماده است» on the plugins and WooCommerce screens until connected.
	 */
	public static function global_notice(): void {
		if ( ! current_user_can( Platform::cap() ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, array( 'plugins', 'dashboard', 'woocommerce_page_wc-settings' ), true ) ) {
			return;
		}
		$mode = Settings::environment();
		if ( Credentials::is_unreadable( $mode ) ) {
			printf(
				'<div class="notice notice-error"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
				esc_html__( 'Varizet: the stored API key can no longer be decrypted (the site salts changed).', 'varizet-woocommerce' ),
				esc_url( admin_url( 'admin.php?page=varizet' ) ),
				esc_html__( 'Enter the key again', 'varizet-woocommerce' )
			);
			return;
		}
		if ( ! Credentials::is_connected( $mode ) ) {
			printf(
				'<div class="notice notice-warning"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
				esc_html__( 'Varizet is almost ready. Connect the store to your Varizet account to offer card-to-card payment with automatic confirmation at checkout.', 'varizet-woocommerce' ),
				esc_url( admin_url( 'admin.php?page=varizet' ) ),
				esc_html__( 'Connect to Varizet', 'varizet-woocommerce' )
			);
		}
	}

	/**
	 * Page router.
	 */
	public static function render(): void {
		if ( ! current_user_can( Platform::cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to manage Varizet.', 'varizet-woocommerce' ), 403 );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab switch.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'connect';
		echo '<div class="wrap varizet-admin">';
		self::header( 'status' === $tab ? 'status' : 'connect' );
		self::print_flash();
		if ( 'status' === $tab ) {
			self::render_status();
		} else {
			self::render_connect();
		}
		echo '</div>';
	}

	/**
	 * Varizet header with tabs.
	 *
	 * @param string $current connect|settings|status.
	 */
	private static function header( string $current ): void {
		$mode      = Settings::environment();
		$connected = Credentials::is_connected( $mode );
		$record    = Credentials::record( $mode );
		$store     = isset( $record['store']['name'] ) ? (string) $record['store']['name'] : '';
		$tabs      = array(
			'connect'  => array( __( 'Connection', 'varizet-woocommerce' ), admin_url( 'admin.php?page=varizet' ), 'dashicons-admin-plugins' ),
			'settings' => array( __( 'Gateway settings', 'varizet-woocommerce' ), admin_url( self::gateway_settings_slug() ), 'dashicons-admin-settings' ),
			'status'   => array( __( 'System status', 'varizet-woocommerce' ), admin_url( 'admin.php?page=varizet&tab=status' ), 'dashicons-chart-line' ),
		);
		?>
		<h1 class="screen-reader-text"><?php esc_html_e( 'Varizet', 'varizet-woocommerce' ); ?></h1>
		<div class="vzh">
			<div class="vzh-top">
				<img src="<?php echo esc_url( VARIZET_WC_URL . 'assets/img/mark.svg' ); ?>" width="34" height="34" alt="" />
				<div class="vzh-name"><b><?php esc_html_e( 'Varizet', 'varizet-woocommerce' ); ?></b><span><?php esc_html_e( 'Card-to-card payments with automatic confirmation for WooCommerce', 'varizet-woocommerce' ); ?></span></div>
				<span class="vz-badge vz-badge-outline">
					<?php
					/* translators: %s: plugin version. */
					echo esc_html( self::n( sprintf( __( 'Version %s', 'varizet-woocommerce' ), VARIZET_WC_VERSION ) ) );
					?>
				</span>
				<span class="vz-grow"></span>
				<span class="vzh-conn" data-conn="<?php echo esc_attr( $connected ? ( 'test' === $mode ? 'test' : 'on' ) : 'off' ); ?>">
					<span class="vz-pulse"></span>
					<span>
						<?php
						if ( ! $connected ) {
							esc_html_e( 'Not connected', 'varizet-woocommerce' );
						} elseif ( '' !== $store ) {
							/* translators: %s: Varizet store name. */
							echo esc_html( sprintf( __( 'Connected to %s', 'varizet-woocommerce' ), $store ) );
						} else {
							esc_html_e( 'Connected', 'varizet-woocommerce' );
						}
						if ( $connected && 'test' === $mode ) {
							echo ' · ' . esc_html__( 'Test mode', 'varizet-woocommerce' );
						}
						?>
					</span>
				</span>
				<a class="button" href="https://varizet.ir/docs/integrations/woocommerce" target="_blank" rel="noopener"><span class="dashicons dashicons-media-document"></span> <?php esc_html_e( 'Docs', 'varizet-woocommerce' ); ?></a>
				<a class="button" href="https://varizet.ir/support" target="_blank" rel="noopener"><span class="dashicons dashicons-sos"></span> <?php esc_html_e( 'Support', 'varizet-woocommerce' ); ?></a>
			</div>
			<nav class="nav-tab-wrapper vzh-tabs" aria-label="<?php esc_attr_e( 'Varizet sections', 'varizet-woocommerce' ); ?>">
				<?php foreach ( $tabs as $key => $tab ) : ?>
					<a class="nav-tab <?php echo $key === $current ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( $tab[1] ); ?>" <?php echo $key === $current ? 'aria-current="page"' : ''; ?>><span class="dashicons <?php echo esc_attr( $tab[2] ); ?>"></span><?php echo esc_html( $tab[0] ); ?></a>
				<?php endforeach; ?>
			</nav>
		</div>
		<?php
	}

	// ---- Connection ---------------------------------------------------------------------

	/**
	 * Connection tab.
	 */
	private static function render_connect(): void {
		$mode      = Settings::environment();
		$connected = Credentials::is_connected( $mode );
		if ( ! $connected ) {
			self::render_not_connected();
			return;
		}
		$record  = Credentials::record( $mode );
		$stats   = WebhookController::stats();
		$state   = Scheduler::state();
		$day     = WebhookController::last_24h();
		$secret  = null !== Credentials::webhook_secret( $mode );
		$store   = isset( $record['store']['name'] ) ? (string) $record['store']['name'] : get_bloginfo( 'name' );
		$last_ev = array() !== $stats['recent'] ? end( $stats['recent'] ) : null;
		$user    = isset( $record['connected_by'] ) ? get_userdata( (int) $record['connected_by'] ) : false;
		?>
		<?php if ( 'test' === $mode ) : ?>
			<div class="notice notice-warning inline vz-test-banner"><p><strong><?php esc_html_e( 'Test mode is on.', 'varizet-woocommerce' ); ?></strong> <?php esc_html_e( 'Orders are created with your test key and deposits are simulated in the Varizet panel. Switch back to Live before sending real customers.', 'varizet-woocommerce' ); ?></p></div>
		<?php endif; ?>
		<?php if ( ! $secret ) : ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'No webhook secret is saved for this environment, so payments arrive only through the 2-minute background sync. Register the webhook URL below in the Varizet panel and save its secret.', 'varizet-woocommerce' ); ?></p></div>
		<?php endif; ?>

		<div class="vz-cn-grid">
			<div class="vz-card">
				<div class="vz-card-h">
					<span class="vz-store-av"><?php echo esc_html( mb_substr( $store, 0, 1 ) ); ?></span>
					<div class="vz-grow">
						<div class="vz-row"><b class="vz-store-name"><?php echo esc_html( $store ); ?></b><span class="vz-badge vz-badge-success vz-dot"><?php esc_html_e( 'Connected', 'varizet-woocommerce' ); ?></span>
						<?php if ( 'test' === $mode ) : ?>
							<span class="vz-badge vz-badge-warning"><?php esc_html_e( 'Test mode', 'varizet-woocommerce' ); ?></span>
						<?php endif; ?>
						</div>
						<div class="vz-xs vz-subtle">
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: connection method, 2: user, 3: date. */
									__( '%1$s by %2$s on %3$s', 'varizet-woocommerce' ),
									'oneclick' === ( $record['method'] ?? '' ) ? __( 'Connected with one click', 'varizet-woocommerce' ) : __( 'Connected manually', 'varizet-woocommerce' ),
									$user ? $user->user_email : '—',
									wp_date( get_option( 'date_format' ) . ' H:i', (int) ( $record['connected_at'] ?? time() ) )
								)
							);
							?>
						</div>
					</div>
				</div>
				<div class="vz-card-b">
					<div class="vz-cn-row"><span><?php esc_html_e( 'Environment', 'varizet-woocommerce' ); ?></span><div class="vz-val">
						<?php foreach ( Credentials::MODES as $m ) : ?>
							<span class="vz-env <?php echo $m === $mode ? 'is-on' : ''; ?>"><?php echo esc_html( 'live' === $m ? __( 'Live, real payments', 'varizet-woocommerce' ) : __( 'Test, Sandbox', 'varizet-woocommerce' ) ); ?>
								<?php echo Credentials::is_connected( $m ) ? '<span class="dashicons dashicons-yes"></span>' : ''; ?></span>
						<?php endforeach; ?>
						<a href="<?php echo esc_url( admin_url( self::gateway_settings_slug() ) ); ?>" class="vz-xs"><?php esc_html_e( 'Change in gateway settings', 'varizet-woocommerce' ); ?></a>
					</div></div>
					<div class="vz-cn-row"><span><?php esc_html_e( 'API key', 'varizet-woocommerce' ); ?></span><div class="vz-val"><code class="vz-mono"><?php echo esc_html( (string) ( $record['key_hint'] ?? '' ) ); ?></code>
						<?php foreach ( ( isset( $record['scopes'] ) && is_array( $record['scopes'] ) && array() !== $record['scopes'] ? $record['scopes'] : array( 'orders:read', 'orders:write' ) ) as $scope ) : ?>
							<span class="vz-scope"><?php echo esc_html( (string) $scope ); ?></span>
						<?php endforeach; ?>
					</div></div>
					<div class="vz-cn-row"><span><?php esc_html_e( 'Webhook', 'varizet-woocommerce' ); ?></span><div class="vz-val">
						<?php if ( $secret && $stats['last_ok_at'] ) : ?>
							<span class="vz-badge vz-badge-success"><?php esc_html_e( 'Verified', 'varizet-woocommerce' ); ?></span>
						<?php elseif ( $secret ) : ?>
							<span class="vz-badge vz-badge-info"><?php esc_html_e( 'Waiting for the first event', 'varizet-woocommerce' ); ?></span>
						<?php else : ?>
							<span class="vz-badge vz-badge-warning"><?php esc_html_e( 'No secret', 'varizet-woocommerce' ); ?></span>
						<?php endif; ?>
						<code class="vz-mono vz-brk"><?php echo esc_html( Settings::webhook_url() ); ?></code>
						<button type="button" class="button button-small" data-vz-copy="<?php echo esc_attr( Settings::webhook_url() ); ?>"><?php esc_html_e( 'Copy', 'varizet-woocommerce' ); ?></button>
						<span class="vz-xs vz-subtle"><?php esc_html_e( 'HMAC-SHA256 signature, 300-second window', 'varizet-woocommerce' ); ?></span>
					</div></div>
					<div class="vz-cn-row"><span><?php esc_html_e( 'Last event', 'varizet-woocommerce' ); ?></span><div class="vz-val">
						<?php if ( is_array( $last_ev ) ) : ?>
							<span class="vz-scope"><?php echo esc_html( '' === $last_ev['type'] ? $last_ev['result'] : $last_ev['type'] ); ?></span>
							<?php if ( '' !== $last_ev['code'] ) : ?>
								<code class="vz-mono"><?php echo esc_html( $last_ev['code'] ); ?></code>
							<?php endif; ?>
							<span><?php echo esc_html( self::ago( (int) $last_ev['t'] ) ); ?></span>
							<span class="vz-code <?php echo (int) $last_ev['status'] < 300 ? 'is-ok' : 'is-bad'; ?>"><?php echo esc_html( (string) $last_ev['status'] ); ?></span>
						<?php else : ?>
							<span class="vz-subtle"><?php esc_html_e( 'No webhook received yet.', 'varizet-woocommerce' ); ?></span>
						<?php endif; ?>
					</div></div>
					<div class="vz-cn-row"><span><?php esc_html_e( 'Background sync', 'varizet-woocommerce' ); ?></span><div class="vz-val"><span class="vz-pulse"></span><span>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: time since last run, 2: open orders checked. */
								__( 'Every 2 minutes with Action Scheduler; last run %1$s, %2$d open orders checked.', 'varizet-woocommerce' ),
								empty( $state['last_run_at'] ) ? __( 'not yet', 'varizet-woocommerce' ) : self::ago( (int) $state['last_run_at'] ),
								(int) ( $state['checked'] ?? 0 )
							)
						);
						?>
					</span></div></div>
				</div>
				<div class="vz-card-f">
					<a class="button" href="<?php echo esc_url( Settings::app_url() . '/integrations' ); ?>" target="_blank" rel="noopener"><span class="dashicons dashicons-external"></span> <?php esc_html_e( 'Open the Varizet panel', 'varizet-woocommerce' ); ?></a>
					<?php self::post_button( 'varizet_test_connection', __( 'Test connection', 'varizet-woocommerce' ), 'dashicons-heart' ); ?>
					<span class="vz-grow"></span>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="vz-inline" data-vz-confirm>
						<?php wp_nonce_field( 'varizet_disconnect' ); ?>
						<input type="hidden" name="action" value="varizet_disconnect" />
						<input type="hidden" name="varizet_mode" value="<?php echo esc_attr( $mode ); ?>" />
						<button type="submit" class="button vz-btn-danger"><span class="dashicons dashicons-dismiss"></span> <?php esc_html_e( 'Disconnect', 'varizet-woocommerce' ); ?></button>
					</form>
				</div>
			</div>

			<div class="vz-col">
				<div class="vz-card">
					<div class="vz-card-h"><span class="vz-card-title"><?php esc_html_e( 'Webhooks, last 24 hours', 'varizet-woocommerce' ); ?></span><span class="vz-live-tag"><span class="vz-pulse"></span><?php esc_html_e( 'Live feed', 'varizet-woocommerce' ); ?></span></div>
					<div class="vz-mini-stats">
						<div><small><?php esc_html_e( 'Delivered', 'varizet-woocommerce' ); ?></small><b><?php echo esc_html( self::n( (string) $day['ok'] ) ); ?></b></div>
						<div><small><?php esc_html_e( 'Rejected', 'varizet-woocommerce' ); ?></small><b><?php echo esc_html( self::n( (string) $day['fail'] ) ); ?></b></div>
						<div><small><?php esc_html_e( 'Open orders', 'varizet-woocommerce' ); ?></small><b><?php echo esc_html( self::n( (string) count( Scheduler::open_orders( 0, 100 ) ) ) ); ?></b></div>
					</div>
				</div>
				<div class="vz-card">
					<div class="vz-card-h"><span class="vz-card-title"><?php esc_html_e( 'Recent webhook events', 'varizet-woocommerce' ); ?></span><a class="vz-xs" href="<?php echo esc_url( admin_url( 'admin.php?page=varizet&tab=status' ) ); ?>"><?php esc_html_e( 'All logs', 'varizet-woocommerce' ); ?></a></div>
					<?php self::events_list( 6 ); ?>
				</div>
			</div>
		</div>

		<details class="vz-card vz-manual">
			<summary><span class="dashicons dashicons-admin-network"></span> <?php esc_html_e( 'Change keys or add the other environment', 'varizet-woocommerce' ); ?></summary>
			<?php self::manual_form( true ); ?>
		</details>
		<?php
	}

	/**
	 * Connection tab before connecting.
	 */
	private static function render_not_connected(): void {
		$one_click = Settings::one_click_connect_enabled();
		$host      = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$unread    = Credentials::is_unreadable( Settings::environment() );
		?>
		<div class="notice notice-warning inline"><p>
			<?php
			echo esc_html(
				$unread
					? __( 'The stored API key can no longer be decrypted (the site salts changed). Enter the key again below.', 'varizet-woocommerce' )
					: __( 'Varizet is almost ready. Connect the store to your Varizet account to activate card-to-card payment at checkout; it takes less than a minute.', 'varizet-woocommerce' )
			);
			?>
		</p></div>
		<div class="vz-card vz-hero">
			<div class="vz-hero-l">
				<span class="vz-eyebrow"><span class="dashicons dashicons-performance"></span><?php esc_html_e( 'Set up in under a minute', 'varizet-woocommerce' ); ?></span>
				<h2>
					<?php
					/* translators: %s: site name. */
					echo esc_html( sprintf( __( 'Connect %s to Varizet', 'varizet-woocommerce' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ) );
					?>
				</h2>
				<p><?php esc_html_e( 'After you approve in the Varizet panel, the plugin configures the API key and the signed webhook by itself, and "card-to-card with automatic confirmation" appears at checkout.', 'varizet-woocommerce' ); ?></p>
				<div class="vz-row vz-gap">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="vz-inline">
						<?php wp_nonce_field( 'varizet_connect_start' ); ?>
						<input type="hidden" name="action" value="varizet_connect_start" />
						<button type="submit" class="button button-primary button-hero" <?php disabled( ! $one_click ); ?>><span class="dashicons dashicons-admin-plugins"></span> <?php esc_html_e( 'Connect to Varizet', 'varizet-woocommerce' ); ?></button>
					</form>
					<a class="button button-hero" href="<?php echo esc_url( Settings::app_url() . '/signup' ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'No account? Sign up free', 'varizet-woocommerce' ); ?></a>
				</div>
				<?php if ( ! $one_click ) : ?>
					<p class="vz-xs vz-subtle"><?php esc_html_e( 'One-click connect is coming soon. For now, connect with an API key below.', 'varizet-woocommerce' ); ?></p>
				<?php endif; ?>
				<details class="vz-manual" <?php echo $one_click ? '' : 'open'; ?>>
					<summary><span class="dashicons dashicons-admin-network"></span> <?php esc_html_e( 'Or connect manually with an API key', 'varizet-woocommerce' ); ?></summary>
					<?php self::manual_form( false ); ?>
				</details>
				<ol class="vz-steps">
					<li><span class="vz-n"><?php echo esc_html( self::n( '1' ) ); ?></span><div><b><?php esc_html_e( 'Sign in and approve in the Varizet panel', 'varizet-woocommerce' ); ?></b><small><?php esc_html_e( 'No Varizet password is stored in WordPress.', 'varizet-woocommerce' ); ?></small></div></li>
					<li><span class="vz-n"><?php echo esc_html( self::n( '2' ) ); ?></span><div><b><?php esc_html_e( 'Key and webhook are created', 'varizet-woocommerce' ); ?></b><small><?php esc_html_e( 'A key for this site with limited scopes and a webhook signed with HMAC-SHA256.', 'varizet-woocommerce' ); ?></small></div></li>
					<li><span class="vz-n"><?php echo esc_html( self::n( '3' ) ); ?></span><div><b><?php esc_html_e( 'The gateway goes live at checkout', 'varizet-woocommerce' ); ?></b><small><?php esc_html_e( 'Customers can pay card-to-card right away and orders are confirmed automatically.', 'varizet-woocommerce' ); ?></small></div></li>
				</ol>
			</div>
			<div class="vz-hero-r">
				<div class="vz-fviz">
					<div class="vz-fv-node"><span class="vz-fv-ic dashicons dashicons-store"></span><b class="vz-mono"><?php echo esc_html( $host ); ?></b><small><?php esc_html_e( 'WordPress and WooCommerce', 'varizet-woocommerce' ); ?></small></div>
					<div class="vz-fv-mid">
						<span class="vz-fv-tag is-ok"><?php esc_html_e( 'Instant webhook', 'varizet-woocommerce' ); ?></span>
						<span class="vz-fv-line"></span>
						<span class="vz-fv-tag"><?php esc_html_e( 'Backup sync', 'varizet-woocommerce' ); ?></span>
					</div>
					<div class="vz-fv-node"><img src="<?php echo esc_url( VARIZET_WC_URL . 'assets/img/mark.svg' ); ?>" width="34" height="34" alt="" /><b><?php esc_html_e( 'Varizet', 'varizet-woocommerce' ); ?></b><small class="vz-mono"><?php echo esc_html( (string) wp_parse_url( Settings::api_base_url(), PHP_URL_HOST ) ); ?></small></div>
				</div>
				<p class="vz-xs vz-subtle vz-center"><?php esc_html_e( 'Payment events arrive within the same second by webhook. If your server is briefly unreachable, the backup sync checks open orders by their id every 2 minutes; no order is left behind.', 'varizet-woocommerce' ); ?></p>
				<div class="vz-req">
					<?php foreach ( self::requirements() as $req ) : ?>
						<span class="vz-badge <?php echo $req[1] ? 'vz-badge-success' : 'vz-badge-danger'; ?>"><?php echo $req[1] ? '✓' : '✕'; ?> <?php echo esc_html( $req[0] ); ?></span>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Manual key form.
	 *
	 * @param bool $connected Whether some environment is connected already.
	 */
	private static function manual_form( bool $connected ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="vz-manual-form" autocomplete="off">
			<?php wp_nonce_field( 'varizet_manual_connect' ); ?>
			<input type="hidden" name="action" value="varizet_manual_connect" />
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="varizet_api_key"><?php esc_html_e( 'API key', 'varizet-woocommerce' ); ?></label></th>
					<td>
						<input type="password" class="regular-text vz-mono" id="varizet_api_key" name="varizet_api_key" placeholder="vz_live_…" dir="ltr" autocomplete="new-password" spellcheck="false" />
						<p class="description"><?php esc_html_e( 'Create it in the Varizet panel under Developers → API keys with the orders:read and orders:write scopes. vz_live_ keys connect Live and vz_test_ keys connect Test.', 'varizet-woocommerce' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="varizet_webhook_secret"><?php esc_html_e( 'Webhook secret', 'varizet-woocommerce' ); ?></label></th>
					<td>
						<input type="password" class="regular-text vz-mono" id="varizet_webhook_secret" name="varizet_webhook_secret" dir="ltr" autocomplete="new-password" spellcheck="false" />
						<p class="description"><?php esc_html_e( 'In the Varizet panel, add a webhook endpoint with this URL and the order events, then paste the secret shown once:', 'varizet-woocommerce' ); ?></p>
						<p><code class="vz-mono vz-brk"><?php echo esc_html( Settings::webhook_url() ); ?></code> <button type="button" class="button button-small" data-vz-copy="<?php echo esc_attr( Settings::webhook_url() ); ?>"><?php esc_html_e( 'Copy', 'varizet-woocommerce' ); ?></button></p>
					</td>
				</tr>
				<?php if ( $connected ) : ?>
					<tr>
						<th scope="row"><label for="varizet_mode"><?php esc_html_e( 'Only update the secret of', 'varizet-woocommerce' ); ?></label></th>
						<td>
							<select id="varizet_mode" name="varizet_mode">
								<option value=""><?php esc_html_e( '— (saving a new key)', 'varizet-woocommerce' ); ?></option>
								<?php foreach ( Credentials::MODES as $m ) : ?>
									<?php if ( Credentials::is_connected( $m ) ) : ?>
										<option value="<?php echo esc_attr( $m ); ?>"><?php echo esc_html( 'live' === $m ? __( 'Live', 'varizet-woocommerce' ) : __( 'Test', 'varizet-woocommerce' ) ); ?></option>
									<?php endif; ?>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Leave the API key empty and pick an environment to replace only its webhook secret.', 'varizet-woocommerce' ); ?></p>
						</td>
					</tr>
				<?php endif; ?>
			</table>
			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Verify and save', 'varizet-woocommerce' ); ?></button></p>
		</form>
		<?php
	}

	/**
	 * Recent webhook events list.
	 *
	 * @param int $limit Rows.
	 */
	private static function events_list( int $limit ): void {
		$recent = array_reverse( WebhookController::stats()['recent'] );
		if ( array() === $recent ) {
			echo '<p class="vz-empty">' . esc_html__( 'No webhook received yet.', 'varizet-woocommerce' ) . '</p>';
			return;
		}
		echo '<ul class="vz-ev-list">';
		foreach ( array_slice( $recent, 0, $limit ) as $ev ) {
			printf(
				'<li class="vz-ev-row"><span class="vz-scope">%1$s</span><code class="vz-mono vz-grow">%2$s</code><span class="vz-xs vz-subtle">%3$s</span><span class="vz-code %4$s">%5$s</span></li>',
				esc_html( '' === $ev['type'] ? (string) $ev['result'] : (string) $ev['type'] ),
				esc_html( '' !== $ev['code'] ? (string) $ev['code'] : ( '' !== $ev['reason'] ? (string) $ev['reason'] : (string) $ev['result'] ) ),
				esc_html( self::ago( (int) $ev['t'] ) ),
				(int) $ev['status'] < 300 ? 'is-ok' : 'is-bad',
				esc_html( (string) $ev['status'] )
			);
		}
		echo '</ul>';
	}

	// ---- Gateway settings ---------------------------------------------------------------

	/**
	 * Gateway settings screen (inside WooCommerce → Settings → Payments).
	 *
	 * @param Gateway $gateway Gateway.
	 */
	public static function render_gateway_settings( Gateway $gateway ): void {
		$mode   = Settings::environment();
		$record = Credentials::record( $mode );
		$api    = HealthChecks::api();
		$day    = WebhookController::last_24h();
		$state  = Scheduler::state();
		$ok     = $api['ok'] && false !== $api['key_ok'] && Credentials::is_connected( $mode );
		?>
		<div class="varizet-admin vz-settings">
			<h2 class="vz-set-title">
				<img src="<?php echo esc_url( VARIZET_WC_URL . 'assets/img/mark.svg' ); ?>" width="30" height="30" alt="" />
				<span><?php echo esc_html( $gateway->get_method_title() ); ?></span>
				<?php if ( 'yes' === $gateway->enabled ) : ?>
					<span class="vz-badge vz-badge-success vz-dot"><?php esc_html_e( 'Enabled', 'varizet-woocommerce' ); ?></span>
				<?php endif; ?>
				<?php if ( 'test' === $mode ) : ?>
					<span class="vz-badge vz-badge-warning"><?php esc_html_e( 'Test mode', 'varizet-woocommerce' ); ?></span>
				<?php endif; ?>
				<?php wc_back_link( __( 'Return to payments', 'woocommerce' ), admin_url( 'admin.php?page=wc-settings&tab=checkout' ) ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- reuse WooCommerce's own string. ?>
			</h2>
			<p class="vz-xs vz-subtle">
				<?php
				printf(
					/* translators: %s: link to the Varizet panel. */
					esc_html__( 'Destination cards, matching rules and notifications are managed in the %s.', 'varizet-woocommerce' ),
					'<a href="' . esc_url( Settings::app_url() ) . '" target="_blank" rel="noopener">' . esc_html__( 'Varizet panel', 'varizet-woocommerce' ) . '</a>'
				);
				?>
			</p>
			<div class="vz-set-grid">
				<div class="vz-card vz-set-card">
					<table class="form-table">
						<?php echo $gateway->settings_rows_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- generated and escaped by WC_Settings_API. ?>
					</table>
				</div>
				<div class="vz-col">
					<div class="vz-card">
						<div class="vz-card-h"><span class="vz-card-title"><?php esc_html_e( 'Connection health', 'varizet-woocommerce' ); ?></span>
							<span class="vz-badge <?php echo $ok ? 'vz-badge-success' : 'vz-badge-danger'; ?> vz-dot"><?php echo esc_html( $ok ? __( 'Healthy', 'varizet-woocommerce' ) : __( 'Needs attention', 'varizet-woocommerce' ) ); ?></span></div>
						<div class="vz-card-b">
							<div class="vz-health-big"><span class="dashicons <?php echo $ok ? 'dashicons-shield-alt' : 'dashicons-warning'; ?>"></span><div><b><?php echo esc_html( $ok ? __( 'Ready to receive payments', 'varizet-woocommerce' ) : __( 'Not ready to receive payments', 'varizet-woocommerce' ) ); ?></b></div></div>
							<dl class="vz-kv">
								<dt><?php esc_html_e( 'API response', 'varizet-woocommerce' ); ?></dt><dd><?php echo esc_html( null === $api['latency'] ? '—' : self::n( sprintf( /* translators: %d: milliseconds. */ __( '%d ms', 'varizet-woocommerce' ), (int) $api['latency'] ) ) ); ?></dd>
								<dt><?php esc_html_e( 'Webhook', 'varizet-woocommerce' ); ?></dt><dd><?php echo esc_html( self::n( sprintf( /* translators: 1: ok deliveries, 2: total deliveries. */ __( '%1$d of %2$d in 24 h', 'varizet-woocommerce' ), $day['ok'], $day['ok'] + $day['fail'] ) ) ); ?></dd>
								<dt><?php esc_html_e( 'Sync', 'varizet-woocommerce' ); ?></dt><dd><?php echo esc_html( empty( $state['last_run_at'] ) ? __( 'not yet', 'varizet-woocommerce' ) : self::ago( (int) $state['last_run_at'] ) ); ?></dd>
								<dt><?php esc_html_e( 'Key', 'varizet-woocommerce' ); ?></dt><dd class="vz-mono"><?php echo esc_html( (string) ( $record['key_hint'] ?? '—' ) ); ?></dd>
							</dl>
							<a class="button vz-btn-block" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=varizet_test_connection&back=settings' ), 'varizet_test_connection' ) ); ?>"><span class="dashicons dashicons-heart"></span> <?php esc_html_e( 'Test connection', 'varizet-woocommerce' ); ?></a>
							<p class="vz-center"><a href="<?php echo esc_url( admin_url( 'admin.php?page=varizet&tab=status' ) ); ?>"><?php esc_html_e( 'Full system status and logs', 'varizet-woocommerce' ); ?></a></p>
						</div>
					</div>
					<div class="vz-card">
						<div class="vz-card-h"><span class="vz-card-title"><?php esc_html_e( 'Preview at checkout', 'varizet-woocommerce' ); ?></span></div>
						<div class="vz-card-b">
							<div class="vz-prev-pm">
								<div class="vz-prev-t"><span data-vz-preview="title"><?php echo esc_html( $gateway->title ); ?></span><img src="<?php echo esc_url( VARIZET_WC_URL . 'assets/img/mark.svg' ); ?>" width="18" height="18" alt="" /></div>
								<p data-vz-preview="description"><?php echo esc_html( $gateway->description ); ?></p>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	// ---- System status ------------------------------------------------------------------

	/**
	 * «وضعیت سیستم» tab.
	 */
	private static function render_status(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only re-run flag, the API check has no side effects.
		$fresh    = isset( $_GET['recheck'] );
		$checks   = HealthChecks::all( $fresh );
		$good     = count( array_filter( $checks, static fn ( $c ) => 'good' === $c['status'] ) );
		$warn     = count( $checks ) - $good;
		$api      = HealthChecks::api();
		$day      = WebhookController::last_24h();
		$queue    = Scheduler::queue_counts();
		$state    = Scheduler::state();
		$groups   = array(
			'connection'  => __( 'Connection and sync', 'varizet-woocommerce' ),
			'environment' => __( 'Server environment', 'varizet-woocommerce' ),
		);
		$recheck  = add_query_arg(
			array(
				'page'    => 'varizet',
				'tab'     => 'status',
				'recheck' => 1,
			),
			admin_url( 'admin.php' )
		);
		$report   = HealthChecks::report();
		$hpos     = class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled();
		$hpos_txt = $hpos ? 'HPOS' : 'CPT';
		?>
		<div class="vz-card vz-summary">
			<span class="vz-summary-ic dashicons <?php echo 0 === $warn ? 'dashicons-shield-alt' : 'dashicons-warning'; ?>"></span>
			<div class="vz-grow">
				<b>
					<?php
					echo esc_html(
						self::n(
							sprintf(
								/* translators: 1: passed checks, 2: checks with suggestions. */
								__( '%1$d checks passed, %2$d need attention', 'varizet-woocommerce' ),
								$good,
								$warn
							)
						)
					);
					?>
				</b>
				<div class="vz-xs vz-subtle"><?php echo esc_html( sprintf( /* translators: 1: storage, 2: time. */ __( 'Orders stored as %1$s; checked %2$s', 'varizet-woocommerce' ), $hpos_txt, wp_date( 'H:i:s' ) ) ); ?></div>
			</div>
			<a class="button" href="<?php echo esc_url( $recheck ); ?>"><span class="dashicons dashicons-update"></span> <?php esc_html_e( 'Run checks again', 'varizet-woocommerce' ); ?></a>
			<button type="button" class="button" data-vz-copy="<?php echo esc_attr( $report ); ?>"><span class="dashicons dashicons-clipboard"></span> <?php esc_html_e( 'Copy system report', 'varizet-woocommerce' ); ?></button>
		</div>

		<div class="vz-stat-grid">
			<div class="vz-card vz-stat"><span class="vz-stat-label"><?php esc_html_e( 'API connection', 'varizet-woocommerce' ); ?></span><span class="vz-stat-value"><?php echo esc_html( null === $api['latency'] ? '—' : self::n( (string) $api['latency'] ) ); ?> <small><?php esc_html_e( 'ms', 'varizet-woocommerce' ); ?></small></span><span class="vz-xs vz-subtle vz-mono"><?php echo esc_html( (string) wp_parse_url( Settings::api_base_url(), PHP_URL_HOST ) ); ?></span></div>
			<div class="vz-card vz-stat"><span class="vz-stat-label"><?php esc_html_e( 'Webhook deliveries, 24 h', 'varizet-woocommerce' ); ?></span><span class="vz-stat-value"><?php echo esc_html( self::n( (string) $day['ok'] ) ); ?> <small><?php echo esc_html( self::n( sprintf( /* translators: %d: total deliveries. */ __( 'of %d', 'varizet-woocommerce' ), $day['ok'] + $day['fail'] ) ) ); ?></small></span><?php self::spark(); ?></div>
			<div class="vz-card vz-stat"><span class="vz-stat-label"><?php esc_html_e( 'Action Scheduler queue', 'varizet-woocommerce' ); ?></span><span class="vz-stat-value"><?php echo esc_html( self::n( (string) $queue['failed'] ) ); ?> <small><?php esc_html_e( 'failed in 7 days', 'varizet-woocommerce' ); ?></small></span><span class="vz-xs vz-subtle"><?php echo esc_html( empty( $state['last_run_at'] ) ? __( 'Backup sync every 2 minutes; not run yet', 'varizet-woocommerce' ) : sprintf( /* translators: %s: time since. */ __( 'Backup sync every 2 minutes; last run %s', 'varizet-woocommerce' ), self::ago( (int) $state['last_run_at'] ) ) ); ?></span></div>
			<div class="vz-card vz-stat"><span class="vz-stat-label"><?php esc_html_e( 'Plugin version', 'varizet-woocommerce' ); ?></span><span class="vz-stat-value"><?php echo esc_html( self::n( VARIZET_WC_VERSION ) ); ?></span><span class="vz-xs vz-subtle"><?php echo esc_html( 'PHP ' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . ' · WC ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '-' ) ); ?></span></div>
		</div>

		<div class="vz-card vz-hc">
			<?php foreach ( $groups as $group => $title ) : ?>
				<div class="vz-hc-grp"><?php echo esc_html( $title ); ?></div>
				<?php foreach ( $checks as $check ) : ?>
					<?php
					if ( $check['group'] !== $group ) {
						continue;
					}
					$icon = 'good' === $check['status'] ? 'yes' : ( 'critical' === $check['status'] ? 'no' : 'warning' );
					?>
					<div class="vz-hc-row" data-status="<?php echo esc_attr( (string) $check['status'] ); ?>">
						<span class="vz-hc-ic dashicons dashicons-<?php echo esc_attr( $icon ); ?>"></span>
						<span class="vz-hc-n"><?php echo esc_html( (string) $check['label'] ); ?></span>
						<span class="vz-hc-v"><?php echo esc_html( (string) $check['text'] ); ?></span>
						<span class="vz-hc-a">
							<?php
							if ( 'sync_all' === ( $check['action'] ?? '' ) ) {
								self::post_button( 'varizet_sync_all', __( 'Sync now', 'varizet-woocommerce' ), 'dashicons-update', 'button-small' );
							} elseif ( 'webhook' === $check['id'] ) {
								printf( '<a class="vz-xs" href="%s" target="_blank" rel="noopener">%s</a>', esc_url( Settings::app_url() . '/developers/webhooks' ), esc_html__( 'Delivery log', 'varizet-woocommerce' ) );
							}
							?>
						</span>
					</div>
				<?php endforeach; ?>
			<?php endforeach; ?>
		</div>

		<?php self::render_logs(); ?>
		<?php
	}

	/**
	 * Log viewer (ring buffer of redacted entries).
	 */
	private static function render_logs(): void {
		$entries = Logger::recent();
		$counts  = array(
			'error'   => 0,
			'warning' => 0,
			'info'    => 0,
			'debug'   => 0,
		);
		foreach ( $entries as $e ) {
			$lv = in_array( $e['level'], array( 'error', 'critical' ), true ) ? 'error' : ( 'notice' === $e['level'] ? 'info' : $e['level'] );
			if ( isset( $counts[ $lv ] ) ) {
				++$counts[ $lv ];
			}
		}
		$labels = array(
			'error'   => __( 'Error', 'varizet-woocommerce' ),
			'warning' => __( 'Warning', 'varizet-woocommerce' ),
			'info'    => __( 'Info', 'varizet-woocommerce' ),
			'debug'   => __( 'Debug', 'varizet-woocommerce' ),
		);
		?>
		<div class="vz-card vz-logs" data-vz-logs>
			<div class="vz-card-h">
				<div class="vz-grow"><span class="vz-card-title"><?php esc_html_e( 'Logs', 'varizet-woocommerce' ); ?></span>
					<div class="vz-xs vz-subtle">
						<?php
						printf(
							/* translators: 1: number of entries, 2: link to WooCommerce logs. */
							esc_html__( 'Last %1$s entries, source varizet. Full files: %2$s', 'varizet-woocommerce' ),
							esc_html( self::n( (string) count( $entries ) ) ),
							'<a href="' . esc_url( admin_url( 'admin.php?page=wc-status&tab=logs&source=varizet' ) ) . '">' . esc_html__( 'WooCommerce → Status → Logs', 'varizet-woocommerce' ) . '</a>'
						);
						?>
					</div>
				</div>
				<input type="search" class="regular-text vz-log-q" placeholder="<?php esc_attr_e( 'Search the log', 'varizet-woocommerce' ); ?>" data-vz-log-q aria-label="<?php esc_attr_e( 'Search the log', 'varizet-woocommerce' ); ?>" />
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=varizet_download_log' ), 'varizet_download_log' ) ); ?>"><span class="dashicons dashicons-download"></span> <?php esc_html_e( 'Download', 'varizet-woocommerce' ); ?></a>
				<?php self::post_button( 'varizet_clear_log', __( 'Clear', 'varizet-woocommerce' ), 'dashicons-trash' ); ?>
			</div>
			<div class="vz-chips" role="toolbar" aria-label="<?php esc_attr_e( 'Filter by level', 'varizet-woocommerce' ); ?>">
				<button type="button" class="vz-chip is-active" data-vz-lv="all"><?php esc_html_e( 'All', 'varizet-woocommerce' ); ?> <b><?php echo esc_html( self::n( (string) count( $entries ) ) ); ?></b></button>
				<?php foreach ( $labels as $lv => $label ) : ?>
					<button type="button" class="vz-chip" data-vz-lv="<?php echo esc_attr( $lv ); ?>"><?php echo esc_html( $label ); ?> <b><?php echo esc_html( self::n( (string) $counts[ $lv ] ) ); ?></b></button>
				<?php endforeach; ?>
			</div>
			<?php if ( array() === $entries ) : ?>
				<p class="vz-empty"><?php esc_html_e( 'No log entries yet.', 'varizet-woocommerce' ); ?></p>
			<?php else : ?>
				<div class="vz-table-wrap"><table class="widefat striped vz-log-tbl">
					<thead><tr><th><?php esc_html_e( 'Time', 'varizet-woocommerce' ); ?></th><th><?php esc_html_e( 'Level', 'varizet-woocommerce' ); ?></th><th><?php esc_html_e( 'Source', 'varizet-woocommerce' ); ?></th><th><?php esc_html_e( 'Message', 'varizet-woocommerce' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( array_slice( $entries, 0, 150 ) as $e ) : ?>
						<?php $lv = in_array( $e['level'], array( 'error', 'critical' ), true ) ? 'error' : ( 'notice' === $e['level'] ? 'info' : $e['level'] ); ?>
						<tr data-lv="<?php echo esc_attr( $lv ); ?>">
							<td class="vz-mono vz-nowrap"><?php echo esc_html( wp_date( 'm/d H:i:s', (int) $e['t'] ) ); ?></td>
							<td><span class="vz-lv vz-lv-<?php echo esc_attr( $lv ); ?>"><?php echo esc_html( $labels[ $lv ] ?? $lv ); ?></span></td>
							<td class="vz-mono"><?php echo esc_html( (string) $e['src'] ); ?></td>
							<td><span class="vz-log-msg" dir="auto"><?php echo esc_html( (string) $e['msg'] ); ?></span>
								<?php if ( ! empty( $e['ctx'] ) ) : ?>
									<code class="vz-log-ctx" dir="ltr"><?php echo esc_html( (string) wp_json_encode( $e['ctx'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); ?></code>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table></div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Hourly webhook sparkline (24 bars).
	 */
	private static function spark(): void {
		$hours = WebhookController::stats()['hours'];
		$now   = (int) floor( time() / HOUR_IN_SECONDS );
		$max   = 1;
		$bars  = array();
		for ( $h = $now - 23; $h <= $now; $h++ ) {
			$ok     = (int) ( $hours[ $h ]['ok'] ?? 0 );
			$fail   = (int) ( $hours[ $h ]['fail'] ?? 0 );
			$bars[] = array( $ok, $fail );
			$max    = max( $max, $ok + $fail );
		}
		echo '<span class="vz-spark" aria-hidden="true">';
		foreach ( $bars as $b ) {
			printf(
				'<i style="height:%1$d%%" class="%2$s"></i>',
				(int) max( 6, round( ( $b[0] + $b[1] ) / $max * 100 ) ),
				$b[1] > 0 ? 'is-bad' : ( $b[0] > 0 ? 'is-ok' : '' )
			);
		}
		echo '</span>';
	}

	// ---- Actions ------------------------------------------------------------------------

	/**
	 * «تست اتصال».
	 */
	public static function test_connection(): void {
		self::require_cap();
		check_admin_referer( 'varizet_test_connection' );
		$api = HealthChecks::api( true );
		if ( $api['ok'] && false !== $api['key_ok'] ) {
			/* translators: %d: latency in ms. */
			self::flash( 'success', self::n( sprintf( __( 'Varizet answered in %d ms and accepted the API key.', 'varizet-woocommerce' ), (int) $api['latency'] ) ) );
		} elseif ( $api['ok'] ) {
			/* translators: %s: error code. */
			self::flash( 'error', sprintf( __( 'Varizet is reachable but rejected the API key (%s).', 'varizet-woocommerce' ), (string) $api['key_error'] ) );
		} else {
			/* translators: %s: error. */
			self::flash( 'error', sprintf( __( 'Varizet is not reachable: %s', 'varizet-woocommerce' ), (string) $api['error'] ) );
		}
		// Nonce verified above with check_admin_referer().
		$back = isset( $_REQUEST['back'] ) && 'settings' === $_REQUEST['back'] ? admin_url( self::gateway_settings_slug() ) : admin_url( 'admin.php?page=varizet' );
		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * «همگام‌سازی» of all open orders now.
	 */
	public static function sync_all(): void {
		self::require_cap();
		check_admin_referer( 'varizet_sync_all' );
		Scheduler::run();
		$state = Scheduler::state();
		self::flash(
			'success',
			self::n(
				sprintf(
					/* translators: 1: checked orders, 2: changed orders, 3: errors. */
					__( '%1$d open orders checked; %2$d updated; %3$d errors.', 'varizet-woocommerce' ),
					(int) ( $state['checked'] ?? 0 ),
					(int) ( $state['changed'] ?? 0 ),
					(int) ( $state['errors'] ?? 0 )
				)
			)
		);
		wp_safe_redirect( admin_url( 'admin.php?page=varizet&tab=status' ) );
		exit;
	}

	/**
	 * Downloads the ring buffer as a text file.
	 */
	public static function download_log(): void {
		self::require_cap();
		check_admin_referer( 'varizet_download_log' );
		$lines = array();
		foreach ( array_reverse( Logger::recent() ) as $e ) {
			$lines[] = sprintf( '%s %s [%s] %s %s', gmdate( 'c', (int) $e['t'] ), strtoupper( (string) $e['level'] ), (string) $e['src'], (string) $e['msg'], empty( $e['ctx'] ) ? '' : (string) wp_json_encode( $e['ctx'], JSON_UNESCAPED_UNICODE ) );
		}
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="varizet-' . gmdate( 'Y-m-d' ) . '.log"' );
		echo implode( "\n", $lines ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain-text download of redacted log lines.
		exit;
	}

	/**
	 * Clears the ring buffer.
	 */
	public static function clear_log(): void {
		self::require_cap();
		check_admin_referer( 'varizet_clear_log' );
		Logger::clear();
		self::flash( 'success', __( 'Log cleared.', 'varizet-woocommerce' ) );
		wp_safe_redirect( admin_url( 'admin.php?page=varizet&tab=status' ) );
		exit;
	}

	// ---- Helpers ------------------------------------------------------------------------

	/**
	 * Small admin-post form with one button.
	 *
	 * @param string $action Action (also the nonce action).
	 * @param string $label  Label.
	 * @param string $icon   Dashicon class.
	 * @param string $extra_class Extra button class.
	 */
	private static function post_button( string $action, string $label, string $icon, string $extra_class = '' ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="vz-inline">
			<?php wp_nonce_field( $action ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>" />
			<button type="submit" class="button <?php echo esc_attr( $extra_class ); ?>"><span class="dashicons <?php echo esc_attr( $icon ); ?>"></span> <?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	/**
	 * Requirement badges.
	 *
	 * @return array<int, array{0:string, 1:bool}>
	 */
	private static function requirements(): array {
		$wc = defined( 'WC_VERSION' ) ? WC_VERSION : '0';
		return array(
			/* translators: %s: version. */
			array( self::n( sprintf( __( 'WordPress %s', 'varizet-woocommerce' ), get_bloginfo( 'version' ) ) ), version_compare( get_bloginfo( 'version' ), '6.4', '>=' ) ),
			/* translators: %s: version. */
			array( self::n( sprintf( __( 'WooCommerce %s', 'varizet-woocommerce' ), $wc ) ), version_compare( $wc, '8.5', '>=' ) ),
			array( self::n( 'PHP ' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION ), version_compare( PHP_VERSION, '8.1', '>=' ) ),
			array( 'HPOS', true ),
			array( __( 'Checkout block', 'varizet-woocommerce' ), class_exists( \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class ) ),
			/* translators: %s: currency code. */
			array( sprintf( __( 'Currency %s', 'varizet-woocommerce' ), Platform::store_currency() ), \Varizet\WooCommerce\Support\Amount::is_supported( Platform::store_currency() ) ),
		);
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
	 * "3 minutes ago".
	 *
	 * @param int $time Unix time.
	 */
	public static function ago( int $time ): string {
		/* translators: %s: human time difference. */
		return self::n( sprintf( __( '%s ago', 'varizet-woocommerce' ), human_time_diff( $time ) ) );
	}

	/**
	 * Persian digits in a Persian admin.
	 *
	 * @param string $text Text.
	 */
	public static function n( string $text ): string {
		return str_starts_with( determine_locale(), 'fa' ) ? Format::to_persian_digits( $text ) : $text;
	}
}
