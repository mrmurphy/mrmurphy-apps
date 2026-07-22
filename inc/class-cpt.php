<?php
/**
 * App custom post type.
 *
 * @package MrMurphyApps
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers and manages the mrmurphy_app post type.
 */
class MRMurphy_Apps_CPT {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_filter( 'manage_mrmurphy_app_posts_columns', array( $this, 'admin_columns' ) );
		add_action( 'manage_mrmurphy_app_posts_custom_column', array( $this, 'admin_column_content' ), 10, 2 );
	}

	/**
	 * Register the app post type.
	 */
	public function register_post_type() {
		$labels = array(
			'name'               => _x( 'Apps', 'post type general name', 'mrmurphy-apps' ),
			'singular_name'      => _x( 'App', 'post type singular name', 'mrmurphy-apps' ),
			'menu_name'          => _x( 'Apps', 'admin menu', 'mrmurphy-apps' ),
			'add_new'            => _x( 'Add New', 'app', 'mrmurphy-apps' ),
			'add_new_item'       => __( 'Add New App', 'mrmurphy-apps' ),
			'edit_item'          => __( 'Edit App', 'mrmurphy-apps' ),
			'new_item'           => __( 'New App', 'mrmurphy-apps' ),
			'view_item'          => __( 'View App', 'mrmurphy-apps' ),
			'all_items'          => __( 'All Apps', 'mrmurphy-apps' ),
			'search_items'       => __( 'Search Apps', 'mrmurphy-apps' ),
			'not_found'          => __( 'No apps found.', 'mrmurphy-apps' ),
			'not_found_in_trash' => __( 'No apps found in Trash.', 'mrmurphy-apps' ),
		);

		register_post_type(
			'mrmurphy_app',
			array(
				'labels'              => $labels,
				'public'              => false,
				'publicly_queryable'  => false,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'query_var'           => false,
				'rewrite'             => false,
				'capability_type'     => 'post',
				'has_archive'         => false,
				'hierarchical'        => false,
				'menu_position'       => 21,
				'menu_icon'           => 'dashicons-welcome-widgets-menus',
				'supports'            => array( 'title' ),
				'show_in_rest'        => false,
			)
		);
	}

	/**
	 * Add admin list columns.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function admin_columns( $columns ) {
		$new = array();

		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;

			if ( 'title' === $key ) {
				$new['app_url']   = __( 'URL', 'mrmurphy-apps' );
				$new['app_files'] = __( 'Files', 'mrmurphy-apps' );
			}
		}

		return $new;
	}

	/**
	 * Render admin list column content.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 */
	public function admin_column_content( $column, $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post || 'mrmurphy_app' !== $post->post_type ) {
			return;
		}

		switch ( $column ) {
			case 'app_url':
				$url = home_url( '/' . MRMURPHY_APPS_ROUTE_PREFIX . '/' . $post->post_name . '/' );
				printf(
					'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
					esc_url( $url ),
					esc_html( $url )
				);
				break;

			case 'app_files':
				$storage = new MRMurphy_Apps_Storage();
				$count   = count( $storage->list_files( $post->post_name ) );
				echo esc_html( number_format_i18n( $count ) );
				break;
		}
	}

	/**
	 * Get a published app by slug.
	 *
	 * Uses 'name' (not 'post_name__in') because post_status is explicitly
	 * 'publish' — WordPress 6.7+ restricts 'name' to published posts by
	 * default, which is the desired behavior here. For admin lookups that
	 * need drafts use 'post_name__in' with 'post_status' => 'any'.
	 *
	 * @param string $slug App slug.
	 * @return WP_Post|null
	 */
	public static function get_app_by_slug( $slug ) {
		$slug = sanitize_title( $slug );

		if ( '' === $slug ) {
			return null;
		}

		$posts = get_posts(
			array(
				'name'           => $slug,
				'post_type'      => 'mrmurphy_app',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
			)
		);

		return ! empty( $posts ) ? $posts[0] : null;
	}

	/**
	 * Get the entry file for an app.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function get_entry_file( $post_id ) {
		$entry = get_post_meta( $post_id, MRMURPHY_APPS_META_ENTRY, true );

		if ( ! is_string( $entry ) || '' === $entry ) {
			return 'index.html';
		}

		return sanitize_file_name( $entry );
	}
}
