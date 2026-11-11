/* 1AB Stats: image picker and field switching on the elements tab. */
jQuery( function ( $ ) {
	var strings = window.oneabAdmin || {};

	function show() {
		var type = $( '.oneab-type' ).val();
		$( '.oneab-field' ).each( function () {
			$( this ).toggle( ( ' ' + $( this ).data( 'types' ) + ' ' ).indexOf( ' ' + type + ' ' ) !== -1 );
		} );
	}
	$( '.oneab-type' ).on( 'change', show );
	show();

	$( '.oneab-pick__choose' ).on( 'click', function () {
		var box = $( this ).closest( '.oneab-pick' );
		var frame = wp.media( { title: strings.pick, button: { text: strings.use }, library: { type: 'image' }, multiple: false } );
		frame.on( 'select', function () {
			var image = frame.state().get( 'selection' ).first().toJSON();
			var size = image.sizes && image.sizes.medium ? image.sizes.medium.url : image.url;
			box.find( '.oneab-pick__id' ).val( image.id );
			box.find( '.oneab-pick__preview' ).attr( 'src', size ).prop( 'hidden', false );
		} );
		frame.open();
	} );
	$( '.oneab-pick__clear' ).on( 'click', function () {
		var box = $( this ).closest( '.oneab-pick' );
		box.find( '.oneab-pick__id' ).val( '' );
		box.find( '.oneab-pick__preview' ).attr( 'src', '' ).prop( 'hidden', true );
	} );
	$( '.oneab-delete' ).on( 'click', function ( e ) {
		if ( ! window.confirm( $( this ).text() + '?' ) ) {
			e.preventDefault();
		}
	} );
} );
