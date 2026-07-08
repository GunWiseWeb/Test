/*
 * GD FFL Finder — ACP AJAX batch-loop driver.
 *
 * Reads window.gdfflImportConfig (set inline by the ACP import
 * page):
 *     {
 *       fflStep:   URL of the FFL batch endpoint,
 *       zipStep:   URL of the ZIP batch endpoint,
 *       fflResume: URL of the "start / resume ATF import" endpoint,
 *       zipResume: URL of the "start / resume ZIP import" endpoint,
 *       csrfKey:   session CSRF key (POSTed as csrfKey),
 *       startFfl:  bool — auto-start the FFL loop on page load,
 *       startZip:  bool — auto-start the ZIP loop on page load,
 *     }
 *
 * On each step the endpoint returns { done, processed, total,
 * offset, skipped }. We drive an offset-based loop until done:true,
 * updating a bar + text on each pass. Two-loop pattern (FFL + ZIP)
 * so an admin can queue one while the other runs.
 */
(function () {
	'use strict';

	var cfg = window.gdfflImportConfig || null;
	if ( !cfg ) { return; }

	function el ( id ) { return document.getElementById( id ); }

	function post ( url, body ) {
		var form = new FormData();
		form.append( 'csrfKey', cfg.csrfKey );
		Object.keys( body || {} ).forEach( function ( k ) {
			form.append( k, String( body[k] ) );
		} );
		return fetch( url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
			body: form,
		} ).then( function ( r ) { return r.json(); } );
	}

	function fmtPct ( processed, total ) {
		if ( !total || total <= 0 ) { return 0; }
		var p = ( processed / total ) * 100;
		if ( p < 0 ) { p = 0; } if ( p > 100 ) { p = 100; }
		return p;
	}

	function fmtInt ( n ) {
		try { return Number( n ).toLocaleString(); } catch ( e ) { return String( n ); }
	}

	function runLoop ( endpoint, panelId, fillId, textId, label ) {
		var panel = el( panelId ); var fill = el( fillId ); var text = el( textId );
		if ( !panel || !fill || !text ) { return; }
		panel.style.display = 'block';
		text.textContent = label + ': starting…';
		fill.style.width = '0%';

		var offset = 0;

		function step () {
			post( endpoint, { offset: offset } ).then( function ( res ) {
				if ( !res || res.error ) {
					text.textContent = label + ' failed (' + ( ( res && res.error ) || 'network' ) + '). Please try again.';
					fill.classList.add( 'gdffl-progress-fill--error' );
					return;
				}

				var processed = Number( res.processed || 0 );
				var total     = Number( res.total     || 0 );
				var skipped   = Number( res.skipped   || 0 );
				var pct       = fmtPct( processed, total );

				fill.style.width = pct.toFixed( 1 ) + '%';
				var line = label + ': ' + fmtInt( processed );
				if ( total > 0 ) { line += ' of ' + fmtInt( total ); }
				if ( skipped > 0 ) { line += ' (' + fmtInt( skipped ) + ' skipped)'; }
				text.textContent = line;

				if ( res.done ) {
					fill.style.width = '100%';
					text.textContent = label + ': done — ' + fmtInt( processed ) +
						( skipped > 0 ? ( ' rows imported, ' + fmtInt( skipped ) + ' skipped.' )
						              : ' rows imported.' );
					fill.classList.add( 'gdffl-progress-fill--done' );
					return;
				}

				offset = Number( res.offset || processed );
				/* Yield to the browser so the paint of the bar + text
				   actually happens between passes. */
				setTimeout( step, 30 );
			} ).catch( function ( e ) {
				text.textContent = label + ' failed: ' + ( e && e.message ? e.message : 'network error' );
				fill.classList.add( 'gdffl-progress-fill--error' );
			} );
		}

		step();
	}

	function bootFfl () {
		runLoop( cfg.fflStep, 'gdffl-ffl-progress', 'gdffl-ffl-fill', 'gdffl-ffl-text', 'ATF FFL import' );
	}
	function bootZip () {
		runLoop( cfg.zipStep, 'gdffl-zip-progress', 'gdffl-zip-fill', 'gdffl-zip-text', 'ZIP centroid import' );
	}

	/*
	 * Wire the two explicit "Start Import" buttons so the admin
	 * can kick either loop directly, even if the session-based
	 * autostart flag from the upload → 302 → manage() hop was
	 * lost (which happens under some ACP session configs). The
	 * button's <form> POSTs to fflResume / zipResume — a normal
	 * form submit works, but we intercept and let the JS drive
	 * the loop straight after the resume returns.
	 */
	function bindStartButton ( buttonId, formAction, kickFn ) {
		var btn = el( buttonId );
		if ( !btn ) { return; }
		btn.addEventListener( 'click', function ( ev ) {
			ev.preventDefault();
			btn.disabled = true;

			/* POST to the resume endpoint to prime the session job.
			   The server responds with a 302; we don't want to
			   follow it (that would download manage()'s HTML for
			   nothing) — redirect: 'manual' makes the fetch resolve
			   with an opaqueredirect response instead. Either way,
			   as long as the POST reaches the server, the session
			   job is primed and we can kick the AJAX loop. */
			var form = new FormData();
			form.append( 'csrfKey', cfg.csrfKey );
			fetch( formAction, {
				method: 'POST',
				credentials: 'same-origin',
				redirect: 'manual',
				headers: { 'X-Requested-With': 'XMLHttpRequest' },
				body: form,
			} ).then( function () {
				kickFn();
			} ).catch( function () {
				/* Fall back to the plain <form> submission if the
				   POST failed — the browser will land back on
				   manage() which will autostart from the session. */
				var backupForm = btn.closest( 'form' );
				if ( backupForm ) { backupForm.submit(); }
			} );
		} );
	}

	function onReady () {
		bindStartButton( 'gdffl-ffl-start', cfg.fflResume || cfg.fflStep, bootFfl );
		bindStartButton( 'gdffl-zip-start', cfg.zipResume || cfg.zipStep, bootZip );

		if ( cfg.startFfl ) { bootFfl(); }
		if ( cfg.startZip ) { bootZip(); }
	}

	if ( document.readyState === 'complete' || document.readyState === 'interactive' ) {
		onReady();
	} else {
		document.addEventListener( 'DOMContentLoaded', onReady );
	}
})();
