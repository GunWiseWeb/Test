/* gdsearch interactive price-history hover tooltips. No framework. */
(function () {
	function initOne( box ) {
		var raw = box.getAttribute( 'data-points' );
		if ( !raw ) { return; }
		var cfg;
		try { cfg = JSON.parse( raw ); } catch ( e ) { return; }
		var pts = ( cfg && cfg.points ) || [];
		var svg = box.querySelector( 'svg' );
		var tip = box.querySelector( '.gd-pc-tip' );
		if ( !svg || !tip || pts.length < 2 ) { return; }
		var guide = svg.querySelector( '.gd-pc-guide' );
		var dot   = svg.querySelector( '.gd-pc-dot' );

		function clientX( e ) { return e.touches && e.touches[0] ? e.touches[0].clientX : e.clientX; }

		function show( e ) {
			var rect = svg.getBoundingClientRect();
			if ( !rect.width ) { return; }
			var vbX = ( ( clientX( e ) - rect.left ) / rect.width ) * cfg.w;
			var best = 0, bd = Infinity;
			for ( var i = 0; i < pts.length; i++ ) {
				var d = Math.abs( pts[i].x - vbX );
				if ( d < bd ) { bd = d; best = i; }
			}
			var p = pts[best];

			if ( guide ) {
				guide.setAttribute( 'x1', p.x ); guide.setAttribute( 'x2', p.x );
				guide.setAttribute( 'y1', cfg.padT ); guide.setAttribute( 'y2', cfg.base );
				guide.style.display = '';
			}
			if ( dot ) { dot.setAttribute( 'cx', p.x ); dot.setAttribute( 'cy', p.y ); dot.style.display = ''; }

			var pxX = ( p.x / cfg.w ) * rect.width;
			var pxY = ( p.y / cfg.h ) * rect.height;
			tip.innerHTML = '<strong>$' + Number( p.price ).toFixed( 2 ) + '</strong><br><span style="opacity:.75">' + p.date + '</span>';
			tip.style.display = 'block';
			var tw = tip.offsetWidth, th = tip.offsetHeight;
			var left = pxX - tw / 2;
			if ( left < 0 ) { left = 0; }
			if ( left + tw > rect.width ) { left = rect.width - tw; }
			var top = pxY - th - 10;
			if ( top < 0 ) { top = pxY + 14; }
			tip.style.left = Math.round( left ) + 'px';
			tip.style.top  = Math.round( top ) + 'px';
		}
		function hide() {
			tip.style.display = 'none';
			if ( guide ) { guide.style.display = 'none'; }
			if ( dot ) { dot.style.display = 'none'; }
		}

		svg.addEventListener( 'mousemove', show );
		svg.addEventListener( 'mouseleave', hide );
		svg.addEventListener( 'touchstart', show, { passive: true } );
		svg.addEventListener( 'touchmove', show, { passive: true } );
		svg.addEventListener( 'touchend', hide );
	}

	function init() {
		var boxes = document.querySelectorAll( '.gd-pricechart' );
		for ( var i = 0; i < boxes.length; i++ ) { initOne( boxes[i] ); }
	}

	if ( document.readyState !== 'loading' ) { init(); }
	else { document.addEventListener( 'DOMContentLoaded', init ); }
})();
