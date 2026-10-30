<?php
/**
 * WooCommerce payment gateway «کارت‌به‌کارت با تایید خودکار — واریزت».
 *
 * @package Varizet\WooCommerce
 */

namespace Varizet\WooCommerce\Gateway;

use Varizet\WooCommerce\Admin\SettingsPage;
use Varizet\WooCommerce\Api\ApiException;
use Varizet\WooCommerce\Connect\Credentials;
use Varizet\WooCommerce\Order\OrderMeta;
use Varizet\WooCommerce\Settings;
use Varizet\WooCommerce\Support\Amount;
use Varizet\WooCommerce\Support\Format;
use Varizet\WooCommerce\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Classic checkout gateway (Blocks use BlocksSupport with the same settings and server code).
 */
class Gateway extends \WC_Payment_Gateway {

	public const ID = 'varizet';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = self::ID;
		$this->has_fields         = true;
		$this->method_title       = __( 'Card-to-card with automatic confirmation — Varizet', 'varizet-woocommerce' );
		$this->method_description = __( 'Customers transfer the exact unique amount from their own bank app; Varizet detects the deposit and confirms the order automatically. Destination cards, matching rules and notifications are managed in the Varizet panel.', 'varizet-woocommerce' );
		$this->supports           = array( 'products' );
		$this->icon               = (string) apply_filters( 'varizet_gateway_icon', VARIZET_WC_URL . 'assets/img/mark.svg' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = (string) $this->get_option( 'title' );
		$this->description = (string) $this->get_option( 'description' );
		$this->enabled     = (string) $this->get_option( 'enabled' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Settings fields (docs/10 §1.5).
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'section_general'   => array(
				'title' => __( 'General', 'varizet-woocommerce' ),
				'type'  => 'title',
			),
			'enabled'           => array(
				'title'   => __( 'Enable', 'varizet-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Varizet card-to-card payments', 'varizet-woocommerce' ),
				'default' => 'no',
			),
			'title'             => array(
				'title'       => __( 'Title at checkout', 'varizet-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'What customers see in the list of payment methods.', 'varizet-woocommerce' ),
				'default'     => __( 'Card-to-card with automatic confirmation', 'varizet-woocommerce' ),
				'desc_tip'    => false,
			),
			'description'       => array(
				'title'       => __( 'Description', 'varizet-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Shown under the payment method; short and reassuring.', 'varizet-woocommerce' ),
				'default'     => __( 'Transfer the amount from your own bank app to the store\'s card; your payment is confirmed automatically within seconds.', 'varizet-woocommerce' ),
			),
			'environment'       => array(
				'title'       => __( 'Environment', 'varizet-woocommerce' ),
				'type'        => 'vz_segmented',
				'options'     => array(
					'live' => __( 'Live', 'varizet-woocommerce' ),
					'test' => __( 'Test (Sandbox)', 'varizet-woocommerce' ),
				),
				'default'     => 'live',
				'description' => __( 'Each environment uses its own API key and webhook secret from the connection page.', 'varizet-woocommerce' ),
			),
			'section_amount'    => array(
				'title' => __( 'Amount and payment', 'varizet-woocommerce' ),
				'type'  => 'title',
			),
			'unique_mode'       => array(
				'title'       => __( 'Unique amount mode', 'varizet-woocommerce' ),
				'type'        => 'vz_radio_cards',
				'options'     => array(
					'increase' => array(
						'label' => __( 'Increase', 'varizet-woocommerce' ),
						'hint'  => __( 'A few rial are added to the order amount.', 'varizet-woocommerce' ),
						'from'  => 8500000,
						'to'    => 8500430,
					),
					'decrease' => array(
						'label' => __( 'Decrease (unique discount)', 'varizet-woocommerce' ),
						'hint'  => __( 'A few rial are taken off; the customer never pays more.', 'varizet-woocommerce' ),
						'from'  => 8500000,
						'to'    => 8499570,
					),
				),
				'default'     => 'increase',
				'description' => __( 'The last digits of the amount identify each order\'s payment.', 'varizet-woocommerce' ),
			),
			'expires_in'        => array(
				'title'       => __( 'Payment window', 'varizet-woocommerce' ),
				'type'        => 'select',
				'class'       => 'wc-enhanced-select',
				'options'     => array(
					'900'   => __( '15 minutes', 'varizet-woocommerce' ),
					'1800'  => __( '30 minutes', 'varizet-woocommerce' ),
					'3600'  => __( '1 hour', 'varizet-woocommerce' ),
					'7200'  => __( '2 hours', 'varizet-woocommerce' ),
					'86400' => __( '24 hours', 'varizet-woocommerce' ),
				),
				'default'     => '1800',
				'description' => __( 'After the window, a grace period still accepts late transfers so the order is confirmed instead of rejected.', 'varizet-woocommerce' ),
			),
			'payer_last4'       => array(
				'title'       => __( 'Last 4 digits of the payer\'s card', 'varizet-woocommerce' ),
				'type'        => 'vz_segmented',
				'options'     => array(
					'off'      => __( 'Off', 'varizet-woocommerce' ),
					'optional' => __( 'Optional', 'varizet-woocommerce' ),
					'required' => __( 'Required', 'varizet-woocommerce' ),
				),
				'default'     => 'optional',
				'description' => __( 'Only the last 4 digits, for more precise matching; the full card number is never asked.', 'varizet-woocommerce' ),
			),
			'min_amount'        => array(
				'title'             => __( 'Minimum cart total', 'varizet-woocommerce' ),
				'type'              => 'price',
				'description'       => __( 'In the store currency. Leave empty for no limit (Varizet\'s own minimum is 10,000 rial).', 'varizet-woocommerce' ),
				'default'           => '',
				'custom_attributes' => array( 'inputmode' => 'numeric' ),
			),
			'max_amount'        => array(
				'title'       => __( 'Maximum cart total', 'varizet-woocommerce' ),
				'type'        => 'price',
				'description' => __( 'In the store currency. Leave empty for no limit (mind your cards\' daily transfer caps).', 'varizet-woocommerce' ),
				'default'     => '',
			),
			'section_order'     => array(
				'title' => __( 'Order and stock', 'varizet-woocommerce' ),
				'type'  => 'title',
			),
			'paid_status'       => array(
				'title'       => __( 'Order status after payment', 'varizet-woocommerce' ),
				'type'        => 'select',
				'class'       => 'wc-enhanced-select',
				'options'     => array(
					'processing' => __( 'Processing', 'varizet-woocommerce' ),
					'completed'  => __( 'Completed', 'varizet-woocommerce' ),
					'auto'       => __( 'WooCommerce default (completed for virtual downloadable orders)', 'varizet-woocommerce' ),
				),
				'default'     => 'processing',
				'description' => __( '"Completed" is recommended for virtual and downloadable products.', 'varizet-woocommerce' ),
			),
			'restock_on_expire' => array(
				'title'   => __( 'Restore stock after expiry', 'varizet-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Cancel the order and return items to stock when the grace period ends', 'varizet-woocommerce' ),
				'default' => 'yes',
			),
			'order_prefix'      => array(
				'title'             => __( 'Order id prefix', 'varizet-woocommerce' ),
				'type'              => 'text',
				'description'       => __( 'Optional prefix for the order id shown in the Varizet panel, e.g. KN-. Useful when several sites share one Varizet store.', 'varizet-woocommerce' ),
				'default'           => '',
				'custom_attributes' => array(
					'maxlength' => 16,
					'pattern'   => '[A-Za-z0-9_\-#.]*',
					'dir'       => 'ltr',
				),
			),
			'section_display'   => array(
				'title' => __( 'Notifications and display', 'varizet-woocommerce' ),
				'type'  => 'title',
			),
			'sms_link'          => array(
				'title'       => __( 'Send the payment link by SMS', 'varizet-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Text the pay.varizet.ir link to the customer\'s mobile', 'varizet-woocommerce' ),
				'default'     => 'no',
				'description' => __( 'Sent by Varizet\'s SMS service; if the customer closes the page, they still have the link.', 'varizet-woocommerce' ),
			),
			'display_mode'      => array(
				'title'   => __( 'Display mode', 'varizet-woocommerce' ),
				'type'    => 'vz_radio_cards',
				'options' => array(
					'redirect' => array(
						'label' => __( 'Redirect to pay.varizet.ir', 'varizet-woocommerce' ),
						'hint'  => __( 'Varizet\'s full payment page with your store name and logo; the most features.', 'varizet-woocommerce' ),
					),
					'inline'   => array(
						'label' => __( 'On-site payment', 'varizet-woocommerce' ),
						'hint'  => __( 'A payment widget on the WooCommerce thank-you page; the customer never leaves your site.', 'varizet-woocommerce' ),
					),
				),
				'default' => 'inline',
			),
			'section_advanced'  => array(
				'title' => __( 'Advanced', 'varizet-woocommerce' ),
				'type'  => 'title',
			),
			'debug'             => array(
				'title'       => __( 'Debug log', 'varizet-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Log request and webhook details', 'varizet-woocommerce' ),
				'default'     => 'no',
				'description' => __( 'Visible under Varizet → System status. Enable only while troubleshooting; card data is never logged.', 'varizet-woocommerce' ),
			),
			'delete_data'       => array(
				'title'       => __( 'Remove data on uninstall', 'varizet-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Delete settings and Varizet order metadata when the plugin is deleted', 'varizet-woocommerce' ),
				'default'     => 'no',
				'description' => __( 'Keys are always removed. Order notes are kept.', 'varizet-woocommerce' ),
			),
		);
	}

	/**
	 * Available at checkout only when connected, in a supported currency and within limits.
	 */
	public function is_available() {
		if ( ! parent::is_available() ) {
			return false;
		}
		if ( ! Credentials::is_connected( Settings::environment() ) ) {
			return false;
		}
		$currency = get_woocommerce_currency();
		if ( ! Amount::is_supported( $currency ) ) {
			return false;
		}
		$total = $this->get_order_total();
		if ( $total > 0 ) {
			try {
				if ( Amount::to_rial( $total, $currency ) < Amount::MIN_ORDER_RIAL ) {
					return false;
				}
			} catch ( \InvalidArgumentException $e ) {
				return false;
			}
			$min = Settings::min_amount();
			$max = Settings::max_amount();
			if ( ( $min > 0 && $total < $min ) || ( $max > 0 && $total > $max ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Classic checkout fields: description, three steps, trust line and the optional last-4 input.
	 */
	public function payment_fields() {
		$mode = Settings::payer_last4();
		echo '<div class="vz-pm-box">';
		if ( $this->description ) {
			echo '<p class="vz-pm-desc">' . wp_kses_post( wptexturize( $this->description ) ) . '</p>';
		}
		if ( 'test' === Settings::environment() ) {
			echo '<p class="vz-pm-test">' . esc_html__( 'Test mode: no real money is transferred.', 'varizet-woocommerce' ) . '</p>';
		}
		echo '<ol class="vz-mini-steps">';
		echo '<li><i>' . esc_html( $this->digit( 1 ) ) . '</i>' . esc_html__( 'Copy the amount and card', 'varizet-woocommerce' ) . '</li>';
		echo '<li><i>' . esc_html( $this->digit( 2 ) ) . '</i>' . esc_html__( 'Transfer from your own bank app', 'varizet-woocommerce' ) . '</li>';
		echo '<li><i>' . esc_html( $this->digit( 3 ) ) . '</i>' . esc_html__( 'Automatic confirmation in seconds', 'varizet-woocommerce' ) . '</li>';
		echo '</ol>';
		echo '<p class="vz-trust"><strong>' . esc_html__( 'No redirect to a payment gateway.', 'varizet-woocommerce' ) . '</strong> ' . esc_html__( 'The exact amount (in rial) and the card number are shown after you place the order. No receipt upload is needed.', 'varizet-woocommerce' ) . '</p>';
		if ( 'off' !== $mode ) {
			$label = 'required' === $mode
				? __( 'Last 4 digits of your card', 'varizet-woocommerce' )
				: __( 'Last 4 digits of your card (optional, for faster confirmation)', 'varizet-woocommerce' );
			printf(
				'<p class="form-row form-row-wide vz-last4-row"><label for="varizet_payer_last4">%1$s%2$s</label><input id="varizet_payer_last4" name="varizet_payer_last4" type="text" inputmode="numeric" autocomplete="off" maxlength="4" pattern="[0-9۰-۹]{4}" dir="ltr" class="input-text vz-last4" placeholder="••••" %3$s /></p>',
				esc_html( $label ),
				'required' === $mode ? ' <abbr class="required" title="' . esc_attr__( 'required', 'varizet-woocommerce' ) . '">*</abbr>' : '',
				'required' === $mode ? 'required' : ''
			);
		}
		echo '</div>';
	}

	/**
	 * Validates the last-4 field (classic checkout; Blocks validate in JS and again here).
	 */
	public function validate_fields() {
		try {
			self::payer_last4_from_request();
		} catch ( \InvalidArgumentException $e ) {
			wc_add_notice( $e->getMessage(), 'error' );
			return false;
		}
		return true;
	}

	/**
	 * Creates the Varizet order and sends the customer to pay.
	 *
	 * @param int $order_id Order id.
	 * @return array{result:string, redirect?:string}
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return array( 'result' => 'failure' );
		}

		try {
			$last4 = self::payer_last4_from_request();
			$vz    = PaymentStarter::start( $order, $last4 );
		} catch ( \InvalidArgumentException $e ) {
			wc_add_notice( $e->getMessage(), 'error' );
			return array( 'result' => 'failure' );
		} catch ( ApiException $e ) {
			wc_add_notice( self::customer_error( $e ), 'error' );
			$order->add_order_note(
				sprintf(
					/* translators: 1: error code, 2: API request id. */
					__( 'Varizet: the payment request could not be created (%1$s, request %2$s).', 'varizet-woocommerce' ),
					$e->error_code,
					(string) $e->request_id
				)
			);
			return array( 'result' => 'failure' );
		}

		if ( ! $order->has_status( 'pending' ) ) {
			$order->update_status( 'pending' );
		}
		$order->add_order_note(
			sprintf(
				/* translators: 1: unique amount in rial, 2: Varizet order code. */
				__( 'Varizet: awaiting a card-to-card transfer of %1$s rial (order %2$s).', 'varizet-woocommerce' ),
				Format::number( (int) $vz['unique_amount'], str_starts_with( determine_locale(), 'fa' ) ),
				(string) $vz['code']
			)
		);
		// Reserve stock like other offline methods; WooCommerce restores it on cancellation.
		wc_maybe_reduce_stock_levels( $order->get_id() );

		if ( WC()->cart ) {
			WC()->cart->empty_cart();
		}

		$redirect = 'redirect' === Settings::display_mode() ? (string) $vz['payment_url'] : $this->get_return_url( $order );
		return array(
			'result'   => 'success',
			'redirect' => $redirect,
		);
	}

	/**
	 * Admin options: WooCommerce form on the left, health and preview cards on the right.
	 */
	public function admin_options() {
		SettingsPage::render_gateway_settings( $this );
	}

	/**
	 * Exposes the generated rows to the settings page renderer.
	 */
	public function settings_rows_html(): string {
		return (string) $this->generate_settings_html( $this->get_form_fields(), false );
	}

	/**
	 * Segmented control (radio buttons styled as a WP button group).
	 *
	 * @param string $key  Field key.
	 * @param array  $data Field data.
	 */
	public function generate_vz_segmented_html( $key, $data ) {
		$data  = $this->field_defaults( $data );
		$field = $this->get_field_key( $key );
		$value = (string) $this->get_option( $key );
		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><label><?php echo esc_html( $data['title'] ); ?></label></th>
			<td class="forminp">
				<fieldset class="vz-seg" id="<?php echo esc_attr( $field ); ?>">
					<legend class="screen-reader-text"><span><?php echo esc_html( $data['title'] ); ?></span></legend>
					<?php foreach ( (array) $data['options'] as $opt => $label ) : ?>
						<label><input type="radio" name="<?php echo esc_attr( $field ); ?>" value="<?php echo esc_attr( (string) $opt ); ?>" <?php checked( $value, (string) $opt ); ?> /><span><?php echo esc_html( (string) $label ); ?></span></label>
					<?php endforeach; ?>
				</fieldset>
				<?php echo $this->get_description_html( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by WooCommerce. ?>
			</td>
		</tr>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Radio cards with a title, a hint and an optional amount example.
	 *
	 * @param string $key  Field key.
	 * @param array  $data Field data.
	 */
	public function generate_vz_radio_cards_html( $key, $data ) {
		$data    = $this->field_defaults( $data );
		$field   = $this->get_field_key( $key );
		$value   = (string) $this->get_option( $key );
		$persian = str_starts_with( determine_locale(), 'fa' );
		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><label><?php echo esc_html( $data['title'] ); ?></label></th>
			<td class="forminp">
				<fieldset class="vz-rc-grid" id="<?php echo esc_attr( $field ); ?>">
					<legend class="screen-reader-text"><span><?php echo esc_html( $data['title'] ); ?></span></legend>
					<?php foreach ( (array) $data['options'] as $opt => $card ) : ?>
						<label class="vz-rc">
							<input type="radio" name="<?php echo esc_attr( $field ); ?>" value="<?php echo esc_attr( (string) $opt ); ?>" <?php checked( $value, (string) $opt ); ?> />
							<span class="vz-rc-t"><?php echo esc_html( $card['label'] ); ?></span>
							<small><?php echo esc_html( $card['hint'] ); ?></small>
							<?php if ( isset( $card['from'], $card['to'] ) ) : ?>
								<span class="vz-rc-ex">
									<span dir="ltr"><?php echo esc_html( Format::number( (int) $card['from'], $persian ) ); ?></span>
									<span aria-hidden="true">←</span>
									<?php echo Format::unique_amount_html( (int) $card['to'], 3, $persian ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Format. ?>
								</span>
							<?php endif; ?>
						</label>
					<?php endforeach; ?>
				</fieldset>
				<?php echo $this->get_description_html( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by WooCommerce. ?>
			</td>
		</tr>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * WooCommerce's defaults for custom field types (get_description_html() reads desc_tip).
	 *
	 * @param array $data Field data.
	 * @return array
	 */
	private function field_defaults( $data ): array {
		return wp_parse_args(
			(array) $data,
			array(
				'title'       => '',
				'description' => '',
				'desc_tip'    => false,
				'options'     => array(),
			)
		);
	}

	/**
	 * Validates a segmented field against its options.
	 *
	 * @param string      $key   Field key.
	 * @param string|null $value Posted value.
	 */
	public function validate_vz_segmented_field( $key, $value ) {
		$options = array_keys( (array) ( $this->form_fields[ $key ]['options'] ?? array() ) );
		$value   = is_string( $value ) ? sanitize_key( $value ) : '';
		return in_array( $value, $options, true ) ? $value : (string) ( $this->form_fields[ $key ]['default'] ?? '' );
	}

	/**
	 * Validates a radio-cards field against its options.
	 *
	 * @param string      $key   Field key.
	 * @param string|null $value Posted value.
	 */
	public function validate_vz_radio_cards_field( $key, $value ) {
		return $this->validate_vz_segmented_field( $key, $value );
	}

	/**
	 * Saves settings and clears the settings cache.
	 */
	public function process_admin_options() {
		$saved = parent::process_admin_options();
		Settings::flush();
		Logger::info( 'Gateway settings saved', array( 'component' => 'admin' ) );
		return $saved;
	}

	/**
	 * Reads and validates `varizet_payer_last4` from the checkout request.
	 *
	 * @return string|null Four digits or null.
	 * @throws \InvalidArgumentException When required and missing, or malformed.
	 */
	public static function payer_last4_from_request(): ?string {
		$mode = Settings::payer_last4();
		if ( 'off' === $mode ) {
			return null;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified the checkout nonce (classic) or Store API nonce (Blocks) before process_payment.
		$raw   = isset( $_POST['varizet_payer_last4'] ) ? sanitize_text_field( wp_unslash( $_POST['varizet_payer_last4'] ) ) : '';
		$value = preg_replace( '/\D+/', '', Format::to_latin_digits( (string) $raw ) );
		if ( '' === $value ) {
			if ( 'required' === $mode ) {
				throw new \InvalidArgumentException( esc_html__( 'Please enter the last 4 digits of the card you will pay from.', 'varizet-woocommerce' ) );
			}
			return null;
		}
		if ( ! preg_match( '/^\d{4}$/', (string) $value ) ) {
			throw new \InvalidArgumentException( esc_html__( 'The last 4 digits of the card must be exactly 4 digits.', 'varizet-woocommerce' ) );
		}
		return (string) $value;
	}

	/**
	 * Customer-facing message for an API error (never internals).
	 *
	 * @param ApiException $e Error.
	 */
	private static function customer_error( ApiException $e ): string {
		switch ( $e->error_code ) {
			case 'unique_amount_exhausted':
				return __( 'Card-to-card payment is busy right now. Please try again in a few minutes or choose another payment method.', 'varizet-woocommerce' );
			case 'amount_too_small':
				return __( 'The order total is below the minimum for card-to-card payment.', 'varizet-woocommerce' );
			case 'plan_limit_reached':
			case 'not_connected':
			case 'invalid_api_key':
			case 'key_revoked':
				return __( 'This payment method is not available right now. Please choose another payment method.', 'varizet-woocommerce' );
		}
		return __( 'We could not start the card-to-card payment. Please try again; if it keeps failing, choose another payment method.', 'varizet-woocommerce' );
	}

	/**
	 * Localised digit.
	 *
	 * @param int $n Digit.
	 */
	private function digit( int $n ): string {
		return str_starts_with( determine_locale(), 'fa' ) ? Format::to_persian_digits( $n ) : (string) $n;
	}
}
