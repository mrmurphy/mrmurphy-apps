<?php
/**
 * Admin UI for app uploads and stats.
 *
 * @package MrMurphyApps
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin screens and upload handling.
 */
class MRMurphy_Apps_Admin {

	/** @var MRMurphy_Apps_Storage */
	private $storage;

	/** @var MRMurphy_Apps_Stats */
	private $stats;

	/**
	 * Constructor.
	 *
	 * @param MRMurphy_Apps_Storage $storage Storage handler.
	 * @param MRMurphy_Apps_Stats   $stats   Stats handler.
	 */
	public function __construct( MRMurphy_Apps_Storage $storage, MRMurphy_Apps_Stats $stats ) {
		$this->storage = $storage;
		$this->stats   = $stats;

		add_action( 'add_meta_boxes', array( $this, 'register_meta_boxes' ) );
		add_action( 'post_edit_form_tag', array( $this, 'add_form_enctype' ) );
		add_action( 'admin_notices', array( $this, 'render_upload_notices' ) );
		add_action( 'save_post_mrmurphy_app', array( $this, 'handle_upload' ), 10, 2 );
		add_action( 'before_delete_post', array( $this, 'delete_app_files' ) );
		add_filter( 'post_updated_messages', array( $this, 'updated_messages' ) );
	}

	/**
	 * Allow zip uploads on the app edit screen.
	 */
	public function add_form_enctype() {
		global $post;

		if ( $post && 'mrmurphy_app' === $post->post_type ) {
			echo ' enctype="multipart/form-data"';
		}
	}

	/**
	 * Show upload success and error notices.
	 */
	public function render_upload_notices() {
		global $post;

		if ( ! $post || 'mrmurphy_app' !== $post->post_type ) {
			return;
		}

		$error = get_transient( 'mrmurphy_app_upload_error_' . $post->ID );
		if ( $error ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $error ) );
			delete_transient( 'mrmurphy_app_upload_error_' . $post->ID );
		}

		$success = get_transient( 'mrmurphy_app_upload_success_' . $post->ID );
		if ( $success ) {
			printf( '<div class="notice notice-success"><p>%s</p></div>', esc_html( $success ) );
			delete_transient( 'mrmurphy_app_upload_success_' . $post->ID );
		}
	}

	/**
	 * Register app meta boxes.
	 */
	public function register_meta_boxes() {
		add_meta_box(
			'mrmurphy_app_assets',
			__( 'App Assets', 'mrmurphy-apps' ),
			array( $this, 'render_assets_meta_box' ),
			'mrmurphy_app',
			'normal',
			'high'
		);

		add_meta_box(
			'mrmurphy_app_stats',
			__( 'Visit Stats', 'mrmurphy-apps' ),
			array( $this, 'render_stats_meta_box' ),
			'mrmurphy_app',
			'side',
			'default'
		);
	}

	/**
	 * Render the assets meta box.
	 *
	 * @param WP_Post $post App post.
	 */
	public function render_assets_meta_box( $post ) {
		wp_nonce_field( 'mrmurphy_app_assets', 'mrmurphy_app_assets_nonce' );

		$slug       = $post->post_name;
		$entry      = MRMurphy_Apps_CPT::get_entry_file( $post->ID );
		$files      = $this->storage->list_files( $slug );
		$public_url = $slug ? trailingslashit( home_url( '/' . MRMURPHY_APPS_ROUTE_PREFIX . '/' . $slug ) ) : '';
		?>
		<p>
			<label for="mrmurphy_app_zip"><strong><?php esc_html_e( 'Upload zip', 'mrmurphy-apps' ); ?></strong></label><br>
			<input type="file" id="mrmurphy_app_zip" name="mrmurphy_app_zip" accept=".zip,application/zip">
		</p>
		<p class="description">
			<?php esc_html_e( 'Upload a zip of static assets (HTML, CSS, JS, images). Existing files for this app will be replaced.', 'mrmurphy-apps' ); ?>
		</p>

		<?php if ( $public_url ) : ?>
			<p>
				<strong><?php esc_html_e( 'Public URL', 'mrmurphy-apps' ); ?></strong><br>
				<a href="<?php echo esc_url( $public_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $public_url ); ?></a>
			</p>
		<?php else : ?>
			<p class="description"><?php esc_html_e( 'Set a title and slug, then publish to get a public URL like /apps/your-slug/.', 'mrmurphy-apps' ); ?></p>
		<?php endif; ?>

		<p>
			<strong><?php esc_html_e( 'Entry file', 'mrmurphy-apps' ); ?></strong><br>
			<input type="text" name="mrmurphy_app_entry" value="<?php echo esc_attr( $entry ); ?>" class="regular-text">
		</p>

		<?php if ( ! empty( $files ) ) : ?>
			<p><strong><?php esc_html_e( 'Stored files', 'mrmurphy-apps' ); ?></strong></p>
			<ul style="max-height:220px; overflow:auto; margin:0; padding-left:1.2em;">
				<?php foreach ( $files as $file ) : ?>
					<li><code><?php echo esc_html( $file ); ?></code></li>
				<?php endforeach; ?>
			</ul>
		<?php else : ?>
			<p><?php esc_html_e( 'No files uploaded yet.', 'mrmurphy-apps' ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render the stats meta box.
	 *
	 * @param WP_Post $post App post.
	 */
	public function render_stats_meta_box( $post ) {
		if ( 'auto-draft' === $post->post_status ) {
			echo '<p>' . esc_html__( 'Publish the app to start collecting visit stats.', 'mrmurphy-apps' ) . '</p>';
			return;
		}

		$summary = $this->stats->get_app_summary( $post->ID );
		$recent  = $this->stats->get_recent_visits( $post->ID, 5 );
		?>
		<ul style="margin:0; padding-left:1.2em;">
			<li><?php echo esc_html( sprintf( __( 'Total visits: %s', 'mrmurphy-apps' ), number_format_i18n( $summary['total'] ) ) ); ?></li>
			<li><?php echo esc_html( sprintf( __( 'Unique visitors: %s', 'mrmurphy-apps' ), number_format_i18n( $summary['unique'] ) ) ); ?></li>
			<li><?php echo esc_html( sprintf( __( 'Last 7 days: %s', 'mrmurphy-apps' ), number_format_i18n( $summary['last_7_days'] ) ) ); ?></li>
		</ul>

		<?php if ( ! empty( $summary['last_visit'] ) ) : ?>
			<p><strong><?php esc_html_e( 'Last visit', 'mrmurphy-apps' ); ?></strong><br><?php echo esc_html( get_date_from_gmt( $summary['last_visit'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></p>
		<?php endif; ?>

		<?php if ( ! empty( $recent ) ) : ?>
			<p><strong><?php esc_html_e( 'Recent visits', 'mrmurphy-apps' ); ?></strong></p>
			<ul style="margin:0; padding-left:1.2em;">
				<?php foreach ( $recent as $visit ) : ?>
					<li>
						<code><?php echo esc_html( $visit->request_path ?: '/' ); ?></code><br>
						<span class="description"><?php echo esc_html( get_date_from_gmt( $visit->visited_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<?php
	}

	/**
	 * Handle zip upload and entry file save.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public function handle_upload( $post_id, $post ) {
		if ( ! isset( $_POST['mrmurphy_app_assets_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mrmurphy_app_assets_nonce'] ) ), 'mrmurphy_app_assets' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( isset( $_POST['mrmurphy_app_entry'] ) ) {
			$entry = sanitize_file_name( wp_unslash( $_POST['mrmurphy_app_entry'] ) );
			if ( '' !== $entry ) {
				update_post_meta( $post_id, MRMURPHY_APPS_META_ENTRY, $entry );
			}
		}

		if ( empty( $_FILES['mrmurphy_app_zip']['name'] ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		$file = wp_unslash( $_FILES['mrmurphy_app_zip'] );

		if ( ! empty( $file['error'] ) ) {
			set_transient( 'mrmurphy_app_upload_error_' . $post_id, __( 'File upload failed.', 'mrmurphy-apps' ), 30 );
			return;
		}

		$overrides = array(
			'test_form' => false,
			'mimes'     => array(
				'zip' => 'application/zip',
			),
		);

		$uploaded = wp_handle_upload( $file, $overrides );

		if ( isset( $uploaded['error'] ) ) {
			set_transient( 'mrmurphy_app_upload_error_' . $post_id, $uploaded['error'], 30 );
			return;
		}

		$result = $this->storage->import_zip( $post_id, $uploaded['file'] );
		@unlink( $uploaded['file'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( is_wp_error( $result ) ) {
			set_transient( 'mrmurphy_app_upload_error_' . $post_id, $result->get_error_message(), 30 );
			return;
		}

		set_transient( 'mrmurphy_app_upload_success_' . $post_id, __( 'App files uploaded successfully.', 'mrmurphy-apps' ), 30 );
	}

	/**
	 * Delete stored files when an app post is deleted.
	 *
	 * @param int $post_id Post ID.
	 */
	public function delete_app_files( $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post || 'mrmurphy_app' !== $post->post_type ) {
			return;
		}

		$this->storage->delete_app_files( $post->post_name );
	}

	/**
	 * Customize post updated messages.
	 *
	 * @param array $messages Messages.
	 * @return array
	 */
	public function updated_messages( $messages ) {
		global $post;

		if ( ! $post || 'mrmurphy_app' !== $post->post_type ) {
			return $messages;
		}

		$error   = get_transient( 'mrmurphy_app_upload_error_' . $post->ID );
		$success = get_transient( 'mrmurphy_app_upload_success_' . $post->ID );

		if ( $error ) {
			$messages['mrmurphy_app'][1] = esc_html( $error );
			delete_transient( 'mrmurphy_app_upload_error_' . $post->ID );
		} elseif ( $success ) {
			$messages['mrmurphy_app'][1] = esc_html( $success );
			delete_transient( 'mrmurphy_app_upload_success_' . $post->ID );
		}

		return $messages;
	}
}
