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
		add_action( 'admin_menu', array( $this, 'add_getting_started_page' ) );
		add_action( 'admin_menu', array( $this, 'add_agent_instructions_link' ) );
		add_action( 'admin_post_mrmurphy_create_agent', array( $this, 'handle_create_agent' ) );
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
	 * Register the Getting Started submenu page.
	 */
	public function add_getting_started_page() {
		add_submenu_page(
			'edit.php?post_type=mrmurphy_app',
			__( 'Getting Started', 'mrmurphy-apps' ),
			__( 'Getting Started', 'mrmurphy-apps' ),
			'manage_mrmurphy_apps',
			'mrmurphy-apps-getting-started',
			array( $this, 'render_getting_started' )
		);
	}

	/**
	 * Render the Getting Started page.
	 */
	public function render_getting_started() {
		$mgmt_base = home_url( '/wp-json/mrmurphy-apps/v1' );
		$app_api   = home_url( '/wp-json/apps/v1/{slug}' );
		$site_url  = home_url();
		$admin_url = admin_url();

		$agent_user    = null;
		$app_password  = null;
		$agent_created = false;

		$created = get_transient( 'mrmurphy_agent_created' );
		if ( $created ) {
			$agent_created  = true;
			$agent_user     = $created['user'];
			$app_password   = $created['password'];
			delete_transient( 'mrmurphy_agent_created' );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Getting Started — MrMurphy Apps', 'mrmurphy-apps' ); ?></h1>

			<hr>

			<h2 style="margin-top:2em"><?php esc_html_e( 'For AI Agents', 'mrmurphy-apps' ); ?></h2>

			<p><?php esc_html_e( 'Point your agent to these REST API bases:', 'mrmurphy-apps' ); ?></p>

			<table class="widefat striped" style="max-width:700px;margin-bottom:2em">
				<thead><tr>
					<th style="width:120px"><?php esc_html_e( 'API', 'mrmurphy-apps' ); ?></th>
					<th><?php esc_html_e( 'Base URL', 'mrmurphy-apps' ); ?></th>
					<th><?php esc_html_e( 'Auth', 'mrmurphy-apps' ); ?></th>
				</tr></thead>
				<tbody>
					<tr>
						<td><strong><?php esc_html_e( 'Management', 'mrmurphy-apps' ); ?></strong></td>
						<td><code><?php echo esc_url( $mgmt_base ); ?></code></td>
						<td><?php esc_html_e( 'Application Password', 'mrmurphy-apps' ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'App Data', 'mrmurphy-apps' ); ?></strong></td>
						<td><code><?php echo esc_url( $app_api ); ?></code></td>
						<td><?php esc_html_e( 'Cookie + scoped nonce (injected)', 'mrmurphy-apps' ); ?></td>
					</tr>
				</tbody>
			</table>

			<p>
				<a href="<?php echo esc_url( rest_url( 'mrmurphy-apps/v1/instructions' ) ); ?>" target="_blank" class="button">
					<?php esc_html_e( 'View Full API Guide', 'mrmurphy-apps' ); ?>
				</a>
			</p>

			<hr>

			<h2 style="margin-top:2em"><?php esc_html_e( 'Create an Agent User', 'mrmurphy-apps' ); ?></h2>

			<p>
				<?php esc_html_e( 'Create a dedicated user with the "MrMurphy Agent" role. This role has access to the management API but cannot access wp-admin settings or content.', 'mrmurphy-apps' ); ?>
				<?php esc_html_e( 'The user will be given an application password — save it now, it will not be shown again.', 'mrmurphy-apps' ); ?>
			</p>

			<?php if ( $agent_created && $agent_user && $app_password ) : ?>
				<div class="notice notice-success notice-alt" style="margin:1em 0">
					<p><strong><?php esc_html_e( 'Agent user created!', 'mrmurphy-apps' ); ?></strong></p>
					<table style="margin:0.5em 0">
						<tr><td style="padding-right:1em"><strong><?php esc_html_e( 'Username', 'mrmurphy-apps' ); ?></strong></td>
							<td><code><?php echo esc_html( $agent_user ); ?></code></td></tr>
						<tr><td style="padding-right:1em"><strong><?php esc_html_e( 'App Password', 'mrmurphy-apps' ); ?></strong></td>
							<td><code style="font-size:1.1em"><?php echo esc_html( $app_password ); ?></code></td></tr>
					</table>
					<p><em><?php esc_html_e( 'This password will not be shown again. Store it securely.', 'mrmurphy-apps' ); ?></em></p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:400px">
				<?php wp_nonce_field( 'mrmurphy_create_agent', 'mrmurphy_create_agent_nonce' ); ?>
				<input type="hidden" name="action" value="mrmurphy_create_agent">

				<table class="form-table">
					<tr>
						<th scope="row"><label for="agent_username"><?php esc_html_e( 'Username', 'mrmurphy-apps' ); ?></label></th>
						<td><input type="text" id="agent_username" name="agent_username" class="regular-text" value="agent" required></td>
					</tr>
					<tr>
						<th scope="row"><label for="agent_email"><?php esc_html_e( 'Email', 'mrmurphy-apps' ); ?></label></th>
						<td><input type="email" id="agent_email" name="agent_email" class="regular-text" placeholder="agent@example.com" required></td>
					</tr>
					<tr>
						<th scope="row"><label for="agent_display_name"><?php esc_html_e( 'Display Name', 'mrmurphy-apps' ); ?></label></th>
						<td><input type="text" id="agent_display_name" name="agent_display_name" class="regular-text" value="AI Agent"></td>
					</tr>
				</table>

				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Create Agent User', 'mrmurphy-apps' ); ?></button>
				</p>
			</form>

			<hr>

			<h2 style="margin-top:2em"><?php esc_html_e( 'Manual Setup (WP-CLI)', 'mrmurphy-apps' ); ?></h2>

			<pre style="background:#f0f0f1;padding:1em;max-width:800px;overflow-x:auto"># Create the user and generate an application password:
wp user create agent agent@example.com \
  --role=mrmurphy_agent \
  --display_name="AI Agent" \
  --user_pass

# The command will output the generated password.

# Create an application password for an existing user:
wp user application-password create agent "opencode"</pre>

			<hr>

			<h2 style="margin-top:2em"><?php esc_html_e( 'Agent Config Example', 'mrmurphy-apps' ); ?></h2>

			<p><?php esc_html_e( 'Add these environment variables to your agent\'s configuration:', 'mrmurphy-apps' ); ?></p>

			<pre style="background:#f0f0f1;padding:1em;max-width:800px;overflow-x:auto">WP_USER=agent
WP_APP_PASSWORD="xxxx xxxx xxxx xxxx xxxx xxxx"
WP_URL="<?php echo esc_url( $site_url ); ?>"
WP_MGMT_API="<?php echo esc_url( $mgmt_base ); ?>"
WP_APP_API="<?php echo esc_url( home_url( '/wp-json/apps/v1/{slug}' ) ); ?>"

# Then call the API:
# curl -u "$WP_USER:$WP_APP_PASSWORD" "$WP_MGMT_API/apps"
# curl -u "$WP_USER:$WP_APP_PASSWORD" "$WP_MGMT_API/apps/my-app/upload" \
#   -H "Content-Type: application/json" \
#   -d '{"zip_base64": "<base64-zip>"}'</pre>
		</div>
		<?php
	}

	/**
	 * Add an "Agent Instructions" submenu page that renders the API guide inline.
	 */
	public function add_agent_instructions_link() {
		add_submenu_page(
			'edit.php?post_type=mrmurphy_app',
			__( 'Agent Instructions', 'mrmurphy-apps' ),
			__( 'Agent Instructions', 'mrmurphy-apps' ),
			'manage_mrmurphy_apps',
			'mrmurphy-agent-instructions',
			array( $this, 'render_instructions_page' )
		);
	}

	/**
	 * Render the full API guide inside an admin page with a copy-to-clipboard button.
	 */
	public function render_instructions_page() {
		$instructions = MRMurphy_Apps_REST::build_instructions();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Agent Instructions', 'mrmurphy-apps' ); ?></h1>
			<p><?php esc_html_e( 'Copy this guide and paste it into an AI agent to let it learn the API:', 'mrmurphy-apps' ); ?></p>
			<p>
				<button id="mrmurphy-copy-instructions" class="button button-primary" onclick="
					var ta = document.getElementById('mrmurphy-instructions-text');
					ta.select();
					ta.setSelectionRange(0, ta.value.length);
					document.execCommand('copy');
					this.textContent = this.dataset.copied;
					var btn = this;
					setTimeout(function(){ btn.textContent = btn.dataset.original; }, 2000);
				" data-original="Copy to Clipboard" data-copied="Copied!">Copy to Clipboard</button>
			</p>
			<textarea id="mrmurphy-instructions-text" readonly style="width:100%;height:70vh;font-family:monospace;font-size:13px;line-height:1.5;padding:1em;background:#f0f0f1;border:1px solid #c3c4c7;white-space:pre;overflow:auto;tab-size:2;box-sizing:border-box"><?php echo esc_html( $instructions ); ?></textarea>
		</div>
		<?php
	}

	/**
	 * Handle the Create Agent form submission.
	 */
	public function handle_create_agent() {
		if ( ! isset( $_POST['mrmurphy_create_agent_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mrmurphy_create_agent_nonce'] ) ), 'mrmurphy_create_agent' )
		) {
			wp_die( esc_html__( 'Security check failed.', 'mrmurphy-apps' ) );
		}

		if ( ! current_user_can( 'manage_mrmurphy_apps' ) ) {
			wp_die( esc_html__( 'You do not have permission to create users.', 'mrmurphy-apps' ) );
		}

		$username     = sanitize_user( wp_unslash( $_POST['agent_username'] ?? '' ) );
		$email        = sanitize_email( wp_unslash( $_POST['agent_email'] ?? '' ) );
		$display_name = sanitize_text_field( wp_unslash( $_POST['agent_display_name'] ?? '' ) );

		if ( '' === $username || '' === $email ) {
			wp_die( esc_html__( 'Username and email are required.', 'mrmurphy-apps' ) );
		}

		if ( username_exists( $username ) ) {
			wp_die( esc_html__( 'That username already exists.', 'mrmurphy-apps' ) );
		}

		if ( email_exists( $email ) ) {
			wp_die( esc_html__( 'That email is already in use.', 'mrmurphy-apps' ) );
		}

		$password = wp_generate_password( 24, true, false );

		$user_id = wp_insert_user(
			array(
				'user_login'   => $username,
				'user_email'   => $email,
				'display_name' => $display_name ?: $username,
				'role'         => 'mrmurphy_agent',
				'user_pass'    => $password,
			)
		);

		if ( is_wp_error( $user_id ) ) {
			wp_die( esc_html( $user_id->get_error_message() ) );
		}

		// Generate an application password for the new user.
		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			require_once ABSPATH . 'wp-includes/class-wp-application-passwords.php';
		}
		$app_pass_result = WP_Application_Passwords::create_new_application_password( $user_id, array( 'name' => 'opencode' ) );

		if ( is_wp_error( $app_pass_result ) ) {
			wp_die( esc_html( $app_pass_result->get_error_message() ) );
		}

		list( $new_password, $item ) = $app_pass_result;

		set_transient(
			'mrmurphy_agent_created',
			array(
				'user'     => $username,
				'password' => $new_password,
			),
			60
		);

		wp_safe_redirect(
			add_query_arg(
				array( 'post_type' => 'mrmurphy_app', 'page' => 'mrmurphy-apps-getting-started' ),
				admin_url( 'edit.php' )
			)
		);
		exit;
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
