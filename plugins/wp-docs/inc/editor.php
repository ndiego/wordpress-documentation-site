<?php
/**
 * The block editor for the `docs` post type: only the blocks that round-trip to Markdown, no styling
 * Markdown cannot carry, the WordPress.org notice block for callouts, and no deleting (pages are
 * removed on GitHub).
 *
 * @package WP_Docs
 */

namespace WP_Docs;

defined( 'WPINC' ) || die();

add_filter( 'allowed_block_types_all', __NAMESPACE__ . '\filter_allowed_block_types', 10, 2 );
add_filter( 'block_editor_settings_all', __NAMESPACE__ . '\filter_editor_settings', 10, 2 );
add_action( 'init', __NAMESPACE__ . '\register_notice_block', 11 );
add_filter( 'register_block_type_args', __NAMESPACE__ . '\filter_notice_block_args', 10, 2 );
add_filter( 'map_meta_cap', __NAMESPACE__ . '\forbid_delete', 10, 4 );
add_action( 'enqueue_block_editor_assets', __NAMESPACE__ . '\enqueue_editor_limits' );

/**
 * The blocks a docs page may hold: every one has a Markdown form the sync can write back.
 *
 * @return string[]
 */
function allowed_blocks() {
	$blocks = array(
		'core/paragraph',
		'core/heading',
		'core/list',
		'core/list-item',
		'core/quote',
		'core/code',
		'core/preformatted',
		'core/table',
		'core/image',
		'core/separator',
		'core/details',
		'core/html',
		'wporg/notice',
	);

	/**
	 * Filters the blocks allowed in the editor for the docs post type.
	 *
	 * @param string[] $blocks Block names.
	 */
	return (array) apply_filters( 'wp_docs_allowed_blocks', $blocks );
}

/**
 * Whether an editor context is for a docs page.
 *
 * @param \WP_Block_Editor_Context $context Context.
 * @return bool
 */
function is_docs_context( $context ) {
	return $context instanceof \WP_Block_Editor_Context && $context->post instanceof \WP_Post && POST_TYPE === $context->post->post_type;
}

/**
 * Limit the inserter to the allowed blocks on docs pages.
 *
 * @param bool|string[]            $allowed Allowed block types.
 * @param \WP_Block_Editor_Context $context Context.
 * @return bool|string[]
 */
function filter_allowed_block_types( $allowed, $context ) {
	return is_docs_context( $context ) ? allowed_blocks() : $allowed;
}

/**
 * Turn off colors, typography, spacing, borders, dimensions and shadows on docs pages: Markdown has no
 * place for them, and a block carrying them could not be written back.
 *
 * @param array                    $settings Editor settings.
 * @param \WP_Block_Editor_Context $context  Context.
 * @return array
 */
function filter_editor_settings( $settings, $context ) {
	if ( ! is_docs_context( $context ) ) {
		return $settings;
	}

	$features = isset( $settings['__experimentalFeatures'] ) && is_array( $settings['__experimentalFeatures'] ) ? $settings['__experimentalFeatures'] : array();

	$features['color'] = array_merge(
		isset( $features['color'] ) && is_array( $features['color'] ) ? $features['color'] : array(),
		array(
			'custom'         => false,
			'customGradient' => false,
			'palette'        => array(
				'default' => array(),
				'theme'   => array(),
				'custom'  => array(),
			),
			'gradients'      => array(
				'default' => array(),
				'theme'   => array(),
				'custom'  => array(),
			),
			'duotone'        => array(
				'default' => array(),
				'theme'   => array(),
				'custom'  => array(),
			),
			'customDuotone'  => false,
			'defaultPalette' => false,
			'defaultGradients' => false,
			'defaultDuotone' => false,
			'link'           => false,
			'text'           => false,
			'background'     => false,
			'button'         => false,
			'heading'        => false,
			'caption'        => false,
		)
	);
	$features['typography'] = array_merge(
		isset( $features['typography'] ) && is_array( $features['typography'] ) ? $features['typography'] : array(),
		array(
			'customFontSize'   => false,
			'defaultFontSizes' => false,
			'fontStyle'        => false,
			'fontWeight'       => false,
			'letterSpacing'    => false,
			'lineHeight'       => false,
			'textAlign'        => false,
			'textColumns'      => false,
			'textDecoration'   => false,
			'textTransform'    => false,
			'writingMode'      => false,
			'dropCap'          => false,
			'fluid'            => false,
		)
	);
	$features['spacing'] = array_merge(
		isset( $features['spacing'] ) && is_array( $features['spacing'] ) ? $features['spacing'] : array(),
		array(
			'margin'            => false,
			'padding'           => false,
			'blockGap'          => false,
			'customSpacingSize' => false,
			'defaultSpacingSizes' => false,
		)
	);
	$features['border'] = array(
		'color'  => false,
		'radius' => false,
		'style'  => false,
		'width'  => false,
	);
	$features['dimensions'] = array(
		'aspectRatio' => false,
		'minHeight'   => false,
	);
	$features['shadow'] = array(
		'defaultPresets' => false,
		'presets'        => array(
			'default' => array(),
			'theme'   => array(),
			'custom'  => array(),
		),
	);
	$features['background'] = array(
		'backgroundImage' => false,
		'backgroundSize'  => false,
	);

	$settings['__experimentalFeatures'] = $features;

	// Legacy flags, still read by some controls.
	$settings['disableCustomColors']    = true;
	$settings['disableCustomFontSizes'] = true;
	$settings['disableCustomGradients'] = true;
	$settings['alignWide']              = false;
	$settings['colors']                 = array();
	$settings['gradients']              = array();
	$settings['codeEditingEnabled']     = true;

	return $settings;
}

/**
 * The client-side half of filter_editor_settings(): the editor rebuilds `__experimentalFeatures` from the
 * theme's global styles after load, so the limits are applied again through `blockEditor.useSetting.before`.
 */
function enqueue_editor_limits() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || POST_TYPE !== $screen->post_type ) {
		return;
	}
	$file = dirname( __DIR__ ) . '/assets/editor-limits.js';
	// Core renders a block toolbar group for every BlockControls fill, even one whose controls all
	// render nothing. The paragraph's "block" group holds only the text alignment control and the RTL
	// control, so with text alignment off (as here, or with theme.json typography.textAlign false) an
	// empty group shows between the mover and the formatting buttons. Hide a group whose slot is empty
	// until that is fixed upstream.
	wp_add_inline_style(
		'wp-edit-blocks',
		'.block-editor-block-toolbar > .components-toolbar-group:has(> .block-editor-block-toolbar__slot:empty) { display: none; }'
	);
	wp_enqueue_script(
		'wp-docs-editor-limits',
		plugins_url( 'assets/editor-limits.js', dirname( __DIR__ ) . '/wp-docs.php' ),
		array( 'wp-hooks', 'wp-block-editor' ),
		file_exists( $file ) ? (string) filemtime( $file ) : '0',
		true
	);
}

/**
 * Register the vendored WordPress.org notice block (callouts) when the real one is not there.
 */
function register_notice_block() {
	if ( \WP_Block_Type_Registry::get_instance()->is_registered( 'wporg/notice' ) ) {
		return;
	}
	register_block_type( dirname( __DIR__ ) . '/vendor/notice/build' );
}

/**
 * The notice block without alignment, spacing, typography, custom classes or raw HTML editing, so a
 * save never carries style attributes the Markdown has no place for.
 *
 * @param array  $args Block type arguments.
 * @param string $name Block name.
 * @return array
 */
function filter_notice_block_args( $args, $name ) {
	if ( 'wporg/notice' !== $name ) {
		return $args;
	}
	$supports = isset( $args['supports'] ) && is_array( $args['supports'] ) ? $args['supports'] : array();
	unset( $supports['align'], $supports['spacing'], $supports['typography'] );
	$supports['customClassName'] = false;
	$supports['html']            = false;
	$args['supports']            = $supports;
	return $args;
}

/**
 * Nobody deletes a docs page here: a page is removed by deleting its Markdown on GitHub.
 *
 * @param string[] $caps    Required capabilities.
 * @param string   $cap     Capability being checked.
 * @param int      $user_id User ID.
 * @param array    $args    Arguments (the post ID first).
 * @return string[]
 */
function forbid_delete( $caps, $cap, $user_id, $args ) {
	if ( 'delete_post' !== $cap || empty( $args[0] ) || '' === (string) get_post_meta( (int) $args[0], '_wp_docs_id', true ) ) {
		return $caps;
	}
	if ( POST_TYPE === get_post_type( (int) $args[0] ) ) {
		return array( 'do_not_allow' );
	}
	return $caps;
}
