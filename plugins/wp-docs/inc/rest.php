<?php
/**
 * Change (i): import webhook.
 *
 * `POST /wp-json/wp-docs/v1/import` with a shared secret runs the import at once (manifest stage, then
 * Markdown stage) and answers with what it did, so the content repository's CI can trigger it on every push
 * to `trunk` instead of waiting for the next 15-minute cron run.
 *
 * Authentication, either:
 * - header `X-WP-Docs-Secret: <secret>`; or
 * - GitHub's `X-Hub-Signature-256: sha256=<HMAC-SHA256 of the raw body with the secret>` (a repository
 *   webhook pointed straight at the endpoint).
 *
 * The secret is the WP_DOCS_WEBHOOK_SECRET constant (or the `wp_docs_webhook_secret` filter). Without a
 * secret the endpoint is disabled (503). Body (JSON or form, optional): `force` (ignore ETags).
 *
 * Response: `{ imported, warnings, pages, unchanged, errors, removed, time }` where `imported` is the
 * number of pages whose content changed and `warnings` the list of warning messages.
 *
 * @package WP_Docs
 */

namespace WP_Docs;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'WPINC' ) || die();

/**
 * Webhook route.
 */
class Webhook {

	/**
	 * Hook up.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register the route.
	 */
	public static function register_routes() {
		register_rest_route(
			'wp-docs/v1',
			'/import',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => array( __CLASS__, 'authorize' ),
				'args'                => array(
					'force' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
	}

	/**
	 * The shared secret.
	 *
	 * @return string
	 */
	public static function secret() {
		/**
		 * Filters the webhook secret. Empty disables the endpoint.
		 *
		 * @param string $secret Default: the WP_DOCS_WEBHOOK_SECRET constant.
		 */
		return (string) apply_filters( 'wp_docs_webhook_secret', defined( 'WP_DOCS_WEBHOOK_SECRET' ) ? WP_DOCS_WEBHOOK_SECRET : '' );
	}

	/**
	 * Check the secret.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function authorize( WP_REST_Request $request ) {
		$secret = self::secret();
		if ( '' === $secret ) {
			return new WP_Error( 'wp_docs_webhook_disabled', 'The import webhook is not configured.', array( 'status' => 503 ) );
		}

		$given = (string) $request->get_header( 'x_wp_docs_secret' );
		if ( '' !== $given && hash_equals( $secret, $given ) ) {
			return true;
		}

		$signature = (string) $request->get_header( 'x_hub_signature_256' );
		if ( '' !== $signature && hash_equals( 'sha256=' . hash_hmac( 'sha256', (string) $request->get_body(), $secret ), $signature ) ) {
			return true;
		}

		return new WP_Error( 'wp_docs_webhook_forbidden', 'Invalid or missing secret.', array( 'status' => 403 ) );
	}

	/**
	 * Run the import.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle( WP_REST_Request $request ) {
		$summary = Importer::instance()->run( array( 'force' => (bool) $request->get_param( 'force' ) ) );
		if ( is_wp_error( $summary ) ) {
			$status = false !== strpos( $summary->get_error_message(), 'still running' ) ? 409 : 500;
			return new WP_Error( 'wp_docs_import_failed', $summary->get_error_message(), array( 'status' => $status ) );
		}

		return new WP_REST_Response(
			array(
				'imported'  => (int) $summary['updated'],
				'warnings'  => array_values( (array) $summary['warning_list'] ),
				'pages'     => (int) $summary['pages'],
				'unchanged' => (int) $summary['unchanged'],
				'errors'    => (int) $summary['errors'],
				'removed'   => array_values( (array) $summary['removed'] ),
				'time'      => $summary['time'],
			),
			200
		);
	}
}
