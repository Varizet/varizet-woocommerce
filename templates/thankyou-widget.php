<?php
/**
 * Thank-you page widget. Override by copying to yourtheme/varizet/thankyou-widget.php is not
 * supported on purpose: the markup is driven by assets/js/thankyou.js.
 *
 * Variables: $model (array), $strings (array), $persian (bool).
 *
 * @package Varizet\WooCommerce
 */

use Varizet\WooCommerce\Support\Format;

defined( 'ABSPATH' ) || exit;

/**
 * Template scope (included from LiveStatus::render()).
 *
 * @var array<string, mixed>  $model
 * @var array<string, string> $strings
 * @var bool                  $persian
 */

$varizet_display = (string) $model['display'];
$varizet_pill    = array(
	'awaiting'  => array( $strings['awaiting'], 'warning' ),
	'reviewing' => array( $strings['reviewing'], 'violet' ),
	'paid'      => array( $strings['paid'], 'success' ),
	'underpaid' => array( $strings['underpaid'], 'danger' ),
	'expired'   => array( $strings['expired'], 'muted' ),
	'canceled'  => array( $strings['canceled'], 'muted' ),
);
$varizet_pill    = $varizet_pill[ $varizet_display ] ?? $varizet_pill['awaiting'];
$varizet_n       = static fn ( $n ) => $persian ? Format::to_persian_digits( (string) $n ) : (string) $n;
$varizet_paid    = $model['paid_amount'] > 0 ? (int) $model['paid_amount'] : (int) $model['unique'];
?>
<section class="vzw" id="vzw" data-st="<?php echo esc_attr( $varizet_display ); ?>" data-expires="<?php echo esc_attr( (string) $model['expires_at'] ); ?>" data-grace="<?php echo esc_attr( (string) $model['grace_until'] ); ?>" data-created="<?php echo esc_attr( (string) $model['created_at'] ); ?>" data-server-time="<?php echo esc_attr( (string) $model['server_time'] ); ?>" aria-live="polite">
	<header class="vzw-h">
		<img class="vzw-mark" src="<?php echo esc_url( VARIZET_WC_URL . 'assets/img/mark.svg' ); ?>" width="26" height="26" alt="" />
		<b><?php esc_html_e( 'Pay with Varizet', 'varizet-woocommerce' ); ?></b>
		<?php if ( $model['test'] ) : ?>
			<span class="vz-badge vz-badge-warning"><?php esc_html_e( 'Test mode', 'varizet-woocommerce' ); ?></span>
		<?php endif; ?>
		<span class="vzw-live" data-vz-live><span class="vz-pulse"></span><span data-vz-live-t><?php echo esc_html( $strings['live'] ); ?></span></span>
		<span class="vzw-grow"></span>
		<span class="vz-badge vz-badge-<?php echo esc_attr( $varizet_pill[1] ); ?> vz-dot" data-vz-pill><?php echo esc_html( $varizet_pill[0] ); ?></span>
	</header>

	<div class="vzw-b">
		<div class="vzw-pay">
			<div class="vzw-row">
				<div>
					<div class="vzw-label"><?php esc_html_e( 'Amount to pay', 'varizet-woocommerce' ); ?></div>
					<div class="vzw-big">
						<?php echo Format::unique_amount_html( (int) $model['unique'], (int) $model['digits'], $persian ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Format. ?>
						<span class="vzw-cur"><?php esc_html_e( 'rial', 'varizet-woocommerce' ); ?></span>
					</div>
					<div class="vzw-sub">
						<?php if ( 'IRR' !== $model['currency'] && '' !== $model['store_amount'] ) : ?>
							<?php
							printf(
								/* translators: 1: amount in the store currency, 2: Varizet order code. */
								esc_html__( 'Equals %1$s; order %2$s', 'varizet-woocommerce' ),
								wp_kses_post( wc_price( (float) $model['store_amount'], array( 'currency' => $model['currency'] ) ) ),
								'<span class="vz-mono">' . esc_html( (string) $model['code'] ) . '</span>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inline.
							);
							?>
						<?php else : ?>
							<?php
							printf(
								/* translators: %s: Varizet order code. */
								esc_html__( 'Order %s', 'varizet-woocommerce' ),
								'<span class="vz-mono">' . esc_html( (string) $model['code'] ) . '</span>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inline.
							);
							?>
						<?php endif; ?>
					</div>
				</div>
				<div class="vzw-timer-wrap">
					<div class="vzw-timer" role="timer" aria-label="<?php esc_attr_e( 'Time left', 'varizet-woocommerce' ); ?>">
						<svg viewBox="0 0 64 64" aria-hidden="true"><circle cx="32" cy="32" r="27" class="vzw-track"/><circle class="vzw-arc" data-vz-arc cx="32" cy="32" r="27" stroke-dasharray="169.65" stroke-dashoffset="0"/></svg>
						<span class="vzw-t" data-vz-timer>--:--</span>
					</div>
					<div class="vzw-sub" data-vz-timer-label><?php esc_html_e( 'Time left', 'varizet-woocommerce' ); ?></div>
				</div>
			</div>
			<div class="vzw-uniq-note"><span class="vzw-sw" aria-hidden="true"></span><span>
				<?php
				printf(
					/* translators: %s: number of highlighted digits. */
					esc_html__( 'The %s amber digits identify your payment; transfer exactly this amount, in rial.', 'varizet-woocommerce' ),
					esc_html( $varizet_n( (int) $model['digits'] ) )
				);
				?>
			</span></div>
			<button type="button" class="vz-btn vz-btn-soft vz-btn-block" data-vz-copy="<?php echo esc_attr( (string) $model['unique'] ); ?>">
				<span aria-hidden="true">⧉</span> <?php esc_html_e( 'Copy amount', 'varizet-woocommerce' ); ?>
			</button>
			<div class="vzw-card">
				<span class="vzw-bank" data-bank="<?php echo esc_attr( (string) $model['bank_code'] ); ?>"><?php echo esc_html( mb_substr( (string) $model['bank'], 0, 1 ) ); ?></span>
				<div class="vzw-card-b">
					<?php if ( '' !== $model['pan'] ) : ?>
						<div class="vzw-pan" dir="ltr"><?php echo esc_html( Format::pan( (string) $model['pan'] ) ); ?></div>
					<?php else : ?>
						<div class="vzw-pan" dir="ltr">•••• <?php echo esc_html( (string) $model['last4'] ); ?></div>
					<?php endif; ?>
					<small>
						<?php
						// No trim() with «،» here: trim() is byte-based and would cut UTF-8 characters.
						if ( '' === (string) $model['owner'] ) {
							echo esc_html( (string) $model['bank'] );
						} else {
							echo esc_html(
								sprintf(
									/* translators: 1: bank name, 2: card owner name. */
									__( '%1$s, %2$s', 'varizet-woocommerce' ),
									(string) $model['bank'],
									sprintf(
										/* translators: %s: card owner name. */
										__( 'owner: %s', 'varizet-woocommerce' ),
										(string) $model['owner']
									)
								)
							);
						}
						?>
					</small>
				</div>
				<?php if ( '' !== $model['pan'] ) : ?>
					<button type="button" class="vz-btn vz-btn-sm" data-vz-copy="<?php echo esc_attr( (string) $model['pan'] ); ?>" aria-label="<?php esc_attr_e( 'Copy card number', 'varizet-woocommerce' ); ?>"><span data-vz-copy-t><?php echo esc_html( $strings['copy'] ); ?></span></button>
				<?php endif; ?>
			</div>
			<?php if ( '' === $model['pan'] && '' !== $model['payment_url'] ) : ?>
				<a class="vz-btn vz-btn-primary vz-btn-block" href="<?php echo esc_url( (string) $model['payment_url'] ); ?>"><?php esc_html_e( 'Open the payment page', 'varizet-woocommerce' ); ?></a>
			<?php endif; ?>
		</div>

		<div class="vzw-livebox">
			<div class="vzw-label"><?php esc_html_e( 'Payment status', 'varizet-woocommerce' ); ?></div>
			<ol class="vzw-steps" data-vz-steps>
				<li class="vzw-step" data-step="awaiting_deposit"><span class="vzw-d"><span class="vzw-n"><?php echo esc_html( $varizet_n( 1 ) ); ?></span><span class="vzw-spin"></span><span class="vzw-ok">✓</span></span><div><b><?php esc_html_e( 'Awaiting transfer', 'varizet-woocommerce' ); ?></b><small><?php esc_html_e( 'Transfer the amount above to the card from your bank app.', 'varizet-woocommerce' ); ?></small></div></li>
				<li class="vzw-step" data-step="deposit_detected"><span class="vzw-d"><span class="vzw-n"><?php echo esc_html( $varizet_n( 2 ) ); ?></span><span class="vzw-spin"></span><span class="vzw-ok">✓</span></span><div><b><?php esc_html_e( 'Transfer received', 'varizet-woocommerce' ); ?></b><small data-vz-s2><?php esc_html_e( 'Usually visible a few seconds after you transfer.', 'varizet-woocommerce' ); ?></small></div></li>
				<li class="vzw-step" data-step="confirmed"><span class="vzw-d"><span class="vzw-n"><?php echo esc_html( $varizet_n( 3 ) ); ?></span><span class="vzw-spin"></span><span class="vzw-ok">✓</span></span><div><b><?php esc_html_e( 'Payment confirmed', 'varizet-woocommerce' ); ?></b><small><?php esc_html_e( 'Your order is confirmed and prepared automatically.', 'varizet-woocommerce' ); ?></small></div></li>
			</ol>
			<div class="vzw-info"><?php esc_html_e( 'Keep this page open; no need to refresh or send a receipt.', 'varizet-woocommerce' ); ?></div>
		</div>
	</div>

	<div class="vzw-done" data-vz-done>
		<span class="vzw-ring" aria-hidden="true">✓</span>
		<h3><?php esc_html_e( 'Payment confirmed', 'varizet-woocommerce' ); ?></h3>
		<p>
			<?php
			printf(
				/* translators: 1: order number, 2: store name. */
				esc_html__( 'Order #%1$s is confirmed and %2$s is preparing it.', 'varizet-woocommerce' ),
				esc_html( $varizet_n( $model['order']->get_order_number() ) ),
				esc_html( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) )
			);
			?>
		</p>
		<div class="vzw-sum">
			<div><?php esc_html_e( 'Amount paid', 'varizet-woocommerce' ); ?><b data-vz-paid-amount><?php echo Format::unique_amount_html( $varizet_paid, (int) $model['digits'], $persian ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Format. ?></b></div>
			<div data-vz-payer-box <?php echo '' === $model['payer_last4'] ? 'hidden' : ''; ?>><?php esc_html_e( 'From card', 'varizet-woocommerce' ); ?><b class="vz-mono" dir="ltr" data-vz-payer><?php echo esc_html( '…' . $model['payer_last4'] ); ?></b></div>
			<div><?php esc_html_e( 'Order status', 'varizet-woocommerce' ); ?><b data-vz-wc-status><?php echo esc_html( wc_get_order_status_name( $model['order']->get_status() ) ); ?></b></div>
		</div>
	</div>

	<div class="vzw-closed" data-vz-closed>
		<h3 data-vz-closed-title><?php echo esc_html( 'canceled' === $varizet_display ? $strings['canceled'] : $strings['expired'] ); ?></h3>
		<p><?php esc_html_e( 'The payment time for this order has ended. If you already transferred the money, it will still be matched; otherwise you can try again.', 'varizet-woocommerce' ); ?></p>
		<a class="vz-btn vz-btn-primary" data-vz-pay-again href="<?php echo esc_url( (string) $model['pay_url'] ); ?>" <?php echo '' === $model['pay_url'] ? 'hidden' : ''; ?>><?php esc_html_e( 'Pay again', 'varizet-woocommerce' ); ?></a>
	</div>

	<footer class="vzw-f">
		<span class="vzw-shield" aria-hidden="true">✓</span>
		<span class="vzw-grow">
			<?php
			echo esc_html(
				$model['verified']
					? __( 'Varizet has verified that this card belongs to the store.', 'varizet-woocommerce' )
					: __( 'Payments are matched automatically by Varizet.', 'varizet-woocommerce' )
			);
			?>
		</span>
		<?php if ( '' !== $model['payment_url'] ) : ?>
			<a href="<?php echo esc_url( (string) $model['payment_url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open the full payment page', 'varizet-woocommerce' ); ?></a>
		<?php endif; ?>
	</footer>
</section>
