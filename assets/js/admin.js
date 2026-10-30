/**
 * Varizet admin: copy buttons, disconnect confirmation, settings preview, log filters.
 * Plain JS, no dependencies.
 */
( function () {
	'use strict';

	var cfg = window.varizetAdmin || {};

	function copyText( text ) {
		if ( navigator.clipboard && window.isSecureContext ) {
			return navigator.clipboard.writeText( text );
		}
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
		return Promise.resolve();
	}

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest ? e.target.closest( '[data-vz-copy]' ) : null;
		if ( ! btn ) {
			return;
		}
		e.preventDefault();
		var old = btn.innerHTML;
		copyText( btn.getAttribute( 'data-vz-copy' ) ).then( function () {
			btn.textContent = cfg.copied || 'Copied';
			setTimeout( function () {
				btn.innerHTML = old;
			}, 1500 );
		} );
	} );

	document.addEventListener( 'submit', function ( e ) {
		var form = e.target;
		if ( form && form.hasAttribute && form.hasAttribute( 'data-vz-confirm' ) ) {
			// eslint-disable-next-line no-alert
			if ( ! window.confirm( cfg.confirmOff || 'Disconnect?' ) ) {
				e.preventDefault();
			}
		}
	} );

	// Live preview of the title and description on the gateway settings screen.
	[ 'title', 'description' ].forEach( function ( key ) {
		var input = document.getElementById( 'woocommerce_varizet_' + key );
		var target = document.querySelector( '[data-vz-preview="' + key + '"]' );
		if ( input && target ) {
			input.addEventListener( 'input', function () {
				target.textContent = input.value;
			} );
		}
	} );

	// Log viewer: level chips + search.
	var logs = document.querySelector( '[data-vz-logs]' );
	if ( logs ) {
		var level = 'all';
		var q = logs.querySelector( '[data-vz-log-q]' );
		var apply = function () {
			var term = ( q && q.value ? q.value : '' ).toLowerCase();
			logs.querySelectorAll( 'tbody tr' ).forEach( function ( tr ) {
				var okLevel = level === 'all' || tr.getAttribute( 'data-lv' ) === level;
				var okText = ! term || tr.textContent.toLowerCase().indexOf( term ) > -1;
				tr.hidden = ! ( okLevel && okText );
			} );
		};
		logs.querySelectorAll( '[data-vz-lv]' ).forEach( function ( chip ) {
			chip.addEventListener( 'click', function () {
				level = chip.getAttribute( 'data-vz-lv' );
				logs.querySelectorAll( '[data-vz-lv]' ).forEach( function ( c ) {
					c.classList.toggle( 'is-active', c === chip );
				} );
				apply();
			} );
		} );
		if ( q ) {
			q.addEventListener( 'input', apply );
		}
	}
} )();
