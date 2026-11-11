/* 1AB Stats: block that places one element. */
( function ( wp ) {
	var el = wp.element.createElement, data = window.oneabElements || { options: [] };
	wp.blocks.registerBlockType( 'oneab-stats/element', {
		edit: function ( props ) {
			var id = props.attributes.id || 0;
			var select = el( wp.components.SelectControl, {
				label: data.choose,
				value: id,
				options: data.options,
				onChange: function ( value ) {
					props.setAttributes( { id: parseInt( value, 10 ) || 0 } );
				}
			} );
			var link = el( 'p', {}, el( 'a', { href: data.manage, target: '_blank', rel: 'noopener' }, data.link ) );
			return el( 'div', wp.blockEditor.useBlockProps(),
				el( wp.blockEditor.InspectorControls, {}, el( wp.components.PanelBody, { title: data.title }, select, link ) ),
				id ? el( wp.components.Disabled, {}, el( wp.serverSideRender, { block: 'oneab-stats/element', attributes: props.attributes } ) )
					: el( wp.components.Placeholder, { icon: 'chart-bar', label: data.title, instructions: data.empty }, select, link )
			);
		},
		save: function () {
			return null;
		}
	} );
}( window.wp ) );
