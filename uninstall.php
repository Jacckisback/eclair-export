<?php
/**
 * Uninstall: every export file and every option of the plugin are deleted.
 *
 * @package EclairExport
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Deleting a duplicate copy (e.g. "eclair-export-main") must not erase the data of the copy still in use.
$eclair_export_active = array_merge( (array) get_option( 'active_plugins', array() ), array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
foreach ( $eclair_export_active as $eclair_export_plugin ) {
	if ( '/eclair-export.php' === substr( (string) $eclair_export_plugin, -18 ) && WP_UNINSTALL_PLUGIN !== $eclair_export_plugin ) {
		return;
	}
}

$eclair_export_name = get_option( 'eclair_export_storage' );
if ( is_string( $eclair_export_name ) && preg_match( '~^eclair-export-[a-f0-9]{32}$~', $eclair_export_name ) ) {
	$eclair_export_uploads = wp_upload_dir( null, false );
	$eclair_export_dir     = trailingslashit( $eclair_export_uploads['basedir'] ) . $eclair_export_name;
	if ( is_dir( $eclair_export_dir ) ) {
		$eclair_export_it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $eclair_export_dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $eclair_export_it as $eclair_export_file ) {
			$eclair_export_file->isDir() ? @rmdir( $eclair_export_file->getPathname() ) : @unlink( $eclair_export_file->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		@rmdir( $eclair_export_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}
}
foreach ( array( 'eclair_export_storage', 'eclair_export_job', 'eclair_export_lock', 'eclair_export_diagnostic', 'eclair_export_branding', 'eclair_export_send' ) as $eclair_export_option ) {
	delete_option( $eclair_export_option );
}
wp_clear_scheduled_hook( 'eclair_export_cleanup' );
