<?php
/**
 * Plugin Name: WordPress.org Docs hub
 * Description: The `wp-docs` post type and the hub importer: a subclass of the WordPress.org Markdown importer (wporg-markdown) that imports manifest/*.json, with GitHub alerts, front matter, image sideloading, link rewriting, redirects, removals, a webhook and block output.
 * Version: 0.1.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Author: WordPress.org
 * License: GPLv2 or later
 * Text Domain: wporg
 *
 * @package WP_Docs
 */

namespace WP_Docs;

defined( 'WPINC' ) || die();

const PLUGIN_FILE = __FILE__;

// Pure modules (no WordPress dependency; tested with plain PHP in tests/).
require_once __DIR__ . '/inc/sources.php';
require_once __DIR__ . '/inc/manifest.php';
require_once __DIR__ . '/inc/transforms/front-matter.php';
require_once __DIR__ . '/inc/transforms/alerts.php';
require_once __DIR__ . '/inc/transforms/links.php';
require_once __DIR__ . '/inc/transforms/images.php';
require_once __DIR__ . '/inc/transforms/blocks.php';
require_once __DIR__ . '/inc/pipeline.php';

// WordPress integration.
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/post-type.php';
require_once __DIR__ . '/inc/template-tags.php';
require_once __DIR__ . '/inc/local-sources.php';
require_once __DIR__ . '/inc/redirects.php';
require_once __DIR__ . '/inc/permalinks.php';

Local_Sources::init();
Redirects::init();
Permalinks::init();

register_activation_hook( __FILE__, __NAMESPACE__ . '\\activate' );
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\\deactivate' );

/**
 * Register the post type and flush rewrite rules so /docs/ and the page URLs resolve at once.
 */
function activate() {
	register_post_type();
	flush_rewrite_rules();
}

/**
 * Stop the recurring import.
 */
function deactivate() {
	wp_clear_scheduled_hook( 'wp_docs_import' );
	flush_rewrite_rules();
}

// The importer subclass needs the vendored wporg-markdown plugin, which loads after this one.
add_action( 'plugins_loaded', __NAMESPACE__ . '\load_importer' );

/**
 * Load the importer once wporg-markdown is available.
 */
function load_importer() {
	if ( ! class_exists( '\WordPressdotorg\Markdown\Importer' ) ) {
		add_action(
			'admin_notices',
			static function () {
				echo '<div class="notice notice-error"><p>WordPress.org Docs hub needs the WPORG Markdown Importer plugin (wporg-markdown) to be active.</p></div>';
			}
		);
		return;
	}

	require_once __DIR__ . '/inc/class-importer.php';
	require_once __DIR__ . '/inc/rest.php';

	Importer::instance()->init();
	Webhook::init();

	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		require_once __DIR__ . '/inc/cli.php';
		\WP_CLI::add_command( 'wp-docs', CLI::class );
	}
}
