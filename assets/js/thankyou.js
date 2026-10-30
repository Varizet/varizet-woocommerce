/**
 * Varizet live payment widget (thank-you page). Plain JS, no dependencies.
 *
 * Polls the same-origin proxy `/wp-json/varizet/v1/orders/{id}/status?key=…` every 4 s (slower
 * when the tab is hidden or after errors), runs the countdown from the server clock and switches
 * to «پرداخت تایید شد» without a reload.
 */
( function () {
	'use strict';

	var cfg = window.varizetThankYou || {};
	var root = document.getElementById( 'vzw' );
	if ( ! root || ! cfg.endpoint ) {
		return;
	}

	var S = cfg.strings || {};
	var FA = [ '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' ];
	var PILL = {
		awaiting: [ S.awaiting, 'warning' ],
		reviewing: [ S.reviewing, 'violet' ],
		paid: [ S.paid, 'success' ],
		underpaid: [ S.underpaid, 'danger' ],
		expired: [ S.expired, 'muted' ],
		canceled: [ S.canceled, 'muted' ],
	};
	var STEPS = { awaiting_deposit: 0, deposit_detected: 1, matching: 1, confirmed: 2 };
	var $ = function ( sel ) {
		return root.querySelector( sel );
	};

	function digits( text ) {
		text = String( text );
		return cfg.persian ? text.replace( /\d/g, function ( d ) {
			return FA[ +d ];
		} ) : text;
	}

	function parseTime( iso ) {
		var t = Date.parse( iso || '' );
		return isNaN( t ) ? null : t;
	}

	// Server clock offset so the countdown does not trust the shopper's clock.
	var serverNow = parseTime( root.getAttribute( 'data-server-time' ) );
	var offset = serverNow ? serverNow - Date.now() : 0;
	var expires = parseTime( root.getAttribute( 'data-expires' ) );
	var grace = parseTime( root.getAttribute( 'data-grace' ) );
	var created = parseTime( root.getAttribute( 'data-created' ) );
	var total = expires && created ? Math.max( 1, expires - created ) : 30 * 60 * 1000;
	var state = root.getAttribute( 'data-st' ) || 'awaiting';
	var timerEl = $( '[data-vz-timer]' );
	var timerLabel = $( '[data-vz-timer-label]' );
	var arc = $( '[data-vz-arc]' );
	var CIRC = 169.65;

	function tick() {
		if ( ! expires || state !== 'awaiting' ) {
			return;
		}
		var now = Date.now() + offset;
		var left = expires - now;
		if ( left <= 0 && grace && grace > now ) {
			// Still payable in the grace period (docs/04 §6).
			timerEl.textContent = digits( fmt( grace - now ) );
			timerLabel.textContent = S.grace;
			arc.style.strokeDashoffset = String( CIRC );
			root.classList.add( 'is-grace' );
			return;
		}
		left = Math.max( 0, left );
		timerEl.textContent = digits( fmt( left ) );
		arc.style.strokeDashoffset = String( CIRC * ( 1 - left / total ) );
	}

	function fmt( ms ) {
		var s = Math.floor( ms / 1000 );
		var h = Math.floor( s / 3600 );
		var m = Math.floor( ( s % 3600 ) / 60 );
		var sec = s % 60;
		var mm = ( m < 10 ? '0' : '' ) + m;
		var ss = ( sec < 10 ? '0' : '' ) + sec;
		return ( h > 0 ? h + ':' + mm : mm ) + ':' + ss;
	}

	function setSteps( step ) {
		// Three visual steps; "deposit_detected" and "matching" share the second one.
		var idx = STEPS[ step ];
		if ( idx === undefined ) {
			idx = 0;
		}
		var items = root.querySelectorAll( '[data-vz-steps] > li' );
		for ( var i = 0; i < items.length; i++ ) {
			items[ i ].classList.toggle( 'is-done', i < idx || ( idx === 2 && i === 2 ) );
			items[ i ].classList.toggle( 'is-cur', i === idx && idx !== 2 );
		}
		if ( step === 'matching' || step === 'deposit_detected' ) {
			var s2 = $( '[data-vz-s2]' );
			if ( s2 && S.deposit2 ) {
				s2.textContent = S.deposit2;
			}
		}
	}

	function render( data ) {
		state = data.display || state;
		root.setAttribute( 'data-st', state );
		var pill = $( '[data-vz-pill]' );
		var p = PILL[ state ] || PILL.awaiting;
		pill.textContent = p[ 0 ];
		pill.className = 'vz-badge vz-badge-' + p[ 1 ] + ' vz-dot';
		setSteps( state === 'paid' ? 'confirmed' : data.step || 'awaiting_deposit' );

		if ( state === 'reviewing' || state === 'underpaid' ) {
			var s2 = $( '[data-vz-s2]' );
			if ( s2 && S.reviewNote ) {
				s2.textContent = S.reviewNote;
			}
		}
		if ( state === 'paid' ) {
			var wc = $( '[data-vz-wc-status]' );
			if ( wc && data.wc_label ) {
				wc.textContent = data.wc_label;
			}
			var payer = $( '[data-vz-payer]' );
			if ( payer && data.payer_last4 ) {
				payer.textContent = '…' + data.payer_last4;
				$( '[data-vz-payer-box]' ).hidden = false;
			}
		}
		if ( state === 'expired' || state === 'canceled' ) {
			var title = $( '[data-vz-closed-title]' );
			title.textContent = state === 'canceled' ? S.canceled : S.expired;
			var again = $( '[data-vz-pay-again]' );
			if ( again && data.pay_url ) {
				again.setAttribute( 'href', data.pay_url );
				again.hidden = false;
			}
		}
		// Keep the WooCommerce "status" line of classic templates in sync when present.
		var wcStatus = document.querySelector( '.woocommerce-order-overview__status strong, [data-vz-wc-line]' );
		if ( wcStatus && data.wc_label ) {
			wcStatus.textContent = data.wc_label;
		}
	}

	var delay = 4000;
	var timer = null;
	var stopped = false;
	var liveTag = $( '[data-vz-live-t]' );

	function schedule( ms ) {
		if ( stopped ) {
			return;
		}
		clearTimeout( timer );
		timer = setTimeout( poll, document.hidden ? Math.max( ms, 15000 ) : ms );
	}

	function poll() {
		var xhr = new XMLHttpRequest();
		xhr.open( 'GET', cfg.endpoint + ( cfg.endpoint.indexOf( '?' ) > -1 ? '&' : '?' ) + '_=' + Date.now(), true );
		xhr.setRequestHeader( 'Accept', 'application/json' );
		xhr.onreadystatechange = function () {
			if ( xhr.readyState !== 4 ) {
				return;
			}
			if ( xhr.status === 200 ) {
				var data = null;
				try {
					data = JSON.parse( xhr.responseText );
				} catch ( e ) {}
				if ( data ) {
					root.classList.remove( 'is-offline' );
					if ( liveTag ) {
						liveTag.textContent = S.live;
					}
					render( data );
					if ( data.terminal ) {
						stopped = true;
						return;
					}
					delay = 4000;
					schedule( delay );
					return;
				}
			}
			if ( xhr.status === 404 ) {
				stopped = true;
				return;
			}
			root.classList.add( 'is-offline' );
			if ( liveTag ) {
				liveTag.textContent = S.offline;
			}
			delay = Math.min( 30000, delay * 2 );
			schedule( delay );
		};
		xhr.send();
	}

	function copy( text, btn ) {
		var done = function () {
			var label = btn.querySelector( '[data-vz-copy-t]' );
			var old = label ? label.textContent : null;
			btn.classList.add( 'is-copied' );
			if ( label ) {
				label.textContent = S.copied;
			}
			setTimeout( function () {
				btn.classList.remove( 'is-copied' );
				if ( label ) {
					label.textContent = old;
				}
			}, 1600 );
		};
		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard.writeText( text ).then( done, function () {
				legacyCopy( text );
				done();
			} );
			return;
		}
		legacyCopy( text );
		done();
	}

	function legacyCopy( text ) {
		var ta = document.createElement( 'textarea' );
		ta.value = text;
		ta.setAttribute( 'readonly', '' );
		ta.style.position = 'fixed';
		ta.style.opacity = '0';
		document.body.appendChild( ta );
		ta.select();
		try {
			document.execCommand( 'copy' );
		} catch ( e ) {}
		document.body.removeChild( ta );
	}

	root.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest ? e.target.closest( '[data-vz-copy]' ) : null;
		if ( btn ) {
			e.preventDefault();
			copy( btn.getAttribute( 'data-vz-copy' ), btn );
		}
	} );

	document.addEventListener( 'visibilitychange', function () {
		if ( ! document.hidden && ! stopped ) {
			schedule( 300 );
		}
	} );

	setSteps( state === 'paid' ? 'confirmed' : state === 'reviewing' || state === 'underpaid' ? 'matching' : 'awaiting_deposit' );
	tick();
	setInterval( tick, 1000 );
	if ( state === 'awaiting' || state === 'reviewing' || state === 'underpaid' ) {
		schedule( 1500 );
	}
} )();
