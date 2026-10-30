/**
 * Varizet payment method for the WooCommerce Checkout block.
 * Plain JS on wp.element: no build step. Strings and settings come from PHP
 * (BlocksSupport::get_payment_method_data) already translated.
 */
( function () {
	'use strict';

	var registry = window.wc && window.wc.wcBlocksRegistry;
	var settings = window.wc && window.wc.wcSettings;
	var element = window.wp && window.wp.element;
	if ( ! registry || ! settings || ! element ) {
		return;
	}

	var el = element.createElement;
	var useState = element.useState;
	var useEffect = element.useEffect;
	var decode = window.wp.htmlEntities ? window.wp.htmlEntities.decodeEntities : function ( s ) {
		return s;
	};
	var data = settings.getSetting( 'varizet_data', {} );
	var i18n = data.i18n || {};
	var FA = [ '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' ];
	var title = decode( data.title || i18n.brand || 'Varizet' );

	function digit( n ) {
		return data.persian ? FA[ n ] : String( n );
	}

	function toLatin( value ) {
		return String( value || '' )
			.replace( /[۰-۹]/g, function ( d ) {
				return String( d.charCodeAt( 0 ) - 0x06f0 );
			} )
			.replace( /[٠-٩]/g, function ( d ) {
				return String( d.charCodeAt( 0 ) - 0x0660 );
			} )
			.replace( /\D+/g, '' );
	}

	function Label( props ) {
		var PaymentMethodLabel = props.components && props.components.PaymentMethodLabel;
		return el(
			'span',
			{ className: 'vz-blk-label' },
			PaymentMethodLabel ? el( PaymentMethodLabel, { text: title } ) : title,
			el( 'img', { className: 'vz-blk-mark', src: data.icon, alt: '', width: 18, height: 18 } )
		);
	}

	function Content( props ) {
		var state = useState( '' );
		var last4 = state[ 0 ];
		var setLast4 = state[ 1 ];
		var onPaymentSetup = props.eventRegistration && ( props.eventRegistration.onPaymentSetup || props.eventRegistration.onPaymentProcessing );
		var types = props.emitResponse && props.emitResponse.responseTypes;

		useEffect(
			function () {
				if ( ! onPaymentSetup ) {
					return undefined;
				}
				return onPaymentSetup( function () {
					var value = toLatin( last4 );
					if ( data.last4 === 'required' && value.length === 0 ) {
						return { type: types.ERROR, message: i18n.last4Required };
					}
					if ( value.length > 0 && ! /^\d{4}$/.test( value ) ) {
						return { type: types.ERROR, message: i18n.last4Invalid };
					}
					return {
						type: types.SUCCESS,
						meta: { paymentMethodData: { varizet_payer_last4: value } },
					};
				} );
			},
			[ onPaymentSetup, last4 ]
		);

		var steps = ( i18n.steps || [] ).map( function ( text, i ) {
			return el( 'li', { key: i }, el( 'i', null, digit( i + 1 ) ), text );
		} );

		var children = [
			data.description ? el( 'p', { key: 'd', className: 'vz-pm-desc' }, decode( data.description ) ) : null,
			data.testMode ? el( 'p', { key: 't', className: 'vz-pm-test' }, i18n.testMode ) : null,
			el( 'ol', { key: 's', className: 'vz-mini-steps' }, steps ),
			el( 'p', { key: 'tr', className: 'vz-trust' }, el( 'strong', null, i18n.trustTitle ), ' ', i18n.trustBody ),
		];
		if ( data.last4 && data.last4 !== 'off' ) {
			children.push(
				el(
					'div',
					{ key: 'l4', className: 'vz-last4-row wc-block-components-text-input is-active' },
					el( 'input', {
						id: 'varizet-payer-last4',
						type: 'text',
						inputMode: 'numeric',
						autoComplete: 'off',
						maxLength: 4,
						dir: 'ltr',
						className: 'vz-last4',
						value: last4,
						required: data.last4 === 'required',
						'aria-label': i18n.last4Label,
						onChange: function ( e ) {
							setLast4( toLatin( e.target.value ).slice( 0, 4 ) );
						},
					} ),
					el( 'label', { htmlFor: 'varizet-payer-last4' }, i18n.last4Label )
				)
			);
		}
		return el( 'div', { className: 'vz-pm-box vz-pm-box--blocks' }, children );
	}

	function canMakePayment( args ) {
		if ( ! data.supported || ! data.factor ) {
			return false;
		}
		var totals = args && args.cartTotals;
		if ( ! totals ) {
			return true;
		}
		var minor = parseInt( totals.currency_minor_unit || 0, 10 );
		var major = parseInt( totals.total_price || '0', 10 ) / Math.pow( 10, minor );
		if ( major * data.factor < data.minRial ) {
			return false;
		}
		if ( data.minAmount > 0 && major < data.minAmount ) {
			return false;
		}
		if ( data.maxAmount > 0 && major > data.maxAmount ) {
			return false;
		}
		return true;
	}

	registry.registerPaymentMethod( {
		name: 'varizet',
		label: el( Label, null ),
		content: el( Content, null ),
		edit: el( Content, null ),
		canMakePayment: canMakePayment,
		ariaLabel: title,
		supports: { features: data.supports || [ 'products' ] },
	} );
} )();
