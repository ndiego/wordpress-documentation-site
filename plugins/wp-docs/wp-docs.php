<?php
/**
 * Plugin Name: WordPress Documentation
 * Description: Imports the WordPress user documentation from Markdown on GitHub into the `wp-docs` post type (a subclass of the WordPress.org Markdown importer, wporg-markdown), serves every page as Markdown for agents with llms.txt and a JSON index, and provides the WordPress.org global header and footer.
 * Version: 0.2.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Author: WordPress.org
 * License: GPLv2 or later
 * Text Domain: wp-docs
 * Update URI: false
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
require_once __DIR__ . '/inc/transforms/anchors.php';
require_once __DIR__ . '/inc/transforms/links.php';
require_once __DIR__ . '/inc/transforms/images.php';
require_once __DIR__ . '/inc/transforms/blocks.php';
require_once __DIR__ . '/inc/transforms/agent-markdown.php';
require_once __DIR__ . '/inc/pipeline.php';

// WordPress integration.
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/post-type.php';
require_once __DIR__ . '/inc/template-tags.php';
require_once __DIR__ . '/inc/local-sources.php';
require_once __DIR__ . '/inc/redirects.php';
require_once __DIR__ . '/inc/permalinks.php';
require_once __DIR__ . '/inc/agents.php';
require_once __DIR__ . '/inc/editor-notice.php';
require_once __DIR__ . '/inc/global-header-footer.php';

Local_Sources::init();
Redirects::init();
Permalinks::init();
Agents::init();

register_activation_hook( __FILE__, __NAMESPACE__ . '\\activate' );
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\\deactivate' );

/**
 * Register the post type and the endpoint rules, then flush, so /docs/ and the page URLs resolve at once.
 */
function activate() {
	register_post_type();
	Agents::add_rewrite_rules();
	flush_rewrite_rules();
}

/**
 * Stop the recurring import.
 */
function deactivate() {
	wp_clear_scheduled_hook( 'wp_docs_import' );
	delete_option( 'wp_docs_rules_version' );
	flush_rewrite_rules();
}

// The importer subclass needs the vendored wporg-markdown plugin, which may load after this one.
add_action( 'plugins_loaded', __NAMESPACE__ . '\\load_importer' );

/**
 * Load the importer once wporg-markdown is available.
 */
function load_importer() {
	if ( ! class_exists( '\WordPressdotorg\Markdown\Importer' ) ) {
		add_action(
			'admin_notices',
			static function () {
				echo '<div class="notice notice-error"><p>WordPress Documentation needs the WPORG Markdown Importer plugin (wporg-markdown) to be active.</p></div>';
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
