/* gdsearch wishlist: heart toggle under product image + remove on My Wishlist page. AJAX, csrfKey in POST. */
(function () {
	function post( url, data ) {
		var body = new URLSearchParams();
		Object.keys( data ).forEach( function ( k ) { body.append( k, data[k] ); } );
		return fetch( url, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
			body: body.toString(), credentials: 'same-origin'
		} ).then( function ( r ) { return r.json().catch( function () { return { ok: false }; } ); } );
	}

	function setState( btn, saved ) {
		var label = btn.querySelector( '.gd-wish-label' );
		var heart = btn.querySelector( '.gd-wish-heart' );
		if ( saved ) {
			btn.setAttribute( 'data-saved', '1' );
			if ( heart ) { heart.innerHTML = '&#9829;'; }
			if ( label ) { label.textContent = 'Saved to Wishlist'; }
			btn.classList.add( 'ipsButton--positive' );
			btn.classList.remove( 'ipsButton--secondary' );
		} else {
			btn.setAttribute( 'data-saved', '' );
			if ( heart ) { heart.innerHTML = '&#9825;'; }
			if ( label ) { label.textContent = 'Save to Wishlist'; }
			btn.classList.add( 'ipsButton--secondary' );
			btn.classList.remove( 'ipsButton--positive' );
		}
	}

	function wire() {
		/* Product-page heart trigger */
		document.querySelectorAll( '.gd-wish-trigger' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				if ( btn.getAttribute( 'data-logged-in' ) !== '1' ) {
					window.location = btn.getAttribute( 'data-login-url' ) || '/';
					return;
				}
				var saved = btn.getAttribute( 'data-saved' ) === '1';
				var url   = saved ? btn.getAttribute( 'data-remove-url' ) : btn.getAttribute( 'data-add-url' );
				btn.disabled = true;
				post( url, { upc: btn.getAttribute( 'data-upc' ), csrfKey: btn.getAttribute( 'data-csrf' ) } ).then( function ( res ) {
					btn.disabled = false;
					if ( res && res.ok ) { setState( btn, !saved ); }
					else if ( res && res.error === 'login' ) { window.location = btn.getAttribute( 'data-login-url' ) || '/'; }
				} );
			} );
		} );

		/* My Wishlist page remove buttons */
		document.querySelectorAll( '.gd-wish-remove' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				btn.disabled = true;
				post( btn.getAttribute( 'data-remove-url' ), { upc: btn.getAttribute( 'data-upc' ), csrfKey: btn.getAttribute( 'data-csrf' ) } ).then( function ( res ) {
					btn.disabled = false;
					if ( res && res.ok ) {
						var card = btn.closest( '.gracct-card' ) || btn.closest( '.gd-wish-card' );
						if ( card ) { card.remove(); }
					}
				} );
			} );
		} );
	}

	if ( document.readyState !== 'loading' ) { wire(); }
	else { document.addEventListener( 'DOMContentLoaded', wire ); }
})();
