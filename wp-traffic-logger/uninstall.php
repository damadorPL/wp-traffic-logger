<?php
/**
 * Uninstall routines.
 *
 * @package WP_Traffic_Logger
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$delete_data = (bool) get_option( 'wtl_delete_data_on_uninstall', 0 );

delete_option( 'wtl_options' );
delete_option( 'wtl_delete_data_on_uninstall' );

if ( ! $delete_data ) {
	return;
}

$upload = wp_upload_dir();
if ( ! empty( $upload['error'] ) || empty( $upload['basedir'] ) ) {
	return;
}

$target_dir = trailingslashit( $upload['basedir'] ) . 'wp-traffic-logger';
if ( ! is_dir( $target_dir ) ) {
	return;
}

if ( 'wp-traffic-logger' !== basename( $target_dir ) ) {
	return;
}

/**
 * Recursively remove plugin log directory.
 *
 * @param string $directory Directory path.
 * @return void
 */
function wtl_rrmdir( $directory ) {
	$items = scandir( $directory );
	if ( ! is_array( $items ) ) {
		return;
	}

	foreach ( $items as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}

		$path = trailingslashit( $directory ) . $item;
		if ( is_dir( $path ) ) {
			wtl_rrmdir( $path );
			continue;
		}

		@unlink( $path );
	}

	@rmdir( $directory );
}

wtl_rrmdir( $target_dir );
