/* gdsearch price-alert: trigger button under image -> modal popup. AJAX, csrfKey in POST. */
(function () {
	var modal = null, current = null;

	function post( url, data ) {
		var body = new URLSearchParams();
		Object.keys( data ).forEach( function ( k ) { body.append( k, data[k] ); } );
		return fetch( url, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
			body: body.toString(), credentials: 'same-origin'
		} ).then( function ( r ) { return r.json().catch( function () { return { ok: false }; } ); } );
	}

	function openModal( trigger ) {
		current = trigger;
		var th  = trigger.getAttribute( 'data-threshold' ) || '';
		var cur = trigger.getAttribute( 'data-current' ) || '';
		modal.querySelector( '.gd-alert-input' ).value = th;
		modal.querySelector( '.gd-alert-current' ).innerHTML = cur
			? 'Lowest price now: <strong>$' + Number( cur ).toFixed( 2 ) + '</strong>. We\'ll email you when a dealer drops to or below your price.'
			: 'We\'ll email you when a dealer drops to or below your price.';
		modal.querySelector( '.gd-alert-remove' ).style.display = th ? '' : 'none';
		modal.querySelector( '.gd-alert-msg' ).textContent = '';
		modal.style.display = 'flex';
		setTimeout( function () { modal.querySelector( '.gd-alert-input' ).focus(); }, 30 );
	}
	function closeModal() { modal.style.display = 'none'; current = null; }

	function setLabel( trigger, threshold ) {
		var label = trigger.querySelector( '.gd-alert-trigger-label' );
		if ( threshold ) { label.innerHTML = '🔔 Alert: $' + Number( threshold ).toFixed( 2 ); trigger.setAttribute( 'data-threshold', Number( threshold ).toFixed( 2 ) ); }
		else { label.innerHTML = '🔔 Set Price Alert'; trigger.setAttribute( 'data-threshold', '' ); }
	}

	function wire() {
		modal = document.getElementById( 'gdAlertModal' );
		if ( modal ) {
			document.querySelectorAll( '.gd-alert-trigger' ).forEach( function ( t ) {
				t.addEventListener( 'click', function () { openModal( t ); } );
			} );

			modal.querySelector( '.gd-alert-close' ).addEventListener( 'click', closeModal );
			modal.addEventListener( 'click', function ( e ) { if ( e.target === modal ) { closeModal(); } } );
			document.addEventListener( 'keydown', function ( e ) { if ( e.key === 'Escape' && modal.style.display === 'flex' ) { closeModal(); } } );

			modal.querySelector( '.gd-alert-save' ).addEventListener( 'click', function () {
				if ( !current ) { return; }
				var val = parseFloat( modal.querySelector( '.gd-alert-input' ).value );
				if ( !( val > 0 ) ) { modal.querySelector( '.gd-alert-input' ).focus(); return; }
				var btn = this; btn.disabled = true;
				post( current.getAttribute( 'data-set-url' ), {
					upc: current.getAttribute( 'data-upc' ), threshold: val, csrfKey: current.getAttribute( 'data-csrf' )
				} ).then( function ( res ) {
					btn.disabled = false;
					if ( res && res.ok ) {
						setLabel( current, val );
						modal.querySelector( '.gd-alert-remove' ).style.display = '';
						modal.querySelector( '.gd-alert-msg' ).textContent = 'Saved!';
						setTimeout( closeModal, 700 );
					} else if ( res && res.error === 'login' ) {
						window.location = current.getAttribute( 'data-login-url' ) || '/';
					} else {
						modal.querySelector( '.gd-alert-msg' ).textContent = 'Could not save.';
					}
				} );
			} );

			modal.querySelector( '.gd-alert-remove' ).addEventListener( 'click', function () {
				if ( !current ) { return; }
				var btn = this; btn.disabled = true;
				post( current.getAttribute( 'data-cancel-url' ), {
					upc: current.getAttribute( 'data-upc' ), csrfKey: current.getAttribute( 'data-csrf' )
				} ).then( function ( res ) {
					btn.disabled = false;
					if ( res && res.ok ) { setLabel( current, '' ); closeModal(); }
				} );
			} );
		}

		document.querySelectorAll( '.gda-remove' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var url  = btn.getAttribute( 'data-cancel-url' );
				var upc  = btn.getAttribute( 'data-upc' );
				var csrf = btn.getAttribute( 'data-csrf' );
				btn.disabled = true;
				post( url, { upc: upc, csrfKey: csrf } ).then( function ( res ) {
					btn.disabled = false;
					if ( res && res.ok ) {
						var card = btn.closest( '.gda-card' );
						if ( card ) { card.remove(); }
					}
				} );
			} );
		} );
	}

	if ( document.readyState !== 'loading' ) { wire(); }
	else { document.addEventListener( 'DOMContentLoaded', wire ); }
})();
