<?php
/**
 * REST API controller for mrmurphy-apps.
 *
 * @package MrMurphyApps
 */

defined( 'ABSPATH' ) || exit;

/**
 * REST API endpoints for app management.
 *
 * Endpoints under `mrmurphy-apps/v1`:
 *   GET    /instructions       — Public TXT guide for agents
 *   POST   /apps               — Create app (optional zip upload)
 *   GET    /apps               — List all apps
 *   GET    /apps/{slug}        — Get app details
 *   POST   /apps/{slug}/upload — Upload/replace zip
 *   POST   /apps/{slug}/publish — Toggle publish/draft
 *   DELETE /apps/{slug}        — Delete app + files
 */
class MRMurphy_Apps_REST {

	/** @var MRMurphy_Apps_Storage */
	private $storage;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->storage = new MRMurphy_Apps_Storage();

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register all REST routes.
	 */
	public function register_routes() {
		$namespace = 'mrmurphy-apps/v1';

		// GET /instructions — public, no auth required.
		register_rest_route(
			$namespace,
			'/instructions',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_instructions' ),
					'permission_callback' => '__return_true',
				),
			)
		);

		// POST /apps — create app.
		register_rest_route(
			$namespace,
			'/apps',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_app' ),
					'permission_callback' => array( $this, 'check_permissions' ),
					'args'                => array(
						'title'       => array(
							'required'    => true,
							'type'        => 'string',
							'description' => __( 'App title.', 'mrmurphy-apps' ),
						),
						'slug'        => array(
							'required'    => false,
							'type'        => 'string',
							'description' => __( 'App slug. Auto-generated from title if omitted.', 'mrmurphy-apps' ),
						),
						'zip_base64'  => array(
							'required'    => false,
							'type'        => 'string',
							'description' => __( 'Base64-encoded zip to upload at creation time.', 'mrmurphy-apps' ),
						),
						'entry_file'  => array(
							'required'    => false,
							'type'        => 'string',
							'description' => __( 'Entry file. Auto-detected if zip provided.', 'mrmurphy-apps' ),
						),
					),
				),
			)
		);

		// GET /apps — list all apps.
		register_rest_route(
			$namespace,
			'/apps',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_apps' ),
					'permission_callback' => array( $this, 'check_permissions' ),
				),
			)
		);

		// GET /apps/{slug} — get app details.
		register_rest_route(
			$namespace,
			'/apps/(?P<slug>[^/]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_app' ),
					'permission_callback' => array( $this, 'check_permissions' ),
					'args'                => array(
						'slug' => array(
							'required' => true,
							'type'     => 'string',
						),
					),
				),
			)
		);

		// POST /apps/{slug}/upload — upload/replace zip.
		register_rest_route(
			$namespace,
			'/apps/(?P<slug>[^/]+)/upload',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'upload_zip' ),
					'permission_callback' => array( $this, 'check_permissions' ),
					'args'                => array(
						'zip_base64'  => array(
							'required'    => true,
							'type'        => 'string',
							'description' => __( 'Base64-encoded zip archive.', 'mrmurphy-apps' ),
						),
						'entry_file'  => array(
							'required'    => false,
							'type'        => 'string',
							'description' => __( 'Entry file. Auto-detected if omitted.', 'mrmurphy-apps' ),
						),
					),
				),
			)
		);

		// POST /apps/{slug}/publish — toggle publish/draft.
		register_rest_route(
			$namespace,
			'/apps/(?P<slug>[^/]+)/publish',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'toggle_publish' ),
					'permission_callback' => array( $this, 'check_permissions' ),
					'args'                => array(
						'status' => array(
							'required'    => true,
							'type'        => 'string',
							'enum'        => array( 'publish', 'draft' ),
							'description' => __( 'Desired status: "publish" or "draft".', 'mrmurphy-apps' ),
						),
					),
				),
			)
		);

		// DELETE /apps/{slug} — delete app + files.
		register_rest_route(
			$namespace,
			'/apps/(?P<slug>[^/]+)',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_app' ),
					'permission_callback' => array( $this, 'check_permissions' ),
					'args'                => array(
						'slug' => array(
							'required' => true,
							'type'     => 'string',
						),
					),
				),
			)
		);
	}

	/**
	 * Check if the current user has permission.
	 *
	 * @return bool
	 */
	public function check_permissions() {
		return is_user_logged_in() && current_user_can( 'manage_options' );
	}

	/* ------------------------------------------------------------------ */
	/*  GET /instructions                                                 */
	/* ------------------------------------------------------------------ */

	/**
	 * Return a plain-text guide for agents.
	 *
	 * @return WP_REST_Response
	 */
	public function get_instructions() {
		$instructions = $this->build_instructions();

		return new WP_REST_Response( $instructions, 200, array( 'Content-Type' => 'text/plain; charset=utf-8' ) );
	}

	/**
	 * Build the instructions text.
	 *
	 * @return string
	 */
	private function build_instructions() {
		$base_url = home_url( '/' . MRMURPHY_APPS_ROUTE_PREFIX . '/' );

		return <<<TXT
MrMurphy Apps REST API — Agent Guide
======================================

Base URL: {base_url}
API Base: {api_base}
Version:  {version}

Authentication
--------------
All endpoints except GET /instructions require WordPress Application Passwords.

  curl -u "username:application-password" https://example.com/wp-json/mrmurphy-apps/v1/apps

Create a dedicated user with the "manage_options" capability for API access.
Application passwords are available under your profile in wp-admin.

Endpoints
---------

1. GET {api_base}instructions
   Public — no auth required. Returns this guide as text/plain.

2. POST {api_base}apps
   Create a new app.
   Body (JSON):
     {{
       "title": "My App",
       "slug": "my-app",
       "zip_base64": "<base64-encoded zip>",
       "entry_file": "index.html"
     }}
   slug, zip_base64, and entry_file are optional.
   Response: {{ "id", "slug", "title", "status", "public_url", "entry_file", "file_count" }}

3. GET {api_base}apps
   List all apps (drafts + published).
   Response: [ {{ "id", "slug", "title", "status", "public_url", "entry_file", "file_count" }} ]

4. GET {api_base}apps/{{slug}}
   Get details for a specific app.
   Response: {{ "id", "slug", "title", "status", "public_url", "entry_file", "files", "file_count", "stats" }}

5. POST {api_base}apps/{{slug}}/upload
   Upload or replace the zip for an existing app.
   Body (JSON):
     {{
       "zip_base64": "<base64-encoded zip>",
       "entry_file": "index.html"
     }}
   entry_file is optional — auto-detected from zip contents.

6. POST {api_base}apps/{{slug}}/publish
   Toggle an app between draft and published.
   Body (JSON):
     {{ "status": "publish" }}
   status can be "publish" or "draft".

7. DELETE {api_base}apps/{{slug}}
   Delete an app post and all its stored files permanently.

Public URL Pattern
------------------
https://example.com/apps/{{slug}}/

Build Process
-------------
Run ./build.sh in the plugin directory to create the zip.
With --bump flag, it increments the patch version.
Output: dist/mrmurphy-apps-{{version}}.zip

Zip contents should be static HTML/JS/CSS/asset files.
No server-side code (PHP, Python, etc.).

Error Responses
---------------
400 — Bad request (missing/invalid parameters)
401 — Unauthorized (not logged in)
403 — Forbidden (insufficient permissions)
404 — App not found
409 — Conflict (e.g., slug already exists)
500 — Server error

TXT;
	}

	/* ------------------------------------------------------------------ */
	/*  POST /apps — Create app                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Create a new app.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_app( WP_REST_Request $request ) {
		$title = sanitize_text_field( $request->get_param( 'title' ) );

		if ( '' === $title ) {
			return new WP_Error( 'missing_title', __( 'Title is required.', 'mrmurphy-apps' ), array( 'status' => 400 ) );
		}

		$slug = $request->get_param( 'slug' );
		if ( is_string( $slug ) && '' !== $slug ) {
			$slug = sanitize_title( $slug );
		} else {
			$slug = sanitize_title( $title );
		}

		if ( '' === $slug ) {
			return new WP_Error( 'invalid_slug', __( 'Invalid slug.', 'mrmurphy-apps' ), array( 'status' => 400 ) );
		}

		// Check for duplicate slug (any status).
		$existing = get_posts(
			array(
				'name'           => $slug,
				'post_type'      => 'mrmurphy_app',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);

		if ( ! empty( $existing ) ) {
			return new WP_Error( 'duplicate_slug', sprintf( __( 'An app with the slug "%s" already exists.', 'mrmurphy-apps' ), $slug ), array( 'status' => 409 ) );
		}

		$post_id = wp_insert_post(
			array(
				'post_type'    => 'mrmurphy_app',
				'post_title'   => $title,
				'post_name'    => $slug,
				'post_status'  => 'draft',
				'post_author'  => get_current_user_id(),
				'post_content' => '',
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$zip_base64 = $request->get_param( 'zip_base64' );
		$entry_file = $request->get_param( 'entry_file' );

		if ( is_string( $zip_base64 ) && '' !== $zip_base64 ) {
			$result = $this->decode_and_import( $post_id, $zip_base64, $entry_file );

			if ( is_wp_error( $result ) ) {
				// Clean up the created post.
				wp_delete_post( $post_id, true );
				return $result;
			}

			$entry_file = $result; // Updated entry file from import.
		}

		return $this->app_response( $post_id, $entry_file );
	}

	/* ------------------------------------------------------------------ */
	/*  GET /apps — List apps                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * List all apps.
	 *
	 * @return WP_REST_Response
	 */
	public function list_apps() {
		$posts = get_posts(
			array(
				'post_type'      => 'mrmurphy_app',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$results = array();

		foreach ( $posts as $post ) {
			$entry_file = MRMurphy_Apps_CPT::get_entry_file( $post->ID );
			$files      = $this->storage->list_files( $post->post_name );

			$results[] = array(
				'id'         => (int) $post->ID,
				'slug'       => $post->post_name,
				'title'      => $post->post_title,
				'status'     => $post->post_status,
				'public_url' => trailingslashit( home_url( '/' . MRMURPHY_APPS_ROUTE_PREFIX . '/' . $post->post_name ) ),
				'entry_file' => $entry_file,
				'file_count' => count( $files ),
			);
		}

		return new WP_REST_Response( $results, 200 );
	}

	/* ------------------------------------------------------------------ */
	/*  GET /apps/{slug} — Get app details                                  */
	/* ------------------------------------------------------------------ */

	/**
	 * Get details for a specific app.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_app( WP_REST_Request $request ) {
		$slug = sanitize_title( $request->get_param( 'slug' ) );

		if ( '' === $slug ) {
			return new WP_Error( 'invalid_slug', __( 'Invalid slug.', 'mrmurphy-apps' ), array( 'status' => 400 ) );
		}

		// Look up by any status (not just published).
		$posts = get_posts(
			array(
				'name'           => $slug,
				'post_type'      => 'mrmurphy_app',
				'post_status'    => 'any',
				'posts_per_page' => 1,
			)
		);

		if ( empty( $posts ) ) {
			return new WP_Error( 'app_not_found', __( 'App not found.', 'mrmurphy-apps' ), array( 'status' => 404 ) );
		}

		$post  = $posts[0];
		$entry = MRMurphy_Apps_CPT::get_entry_file( $post->ID );
		$files = $this->storage->list_files( $post->post_name );

		$stats = array();
		$stats_obj = new MRMurphy_Apps_Stats();
		$summary = $stats_obj->get_app_summary( $post->ID );

		$stats = array(
			'total_visits'  => $summary['total'],
			'unique_visitors' => $summary['unique'],
			'last_7_days'   => $summary['last_7_days'],
		);

		return new WP_REST_Response(
			array(
				'id'         => (int) $post->ID,
				'slug'       => $post->post_name,
				'title'      => $post->post_title,
				'status'     => $post->post_status,
				'public_url' => trailingslashit( home_url( '/' . MRMURPHY_APPS_ROUTE_PREFIX . '/' . $post->post_name ) ),
				'entry_file' => $entry,
				'files'      => $files,
				'file_count' => count( $files ),
				'stats'      => $stats,
			),
			200
		);
	}

	/* ------------------------------------------------------------------ */
	/*  POST /apps/{slug}/upload — Upload zip                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Upload or replace a zip for an existing app.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload_zip( WP_REST_Request $request ) {
		$slug = sanitize_title( $request->get_param( 'slug' ) );

		if ( '' === $slug ) {
			return new WP_Error( 'invalid_slug', __( 'Invalid slug.', 'mrmurphy-apps' ), array( 'status' => 400 ) );
		}

		$post = $this->get_app_post_by_slug( $slug );

		if ( ! $post ) {
			return new WP_Error( 'app_not_found', __( 'App not found.', 'mrmurphy-apps' ), array( 'status' => 404 ) );
		}

		$zip_base64 = $request->get_param( 'zip_base64' );

		if ( ! is_string( $zip_base64 ) || '' === $zip_base64 ) {
			return new WP_Error( 'missing_zip', __( 'zip_base64 is required.', 'mrmurphy-apps' ), array( 'status' => 400 ) );
		}

		$entry_file = $request->get_param( 'entry_file' );

		$result = $this->decode_and_import( $post->ID, $zip_base64, $entry_file );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$entry = $result; // Updated entry file from import.

		return new WP_REST_Response(
			array(
				'slug'       => $post->post_name,
				'public_url' => trailingslashit( home_url( '/' . MRMURPHY_APPS_ROUTE_PREFIX . '/' . $post->post_name ) ),
				'entry_file' => $entry,
				'message'    => __( 'App files uploaded successfully.', 'mrmurphy-apps' ),
			),
			200
		);
	}

	/* ------------------------------------------------------------------ */
	/*  POST /apps/{slug}/publish — Toggle publish                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Toggle an app between draft and published.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function toggle_publish( WP_REST_Request $request ) {
		$slug = sanitize_title( $request->get_param( 'slug' ) );

		if ( '' === $slug ) {
			return new WP_Error( 'invalid_slug', __( 'Invalid slug.', 'mrmurphy-apps' ), array( 'status' => 400 ) );
		}

		$post = $this->get_app_post_by_slug( $slug );

		if ( ! $post ) {
			return new WP_Error( 'app_not_found', __( 'App not found.', 'mrmurphy-apps' ), array( 'status' => 404 ) );
		}

		$new_status = sanitize_key( $request->get_param( 'status' ) );

		if ( ! in_array( $new_status, array( 'publish', 'draft' ), true ) ) {
			return new WP_Error( 'invalid_status', __( 'Status must be "publish" or "draft".', 'mrmurphy-apps' ), array( 'status' => 400 ) );
		}

		$update_result = wp_update_post(
			array(
				'ID'         => (int) $post->ID,
				'post_status' => $new_status,
			),
			true
		);

		if ( is_wp_error( $update_result ) ) {
			return $update_result;
		}

		return new WP_REST_Response(
			array(
				'slug'       => $post->post_name,
				'status'     => $new_status,
				'public_url' => trailingslashit( home_url( '/' . MRMURPHY_APPS_ROUTE_PREFIX . '/' . $post->post_name ) ),
				'message'    => sprintf( __( 'App status changed to "%s".', 'mrmurphy-apps' ), $new_status ),
			),
			200
		);
	}

	/* ------------------------------------------------------------------ */
	/*  DELETE /apps/{slug} — Delete app                                    */
	/* ------------------------------------------------------------------ */

	/**
	 * Delete an app and its files.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_app( WP_REST_Request $request ) {
		$slug = sanitize_title( $request->get_param( 'slug' ) );

		if ( '' === $slug ) {
			return new WP_Error( 'invalid_slug', __( 'Invalid slug.', 'mrmurphy-apps' ), array( 'status' => 400 ) );
		}

		$post = $this->get_app_post_by_slug( $slug );

		if ( ! $post ) {
			return new WP_Error( 'app_not_found', __( 'App not found.', 'mrmurphy-apps' ), array( 'status' => 404 ) );
		}

		// Delete stored files first.
		$this->storage->delete_app_files( $post->post_name );

		// Then delete the post (force delete, no trash).
		wp_delete_post( (int) $post->ID, true );

		return new WP_REST_Response(
			array(
				'slug'    => $post->post_name,
				'message' => __( 'App deleted successfully.', 'mrmurphy-apps' ),
			),
			200
		);
	}

	/* ------------------------------------------------------------------ */
	/*  Helpers                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Get an app post by slug regardless of status.
	 *
	 * @param string $slug App slug.
	 * @return WP_Post|null
	 */
	private function get_app_post_by_slug( $slug ) {
		$slug = sanitize_title( $slug );

		if ( '' === $slug ) {
			return null;
		}

		$posts = get_posts(
			array(
				'name'           => $slug,
				'post_type'      => 'mrmurphy_app',
				'post_status'    => 'any',
				'posts_per_page' => 1,
			)
		);

		return ! empty( $posts ) ? $posts[0] : null;
	}

	/**
	 * Decode a base64 zip and import it into the app.
	 *
	 * Cleans up the temp file on exit via shutdown handler.
	 *
	 * @param int    $post_id    App post ID.
	 * @param string $zip_base64 Base64-encoded zip.
	 * @param string $entry_file Optional entry file override.
	 * @return string|WP_Error Detected or provided entry file, or error.
	 */
	private function decode_and_import( $post_id, $zip_base64, $entry_file = '' ) {
		$decoded = base64_decode( $zip_base64, true );

		if ( false === $decoded ) {
			return new WP_Error( 'invalid_base64', __( 'Invalid base64 encoding.', 'mrmurphy-apps' ), array( 'status' => 400 ) );
		}

		$temp_file = trailingslashit( sys_get_temp_dir() ) . 'mrmurphy-apps-' . wp_generate_password( 16, false ) . '.zip';

		// Register cleanup on shutdown in case of early exit.
		register_shutdown_function(
			function () use ( $temp_file ) {
				if ( file_exists( $temp_file ) ) {
					@unlink( $temp_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				}
			}
		);

		file_put_contents( $temp_file, $decoded ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		$result = $this->storage->import_zip( $post_id, $temp_file );

		@unlink( $temp_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Use provided entry_file if given, otherwise let import_zip's detection stand.
		if ( is_string( $entry_file ) && '' !== $entry_file ) {
			$entry_file = sanitize_file_name( $entry_file );
			update_post_meta( $post_id, MRMURPHY_APPS_META_ENTRY, $entry_file );
			return $entry_file;
		}

		return MRMurphy_Apps_CPT::get_entry_file( $post_id );
	}

	/**
	 * Build a standard app response array.
	 *
	 * @param int    $post_id    App post ID.
	 * @param string $entry_file Entry file (optional).
	 * @return array
	 */
	private function app_response( $post_id, $entry_file = '' ) {
		$post = get_post( (int) $post_id );

		if ( '' === $entry_file ) {
			$entry_file = MRMurphy_Apps_CPT::get_entry_file( (int) $post_id );
		}

		$files = $this->storage->list_files( $post->post_name );

		return array(
			'id'         => (int) $post->ID,
			'slug'       => $post->post_name,
			'title'      => $post->post_title,
			'status'     => $post->post_status,
			'public_url' => trailingslashit( home_url( '/' . MRMURPHY_APPS_ROUTE_PREFIX . '/' . $post->post_name ) ),
			'entry_file' => $entry_file,
			'file_count' => count( $files ),
		);
	}
}
