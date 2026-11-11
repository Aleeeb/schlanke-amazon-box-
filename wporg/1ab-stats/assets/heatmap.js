/* 1AB Stats: heatmap overlay, shown to administrators only. */
( function () {
	var c = window.oneabHeat;
	if ( ! c ) {
		return;
	}
	var dev = '', box = null, panel = null;

	function docHeight() {
		return Math.max( document.documentElement.scrollHeight, document.body.scrollHeight );
	}

	function text( template, a, b ) {
		return template.replace( '%1$s', a ).replace( '%2$s', b ).replace( '%s', a ).replace( '%%', '%' );
	}

	function load() {
		fetch( c.u + ( c.u.indexOf( '?' ) < 0 ? '?' : '&' ) + 'path=' + encodeURIComponent( location.pathname ) + ( dev ? '&dev=' + dev : '' ), {
			headers: { 'X-WP-Nonce': c.n },
			credentials: 'same-origin'
		} ).then( function ( r ) {
			return r.json();
		} ).then( draw );
	}

	function draw( d ) {
		if ( box ) {
			box.remove();
		}
		var h = docHeight(), maxSecs = Math.max.apply( null, d.z.concat( [ 1 ] ) );
		box = document.createElement( 'div' );
		box.style.cssText = 'position:absolute;left:0;top:0;width:100%;height:' + h + 'px;pointer-events:none;z-index:99990';

		d.z.forEach( function ( secs, i ) {
			var t = secs / maxSecs, band = document.createElement( 'div' ), label = document.createElement( 'span' );
			band.style.cssText = 'position:absolute;left:0;right:0;top:' + ( i * 5 ) + '%;height:5%;background:hsla(' + Math.round( 220 - t * 220 ) + ',90%,50%,' + ( 0.12 + t * 0.3 ) + ');border-top:1px dashed rgba(0,0,0,.15)';
			label.style.cssText = 'position:absolute;right:6px;top:2px;background:rgba(0,0,0,.72);color:#fff;font:12px/1.3 sans-serif;padding:2px 6px;border-radius:3px';
			label.textContent = text( c.s.band, String( Math.round( ( d.r[ i ] || 0 ) * 100 ) ), secs.toFixed( 1 ) );
			band.appendChild( label );
			box.appendChild( band );
		} );

		var maxClicks = Math.max.apply( null, d.k.map( function ( k ) {
			return k[ 2 ];
		} ).concat( [ 1 ] ) );
		d.k.forEach( function ( k ) {
			var size = 10 + Math.round( 24 * k[ 2 ] / maxClicks ), dot = document.createElement( 'div' );
			dot.style.cssText = 'position:absolute;border-radius:50%;background:rgba(220,0,0,.45);box-shadow:0 0 8px rgba(220,0,0,.6);pointer-events:auto;width:' + size + 'px;height:' + size + 'px;left:calc(' + ( k[ 0 ] * 2.5 + 1.25 ) + '% - ' + ( size / 2 ) + 'px);top:' + ( k[ 1 ] * 25 + 12 - size / 2 ) + 'px';
			dot.title = text( c.s.clicks, String( k[ 2 ] ) );
			box.appendChild( dot );
		} );
		document.body.appendChild( box );

		if ( ! panel ) {
			panel = document.createElement( 'div' );
			panel.style.cssText = 'position:fixed;right:12px;bottom:12px;z-index:99991;background:#fff;color:#111;font:13px/1.5 sans-serif;padding:10px 12px;border-radius:8px;box-shadow:0 2px 12px rgba(0,0,0,.3);max-width:280px';
			document.body.appendChild( panel );
		}
		panel.textContent = '';
		var head = document.createElement( 'b' );
		head.textContent = c.s.title;
		panel.appendChild( head );
		[ c.s.views.replace( '{v}', String( d.v ) ), c.s.legend ].concat( d.v < 30 ? [ c.s.few ] : [] ).forEach( function ( line ) {
			var p = document.createElement( 'div' );
			p.textContent = line;
			panel.appendChild( p );
		} );
		[ [ '', c.s.all ], [ 'd', c.s.desktop ], [ 'm', c.s.mobile ] ].forEach( function ( o ) {
			var a = document.createElement( 'a' );
			a.href = '#';
			a.textContent = o[ 1 ];
			a.style.cssText = 'margin-right:10px;' + ( dev === o[ 0 ] ? 'font-weight:700' : '' );
			a.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				dev = o[ 0 ];
				load();
			} );
			panel.appendChild( a );
		} );
	}

	if ( document.readyState === 'complete' ) {
		load();
	} else {
		addEventListener( 'load', load );
	}
}() );
