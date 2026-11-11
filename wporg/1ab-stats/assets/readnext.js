/* 1AB Stats: places the read-next box and, while a test runs, draws variant A or B by chance. Stores nothing in the browser. */
( function () {
	var box = document.querySelector( '[data-oneab-next]' ), cfg;
	if ( ! box ) {
		return;
	}
	try {
		cfg = JSON.parse( box.getAttribute( 'data-oneab-next' ) );
	} catch ( e ) {
		return;
	}

	var v = cfg.b && Math.random() < 0.5 ? 'b' : 'a';

	if ( v === 'b' ) {
		var title = box.querySelector( '.oneab-next__title' );
		if ( title ) {
			title.textContent = cfg.b.title;
		}
		box.classList.remove( 'oneab-next--cards', 'oneab-next--list' );
		box.classList.add( 'oneab-next--' + cfg.b.layout );
	}

	// "Middle": after the middle one of the paragraphs that sit directly in the article, never inside lists, tables or boxes.
	if ( cfg[ v ].pos === 'middle' && box.parentNode ) {
		var paragraphs = [].filter.call( box.parentNode.children, function ( el ) {
			return el.tagName === 'P';
		} );
		if ( paragraphs.length >= 6 ) {
			var after = paragraphs[ Math.floor( paragraphs.length / 2 ) - 1 ];
			after.parentNode.insertBefore( box, after.nextSibling );
		}
	}

	// Counting only while a test runs and only where page views are counted at all.
	var c = window.oneabStats;
	if ( ! cfg.b || ! cfg.t || ! c || ! c.e || navigator.doNotTrack === '1' ) {
		return;
	}
	var robot = 0;
	try {
		robot = ( navigator.webdriver === true || /HeadlessChrome|PhantomJS/i.test( navigator.userAgent ) ) ? 1 : 0;
	} catch ( e ) {}

	function send( event ) {
		try {
			var b = new Blob( [ JSON.stringify( { t: 'ab', id: cfg.t, v: v, e: event, p: location.pathname, a: robot } ) ], { type: 'text/plain' } );
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

	box.addEventListener( 'click', function ( e ) {
		if ( e.target.closest && e.target.closest( 'a[href]' ) ) {
			send( 'c' );
		}
	}, true );
	box.addEventListener( 'auxclick', function ( e ) {
		if ( e.target.closest && e.target.closest( 'a[href]' ) ) {
			send( 'c' );
		}
	}, true );
}() );
