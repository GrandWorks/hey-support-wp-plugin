/* global wp */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.blocks || ! wp.element || ! wp.blockEditor ) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var registerBlockType = wp.blocks.registerBlockType;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var TextControl = wp.components.TextControl;
	var ToggleControl = wp.components.ToggleControl;
	var __ = wp.i18n.__;

	registerBlockType( 'hey-support-ai-chatbot/embed', {
		edit: function ( props ) {
			var attrs = props.attributes;
			var setAttributes = props.setAttributes;
			var blockProps = useBlockProps( {
				style: {
					border: '1px dashed #ccc',
					padding: '16px',
					borderRadius: '8px',
					background: '#fafaf8',
					color: '#333'
				}
			} );

			return el(
				Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Hey Support', 'hey-support-ai-chatbot' ), initialOpen: true },
						el( TextControl, {
							label: __( 'Chatbot ID (override)', 'hey-support-ai-chatbot' ),
							help: __( 'Leave empty to use the chatbot configured in Settings → Hey Support.', 'hey-support-ai-chatbot' ),
							value: attrs.chatbotId || '',
							onChange: function ( v ) { setAttributes( { chatbotId: v } ); }
						} ),
						el( ToggleControl, {
							label: __( 'Auto-open on page load', 'hey-support-ai-chatbot' ),
							checked: !! attrs.autoOpen,
							onChange: function ( v ) { setAttributes( { autoOpen: !! v } ); }
						} )
					)
				),
				el(
					'div',
					blockProps,
					el( 'strong', null, 'Hey Support' ),
					' — ',
					attrs.chatbotId
						? __( 'Chatbot: ', 'hey-support-ai-chatbot' ) + attrs.chatbotId
						: __( 'Using the chatbot from Settings → Hey Support.', 'hey-support-ai-chatbot' )
				)
			);
		},
		save: function () {
			// Server-rendered via render_callback in PHP.
			return null;
		}
	} );
}( window.wp ) );
