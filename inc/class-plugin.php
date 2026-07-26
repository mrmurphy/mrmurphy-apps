<?php
/**
 * Plugin bootstrap.
 *
 * @package MrMurphyApps
 */

defined( 'ABSPATH' ) || exit;

require_once MRMURPHY_APPS_DIR . 'inc/class-cpt.php';
require_once MRMURPHY_APPS_DIR . 'inc/class-storage.php';
require_once MRMURPHY_APPS_DIR . 'inc/class-router.php';
require_once MRMURPHY_APPS_DIR . 'inc/class-stats.php';
require_once MRMURPHY_APPS_DIR . 'inc/class-admin.php';
require_once MRMURPHY_APPS_DIR . 'inc/class-rest.php';

/**
 * Main plugin loader.
 */
final class MRMurphy_Apps_Plugin {

	/** @var self|null */
	private static $instance = null;

	/** @var MRMurphy_Apps_CPT */
	public $cpt;

	/** @var MRMurphy_Apps_Storage */
	public $storage;

	/** @var MRMurphy_Apps_Router */
	public $router;

	/** @var MRMurphy_Apps_Stats */
	public $stats;

	/** @var MRMurphy_Apps_Admin */
	public $admin;

	/** @var MRMurphy_Apps_REST */
	public $rest;

	/**
	 * Singleton accessor.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->cpt     = new MRMurphy_Apps_CPT();
		$this->storage = new MRMurphy_Apps_Storage();
		$this->router  = new MRMurphy_Apps_Router( $this->storage );
		$this->stats   = new MRMurphy_Apps_Stats();

		if ( is_admin() ) {
			$this->admin = new MRMurphy_Apps_Admin( $this->storage, $this->stats );
		}

		$this->rest = new MRMurphy_Apps_REST();

		add_action( 'init', array( __CLASS__, 'maybe_update_capabilities' ) );
	}

	/**
	 * Ensure capabilities are up to date without requiring re-activation.
	 */
	public static function maybe_update_capabilities() {
		$stored = get_option( 'mrmurphy_apps_caps_version', '' );
		if ( $stored !== MRMURPHY_APPS_VERSION ) {
			self::add_capabilities();
			update_option( 'mrmurphy_apps_caps_version', MRMURPHY_APPS_VERSION );
		}
	}

	/**
	 * Run on plugin activation.
	 */
	public static function activate() {
		$cpt = new MRMurphy_Apps_CPT();
		$cpt->register_post_type();

		$router = new MRMurphy_Apps_Router( new MRMurphy_Apps_Storage() );
		$router->register_rewrite_rules();

		MRMurphy_Apps_Stats::create_table();
		MRMurphy_Apps_Storage::ensure_uploads_directory();

		self::add_capabilities();

		flush_rewrite_rules();
	}

	/**
	 * Register capabilities and roles.
	 */
	public static function add_capabilities() {
		$cap = 'manage_mrmurphy_apps';

		// Grant to Administrator.
		$admin = get_role( 'administrator' );
		if ( $admin instanceof WP_Role ) {
			$admin->add_cap( $cap, true );
			$admin->add_cap( 'manage_mrmurphy_evars', true );
		}

		// Add dedicated agent role.
		add_role(
			'mrmurphy_agent',
			__( 'MrMurphy Agent', 'mrmurphy-apps' ),
			array(
				'read'        => true,
				$cap          => true,
				'level_0'     => true,
			)
		);

		// Ensure caps are present on the agent role.
		// add_role() is a no-op if the role already exists, so retroactive
		// add_cap() calls are needed for caps added in plugin updates.
		$agent = get_role( 'mrmurphy_agent' );
		if ( $agent instanceof WP_Role ) {
			$agent->add_cap( 'manage_mrmurphy_evars', true );
			$agent->add_cap( 'edit_posts', true );
		}
	}

	/**
	 * Run on plugin deactivation.
	 */
	public static function deactivate() {
		$cap = 'manage_mrmurphy_apps';

		$admin = get_role( 'administrator' );
		if ( $admin instanceof WP_Role ) {
			$admin->remove_cap( $cap );
		}

		remove_role( 'mrmurphy_agent' );

		flush_rewrite_rules();
	}
}
