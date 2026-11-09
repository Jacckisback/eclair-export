<?php
/**
 * Plugin Name:       Eclair Export
 * Plugin URI:        https://github.com/Jacckisback/eclair-export
 * Description:       Diagnose a WordPress site and export it as a complete package (contents, media, SEO, redirects) ready to be imported into Éclair CMS without breaking URLs.
 * Version:           1.0.1
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

define( 'ECLAIR_EXPORT_VERSION', '1.0.1' );
define( 'ECLAIR_EXPORT_FORMAT', '1.0' );
define( 'ECLAIR_EXPORT_FILE', __FILE__ );
define( 'ECLAIR_EXPORT_DIR', __DIR__ );

require_once __DIR__ . '/includes/class-eclair-export-util.php';
require_once __DIR__ . '/includes/class-eclair-export-storage.php';
require_once __DIR__ . '/includes/class-eclair-export-detector.php';
require_once __DIR__ . '/includes/class-eclair-export-multilang.php';
require_once __DIR__ . '/includes/class-eclair-export-shortcodes.php';
require_once __DIR__ . '/includes/class-eclair-export-converter.php';
require_once __DIR__ . '/includes/class-eclair-export-seo.php';
require_once __DIR__ . '/includes/class-eclair-export-redirects.php';
require_once __DIR__ . '/includes/class-eclair-export-collector.php';
require_once __DIR__ . '/includes/class-eclair-export-diagnostic.php';
require_once __DIR__ . '/includes/class-eclair-export-report.php';
require_once __DIR__ . '/includes/class-eclair-export-exporter.php';
require_once __DIR__ . '/includes/class-eclair-export-plugin.php';

Eclair_Export_Plugin::boot();

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/includes/class-eclair-export-cli.php';
	WP_CLI::add_command( 'eclair', 'Eclair_Export_CLI' );
}

register_activation_hook( __FILE__, array( 'Eclair_Export_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Eclair_Export_Plugin', 'deactivate' ) );
