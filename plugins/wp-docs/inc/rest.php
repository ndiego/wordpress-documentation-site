<?php
/**
 * Change (i): import webhook.
 *
 * `POST /wp-json/wp-docs/v1/import` with a shared secret schedules an immediate import, so a GitHub
 * Action can trigger the import on merge instead of waiting for the next 15-minute cron run.
 *
 * Authentication, either:
 * - header `X-WP-Docs-Secret: <secret>`; or
 * - GitHub's `X-Hub-Signature-256: sha256=<HMAC-SHA256 of the raw body with the secret>` (a repository
 *   webhook pointed straight at the endpoint).
 *
 * The secret is the WP_DOCS_WEBHOOK_SECRET constant (or the `wp_docs_webhook_secret` filter).
 * Without a secret the endpoint is disabled. Body (JSON or form): `manifest` (optional, a manifest
 * name; empty imports all), `force` (optional, ignore ETags).
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
					'manifest' => array(
						'type'    => 'string',
						'default' => '',
					),
					'force'    => array(
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
	 * Schedule the import.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle( WP_REST_Request $request ) {
		$manifest = sanitize_file_name( (string) $request->get_param( 'manifest' ) );
		$manifest = preg_replace( '/\.json$/', '', $manifest );
		if ( '' !== $manifest && ! in_array( $manifest, Manifests::names(), true ) ) {
			return new WP_Error( 'wp_docs_unknown_manifest', "Unknown manifest \"{$manifest}\".", array( 'status' => 400 ) );
		}

		$args = array( '' === $manifest ? null : $manifest, (bool) $request->get_param( 'force' ) );
		if ( ! wp_next_scheduled( Importer::CRON_HOOK, $args ) ) {
			wp_schedule_single_event( time(), Importer::CRON_HOOK, $args );
		}
		// Run the scheduled event now rather than on the next page view.
		spawn_cron();

		return new WP_REST_Response(
			array(
				'scheduled' => true,
				'manifest'  => '' === $manifest ? 'all' : $manifest,
				'force'     => $args[1],
			),
			202
		);
	}
}
