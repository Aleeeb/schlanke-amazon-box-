/* 1AB Stats: counts page views and clicks on affiliate links. Sets no cookie and stores nothing in the browser. */
( function () {
	var c = window.oneabStats;
	if ( ! c || ! c.e || navigator.doNotTrack === '1' ) {
		return;
	}

	function send( d ) {
		d.p = location.pathname;
		try {
			var b = new Blob( [ JSON.stringify( d ) ], { type: 'text/plain' } );
			if ( ! ( navigator.sendBeacon && navigator.sendBeacon( c.e, b ) ) ) {
				fetch( c.e, { method: 'POST', body: b, keepalive: true } );
			}
		} catch ( e ) {}
	}

	var tz = '';
	try {
		tz = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
	} catch ( e ) {}

	var robot = 0;
	try {
		robot = ( navigator.webdriver === true || /HeadlessChrome|PhantomJS/i.test( navigator.userAgent ) ) ? 1 : 0;
	} catch ( e ) {}

	send( { t: 'v', r: document.referrer, w: screen.width, z: tz, g: navigator.language || '', a: robot } );

	function isAffiliate( host ) {
		host = host.toLowerCase().replace( /^www\./, '' );
		if ( /(^|\.)(amazon\.[a-z.]+|amzn\.[a-z]+|a\.co)$/.test( host ) || /(^|\.)ebay\.[a-z.]+$/.test( host ) ) {
			return true;
		}
		var d = c.d || [], k;
		for ( k = 0; k < d.length; k++ ) {
			if ( host === d[ k ] || host.slice( -( d[ k ].length + 1 ) ) === '.' + d[ k ] ) {
				return true;
			}
		}
		return false;
	}

	function item( a ) {
		var m, p = a.pathname || '';
		if ( /amazon|amzn/i.test( a.hostname ) ) {
			m = p.match( /\/(?:dp|gp\/product|gp\/aw\/d|exec\/obidos\/ASIN|ASIN)\/([A-Z0-9]{10})/i );
			return m ? m[ 1 ].toUpperCase() : '';
		}
		if ( /ebay\./i.test( a.hostname ) ) {
			m = p.match( /\/itm\/(?:[^\/]*\/)?(\d{9,})/ ) || ( a.search || '' ).match( /[?&]item=(\d{9,})/ );
			return m ? m[ 1 ] : '';
		}
		return '';
	}

	function click( e ) {
		var a = e.target && e.target.closest ? e.target.closest( 'a[href]' ) : null;
		if ( ! a || ! a.hostname || a.hostname === location.hostname || ! isAffiliate( a.hostname ) ) {
			return;
		}
		send( { t: 'c', to: a.hostname, i: item( a ) } );
	}

	document.addEventListener( 'click', click, true );
	document.addEventListener( 'auxclick', click, true );

	// Heatmap: only sums are sent (reading depth, seconds per part of the page, click positions in a coarse grid).
	if ( c.h ) {
		var D = document.documentElement, zone = {}, clicks = [], secs = 0, reach = -1, sentReach = -1, first = 1, dev = innerWidth < 768 ? 'm' : 'd';

		function docHeight() {
			return Math.max( D.scrollHeight, document.body ? document.body.scrollHeight : 0, 1 );
		}

		function band( y ) {
			return Math.max( 0, Math.min( 19, Math.floor( y / docHeight() * 20 ) ) );
		}

		function look() {
			reach = Math.max( reach, band( scrollY + innerHeight - 1 ) );
		}

		setInterval( function () {
			if ( document.visibilityState !== 'visible' || secs >= 900 ) {
				return;
			}
			secs++;
			var b = band( scrollY + innerHeight / 2 );
			zone[ b ] = ( zone[ b ] || 0 ) + 1;
			look();
		}, 1000 );

		document.addEventListener( 'click', function ( e ) {
			if ( clicks.length < 20 ) {
				clicks.push( [ Math.min( 39, Math.floor( e.pageX / Math.max( D.clientWidth, 1 ) * 40 ) ), Math.min( 2000, Math.floor( e.pageY / 25 ) ) ] );
			}
		}, true );

		function flush() {
			look();
			if ( ! first && reach === sentReach && ! clicks.length && ! Object.keys( zone ).length ) {
				return;
			}
			var o = { t: 'm', dv: dev, z: zone, k: clicks, r: reach, r0: sentReach, a: robot };
			if ( first ) {
				o.f = 1;
				first = 0;
			}
			send( o );
			sentReach = reach;
			zone = {};
			clicks = [];
		}

		// Long visits report every half minute, so that little is lost if the browser never signals the end of the visit.
		setInterval( function () {
			if ( document.visibilityState === 'visible' ) {
				flush();
			}
		}, 30000 );
		addEventListener( 'pagehide', flush );
		document.addEventListener( 'visibilitychange', function () {
			if ( document.visibilityState === 'hidden' ) {
				flush();
			}
		} );
	}
}() );
