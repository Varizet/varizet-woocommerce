<?php
/**
 * «واریزت» box on the order edit screen (HPOS and legacy).
 *
 * @package Varizet\WooCommerce
 */

namespace Varizet\WooCommerce\Order;

use Varizet\WooCommerce\Admin\SettingsPage;
use Varizet\WooCommerce\Api\ApiException;
use Varizet\WooCommerce\Settings;
use Varizet\WooCommerce\Support\Amount;
use Varizet\WooCommerce\Support\Format;
use Varizet\WooCommerce\Support\Logger;
use Varizet\WooCommerce\ThankYou\LiveStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Everything support needs to answer a customer (design wp-orders → edit): status, unique
 * amount with amber digits, destination card, payment link, match score and reasons, payer
 * card, timeline, and «همگام‌سازی وضعیت» / «مشاهده در پنل واریزت».
 */
final class MetaBox {

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( 'add_meta_boxes', array( self::class, 'register' ), 10, 2 );
		add_action( 'admin_post_varizet_sync_order', array( self::class, 'sync_order' ) );
		add_action( 'admin_notices', array( self::class, 'notice' ) );
	}

	/**
	 * Registers the box on the HPOS or legacy order screen.
	 *
	 * @param string             $post_type     Screen/post type.
	 * @param \WP_Post|\WC_Order $post_or_order Object.
	 */
	public static function register( $post_type, $post_or_order = null ): void {
		$screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		$order  = $post_or_order instanceof \WP_Post ? wc_get_order( $post_or_order->ID ) : $post_or_order;
		if ( ! $order instanceof \WC_Order || ! OrderMeta::is_varizet( $order ) ) {
			return;
		}
		add_meta_box( 'varizet-order', __( 'Varizet', 'varizet-woocommerce' ), array( self::class, 'render' ), $screen, 'side', 'high' );
	}

	/**
	 * Box content.
	 *
	 * @param \WP_Post|\WC_Order $post_or_order Object.
	 */
	public static function render( $post_or_order ): void {
		$order = $post_or_order instanceof \WP_Post ? wc_get_order( $post_or_order->ID ) : $post_or_order;
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$persian = str_starts_with( determine_locale(), 'fa' );
		$vz_id   = (string) $order->get_meta( OrderMeta::ORDER_ID );
		$status  = (string) $order->get_meta( OrderMeta::STATUS );
		$labels  = OrderSync::status_labels();

		echo '<div class="varizet-admin vz-meta">';
		if ( '' === $vz_id ) {
			echo '<p class="vz-subtle">' . esc_html__( 'No Varizet payment request was created for this order yet.', 'varizet-woocommerce' ) . '</p></div>';
			return;
		}

		$amount  = (int) $order->get_meta( OrderMeta::AMOUNT );
		$unique  = (int) $order->get_meta( OrderMeta::UNIQUE_AMOUNT );
		$dest    = OrderMeta::array_meta( $order, OrderMeta::DESTINATION );
		$match   = OrderMeta::array_meta( $order, OrderMeta::MATCH );
		$url     = (string) $order->get_meta( OrderMeta::PAYMENT_URL );
		$code    = (string) $order->get_meta( OrderMeta::CODE );
		$payer   = (string) $order->get_meta( OrderMeta::PAYER_LAST4 );
		$claimed = (string) $order->get_meta( OrderMeta::CLAIMED_LAST4 );
		$stock   = (string) $order->get_meta( OrderMeta::STOCK_WARNING );
		$digits  = Amount::unique_digits( $amount, $unique );
		$diff    = $unique - $amount;
		$n       = static fn ( $v ) => $persian ? Format::to_persian_digits( (string) $v ) : (string) $v;
		?>
		<div class="vz-meta-h">
			<span class="vz-badge <?php echo esc_attr( self::badge_class( $status ) ); ?> vz-dot"><?php echo esc_html( $labels[ $status ] ?? $status ); ?></span>
			<?php if ( 'test' === OrderMeta::mode( $order ) ) : ?>
				<span class="vz-badge vz-badge-warning"><?php esc_html_e( 'Test', 'varizet-woocommerce' ); ?></span>
			<?php endif; ?>
		</div>

		<?php if ( '' !== $stock ) : ?>
			<div class="notice notice-warning inline vz-stock"><p>
				<?php
				echo esc_html(
					'check' === $stock
						? __( 'Reopened after WooCommerce cancelled it: check stock before shipping.', 'varizet-woocommerce' )
						/* translators: %s: products. */
						: sprintf( __( 'Oversold after reopening: %s', 'varizet-woocommerce' ), $stock )
				);
				?>
			</p></div>
		<?php endif; ?>

		<div class="vz-vm-sec">
			<small><?php esc_html_e( 'Unique amount', 'varizet-woocommerce' ); ?></small>
			<div class="vz-vm-amt"><?php echo Format::unique_amount_html( $unique, $digits, $persian ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Format. ?> <small><?php esc_html_e( 'rial', 'varizet-woocommerce' ); ?></small></div>
			<span class="vz-xs vz-subtle">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: base amount, 2: sign, 3: unique part, 4: Varizet order code. */
						__( '%1$s rial %2$s %3$s rial unique id, order %4$s', 'varizet-woocommerce' ),
						Format::number( $amount, $persian ),
						$diff >= 0 ? '+' : '−',
						Format::number( abs( $diff ), $persian ),
						$code
					)
				);
				?>
			</span>
		</div>

		<?php if ( array() !== $dest ) : ?>
			<div class="vz-vm-sec">
				<small><?php esc_html_e( 'Destination card', 'varizet-woocommerce' ); ?></small>
				<div class="vz-vm-card">
					<span class="vz-bk" data-bank="<?php echo esc_attr( (string) ( $dest['bank'] ?? '' ) ); ?>"><?php echo esc_html( self::bank_letter( (string) ( $dest['bank'] ?? '' ) ) ); ?></span>
					<div class="vz-grow"><b><?php echo esc_html( LiveStatus::bank_name( (string) ( $dest['bank'] ?? '' ) ) ); ?> <span class="vz-mono" dir="ltr">…<?php echo esc_html( (string) ( $dest['last4'] ?? '' ) ); ?></span></b>
						<?php if ( ! empty( $dest['owner_name'] ) ) : ?>
							<div class="vz-xs vz-subtle"><?php echo esc_html( (string) $dest['owner_name'] ); ?></div>
						<?php endif; ?>
					</div>
				</div>
			</div>
		<?php endif; ?>

		<?php if ( '' !== $url ) : ?>
			<div class="vz-vm-sec">
				<small><?php esc_html_e( 'Payment link', 'varizet-woocommerce' ); ?></small>
				<div class="vz-vm-link"><code class="vz-mono" dir="ltr"><?php echo esc_html( preg_replace( '#^https?://#', '', $url ) ); ?></code>
					<button type="button" class="button button-small" data-vz-copy="<?php echo esc_attr( $url ); ?>"><?php esc_html_e( 'Copy', 'varizet-woocommerce' ); ?></button></div>
			</div>
		<?php endif; ?>

		<?php if ( isset( $match['score'] ) ) : ?>
			<div class="vz-vm-sec">
				<small><?php esc_html_e( 'Match confidence', 'varizet-woocommerce' ); ?></small>
				<div class="vz-score-row">
					<?php self::score_ring( (int) $match['score'], $n ); ?>
					<div class="vz-reasons">
						<?php if ( ! empty( $match['channel'] ) ) : ?>
							<span class="vz-reason"><?php echo esc_html( OrderSync::channel_label( (string) $match['channel'] ) ); ?></span>
						<?php endif; ?>
						<?php foreach ( array_slice( (array) ( $match['reasons'] ?? array() ), 0, 6 ) as $reason ) : ?>
							<span class="vz-reason <?php echo esc_attr( self::reason_negative( (string) $reason ) ? 'is-no' : '' ); ?>"><?php echo esc_html( self::reason_label( (string) $reason ) ); ?></span>
						<?php endforeach; ?>
					</div>
				</div>
				<?php if ( '' !== $payer ) : ?>
					<span class="vz-xs vz-subtle"><?php esc_html_e( 'Payer card', 'varizet-woocommerce' ); ?> <span class="vz-mono" dir="ltr">…<?php echo esc_html( $payer ); ?></span></span>
				<?php endif; ?>
			</div>
		<?php endif; ?>
		<?php if ( '' !== $claimed ) : ?>
			<p class="vz-xs vz-subtle"><?php esc_html_e( 'Card declared at checkout', 'varizet-woocommerce' ); ?> <span class="vz-mono" dir="ltr">…<?php echo esc_html( $claimed ); ?></span></p>
		<?php endif; ?>

		<div class="vz-vm-sec">
			<small><?php esc_html_e( 'Timeline', 'varizet-woocommerce' ); ?></small>
			<ol class="vz-timeline">
				<?php
				$items = $order->get_meta( OrderMeta::TIMELINE );
				foreach ( array_reverse( is_array( $items ) ? $items : array() ) as $item ) :
					?>
					<li class="vz-tl-<?php echo esc_attr( (string) $item['type'] ); ?>"><b><?php echo esc_html( (string) $item['text'] ); ?></b><small><?php echo esc_html( wp_date( 'Y/m/d H:i:s', (int) $item['t'] ) ); ?></small></li>
				<?php endforeach; ?>
			</ol>
		</div>

		<div class="vz-vm-actions">
			<a class="button vz-btn-block" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=varizet_sync_order&order_id=' . $order->get_id() ), 'varizet_sync_order_' . $order->get_id() ) ); ?>"><span class="dashicons dashicons-update"></span> <?php esc_html_e( 'Sync status', 'varizet-woocommerce' ); ?></a>
			<a class="button vz-btn-block" href="<?php echo esc_url( Settings::app_url() . '/orders/' . rawurlencode( $vz_id ) ); ?>" target="_blank" rel="noopener"><span class="dashicons dashicons-external"></span> <?php echo esc_html( in_array( $status, array( StatusMapper::NEEDS_REVIEW, StatusMapper::UNDERPAID ), true ) ? __( 'Review in the Varizet panel', 'varizet-woocommerce' ) : __( 'View in the Varizet panel', 'varizet-woocommerce' ) ); ?></a>
			<?php
			$synced = (int) $order->get_meta( OrderMeta::SYNCED_AT );
			if ( $synced > 0 ) :
				?>
				<span class="vz-xs vz-subtle vz-center"><?php echo esc_html( sprintf( /* translators: %s: time since. */ __( 'Last synced %s', 'varizet-woocommerce' ), SettingsPage::ago( $synced ) ) ); ?></span>
			<?php endif; ?>
		</div>
		</div>
		<?php
	}

	/**
	 * «همگام‌سازی وضعیت» for one order.
	 */
	public static function sync_order(): void {
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		check_admin_referer( 'varizet_sync_order_' . $order_id );
		if ( ! current_user_can( 'edit_shop_orders' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to edit orders.', 'varizet-woocommerce' ), 403 );
		}
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			wp_die( esc_html__( 'Order not found.', 'varizet-woocommerce' ), 404 );
		}
		try {
			$result = OrderSync::refresh( $order, 'admin' );
			$order  = wc_get_order( $order_id );
			$labels = OrderSync::status_labels();
			$status = (string) $order->get_meta( OrderMeta::STATUS );
			SettingsPage::flash(
				'success',
				sprintf(
					/* translators: 1: Varizet status, 2: result. */
					__( 'Synced with Varizet: %1$s (%2$s).', 'varizet-woocommerce' ),
					$labels[ $status ] ?? $status,
					OrderSync::APPLIED === $result ? __( 'updated', 'varizet-woocommerce' ) : __( 'no change', 'varizet-woocommerce' )
				)
			);
		} catch ( ApiException $e ) {
			Logger::warning( sprintf( 'Manual sync of order #%d failed (%s)', $order_id, $e->error_code ), array( 'component' => 'admin' ) + $e->context() );
			/* translators: %s: error. */
			SettingsPage::flash( 'error', sprintf( __( 'Sync failed: %s', 'varizet-woocommerce' ), $e->getMessage() ) );
		}
		wp_safe_redirect( $order->get_edit_order_url() );
		exit;
	}

	/**
	 * Shows the flash notice on order screens.
	 */
	public static function notice(): void {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, array( 'woocommerce_page_wc-orders', 'shop_order', 'edit-shop_order' ), true ) ) {
			return;
		}
		$flash = get_transient( 'varizet_flash_' . get_current_user_id() );
		if ( ! is_array( $flash ) ) {
			return;
		}
		delete_transient( 'varizet_flash_' . get_current_user_id() );
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( 'error' === $flash['type'] ? 'error' : 'success' ),
			esc_html( (string) $flash['message'] )
		);
	}

	/**
	 * Human label for a matching reason code (packages/matching scoring).
	 *
	 * @param string $code Reason code.
	 */
	public static function reason_label( string $code ): string {
		$labels = array(
			'exact_amount'               => __( 'Exact amount', 'varizet-woocommerce' ),
			'amount_within_tolerance'    => __( 'Amount within tolerance', 'varizet-woocommerce' ),
			'amount_mismatch'            => __( 'Amount differs', 'varizet-woocommerce' ),
			'destination_card_unique'    => __( 'Destination card', 'varizet-woocommerce' ),
			'destination_card_ambiguous' => __( 'Destination card ambiguous', 'varizet-woocommerce' ),
			'destination_card_mismatch'  => __( 'Different destination card', 'varizet-woocommerce' ),
			'within_time_window'         => __( 'Time window', 'varizet-woocommerce' ),
			'outside_window'             => __( 'Outside the time window', 'varizet-woocommerce' ),
			'grace_period'               => __( 'Grace period', 'varizet-woocommerce' ),
			'late_payment'               => __( 'Late payment', 'varizet-woocommerce' ),
			'customer_claim'             => __( 'Customer said they paid', 'varizet-woocommerce' ),
			'receipt_ocr_match'          => __( 'Receipt matches', 'varizet-woocommerce' ),
			'receipt_ocr_mismatch'       => __( 'Receipt differs', 'varizet-woocommerce' ),
			'close_runner_up'            => __( 'Another order is close', 'varizet-woocommerce' ),
			'multi_candidate'            => __( 'Several candidate orders', 'varizet-woocommerce' ),
			'payer_blacklisted'          => __( 'Blocked payer', 'varizet-woocommerce' ),
			'split_payment'              => __( 'Split payment', 'varizet-woocommerce' ),
		);
		return $labels[ $code ] ?? str_replace( '_', ' ', $code );
	}

	/**
	 * Whether a reason lowers confidence (rendered in amber).
	 *
	 * @param string $code Reason code.
	 */
	private static function reason_negative( string $code ): bool {
		return in_array( $code, array( 'amount_mismatch', 'destination_card_ambiguous', 'destination_card_mismatch', 'outside_window', 'late_payment', 'receipt_ocr_mismatch', 'close_runner_up', 'multi_candidate', 'payer_blacklisted' ), true );
	}

	/**
	 * Letter for the bank tile: the bank's own name, not the word «بانک».
	 *
	 * @param string $bank Bank code.
	 */
	private static function bank_letter( string $bank ): string {
		$name = trim( str_replace( array( 'بانک', 'Bank', 'Bank ' ), '', LiveStatus::bank_name( $bank ) ) );
		return '' === $name ? '•' : mb_substr( $name, 0, 1 );
	}

	/**
	 * Badge class per status.
	 *
	 * @param string $status Varizet status.
	 */
	public static function badge_class( string $status ): string {
		$map = array(
			StatusMapper::PAID               => 'vz-badge-success',
			StatusMapper::AWAITING           => 'vz-badge-warning',
			StatusMapper::NEEDS_REVIEW       => 'vz-badge-violet',
			StatusMapper::UNDERPAID          => 'vz-badge-danger',
			StatusMapper::REFUNDED           => 'vz-badge-info',
			StatusMapper::PARTIALLY_REFUNDED => 'vz-badge-info',
		);
		return $map[ $status ] ?? 'vz-badge-muted';
	}

	/**
	 * Score ring (SVG).
	 *
	 * @param int      $score Score 0-100.
	 * @param callable $n     Digit formatter.
	 */
	private static function score_ring( int $score, callable $n ): void {
		$c     = 2 * M_PI * 21;
		$class = $score >= 90 ? 'is-high' : ( $score >= 70 ? 'is-mid' : 'is-low' );
		printf(
			'<span class="vz-score %1$s"><svg viewBox="0 0 52 52" aria-hidden="true"><circle cx="26" cy="26" r="21" class="vz-score-track"/><circle cx="26" cy="26" r="21" class="vz-score-arc" stroke-dasharray="%2$s" stroke-dashoffset="%3$s" transform="rotate(-90 26 26)"/></svg><b>%4$s</b></span>',
			esc_attr( $class ),
			esc_attr( number_format( $c, 2, '.', '' ) ),
			esc_attr( number_format( $c * ( 1 - max( 0, min( 100, $score ) ) / 100 ), 2, '.', '' ) ),
			esc_html( $n( $score ) )
		);
	}
}
