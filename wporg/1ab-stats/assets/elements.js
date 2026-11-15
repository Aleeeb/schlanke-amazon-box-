/* 1AB Stats: counts page views, sightings and clicks of elements whose A/B test is running. Stores nothing in the browser. */
( function () {
	var boxes = document.querySelectorAll( '[data-oneab-el]' ), c = window.oneabStats;
	if ( ! boxes.length ) {
		return;
	}
	var count = c && c.e && navigator.doNotTrack !== '1', robot = 0;
	try {
		robot = ( navigator.webdriver === true || /HeadlessChrome|PhantomJS/i.test( navigator.userAgent ) ) ? 1 : 0;
	} catch ( e ) {}

	[].forEach.call( boxes, function ( box ) {
		var test = parseInt( box.getAttribute( 'data-oneab-el' ), 10 ), v = box.getAttribute( 'data-oneab-v' );

		// Normally the draw has already happened right behind the element. This is the fallback when that small script was held back.
		if ( ! v ) {
			var spares = box.querySelectorAll( 'template' ), pick = Math.floor( Math.random() * ( spares.length + 1 ) );
			v = 'a';
			if ( pick && spares[ pick - 1 ].content ) {
				var copy = spares[ pick - 1 ].content.cloneNode( true );
				v = spares[ pick - 1 ].getAttribute( 'data-v' ) || 'b';
				box.textContent = '';
				box.appendChild( copy );
			}
			box.setAttribute( 'data-oneab-v', v );
		}
		if ( ! count || ! test ) {
			return;
		}

		function send( event ) {
			try {
				var b = new Blob( [ JSON.stringify( { t: 'ab', id: test, v: v, e: event, p: location.pathname, a: robot } ) ], { type: 'text/plain' } );
				if ( ! ( navigator.sendBeacon && navigator.sendBeacon( c.e, b ) ) ) {
					fetch( c.e, { method: 'POST', body: b, keepalive: true } );
				}
			} catch ( e ) {}
		}

		send( 's' );

		if ( 'IntersectionObserver' in window ) {
			var seen = new IntersectionObserver( function ( entries ) {
				if ( entries[ 0 ].isIntersecting ) {
					seen.disconnect();
					send( 'v' );
				}
			}, { threshold: 0.25 } );
			seen.observe( box );
		}

		function click( e ) {
			if ( e.target.closest && e.target.closest( 'a[href]' ) ) {
				send( 'c' );
			}
		}
		box.addEventListener( 'click', click, true );
		box.addEventListener( 'auxclick', click, true );
	} );
}() );
