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

		flush_rewrite_rules();
	}

	/**
	 * Run on plugin deactivation.
	 */
	public static function deactivate() {
		flush_rewrite_rules();
	}
}
