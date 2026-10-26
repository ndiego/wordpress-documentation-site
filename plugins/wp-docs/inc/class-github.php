<?php
/**
 * A small GitHub client for the reverse sync: read a file at a branch head, commit files on a branch
 * with a compare-and-swap on the files being replaced, create branches and pull requests.
 *
 * Uses the Git Data API (blobs, trees, commits, refs) so one commit can carry a page and the images
 * uploaded with it. A ref is only ever moved without force on the content branch, so a commit that
 * raced another one is rejected by GitHub and retried on the new head after the per-file check.
 *
 * @package WP_Docs
 */

namespace WP_Docs;

use WP_Error;

defined( 'WPINC' ) || die();

/**
 * GitHub API client.
 */
class GitHub {

	const API = 'https://api.github.com';

	/**
	 * Owner.
	 *
	 * @var string
	 */
	protected $owner;

	/**
	 * Repository.
	 *
	 * @var string
	 */
	protected $repo;

	/**
	 * Token.
	 *
	 * @var string
	 */
	protected $token;

	/**
	 * Constructor.
	 *
	 * @param string $owner Owner.
	 * @param string $repo  Repository.
	 * @param string $token Token with contents and pull requests write access.
	 */
	public function __construct( $owner, $repo, $token ) {
		$this->owner = $owner;
		$this->repo  = $repo;
		$this->token = $token;
	}

	/**
	 * The token the site uses for GitHub, from the WP_DOCS_GITHUB_TOKEN constant (filterable).
	 *
	 * @return string
	 */
	public static function token() {
		/** This filter is documented in inc/functions.php */
		return (string) apply_filters( 'wp_docs_github_token', defined( 'WP_DOCS_GITHUB_TOKEN' ) ? WP_DOCS_GITHUB_TOKEN : '' );
	}

	/**
	 * `owner/repo`.
	 *
	 * @return string
	 */
	public function full_name() {
		return $this->owner . '/' . $this->repo;
	}

	/**
	 * Git's blob SHA of a file's bytes.
	 *
	 * @param string $content Bytes.
	 * @return string
	 */
	public static function blob_sha( $content ) {
		return sha1( 'blob ' . strlen( $content ) . "\0" . $content );
	}

	/**
	 * One API request.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path   Path under the repository (`git/refs/heads/trunk`) or absolute (`/repos/...`).
	 * @param array|null $body   JSON body.
	 * @return array|WP_Error Decoded response.
	 */
	public function request( $method, $path, $body = null ) {
		$url  = self::API . ( '/' === $path[0] ? $path : "/repos/{$this->owner}/{$this->repo}/{$path}" );
		$args = array(
			'method'  => $method,
			'timeout' => 20,
			'headers' => array(
				'Accept'               => 'application/vnd.github+json',
				'User-Agent'           => 'wp-docs',
				'X-GitHub-Api-Version' => '2022-11-28',
			),
		);
		if ( '' !== $this->token ) {
			$args['headers']['Authorization'] = 'Bearer ' . $this->token; // Reads of a public repository work without it.
		}
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) {
			$message = is_array( $data ) && ! empty( $data['message'] ) ? (string) $data['message'] : "HTTP {$code}";
			return new WP_Error( 'github_' . $code, "GitHub: {$message} ({$method} {$path})", array( 'status' => $code, 'body' => $data ) );
		}
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Head commit SHA of a branch, or null when the branch does not exist.
	 *
	 * @param string $branch Branch.
	 * @return string|null|WP_Error
	 */
	public function branch_head( $branch ) {
		$ref = $this->request( 'GET', 'git/ref/heads/' . rawurlencode( $branch ) );
		if ( is_wp_error( $ref ) ) {
			return 404 === $ref->get_error_data()['status'] ? null : $ref;
		}
		return (string) ( $ref['object']['sha'] ?? '' );
	}

	/**
	 * A file at a ref: its blob SHA and content. Null when it does not exist there.
	 *
	 * @param string $path Repository path.
	 * @param string $ref  Branch or commit.
	 * @return array|null|WP_Error { sha, content }
	 */
	public function file( $path, $ref ) {
		$data = $this->request( 'GET', 'contents/' . Sources\encode_path( $path ) . '?ref=' . rawurlencode( $ref ) );
		if ( is_wp_error( $data ) ) {
			return 404 === $data->get_error_data()['status'] ? null : $data;
		}
		if ( empty( $data['sha'] ) || ! isset( $data['content'] ) ) {
			return new WP_Error( 'github_file', "GitHub returned no content for {$path}." );
		}
		return array(
			'sha'     => (string) $data['sha'],
			'content' => (string) base64_decode( str_replace( "\n", '', $data['content'] ) ),
		);
	}

	/**
	 * Commit files on top of a commit. Does not move any ref.
	 *
	 * @param string      $base_sha Parent commit.
	 * @param array       $files    path => content, or path => null to delete.
	 * @param string      $message  Commit message.
	 * @param array|null  $author   { name, email } of the WordPress user, or null for the token's user.
	 * @return string|WP_Error New commit SHA.
	 */
	public function commit( $base_sha, array $files, $message, $author = null ) {
		$base = $this->request( 'GET', 'git/commits/' . $base_sha );
		if ( is_wp_error( $base ) ) {
			return $base;
		}

		$tree = array();
		foreach ( $files as $path => $content ) {
			$entry = array(
				'path' => $path,
				'mode' => '100644',
				'type' => 'blob',
			);
			if ( null === $content ) {
				$entry['sha'] = null;
			} else {
				$blob = $this->request(
					'POST',
					'git/blobs',
					array(
						'content'  => base64_encode( $content ),
						'encoding' => 'base64',
					)
				);
				if ( is_wp_error( $blob ) ) {
					return $blob;
				}
				$entry['sha'] = $blob['sha'];
			}
			$tree[] = $entry;
		}

		$new_tree = $this->request(
			'POST',
			'git/trees',
			array(
				'base_tree' => $base['tree']['sha'],
				'tree'      => $tree,
			)
		);
		if ( is_wp_error( $new_tree ) ) {
			return $new_tree;
		}

		$body = array(
			'message' => $message,
			'tree'    => $new_tree['sha'],
			'parents' => array( $base_sha ),
		);
		if ( $author && ! empty( $author['name'] ) && ! empty( $author['email'] ) ) {
			$body['author'] = array(
				'name'  => $author['name'],
				'email' => $author['email'],
				'date'  => gmdate( 'c' ),
			);
		}
		$commit = $this->request( 'POST', 'git/commits', $body );
		return is_wp_error( $commit ) ? $commit : (string) $commit['sha'];
	}

	/**
	 * Move a branch to a commit. Without force, GitHub refuses when the branch moved in the meantime.
	 *
	 * @param string $branch Branch.
	 * @param string $sha    Commit.
	 * @param bool   $force  Allow a non fast-forward move.
	 * @return true|WP_Error `not_fast_forward` when refused.
	 */
	public function update_ref( $branch, $sha, $force = false ) {
		$result = $this->request(
			'PATCH',
			'git/refs/heads/' . rawurlencode( $branch ),
			array(
				'sha'   => $sha,
				'force' => (bool) $force,
			)
		);
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			if ( 422 === ( $data['status'] ?? 0 ) ) {
				return new WP_Error( 'not_fast_forward', $result->get_error_message() );
			}
			return $result;
		}
		return true;
	}

	/**
	 * Create a branch.
	 *
	 * @param string $branch Branch.
	 * @param string $sha    Commit.
	 * @return true|WP_Error
	 */
	public function create_ref( $branch, $sha ) {
		$result = $this->request(
			'POST',
			'git/refs',
			array(
				'ref' => 'refs/heads/' . $branch,
				'sha' => $sha,
			)
		);
		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Commit files on a branch, checking first that the files being replaced are still the versions
	 * the caller based its change on. Retried on the new head when the branch moves meanwhile.
	 *
	 * @param string     $branch  Branch.
	 * @param array      $files   path => content (or null to delete).
	 * @param array      $expect  path => blob SHA the content was derived from ('' when the file must not exist).
	 * @param string     $message Commit message.
	 * @param array|null $author  Author.
	 * @return array|WP_Error { commit, head, blobs: path => new blob SHA }. `wp_docs_stale` when a file changed.
	 */
	public function push( $branch, array $files, array $expect, $message, $author = null ) {
		for ( $attempt = 0; $attempt < 4; $attempt++ ) {
			$head = $this->branch_head( $branch );
			if ( is_wp_error( $head ) ) {
				return $head;
			}
			if ( null === $head ) {
				return new WP_Error( 'github_404', "The branch {$branch} does not exist in {$this->full_name()}." );
			}

			foreach ( $expect as $path => $sha ) {
				$current = $this->file( $path, $head );
				if ( is_wp_error( $current ) ) {
					return $current;
				}
				$current_sha = $current ? $current['sha'] : '';
				if ( $current_sha !== $sha ) {
					return new WP_Error( 'wp_docs_stale', $path, array( 'path' => $path, 'sha' => $current_sha ) );
				}
			}

			$commit = $this->commit( $head, $files, $message, $author );
			if ( is_wp_error( $commit ) ) {
				return $commit;
			}
			$moved = $this->update_ref( $branch, $commit );
			if ( true === $moved ) {
				$blobs = array();
				foreach ( $files as $path => $content ) {
					$blobs[ $path ] = null === $content ? '' : self::blob_sha( $content );
				}
				return array(
					'commit' => $commit,
					'head'   => $head,
					'blobs'  => $blobs,
				);
			}
			if ( 'not_fast_forward' !== $moved->get_error_code() ) {
				return $moved;
			}
			// The branch moved while we were committing: check the files again on the new head.
		}
		return new WP_Error( 'wp_docs_busy', "The branch {$branch} kept moving; try again." );
	}

	/**
	 * The open pull request from a branch, if any.
	 *
	 * @param string $branch Head branch.
	 * @return array|null|WP_Error { number, html_url, ... }
	 */
	public function open_pull( $branch ) {
		$pulls = $this->request( 'GET', 'pulls?state=open&per_page=1&head=' . rawurlencode( $this->owner . ':' . $branch ) );
		if ( is_wp_error( $pulls ) ) {
			return $pulls;
		}
		return $pulls[0] ?? null;
	}

	/**
	 * Open a pull request.
	 *
	 * @param string $branch Head branch.
	 * @param string $base   Base branch.
	 * @param string $title  Title.
	 * @param string $body   Body.
	 * @return array|WP_Error
	 */
	public function create_pull( $branch, $base, $title, $body ) {
		return $this->request(
			'POST',
			'pulls',
			array(
				'head'  => $branch,
				'base'  => $base,
				'title' => $title,
				'body'  => $body,
			)
		);
	}

	/**
	 * Update a pull request's title and body.
	 *
	 * @param int    $number Number.
	 * @param string $title  Title.
	 * @param string $body   Body.
	 * @return array|WP_Error
	 */
	public function update_pull( $number, $title, $body ) {
		return $this->request(
			'PATCH',
			'pulls/' . (int) $number,
			array(
				'title' => $title,
				'body'  => $body,
			)
		);
	}
}
