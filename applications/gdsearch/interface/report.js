/* gdsearch product report: trigger -> modal (reason + details). AJAX, csrfKey in POST. */
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
		modal.querySelector( '.gd-report-msg' ).textContent = '';
		modal.querySelector( '.gd-report-details' ).value = '';
		var radios = modal.querySelectorAll( 'input[name="gd_report_reason"]' );
		if ( radios.length ) { radios[0].checked = true; }
		modal.querySelector( '.gd-report-form' ).style.display = '';
		modal.querySelector( '.gd-report-submit' ).style.display = '';
		modal.style.display = 'flex';
	}
	function closeModal() { modal.style.display = 'none'; current = null; }

	function wire() {
		modal = document.getElementById( 'gdReportModal' );
		document.querySelectorAll( '.gd-report-trigger' ).forEach( function ( t ) {
			t.addEventListener( 'click', function () {
				if ( t.getAttribute( 'data-logged-in' ) !== '1' ) { window.location = t.getAttribute( 'data-login-url' ) || '/'; return; }
				if ( modal ) { openModal( t ); }
			} );
		} );
		if ( !modal ) { return; }

		modal.querySelector( '.gd-report-close' ).addEventListener( 'click', closeModal );
		modal.addEventListener( 'click', function ( e ) { if ( e.target === modal ) { closeModal(); } } );
		document.addEventListener( 'keydown', function ( e ) { if ( e.key === 'Escape' && modal.style.display === 'flex' ) { closeModal(); } } );

		modal.querySelector( '.gd-report-submit' ).addEventListener( 'click', function () {
			if ( !current ) { return; }
			var reason = ( modal.querySelector( 'input[name="gd_report_reason"]:checked' ) || {} ).value || 'data';
			var details = modal.querySelector( '.gd-report-details' ).value || '';
			var btn = this; btn.disabled = true;
			post( current.getAttribute( 'data-report-url' ), {
				upc: current.getAttribute( 'data-upc' ), reason: reason, details: details, csrfKey: current.getAttribute( 'data-csrf' )
			} ).then( function ( res ) {
				btn.disabled = false;
				if ( res && res.ok ) {
					modal.querySelector( '.gd-report-form' ).style.display = 'none';
					btn.style.display = 'none';
					modal.querySelector( '.gd-report-msg' ).textContent = 'Thanks — your report has been sent to our team.';
					setTimeout( closeModal, 1600 );
				} else if ( res && res.error === 'login' ) {
					window.location = current.getAttribute( 'data-login-url' ) || '/';
				} else {
					modal.querySelector( '.gd-report-msg' ).textContent = 'Could not submit. Please try again.';
				}
			} );
		} );
	}

	if ( document.readyState !== 'loading' ) { wire(); }
	else { document.addEventListener( 'DOMContentLoaded', wire ); }
})();
