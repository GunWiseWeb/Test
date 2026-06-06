/* gdsearch price-alert set/cancel (AJAX, csrfKey in POST body). No framework. */
(function () {
	function post( url, data ) {
		var body = new URLSearchParams();
		Object.keys( data ).forEach( function ( k ) { body.append( k, data[k] ); } );
		return fetch( url, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
			body: body.toString(),
			credentials: 'same-origin'
		} ).then( function ( r ) { return r.json().catch( function () { return { ok: false }; } ); } );
	}

	function wire( root ) {
		root = root || document;

		// Set / update
		root.querySelectorAll( '.gd-alert-set' ).forEach( function ( btn ) {
			if ( btn.__wired ) { return; } btn.__wired = true;
			btn.addEventListener( 'click', function () {
				var box   = btn.closest( '.gd-alert' );
				var input = box.querySelector( '.gd-alert-input' );
				var val   = parseFloat( input.value );
				if ( !( val > 0 ) ) { input.focus(); return; }
				btn.disabled = true;
				post( box.getAttribute( 'data-set-url' ), {
					upc: box.getAttribute( 'data-upc' ),
					threshold: val,
					csrfKey: box.getAttribute( 'data-csrf' )
				} ).then( function ( res ) {
					btn.disabled = false;
					if ( res && res.ok ) {
						var msg = box.querySelector( '.gd-alert-state' );
						if ( msg ) { msg.innerHTML = 'Alert set — we\'ll email you when it drops to or below <strong>$' + res.threshold + '</strong>.'; }
						box.classList.add( 'gd-alert-active' );
					} else if ( res && res.error === 'login' ) {
						window.location = box.getAttribute( 'data-login-url' ) || '/';
					}
				} );
			} );
		} );

		// Cancel
		root.querySelectorAll( '.gd-alert-cancel' ).forEach( function ( btn ) {
			if ( btn.__wired ) { return; } btn.__wired = true;
			btn.addEventListener( 'click', function () {
				var box = btn.closest( '.gd-alert' ) || btn.closest( '.gd-alert-row' );
				btn.disabled = true;
				post( box.getAttribute( 'data-cancel-url' ), {
					upc: box.getAttribute( 'data-upc' ),
					csrfKey: box.getAttribute( 'data-csrf' )
				} ).then( function ( res ) {
					btn.disabled = false;
					if ( res && res.ok ) {
						if ( box.classList.contains( 'gd-alert-row' ) ) { box.parentNode.removeChild( box ); }
						else {
							box.classList.remove( 'gd-alert-active' );
							var msg = box.querySelector( '.gd-alert-state' );
							if ( msg ) { msg.textContent = 'Alert cancelled.'; }
						}
					}
				} );
			} );
		} );
	}

	if ( document.readyState !== 'loading' ) { wire(); }
	else { document.addEventListener( 'DOMContentLoaded', function () { wire(); } ); }
})();
