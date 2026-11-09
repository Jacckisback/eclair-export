<?php
/**
 * Plugin Name:       Eclair Export
 * Plugin URI:        https://github.com/Jacckisback/eclair-export
 * Description:       Diagnose a WordPress site and export it as a complete package (contents, media, SEO, redirects) ready to be imported into Éclair CMS without breaking URLs.
 * Version:           1.0.2
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Thierry Gustin
 * Author URI:        https://github.com/Jacckisback
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       eclair-export
 * Domain Path:       /languages
 *
 * @package EclairExport
 */

// Copyright (c) 2026 Thierry Gustin
//
// This program is free software: you can redistribute it and/or modify it under the terms of the GNU General Public
// License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later
// version. This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the
// implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for details.

defined( 'ABSPATH' ) || exit;

/*
 * Safe start: the plugin never stops WordPress with a fatal error.
 *
 * 1. Two copies of the plugin (for example "eclair-export" and "eclair-export-main", the folder made by GitHub's
 *    "Download ZIP"): the second copy loads nothing, refuses its own activation with a clear message and names
 *    the folder to delete.
 * 2. PHP or WordPress too old: nothing is loaded, a notice says what to update.
 * 3. Any error while loading: caught, the plugin stays inactive and a notice shows the error.
 */

if ( ! function_exists( 'eclair_export_safe_notice' ) ) {
	/** Notice shown in the admin; same text for the activation screen. */
	function eclair_export_safe_notice( $message ) {
		$show = static function () use ( $message ) {
			if ( current_user_can( 'activate_plugins' ) ) {
				echo '<div class="notice notice-error"><p><strong>Eclair Export</strong> — ' . wp_kses_post( $message ) . '</p></div>';
			}
		};
		add_action( 'admin_notices', $show );
		add_action( 'network_admin_notices', $show );
	}
}

if ( ! function_exists( 'eclair_export_refuse_activation' ) ) {
	/** Activation refused with a readable page and a back link, instead of "fatal error". */
	function eclair_export_refuse_activation( $file, $message ) {
		register_activation_hook(
			$file,
			static function () use ( $message ) {
				wp_die( '<p>' . wp_kses_post( $message ) . '</p>', 'Eclair Export', array( 'back_link' => true, 'response' => 200 ) );
			}
		);
	}
}

// 1. Another copy is already loaded.
if ( defined( 'ECLAIR_EXPORT_FILE' ) || class_exists( 'Eclair_Export_Plugin', false ) ) {
	$eclair_export_other = defined( 'ECLAIR_EXPORT_FILE' ) ? basename( dirname( ECLAIR_EXPORT_FILE ) ) : '?';
	$eclair_export_this  = basename( __DIR__ );
	$eclair_export_msg   = sprintf(
		'Une autre copie de l’extension est déjà active (dossier <code>%1$s</code>). Gardez-en une seule : supprimez le dossier <code>%2$s</code> dans Extensions. / Another copy is already active (folder <code>%1$s</code>): delete the folder <code>%2$s</code>. / Ya hay otra copia activa (carpeta <code>%1$s</code>): elimine la carpeta <code>%2$s</code>.',
		esc_html( $eclair_export_other ),
		esc_html( $eclair_export_this )
	);
	if ( $eclair_export_other !== $eclair_export_this ) {
		eclair_export_refuse_activation( __FILE__, $eclair_export_msg );
		eclair_export_safe_notice( $eclair_export_msg );
	}
	unset( $eclair_export_other, $eclair_export_this, $eclair_export_msg );
	return;
}

// 2. Versions.
$eclair_export_problems = array();
if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
	$eclair_export_problems[] = sprintf( 'PHP 7.4 minimum (ce serveur / this server: %s).', esc_html( PHP_VERSION ) );
}
if ( isset( $GLOBALS['wp_version'] ) && version_compare( $GLOBALS['wp_version'], '6.0', '<' ) ) {
	$eclair_export_problems[] = sprintf( 'WordPress 6.0 minimum (ce site / this site: %s).', esc_html( $GLOBALS['wp_version'] ) );
}
if ( $eclair_export_problems ) {
	$eclair_export_msg = 'Impossible de démarrer / Cannot start: ' . implode( ' ', $eclair_export_problems );
	eclair_export_refuse_activation( __FILE__, $eclair_export_msg );
	eclair_export_safe_notice( $eclair_export_msg );
	unset( $eclair_export_problems, $eclair_export_msg );
	return;
}
unset( $eclair_export_problems );

define( 'ECLAIR_EXPORT_VERSION', '1.0.2' );
define( 'ECLAIR_EXPORT_FORMAT', '1.0' );
define( 'ECLAIR_EXPORT_FILE', __FILE__ );
define( 'ECLAIR_EXPORT_DIR', __DIR__ );

// 3. Loading.
try {
	foreach ( array( 'util', 'storage', 'detector', 'multilang', 'shortcodes', 'converter', 'seo', 'redirects', 'collector', 'diagnostic', 'report', 'exporter', 'plugin' ) as $eclair_export_part ) {
		$eclair_export_path = __DIR__ . '/includes/class-eclair-export-' . $eclair_export_part . '.php';
		if ( ! is_readable( $eclair_export_path ) ) {
			throw new RuntimeException( 'Fichier manquant / Missing file: includes/class-eclair-export-' . $eclair_export_part . '.php — réinstallez l’extension avec le ZIP officiel / reinstall the plugin from the official ZIP.' );
		}
		require_once $eclair_export_path;
	}
	unset( $eclair_export_part, $eclair_export_path );

	Eclair_Export_Plugin::boot();

	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		require_once __DIR__ . '/includes/class-eclair-export-cli.php';
		WP_CLI::add_command( 'eclair', 'Eclair_Export_CLI' );
	}

	register_activation_hook( __FILE__, array( 'Eclair_Export_Plugin', 'activate' ) );
	register_deactivation_hook( __FILE__, array( 'Eclair_Export_Plugin', 'deactivate' ) );
} catch ( Throwable $eclair_export_error ) {
	$eclair_export_msg = 'Impossible de démarrer / Cannot start: ' . esc_html( $eclair_export_error->getMessage() ) . ' <small>(' . esc_html( basename( $eclair_export_error->getFile() ) . ':' . $eclair_export_error->getLine() ) . ')</small>';
	eclair_export_refuse_activation( __FILE__, $eclair_export_msg );
	eclair_export_safe_notice( $eclair_export_msg );
	unset( $eclair_export_error, $eclair_export_msg );
}
