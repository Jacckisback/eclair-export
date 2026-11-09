<?php
/**
 * WP-CLI commands, to migrate many sites one after the other.
 *
 * @package EclairExport
 */

defined( 'ABSPATH' ) || exit;

/**
 * Diagnose a WordPress site and export it for Éclair CMS.
 */
final class Eclair_Export_CLI {

	/**
	 * Diagnoses the site (read only).
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table (summary), json (all data) or html (client report).
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - html
	 * ---
	 *
	 * [--locale=<locale>]
	 * : Language of the html report: fr_FR, es_ES or en_US.
	 *
	 * [--output=<file>]
	 * : Writes the json or html to this file instead of the screen.
	 *
	 * ## EXAMPLES
	 *
	 *     wp eclair diagnose
	 *     wp eclair diagnose --format=html --locale=fr_FR --output=diagnostic.html
	 *
	 * @when after_wp_load
	 */
	public function diagnose( $args, $assoc ) {
		$d      = Eclair_Export_Diagnostic::run( 600 );
		$format = isset( $assoc['format'] ) ? $assoc['format'] : 'table';
		$locale = isset( $assoc['locale'] ) ? $assoc['locale'] : '';
		if ( 'json' === $format ) {
			$this->out( Eclair_Export_Util::json( $d, true ), $assoc );
			return;
		}
		if ( 'html' === $format ) {
			$this->out( Eclair_Export_Plugin::in_locale( $locale, static function () use ( $d ) {
				$d['risks'] = Eclair_Export_Diagnostic::risks( $d );
				return Eclair_Export_Report::html( $d );
			} ), $assoc );
			return;
		}
		WP_CLI::log( sprintf( '%s — %s', $d['site']['name'], $d['site']['url'] ) );
		WP_CLI::log( sprintf( 'Compatibility: %d/100 · %d contents · %d media (%s) · %d redirections', $d['score'], $d['total_content'], $d['media']['count'], Eclair_Export_Util::size( $d['media']['bytes'] ), $d['redirects']['existing'] ) );
		$rows = array();
		foreach ( $d['risks'] as $risk ) {
			$rows[] = array( 'level' => $risk['level'], 'point' => $risk['title'], 'detail' => $risk['detail'] );
		}
		if ( $rows ) {
			WP_CLI\Utils\format_items( 'table', $rows, array( 'level', 'point', 'detail' ) );
		} else {
			WP_CLI::success( 'Nothing blocks the migration.' );
		}
	}

	/**
	 * Exports the site as an Éclair package (ZIP, possibly in parts).
	 *
	 * ## OPTIONS
	 *
	 * [--media=<mode>]
	 * : include (files in the archive) or remote (downloaded from the old site during the import).
	 * ---
	 * default: include
	 * options:
	 *   - include
	 *   - remote
	 * ---
	 *
	 * [--part-size=<mb>]
	 * : Size of each part in MB (50, 100, 250, 500, 1000; 0 = one file).
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--types=<types>]
	 * : Post types to export, separated by commas (default: every content type).
	 *
	 * [--with-emails]
	 * : Include the e-mail addresses of the authors. Passwords are never exported.
	 *
	 * [--output=<dir>]
	 * : Folder where the archive is written (default: current folder).
	 *
	 * ## EXAMPLES
	 *
	 *     wp eclair export --output=/backups/migrations/
	 *     wp eclair export --media=remote --types=post,page
	 *
	 * @when after_wp_load
	 */
	public function export( $args, $assoc ) {
		$output = rtrim( isset( $assoc['output'] ) ? $assoc['output'] : getcwd(), '/\\' );
		if ( ! is_dir( $output ) && ! wp_mkdir_p( $output ) ) {
			WP_CLI::error( "Cannot create the folder {$output}." );
		}
		if ( ! is_writable( $output ) ) {
			WP_CLI::error( "The folder {$output} is not writable." );
		}
		$options = array(
			'media'   => isset( $assoc['media'] ) ? $assoc['media'] : 'include',
			'part_mb' => isset( $assoc['part-size'] ) ? (int) $assoc['part-size'] : 0,
			'emails'  => ! empty( $assoc['with-emails'] ),
		);
		if ( ! empty( $assoc['types'] ) ) {
			$options['types'] = array_map( 'trim', explode( ',', $assoc['types'] ) );
		}
		try {
			$job = Eclair_Export_Exporter::start( $options );
		} catch ( Throwable $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		$bar  = WP_CLI\Utils\make_progress_bar( 'Export', 100 );
		$last = 0;
		while ( $job && 'running' === $job['status'] ) {
			$job      = Eclair_Export_Exporter::step( 30 );
			$progress = Eclair_Export_Exporter::progress( $job );
			if ( $progress['percent'] > $last ) {
				$bar->tick( $progress['percent'] - $last );
				$last = $progress['percent'];
			}
			if ( ! empty( $job['error'] ) && 'running' === $job['status'] ) {
				WP_CLI::warning( $job['error'] . ' (new try)' );
			}
		}
		$bar->finish();
		if ( ! $job || 'done' !== $job['status'] ) {
			$message = $job ? $job['error'] : 'unknown error';
			Eclair_Export_Exporter::cancel();
			WP_CLI::error( 'Export failed: ' . $message );
		}
		$dir   = Eclair_Export_Storage::job_dir( $job['id'] );
		$files = array();
		foreach ( $job['parts'] as $part ) {
			$target = $output . '/' . $part['file'];
			if ( ! @rename( $dir . '/' . $part['file'], $target ) && ! copy( $dir . '/' . $part['file'], $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				WP_CLI::error( "Cannot write {$target}." );
			}
			$files[] = $target . ' (' . Eclair_Export_Util::size( filesize( $target ) ) . ')';
		}
		$report = $job['report'];
		Eclair_Export_Exporter::cancel();
		foreach ( $files as $file ) {
			WP_CLI::log( $file );
		}
		$counts = $job['counts'];
		WP_CLI::log( sprintf( '%d terms · %d authors · %d media · %d redirections', isset( $counts['terms'] ) ? $counts['terms'] : 0, isset( $counts['authors'] ) ? $counts['authors'] : 0, isset( $counts['media'] ) ? $counts['media'] : 0, isset( $counts['redirects'] ) ? $counts['redirects'] : 0 ) );
		foreach ( $report['shortcodes'] as $tag => $row ) {
			WP_CLI::warning( sprintf( 'Shortcode [%s] kept as text: %d uses in %d contents.', $tag, $row['count'], $row['contents'] ) );
		}
		foreach ( $report['errors'] as $error ) {
			WP_CLI::warning( $error );
		}
		WP_CLI::success( 'Package ready.' );
	}

	/**
	 * Deletes every export file kept on this site.
	 *
	 * @when after_wp_load
	 */
	public function cleanup( $args, $assoc ) {
		Eclair_Export_Exporter::cancel();
		$n = Eclair_Export_Storage::cleanup( 0 );
		WP_CLI::success( sprintf( '%d export folder(s) deleted.', $n ) );
	}

	private function out( $content, array $assoc ) {
		if ( ! empty( $assoc['output'] ) ) {
			if ( false === file_put_contents( $assoc['output'], $content ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
				WP_CLI::error( 'Cannot write ' . $assoc['output'] );
			}
			WP_CLI::success( 'Written: ' . $assoc['output'] );
			return;
		}
		WP_CLI::line( $content );
	}
}
