<?php
/**
 * Front-end routing and static file serving.
 *
 * @package MrMurphyApps
 */

defined( 'ABSPATH' ) || exit;

/**
 * Serves app assets at /apps/{slug}/ without theme chrome.
 */
class MRMurphy_Apps_Router {

	/** @var MRMurphy_Apps_Storage */
	private $storage;

	/**
	 * Constructor.
	 *
	 * @param MRMurphy_Apps_Storage $storage Storage handler.
	 */
	public function __construct( MRMurphy_Apps_Storage $storage ) {
		$this->storage = $storage;

		add_action( 'init', array( $this, 'register_rewrite_rules' ) );
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
		add_action( 'template_redirect', array( $this, 'maybe_serve_app' ), 0 );
	}

	/**
	 * Register rewrite rules.
	 */
	public function register_rewrite_rules() {
		add_rewrite_rule(
			'^' . MRMURPHY_APPS_ROUTE_PREFIX . '/([^/]+)/?(.*)$',
			'index.php?mrmurphy_app=$matches[1]&mrmurphy_app_path=$matches[2]',
			'top'
		);
	}

	/**
	 * Register custom query vars.
	 *
	 * @param array $vars Query vars.
	 * @return array
	 */
	public function register_query_vars( $vars ) {
		$vars[] = 'mrmurphy_app';
		$vars[] = 'mrmurphy_app_path';

		return $vars;
	}

	/**
	 * Serve a static app request and exit before theme templates load.
	 */
	public function maybe_serve_app() {
		$slug = get_query_var( 'mrmurphy_app' );

		if ( '' === $slug || false === $slug ) {
			return;
		}

		$app = MRMurphy_Apps_CPT::get_app_by_slug( $slug );

		if ( ! $app ) {
			status_header( 404 );
			nocache_headers();
			wp_die( esc_html__( 'App not found.', 'mrmurphy-apps' ), esc_html__( 'App not found', 'mrmurphy-apps' ), array( 'response' => 404 ) );
		}

		$path       = (string) get_query_var( 'mrmurphy_app_path' );
		$entry_file = MRMurphy_Apps_CPT::get_entry_file( $app->ID );
		$file_path  = $this->storage->resolve_file_path( $slug, $path, $entry_file );

		if ( ! $file_path ) {
			status_header( 404 );
			nocache_headers();
			wp_die( esc_html__( 'App file not found.', 'mrmurphy-apps' ), esc_html__( 'Not found', 'mrmurphy-apps' ), array( 'response' => 404 ) );
		}

		$relative_path = ltrim( str_replace( $this->storage->get_app_directory( $slug ), '', wp_normalize_path( $file_path ) ), '/' );
		$extension     = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
		$content_type  = $this->get_content_type( $file_path, $extension );
		$is_html       = in_array( $extension, array( 'html', 'htm' ), true );

		if ( $is_html ) {
			$body = file_get_contents( $file_path );

			if ( false === $body ) {
				status_header( 500 );
				wp_die( esc_html__( 'Could not read app file.', 'mrmurphy-apps' ), esc_html__( 'Server error', 'mrmurphy-apps' ), array( 'response' => 500 ) );
			}

			$body = $this->inject_base_tag( $body, $slug );
			$body = $this->inject_app_data( $body, $slug );
			$this->log_visit( $app, $relative_path );

			status_header( 200 );
			nocache_headers();
			header( 'Content-Type: ' . $content_type . '; charset=utf-8' );
			header( 'X-Robots-Tag: noindex, nofollow', true );
			echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw static HTML asset.
			exit;
		}

		status_header( 200 );
		header( 'Content-Type: ' . $content_type );
		header( 'Content-Length: ' . (string) filesize( $file_path ) );
		header( 'X-Robots-Tag: noindex, nofollow', true );

		readfile( $file_path );
		exit;
	}

	/**
	 * Inject a base tag so relative asset URLs resolve under /apps/{slug}/.
	 *
	 * @param string $html HTML content.
	 * @param string $slug App slug.
	 * @return string
	 */
	private function inject_base_tag( $html, $slug ) {
		if ( preg_match( '/<base[^>]+href\s*=/i', $html ) ) {
			return $html;
		}

		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return $html;
		}
		$base_href = trailingslashit( $uploads['baseurl'] . '/mrmurphy-apps/' . $slug );
		$base_tag  = '<base href="' . esc_url( $base_href ) . '">';

		if ( preg_match( '/<head[^>]*>/i', $html ) ) {
			return preg_replace( '/(<head[^>]*>)/i', '$1' . "\n" . $base_tag, $html, 1 );
		}

		return '<head>' . $base_tag . '</head>' . $html;
	}

	/**
	 * Inject REST nonce and user info into the app HTML.
	 *
	 * The injected window.mrmurphyApps object includes the app's API base
	 * so static apps can target endpoints scoped under /apps/{slug}/.
	 *
	 * @param string $html HTML content.
	 * @param string $slug App slug.
	 * @return string
	 */
	private function inject_app_data( $html, $slug ) {
		$user = wp_get_current_user();
		$data = array(
			'slug'      => $slug,
			'namespace' => 'mrmurphy-apps/v1',
			'root'      => esc_url_raw( rest_url() ),
			'apiBase'   => esc_url_raw( rest_url( 'apps/v1/' . $slug ) ),
			'nonce'     => wp_create_nonce( 'mrmurphy_apps:scope:' . $slug ),
		);

		if ( $user->exists() ) {
			$data['user'] = array(
				'id'           => (int) $user->ID,
				'display_name' => $user->display_name,
			);
		}

		$script = '<script>window.mrmurphyApps=' . wp_json_encode( $data ) . ';</script>';

		if ( preg_match( '/<head[^>]*>/i', $html ) ) {
			return preg_replace( '/(<head[^>]*>)/i', '$1' . "\n" . $script, $html, 1 );
		}

		return $script . "\n" . $html;
	}

	/**
	 * Record a visit for HTML document requests.
	 *
	 * @param WP_Post $app App post.
	 * @param string  $relative_path Requested relative path.
	 * @param bool    $html_only Whether to only log HTML requests.
	 */
	private function log_visit( WP_Post $app, $relative_path, $html_only = true ) {
		if ( $html_only ) {
			$extension = strtolower( pathinfo( $relative_path, PATHINFO_EXTENSION ) );
			if ( ! in_array( $extension, array( 'html', 'htm', '' ), true ) ) {
				return;
			}
		}

		MRMurphy_Apps_Stats::log_visit(
			array(
				'app_id'        => (int) $app->ID,
				'app_slug'      => $app->post_name,
				'request_path'  => $relative_path,
				'referrer'      => isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '',
				'visitor_hash'  => MRMurphy_Apps_Stats::hash_visitor(),
			)
		);
	}

	/**
	 * Resolve a response content type for a static asset.
	 *
	 * @param string $file_path Absolute file path.
	 * @param string $extension Lowercase file extension.
	 * @return string
	 */
	private function get_content_type( $file_path, $extension ) {
		$map = array(
			'html' => 'text/html',
			'htm'  => 'text/html',
			'css'  => 'text/css',
			'js'   => 'application/javascript',
			'mjs'  => 'application/javascript',
			'json' => 'application/json',
			'svg'  => 'image/svg+xml',
			'png'  => 'image/png',
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'gif'  => 'image/gif',
			'webp' => 'image/webp',
			'ico'  => 'image/x-icon',
			'woff' => 'font/woff',
			'woff2'=> 'font/woff2',
			'ttf'  => 'font/ttf',
			'map'  => 'application/json',
			'wasm' => 'application/wasm',
		);

		if ( isset( $map[ $extension ] ) ) {
			return $map[ $extension ];
		}

		$mime_type = wp_check_filetype( $file_path );

		return $mime_type['type'] ?: 'application/octet-stream';
	}
}
