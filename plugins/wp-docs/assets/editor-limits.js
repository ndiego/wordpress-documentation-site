/**
 * No styling on docs pages. The editor recomputes its settings from the theme's global styles on the
 * client (overriding what the server sent), so the limits are applied where every control reads them:
 * the `blockEditor.useSetting.before` filter. Lists (palettes, font sizes, spacing sizes) become empty,
 * everything else under color, typography, spacing, border, dimensions, shadow and background is off.
 */
( function () {
	var lists = /^(color\.(palette|gradients|duotone)|typography\.(fontSizes|fontFamilies)|spacing\.(spacingSizes|units)|shadow\.presets)(\.|$)/;
	var off = /^(color|typography|spacing|border|dimensions|shadow|background)(\.|$)/;

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
