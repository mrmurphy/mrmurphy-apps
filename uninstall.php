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
	$app_id = (int) $app_id;

	// Clean up app-scoped evar meta keys.
	$all_meta = get_post_meta( $app_id );
	foreach ( $all_meta as $meta_key => $meta_values ) {
		if ( 0 === strpos( $meta_key, '_mrmurphy_app_evar_' ) ) {
			delete_post_meta( $app_id, $meta_key );
		}
	}

	wp_delete_post( $app_id, true );
}

// Clean up global evar options.
$rows = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( '_mrmurphy_global_evar_' ) . '%'
	)
);
foreach ( $rows as $option_name ) {
	delete_option( $option_name );
}

$storage = new MRMurphy_Apps_Storage();
MRMurphy_Apps_Storage::delete_directory( MRMurphy_Apps_Storage::get_base_directory() );
