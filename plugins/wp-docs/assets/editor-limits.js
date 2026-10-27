/**
 * No styling on docs pages. The editor recomputes its settings from the theme's global styles on the
 * client (overriding what the server sent), so the limits are applied where every control reads them:
 * the `blockEditor.useSetting.before` filter. Lists (palettes, font sizes, spacing sizes) become empty,
 * everything else under color, typography, spacing, border, dimensions, shadow and background is off.
 */
( function () {
	var lists = /^(color\.(palette|gradients|duotone)|typography\.(fontSizes|fontFamilies)|spacing\.(spacingSizes|units)|shadow\.presets)(\.|$)/;
	var off = /^(color|typography|spacing|border|dimensions|shadow|background)(\.|$)/;

	/* The blocks themselves: no typography, color, spacing, border, dimension, shadow, background,
	   alignment, custom class or anchor supports (the heading keeps its anchor: the id is part of its
	   markup, and the importer writes it). Core blocks register their supports on the client, so this is
	   where they are trimmed. */
	var trim = [ 'typography', 'color', 'spacing', 'dimensions', '__experimentalBorder', 'border', 'shadow', 'background', 'align', 'alignWide', 'customClassName', 'filter', 'position', 'layout', '__experimentalLayout' ];
	wp.hooks.addFilter( 'blocks.registerBlockType', 'wp-docs/limit-supports', function ( settings, name ) {
		if ( ! settings.supports || ( 0 !== name.indexOf( 'core/' ) && 'wporg/notice' !== name ) ) {
			return settings;
		}
		var supports = Object.assign( {}, settings.supports );
		trim.forEach( function ( key ) {
			delete supports[ key ];
		} );
		if ( 'core/heading' !== name ) {
			delete supports.anchor;
		}
		supports.customCSS       = false; // The per-block "Additional CSS" field (on by default).
		supports.customClassName = false; // "Additional CSS class(es)" (on by default too).
		return Object.assign( {}, settings, { supports: supports } );
	} );

	wp.hooks.addFilter( 'blockEditor.useSetting.before', 'wp-docs/no-styling', function ( result, path ) {
		if ( lists.test( path ) ) {
			return [];
		}
		if ( off.test( path ) ) {
			return false;
		}
		return result;
	} );
} )();
