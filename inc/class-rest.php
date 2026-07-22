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
 * Management API — mrmurphy-apps/v1 (requires manage_options):
 *   GET    /instructions       — Public TXT guide for agents
 *   POST   /apps               — Create app (optional zip upload)
 *   GET    /apps               — List all apps
 *   GET    /apps/{slug}        — Get app details
 *   POST   /apps/{slug}/upload — Upload/replace zip
 *   POST   /apps/{slug}/publish — Toggle publish/draft
 *   DELETE /apps/{slug}        — Delete app + files
 *
 * App Data API — apps/v1 (requires login):
 *   Routes under apps/v1/{slug}/ are registered by or for individual apps.
 *   Example: apps/v1/counter/counter (counter app's per-user counter).
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
		add_action( 'rest_api_init', array( $this, 'load_app_routes' ), 20 );
		add_action( 'save_post_mrmurphy_app', array( $this, 'flush_routes_cache' ) );
		add_action( 'before_delete_post', array( $this, 'flush_routes_cache' ) );
		add_filter( 'rest_authentication_errors', array( $this, 'check_scoped_nonce' ), 5 );
		add_filter( 'rest_pre_serve_request', array( $this, 'serve_instructions_raw' ), 10, 4 );
	}

	/**
	 * Flush the published-slugs cache when an app is created, updated, or deleted.
	 *
	 * @param int $post_id Post ID.
	 */
	public function flush_routes_cache( $post_id ) {
		if ( 'mrmurphy_app' !== get_post_type( $post_id ) ) {
			return;
		}
		delete_transient( 'mrmurphy_apps_published_slugs' );
	}

	/**
	 * Serve the /instructions endpoint as raw plain text, not JSON-wrapped.
	 *
	 * WordPress REST API always JSON-encodes responses, turning newlines into
	 * literal \n strings. This intercepts the instructions route and sends
	 * the text with proper Content-Type before JSON serialization.
	 *
	 * @param bool             $served  Whether the request has already been served.
	 * @param WP_HTTP_Response $result  Response object.
	 * @param WP_REST_Request  $request Request object.
	 * @param WP_REST_Server   $server  REST server instance.
	 * @return bool
	 */
	public function serve_instructions_raw( $served, $result, $request, $server ) {
		if ( '/mrmurphy-apps/v1/instructions' !== $request->get_route() ) {
			return $served;
		}

		if ( ! in_array( $request->get_method(), array( 'GET', 'HEAD' ), true ) ) {
			return $served;
		}

		$data = $result->get_data();
		if ( ! is_string( $data ) ) {
			return $served;
		}

		header( 'Content-Type: text/plain; charset=UTF-8' );
		echo $data;
		return true;
	}

	/**
	 * Authenticate requests to apps/v1/{slug}/* using a per-app scoped nonce.
	 *
	 * Apps served at /apps/{slug}/ receive a nonce scoped to their slug
	 * via window.mrmurphyApps.nonce. This handler accepts that nonce
	 * for routes under apps/v1/{slug}/, while the standard wp_rest nonce
	 * still works for admin users browsing wp-admin.
	 *
	 * Runs at priority 5 (before WP core's cookie check at 10).
	 *
	 * @param mixed $result Current auth status (null = unauthenticated).
	 * @return mixed
	 */
	public function check_scoped_nonce( $result ) {
		if ( null !== $result ) {
			return $result;
		}

		$route_rest = $GLOBALS['wp']->query_vars['rest_route'] ?? '';
		if ( '' === $route_rest ) {
			$route_rest = isset( $_SERVER['REQUEST_URI'] ) ? parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) : '';
			$route_rest = preg_replace( '#^.*/wp-json#', '', $route_rest );
		}

		if ( ! preg_match( '#^/apps/v1/([^/]+)#', $route_rest, $m ) ) {
			return $result;
		}

		$slug  = sanitize_title( $m[1] );
		if ( '' === $slug ) {
			return $result;
		}

		$nonce = isset( $_SERVER['HTTP_X_WP_NONCE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_WP_NONCE'] ) ) : '';
		if ( '' === $nonce ) {
			return $result;
		}

		if ( 1 === wp_verify_nonce( $nonce, 'mrmurphy_apps:scope:' . $slug ) ) {
			return true;
		}

		return $result;
	}

	/**
	 * Register all REST routes.
	 */
	public function register_routes() {
		$mgmt = 'mrmurphy-apps/v1';
		$apps = 'apps/v1';

		// GET /instructions — public, no auth required.
		register_rest_route(
			$mgmt,
			'/instructions',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_instructions' ),
					'permission_callback' => array( $this, 'check_permissions' ),
				),
			)
		);

		// POST /apps — create app.
		register_rest_route(
			$mgmt,
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
						'status'      => array(
							'required'    => false,
							'type'        => 'string',
							'enum'        => array( 'draft', 'publish' ),
							'default'     => 'draft',
							'description' => __( 'Initial status: "draft" or "publish".', 'mrmurphy-apps' ),
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
			$mgmt,
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
			$mgmt,
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
			$mgmt,
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
			$mgmt,
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
			$mgmt,
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

		/* ------------------------------------------------------------------ */
		/*  App Data API — apps/v1/{slug}/...                                 */
		/* ------------------------------------------------------------------ */

		// GET /{slug}/counter — get counter value (per-app, per-user).
		register_rest_route(
			$apps,
			'/(?P<slug>[^/]+)/counter',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_counter' ),
					'permission_callback' => array( $this, 'check_logged_in' ),
					'args'                => array(
						'slug' => array(
							'required' => true,
							'type'     => 'string',
						),
					),
				),
			)
		);

		// POST /{slug}/counter — update counter value.
		register_rest_route(
			$apps,
			'/(?P<slug>[^/]+)/counter',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_counter' ),
					'permission_callback' => array( $this, 'check_logged_in' ),
					'args'                => array(
						'slug' => array(
							'required' => true,
							'type'     => 'string',
						),
						'value' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);
	}

	/**
	 * Load server/routes.php from every published app.
	 *
	 * Each app can ship server/routes.php in its zip to register custom REST
	 * endpoints under apps/v1/{slug}/. The file runs inside a function scope
	 * with $mrmurphy_app_slug set to the app's slug.
	 *
	 * Runs at priority 20 so plugin routes register first.
	 */
	public function load_app_routes() {
		$cached = get_transient( 'mrmurphy_apps_published_slugs' );
		if ( false === $cached ) {
			$posts = get_posts(
				array(
					'post_type'      => 'mrmurphy_app',
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'fields'         => 'post_name',
				)
			);
			$slugs = wp_list_pluck( $posts, 'post_name' );
			set_transient( 'mrmurphy_apps_published_slugs', $slugs, HOUR_IN_SECONDS );
		} else {
			$slugs = $cached;
		}

		foreach ( $slugs as $slug ) {
			$routes_file = trailingslashit( MRMurphy_Apps_Storage::get_base_directory() ) . $slug . '/server/routes.php';
			if ( ! file_exists( $routes_file ) ) {
				continue;
			}

			$mrmurphy_app_slug = $slug;
			try {
				include $routes_file;
			} catch ( Throwable $e ) {
				error_log( sprintf(
					'mrmurphy-apps: Fatal in routes.php for app "%s": %s',
					$slug,
					$e->getMessage()
				) );
			}
		}
	}

	/**
	 * Check if the current user has permission.
	 *
	 * @return bool
	 */
	public function check_permissions() {
		return is_user_logged_in() && ( current_user_can( 'manage_options' ) || current_user_can( 'manage_mrmurphy_apps' ) );
	}

	/**
	 * Check if user is logged in (for user-facing endpoints).
	 *
	 * @return bool
	 */
	public function check_logged_in() {
		return is_user_logged_in();
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
		$instructions = self::build_instructions();

		return new WP_REST_Response( $instructions, 200, array( 'Content-Type' => 'text/plain; charset=utf-8' ) );
	}

	/**
	 * Build the instructions text.
	 *
	 * @return string
	 */
	public static function build_instructions() {
		$base_url   = home_url( '/' . MRMURPHY_APPS_ROUTE_PREFIX . '/' );
		$mgmt_base  = home_url( '/wp-json/mrmurphy-apps/v1' );
		$app_base   = home_url( '/wp-json/apps/v1' );
		$site_url   = home_url();
		$version    = MRMURPHY_APPS_VERSION;

		$text = <<<'TXT'
MrMurphy Apps REST API — Agent Guide
======================================

App URL:      %s
Management:   %s
App Data API: %s
Version:      %s

The API is split into two namespaces:

  mrmurphy-apps/v1  — Admin operations (create, upload, publish, delete apps).
                      Requires manage_options capability.
  apps/v1           — Per-app user-facing data endpoints.
                      Requires login. Routes are scoped under /{slug}/.

Authentication
--------------
Admin endpoints require WordPress Application Passwords with
manage_options or manage_mrmurphy_apps capability.

  curl -u "username:application-password" %s/apps

The plugin ships a "MrMurphy Agent" role (manage_mrmurphy_apps)
that can use the management API but cannot access wp-admin settings.

Agent Setup (for the human operator)
-------------------------------------
1. Go to Apps &rarr; Getting Started in wp-admin:
   %s/wp-admin/admin.php?page=mrmurphy-apps-getting-started

2. Create an "Agent" user with the MrMurphy Agent role.
   The page will show you the generated application password once.

3. Provide the agent with two environment variables instead of
   pasting credentials into prompts:

   MRMURPHY_APPS_USERNAME=agent
   MRMURPHY_APPS_PASSWORD="<the generated password>"
   MRMURPHY_APPS_URL="%s"

   Most agents support evars (environment variables) set in their
   configuration or profile. Credentials in evars stay out of
   conversation history and prompt context.

4. The agent uses these evars to authenticate:

   curl -u "$MRMURPHY_APPS_USERNAME:$MRMURPHY_APPS_PASSWORD" "$MRMURPHY_APPS_URL/wp-json/mrmurphy-apps/v1/apps"

Creating an Agent User (WP-CLI alternative)
--------------------------------------------
  wp user create agent agent@example.com --role=mrmurphy_agent
  wp user application-password create agent opencode

App data endpoints use cookie auth + X-WP-Nonce (injected into HTML pages).

----------------------------------------------------------------------
Management API — mrmurphy-apps/v1
----------------------------------------------------------------------

1. GET %s/instructions
   Public — no auth required. Returns this guide as text/plain.

2. POST %s/apps
   Create a new app.
   Body (JSON):
     {
       "title": "My App",
       "slug": "my-app",
       "zip_base64": "<base64-encoded zip>",
       "entry_file": "index.html"
     }
   slug, zip_base64, and entry_file are optional.
   Response: { "id", "slug", "title", "status", "public_url", "entry_file", "file_count" }

3. GET %s/apps
   List all apps (drafts + published).
   Response: [ { "id", "slug", "title", "status", "public_url", "entry_file", "file_count" } ]

4. GET %s/apps/{slug}
   Get details for a specific app.
   Response: { "id", "slug", "title", "status", "public_url", "entry_file", "files", "file_count", "stats" }

5. POST %s/apps/{slug}/upload
   Upload or replace the zip for an existing app.
   Body (JSON): { "zip_base64": "...", "entry_file": "index.html" }

6. POST %s/apps/{slug}/publish
   Toggle an app between draft and published.
   Body (JSON): { "status": "publish" }
   status can be "publish" or "draft".

7. DELETE %s/apps/{slug}
   Delete an app post and all its stored files permanently.

----------------------------------------------------------------------
App Data API — apps/v1
----------------------------------------------------------------------

Apps define data endpoints under apps/v1/{slug}/.
These use cookie auth + the nonce injected by the plugin.

8. GET %s/{slug}/counter
   Get the per-user counter value for an app. Requires login.

9. POST %s/{slug}/counter
   Update the per-user counter for an app. Requires login.
   Body (JSON): { "value": <int> }

----------------------------------------------------------------------
App-Scoped Convention
----------------------------------------------------------------------

Apps registered under /apps/{slug}/ may add or modify REST API routes
only under apps/v1/{slug}/. This keeps each app's data isolated.

The plugin injects window.mrmurphyApps into served HTML pages with:
  - slug      — the app's slug
  - root      — REST API root URL
  - apiBase   — base URL for this app's scoped endpoints (apps/v1/{slug})
  - nonce     — scoped nonce for cookie auth on apps/v1/{slug}/* only
  - user      — { id, display_name } (null if not logged in)

The nonce is scoped to the app's slug via action mrmurphy_apps:scope:{slug}.
It cannot be used to call endpoints outside apps/v1/{slug}/* — not even
the mrmurphy-apps/v1 management API. Admin users can still use their
standard wp_rest nonce (from wp-admin) on any endpoint.

Example: a counter app at /apps/counter/ uses apiBase + "/counter"
to hit POST apps/v1/counter/counter for its per-user state.

----------------------------------------------------------------------
Frontend Serving
----------------------------------------------------------------------

Public URL Pattern
------------------
https://example.com/apps/{slug}/

Build Process
-------------
Run ./build.sh in the plugin directory to create the zip.
With --bump flag, it increments the patch version.
Output: dist/mrmurphy-apps-{version}.zip

Zip contents should be static HTML/JS/CSS/asset files.
Optionally, include server/routes.php and server/init.php for
server-side endpoints and initialization (see Server-Side Code).

Asset URLs & Base Tag
---------------------
The plugin injects a <base> tag pointing to the uploads directory
(wp-content/uploads/mrmurphy-apps/{slug}/). This means:
  - Relative asset paths (src="bundle.js", href="style.css") resolve to
    the uploads URL — these work transparently.
  - Forms and History API pushState() with relative paths will resolve
    against the uploads URL, not the route URL /apps/{slug}/.
  - Use absolute paths (leading /) or the injected apiBase for form
    actions, navigation links, and History API calls.
  - Hash-based routing (#/route) is recommended for SPAs and is
    unaffected by the base tag.

Server-Side Code
----------------
Apps can ship PHP files that run on the server:

  server/init.php
    Runs when a zip is uploaded. Use for dbDelta() table creation,
    option setup, or any one-time initialization.
    Variable in scope: $mrmurphy_app_slug (string)

  server/routes.php
    Loaded on every REST API request for published apps. Register
    custom endpoints under apps/v1/{slug}/ using the standard
    register_rest_route() function.
    Variable in scope: $mrmurphy_app_slug (string)

  Example server/routes.php:
    <?php
    $slug = $mrmurphy_app_slug;
    register_rest_route( "apps/v1/$slug", '/notes', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => function () use ( $slug ) {
            global $wpdb;
            $table = $wpdb->prefix . 'app_' . str_replace( '-', '_', $slug );
            return $wpdb->get_results( "SELECT * FROM $table ORDER BY id DESC" );
        },
        'permission_callback' => 'is_user_logged_in',
    ) );

  Example server/init.php:
    <?php
    global $wpdb;
    $table = $wpdb->prefix . 'app_' . str_replace( '-', '_', $mrmurphy_app_slug );
    $wpdb->query( "CREATE TABLE IF NOT EXISTS $table (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        user_id bigint(20) unsigned NOT NULL,
        content text NOT NULL,
        created_at datetime NOT NULL,
        PRIMARY KEY (id)
    )" );

  The server code has full access to WordPress APIs: $wpdb, wp_get_current_user(),
  get_current_user_id(), register_rest_route(), etc.
  Routes are automatically namespaced under apps/v1/{slug}/ — no collisions
  with other apps.
  Security: uploading apps requires the manage_mrmurphy_apps capability,
  which implies full trust — same as activating a plugin.

Error Responses
---------------
400 — Bad request (missing/invalid parameters)
401 — Unauthorized (not logged in)
403 — Forbidden (insufficient permissions)
404 — App not found
409 — Conflict (e.g., slug already exists)
500 — Server error

TXT;

		return sprintf(
			$text,
			$base_url,
			$mgmt_base,
			$app_base,
			$version,
			$mgmt_base,
			$site_url,
			$site_url,
			$mgmt_base,
			$mgmt_base,
			$mgmt_base,
			$mgmt_base,
			$mgmt_base,
			$mgmt_base,
			$mgmt_base,
			$mgmt_base,
			$app_base,
			$app_base
		);
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
				'post_name__in'  => array( $slug ),
				'post_type'      => 'mrmurphy_app',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);

		if ( ! empty( $existing ) ) {
			return new WP_Error( 'duplicate_slug', sprintf( __( 'An app with the slug "%s" already exists.', 'mrmurphy-apps' ), $slug ), array( 'status' => 409 ) );
		}

		$status = $request->get_param( 'status' );
		if ( ! in_array( $status, array( 'draft', 'publish' ), true ) ) {
			$status = 'draft';
		}

		$post_id = wp_insert_post(
			array(
				'post_type'    => 'mrmurphy_app',
				'post_title'   => $title,
				'post_name'    => $slug,
				'post_status'  => $status,
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
				'post_name__in'  => array( $slug ),
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
	/*  GET /apps/{slug}/counter & POST /apps/{slug}/counter                */
	/* ------------------------------------------------------------------ */

	/**
	 * Get the counter value for the current user within an app.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_counter( WP_REST_Request $request ) {
		$app = $this->get_app_post_by_slug( $request->get_param( 'slug' ) );

		if ( ! $app ) {
			return new WP_Error( 'app_not_found', __( 'App not found.', 'mrmurphy-apps' ), array( 'status' => 404 ) );
		}

		$user_id = get_current_user_id();
		$meta_key = '_mrmurphy_counter_' . $app->post_name;
		$value   = (int) get_user_meta( $user_id, $meta_key, true );

		return new WP_REST_Response(
			array(
				'value'        => $value,
				'display_name' => wp_get_current_user()->display_name,
			),
			200
		);
	}

	/**
	 * Update the counter value for the current user within an app.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_counter( WP_REST_Request $request ) {
		$app = $this->get_app_post_by_slug( $request->get_param( 'slug' ) );

		if ( ! $app ) {
			return new WP_Error( 'app_not_found', __( 'App not found.', 'mrmurphy-apps' ), array( 'status' => 404 ) );
		}

		$user_id = get_current_user_id();
		$meta_key = '_mrmurphy_counter_' . $app->post_name;
		$value   = (int) $request->get_param( 'value' );

		update_user_meta( $user_id, $meta_key, $value );

		return new WP_REST_Response(
			array(
				'value'        => $value,
				'display_name' => wp_get_current_user()->display_name,
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
				'post_name__in'  => array( $slug ),
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
	 * Cleans up the temp file after import.
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

		if ( ! $post ) {
			return array(
				'id'         => 0,
				'slug'       => '',
				'title'      => '',
				'status'     => '',
				'public_url' => '',
				'entry_file' => $entry_file,
				'file_count' => 0,
			);
		}

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
