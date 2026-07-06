/**
 * Hubbee Token block (hubbee/token) — dynamic, server-rendered.
 *
 * No build step: uses the wp-* script handles as globals. The editor shows the
 * chosen token key; the real value is resolved server-side by TokenShortcode::
 * render_block() so it works without Elementor Pro.
 */
( function ( blocks, element, blockEditor, components, i18n ) {
	'use strict';

	var el = element.createElement;
	var __ = i18n.__;
	var useBlockProps = blockEditor.useBlockProps;
	var InspectorControls = blockEditor.InspectorControls;
	var TextControl = components.TextControl;
	var PanelBody = components.PanelBody;

	blocks.registerBlockType( 'hubbee/token', {
		apiVersion: 2,
		title: __( 'Hubbee Token', 'hubbee' ),
		description: __( 'Render a Hubbee content token by key.', 'hubbee' ),
		icon: 'editor-textcolor',
		category: 'text',
		attributes: {
			tokenKey: { type: 'string', default: '' },
			locale: { type: 'string', default: 'auto' },
			fallback: { type: 'string', default: '' }
		},
		edit: function ( props ) {
			var a = props.attributes;
			var blockProps = useBlockProps();

			return el(
				'div',
				blockProps,
				el(
					InspectorControls,
					{},
					el(
						PanelBody,
						{ title: __( 'Token', 'hubbee' ), initialOpen: true },
						el( TextControl, {
							label: __( 'Token key', 'hubbee' ),
							value: a.tokenKey,
							onChange: function ( v ) {
								props.setAttributes( { tokenKey: v } );
							}
						} ),
						el( TextControl, {
							label: __( 'Fallback text', 'hubbee' ),
							value: a.fallback,
							onChange: function ( v ) {
								props.setAttributes( { fallback: v } );
							}
						} )
					)
				),
				a.tokenKey
					? el( 'span', {}, '[' + a.tokenKey + ']' )
					: el(
						'span',
						{ style: { color: '#999' } },
						__( 'Select a Hubbee token', 'hubbee' )
					)
			);
		},
		// Dynamic block — output comes from the PHP render_callback.
		save: function () {
			return null;
		}
	} );
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.i18n );
