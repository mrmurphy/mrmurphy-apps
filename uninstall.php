<?php
/**
 * Uninstall cleanup.
 *
 * @package MrMurphyApps
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/inc/class-storage.php';
require_once __DIR__ . '/inc/class-stats.php';

global $wpdb;

$table = $wpdb->prefix . 'mrmurphy_app_visits';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

$apps = get_posts(
	array(
		'post_type'      => 'mrmurphy_app',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);

foreach ( $apps as $app_id ) {
	wp_delete_post( (int) $app_id, true );
}

$storage = new MRMurphy_Apps_Storage();
MRMurphy_Apps_Storage::delete_directory( MRMurphy_Apps_Storage::get_base_directory() );
