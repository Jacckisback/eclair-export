<?php
/**
 * Private storage of the exports: one folder with a random name inside uploads, closed to visitors (.htaccess,
 * web.config, empty index files), every archive with a random name, everything deleted after download or after 24 h.
 *
 * @package EclairExport
 */

defined( 'ABSPATH' ) || exit;

final class Eclair_Export_Storage {

	const OPTION = 'eclair_export_storage';
	const TTL    = DAY_IN_SECONDS;

	/** Private folder (created and protected on first use). Throws when uploads is not writable. */
	public static function dir() {
		$name = get_option( self::OPTION );
		if ( ! is_string( $name ) || ! preg_match( '~^eclair-export-[a-f0-9]{32}$~', $name ) ) {
			$name = 'eclair-export-' . bin2hex( random_bytes( 16 ) );
			update_option( self::OPTION, $name, false );
		}
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) ) {
			throw new RuntimeException( (string) $uploads['error'] );
		}
		$dir = trailingslashit( $uploads['basedir'] ) . $name;
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			throw new RuntimeException( __( 'The uploads folder is not writable: the export cannot be prepared.', 'eclair-export' ) );
		}
		self::protect( $dir );
		return $dir;
	}

	/** Files that close a folder to visitors on Apache, LiteSpeed and IIS (on Nginx, the random name protects it). */
	public static function protect( $dir ) {
		$files = array(
			'.htaccess'  => "# Eclair Export: private files, never served.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\nOptions -Indexes\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization><directoryBrowse enabled=\"false\" /></system.webServer></configuration>\n",
			'index.php'  => "<?php\n// Silence is golden.\n",
			'index.html' => '',
		);
		foreach ( $files as $file => $content ) {
			if ( ! file_exists( $dir . '/' . $file ) ) {
				file_put_contents( $dir . '/' . $file, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
	}

	/** Working folder of an export job. */
	public static function job_dir( $job_id ) {
		$dir = self::dir() . '/' . preg_replace( '~[^a-f0-9]~', '', (string) $job_id );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			self::protect( $dir );
		}
		return $dir;
	}

	/** Random file name for an archive: eclair-export-<host>-<date>-<random>. */
	public static function archive_name() {
		$host = sanitize_title( Eclair_Export_Util::host( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
		return 'eclair-export-' . ( '' !== $host ? $host : 'site' ) . '-' . gmdate( 'Ymd-His' ) . '-' . bin2hex( random_bytes( 6 ) );
	}

	/** Deletes every export older than $age seconds (all of them with 0). Returns the number of folders removed. */
	public static function cleanup( $age = self::TTL ) {
		$name = get_option( self::OPTION );
		if ( ! is_string( $name ) || '' === $name ) {
			return 0;
		}
		$uploads = wp_upload_dir( null, false );
		$dir     = trailingslashit( $uploads['basedir'] ) . $name;
		if ( ! is_dir( $dir ) ) {
			return 0;
		}
		$removed = 0;
		foreach ( (array) scandir( $dir ) as $entry ) {
			if ( ! preg_match( '~^[a-f0-9]{32}$~', (string) $entry ) ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if ( 0 === $age || filemtime( $path ) < time() - $age ) {
				self::delete_tree( $path );
				$removed++;
			}
		}
		if ( 0 === $age ) {
			self::delete_tree( $dir );
			delete_option( self::OPTION );
		}
		return $removed;
	}

	/** Recursive delete, restricted to the private folder. */
	public static function delete_tree( $path ) {
		$path = (string) $path;
		if ( '' === $path || false === strpos( str_replace( '\\', '/', $path ), '/eclair-export-' ) ) {
			return;
		}
		if ( is_file( $path ) || is_link( $path ) ) {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}
		foreach ( (array) scandir( $path ) as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				self::delete_tree( $path . '/' . $entry );
			}
		}
		@rmdir( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	/**
	 * Sends a file to the browser in chunks. Returns true when the whole file left the server (the caller then deletes it).
	 */
	public static function stream( $file, $download_name, $type = 'application/zip' ) {
		if ( ! is_file( $file ) ) {
			return false;
		}
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: ' . $type );
		header( 'Content-Disposition: attachment; filename="' . str_replace( '"', '', $download_name ) . '"' );
		header( 'Content-Length: ' . filesize( $file ) );
		header( 'X-Content-Type-Options: nosniff' );
		$handle = fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $handle ) {
			return false;
		}
		ignore_user_abort( true );
		while ( ! feof( $handle ) ) {
			echo fread( $handle, 1048576 ); // phpcs:ignore WordPress.Security.EscapeOutput, WordPress.WP.AlternativeFunctions
			flush();
			if ( connection_aborted() ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				return false;
			}
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return true;
	}
}
