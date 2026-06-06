<?php
/**
 * Visit tracking.
 *
 * @package MrMurphyApps
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores and reports app visit stats.
 */
class MRMurphy_Apps_Stats {

	/**
	 * Get the visits table name.
	 *
	 * @return string
	 */
	public static function get_table_name() {
		global $wpdb;

		return $wpdb->prefix . 'mrmurphy_app_visits';
	}

	/**
	 * Create the visits table.
	 */
	public static function create_table() {
		global $wpdb;

		$table   = self::get_table_name();
		$charset = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				app_id bigint(20) unsigned NOT NULL,
				app_slug varchar(200) NOT NULL,
				request_path varchar(500) NOT NULL DEFAULT '',
				referrer varchar(500) NOT NULL DEFAULT '',
				visitor_hash char(64) NOT NULL DEFAULT '',
				visited_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY app_id (app_id),
				KEY app_slug (app_slug),
				KEY visited_at (visited_at)
			) {$charset};"
		);
	}

	/**
	 * Log a visit.
	 *
	 * @param array $data Visit data.
	 */
	public static function log_visit( array $data ) {
		global $wpdb;

		$wpdb->insert(
			self::get_table_name(),
			array(
				'app_id'       => (int) ( $data['app_id'] ?? 0 ),
				'app_slug'     => sanitize_title( $data['app_slug'] ?? '' ),
				'request_path' => sanitize_text_field( $data['request_path'] ?? '' ),
				'referrer'     => sanitize_text_field( $data['referrer'] ?? '' ),
				'visitor_hash' => sanitize_text_field( $data['visitor_hash'] ?? '' ),
				'visited_at'   => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Build a privacy-preserving visitor hash.
	 *
	 * @return string
	 */
	public static function hash_visitor() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

		return hash( 'sha256', $ip . '|' . $ua . '|' . wp_salt( 'auth' ) );
	}

	/**
	 * Get summary stats for an app.
	 *
	 * @param int $app_id App post ID.
	 * @return array{total:int,unique:int,last_7_days:int,last_visit:string}
	 */
	public function get_app_summary( $app_id ) {
		global $wpdb;

		$table = self::get_table_name();
		$app_id = (int) $app_id;

		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE app_id = %d",
				$app_id
			)
		);

		$unique = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT visitor_hash) FROM {$table} WHERE app_id = %d",
				$app_id
			)
		);

		$since = gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) );

		$last_7_days = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE app_id = %d AND visited_at >= %s",
				$app_id,
				$since
			)
		);

		$last_visit = (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT visited_at FROM {$table} WHERE app_id = %d ORDER BY visited_at DESC LIMIT 1",
				$app_id
			)
		);

		return array(
			'total'        => $total,
			'unique'       => $unique,
			'last_7_days'  => $last_7_days,
			'last_visit'   => $last_visit,
		);
	}

	/**
	 * Get recent visits for an app.
	 *
	 * @param int $app_id App post ID.
	 * @param int $limit  Max rows.
	 * @return array<int, object>
	 */
	public function get_recent_visits( $app_id, $limit = 10 ) {
		global $wpdb;

		$table = self::get_table_name();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT request_path, referrer, visited_at FROM {$table} WHERE app_id = %d ORDER BY visited_at DESC LIMIT %d",
				(int) $app_id,
				(int) $limit
			)
		);
	}
}
