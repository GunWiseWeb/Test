/* gdsearch Compare: session tray (localStorage), product-page add button, side-by-side page. */
(function () {
	var KEY = 'gdCompareItems', MAX = 4;

	function read() {
		try { var v = JSON.parse( window.localStorage.getItem( KEY ) || '[]' ); return Array.isArray( v ) ? v : []; }
		catch ( e ) { return []; }
	}
	function write( items ) {
		try { window.localStorage.setItem( KEY, JSON.stringify( items ) ); } catch ( e ) {}
	}
	function has( items, upc ) { return items.some( function ( i ) { return i.upc === upc; } ); }
	function compareUrlFor( base, items ) {
		return base + ( base.indexOf( '?' ) === -1 ? '?' : '&' ) + 'upcs=' + items.map( function ( i ) { return encodeURIComponent( i.upc ); } ).join( ',' );
	}

	function syncTriggers() {
		var items = read();
		document.querySelectorAll( '.gd-compare-trigger' ).forEach( function ( btn ) {
			var on = has( items, btn.getAttribute( 'data-upc' ) );
			var label = btn.querySelector( '.gd-compare-label' );
			if ( on ) { if ( label ) { label.innerHTML = '&#10003; Added to Compare'; } btn.classList.add( 'ipsButton--positive' ); btn.classList.remove( 'ipsButton--secondary' ); }
			else { if ( label ) { label.textContent = 'Add to Compare'; } btn.classList.add( 'ipsButton--secondary' ); btn.classList.remove( 'ipsButton--positive' ); }
		} );
	}

	function trayEl() {
		var t = document.getElementById( 'gdCompareTray' );
		if ( !t ) {
			t = document.createElement( 'div' );
			t.id = 'gdCompareTray';
			document.body.appendChild( t );
		}
		return t;
	}

	function renderTray() {
		var items = read();
		var base  = document.body.getAttribute( 'data-gd-compare-url' );
		if ( !base ) { var anyBtn = document.querySelector( '.gd-compare-trigger' ); base = anyBtn ? anyBtn.getAttribute( 'data-compare-url' ) : ''; }
		var t = trayEl();
		if ( !items.length || !base ) { t.style.display = 'none'; t.innerHTML = ''; return; }

		var chips = items.map( function ( i, idx ) {
			var img = i.image ? '<img src="' + i.image + '" alt="">' : '<span class="gd-ctray-ph">&#128230;</span>';
			return '<span class="gd-ctray-chip" title="' + ( i.title || '' ).replace( /"/g, '' ) + '">' + img +
				'<button type="button" class="gd-ctray-x" data-idx="' + idx + '">&times;</button></span>';
		} ).join( '' );

		var canCompare = items.length >= 2;
		t.innerHTML =
			'<div class="gd-ctray-inner">' +
				'<div class="gd-ctray-label">Compare <strong>' + items.length + '</strong>/' + MAX + '</div>' +
				'<div class="gd-ctray-chips">' + chips + '</div>' +
				'<div class="gd-ctray-actions">' +
					'<a class="gd-ctray-go ' + ( canCompare ? '' : 'gd-ctray-disabled' ) + '" href="' + ( canCompare ? compareUrlFor( base, items ) : '#' ) + '">Compare</a>' +
					'<button type="button" class="gd-ctray-clear">Clear</button>' +
				'</div>' +
			'</div>';
		t.style.display = 'block';

		t.querySelectorAll( '.gd-ctray-x' ).forEach( function ( x ) {
			x.addEventListener( 'click', function () { var a = read(); a.splice( parseInt( x.getAttribute( 'data-idx' ), 10 ), 1 ); write( a ); renderTray(); syncTriggers(); } );
		} );
		t.querySelector( '.gd-ctray-clear' ).addEventListener( 'click', function () { write( [] ); renderTray(); syncTriggers(); } );
		if ( !canCompare ) { var go = t.querySelector( '.gd-ctray-go' ); if ( go ) { go.addEventListener( 'click', function ( e ) { e.preventDefault(); } ); } }
	}


	function injectCss() {
		if ( document.getElementById( 'gdCompareCss' ) ) { return; }
		var s = document.createElement( 'style' );
		s.id = 'gdCompareCss';
		s.textContent = '#gdCompareTray{position:fixed;left:0;right:0;bottom:0;z-index:1200;background:#0f172a;color:#fff;box-shadow:0 -6px 24px -8px rgba(0,0,0,.45);font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}'
			+ '.gd-ctray-inner{max-width:1100px;margin:0 auto;display:flex;align-items:center;gap:18px;padding:12px 20px;flex-wrap:wrap}'
			+ '.gd-ctray-label{font-size:.9em;color:#cbd5e1;white-space:nowrap}.gd-ctray-label strong{color:#fff;font-size:1.15em}'
			+ '.gd-ctray-chips{display:flex;gap:8px;flex:1;flex-wrap:wrap}'
			+ '.gd-ctray-chip{position:relative;width:46px;height:46px;border-radius:8px;background:#fff;border:1px solid #1e293b;display:flex;align-items:center;justify-content:center;overflow:hidden}'
			+ '.gd-ctray-chip img{width:100%;height:100%;object-fit:contain}.gd-ctray-ph{color:#cbd5e1;font-size:1.2em}'
			+ '.gd-ctray-x{position:absolute;top:-7px;right:-7px;width:18px;height:18px;border-radius:50%;border:none;background:#ef4444;color:#fff;font-size:12px;line-height:1;cursor:pointer;padding:0}'
			+ '.gd-ctray-actions{display:flex;gap:10px;align-items:center}'
			+ '.gd-ctray-go{background:#1E40AF;color:#fff;font-weight:700;text-decoration:none;padding:10px 22px;border-radius:9px;font-size:.92em;white-space:nowrap}'
			+ '.gd-ctray-go:hover{background:#2563eb}.gd-ctray-disabled{opacity:.45;cursor:not-allowed}'
			+ '.gd-ctray-clear{background:transparent;border:1px solid #334155;color:#cbd5e1;border-radius:9px;padding:10px 16px;font-size:.85em;cursor:pointer}'
			+ '.gd-ctray-clear:hover{border-color:#64748b;color:#fff}'
			+ '@media(max-width:560px){.gd-ctray-inner{gap:10px;padding:10px 12px}.gd-ctray-label{width:100%}}';
		document.head.appendChild( s );
	}

	function wire() {
		injectCss();
		document.querySelectorAll( '.gd-compare-trigger' ).forEach( function ( btn ) {
			var base = btn.getAttribute( 'data-compare-url' );
			if ( base ) { document.body.setAttribute( 'data-gd-compare-url', base ); }
			btn.addEventListener( 'click', function ( e ) {
				if ( e ) { e.preventDefault(); e.stopPropagation(); }
				var items = read(), upc = btn.getAttribute( 'data-upc' );
				if ( has( items, upc ) ) { items = items.filter( function ( i ) { return i.upc !== upc; } ); }
				else {
					if ( items.length >= MAX ) { alert( 'You can compare up to ' + MAX + ' products at once.' ); return; }
					items.push( { upc: upc, title: btn.getAttribute( 'data-title' ) || upc, image: btn.getAttribute( 'data-image' ) || '' } );
				}
				write( items ); renderTray(); syncTriggers();
			} );
		} );

		/* Compare-page column remove (rebuilds URL without that upc) */
		document.querySelectorAll( '.gd-compare-col-remove' ).forEach( function ( x ) {
			x.addEventListener( 'click', function () {
				var upc = x.getAttribute( 'data-upc' );
				var items = read().filter( function ( i ) { return i.upc !== upc; } );
				write( items );
				var params = new URLSearchParams( window.location.search );
				var remaining = ( params.get( 'upcs' ) || '' ).split( ',' ).filter( function ( u ) { return u && decodeURIComponent( u ) !== upc; } );
				if ( remaining.length ) { params.set( 'upcs', remaining.join( ',' ) ); window.location.search = params.toString(); }
				else { window.history.back(); }
			} );
		} );

		syncTriggers();
		renderTray();
	}

	if ( document.readyState !== 'loading' ) { wire(); }
	else { document.addEventListener( 'DOMContentLoaded', wire ); }
})();
