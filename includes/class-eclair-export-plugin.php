<?php
/**
 * WordPress side: the Tools › Export to Éclair screen, its AJAX actions, downloads and the clean-up task.
 * Every action requires the manage_options capability and a valid nonce.
 *
 * @package EclairExport
 */

defined( 'ABSPATH' ) || exit;

final class Eclair_Export_Plugin {

	const CAP   = 'manage_options';
	const NONCE = 'eclair_export';
	const CRON  = 'eclair_export_cleanup';
	const SLUG  = 'eclair-export';
	/** Languages of the report. */
	const LOCALES = array( 'fr_FR' => 'Français', 'es_ES' => 'Español', 'en_US' => 'English' );

	public static function boot() {
		add_action( 'init', array( __CLASS__, 'textdomain' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( self::CRON, array( __CLASS__, 'cleanup' ) );
		foreach ( array( 'diagnose', 'start', 'step', 'cancel', 'branding' ) as $action ) {
			add_action( 'wp_ajax_eclair_export_' . $action, array( __CLASS__, 'ajax_' . $action ) );
		}
		add_action( 'admin_post_eclair_export_report', array( __CLASS__, 'report' ) );
		add_action( 'admin_post_eclair_export_download', array( __CLASS__, 'download' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( ECLAIR_EXPORT_FILE ), array( __CLASS__, 'links' ) );
	}

	public static function textdomain() {
		load_plugin_textdomain( 'eclair-export', false, dirname( plugin_basename( ECLAIR_EXPORT_FILE ) ) . '/languages' );
	}

	public static function activate() {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CRON );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON );
		Eclair_Export_Exporter::cancel();
		Eclair_Export_Storage::cleanup( 0 );
	}

	/** Hourly: every export older than 24 hours is deleted, finished or not. */
	public static function cleanup() {
		$job = Eclair_Export_Exporter::job();
		if ( $job && (int) $job['created'] < time() - Eclair_Export_Storage::TTL ) {
			Eclair_Export_Exporter::cancel();
		}
		Eclair_Export_Storage::cleanup();
	}

	public static function menu() {
		add_management_page( __( 'Export to Éclair', 'eclair-export' ), __( 'Export to Éclair', 'eclair-export' ), self::CAP, self::SLUG, array( __CLASS__, 'page' ) );
	}

	public static function links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'tools.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Open', 'eclair-export' ) . '</a>' );
		return $links;
	}

	public static function assets( $hook ) {
		if ( 'tools_page_' . self::SLUG !== $hook ) {
			return;
		}
		$url = plugins_url( 'assets/', ECLAIR_EXPORT_FILE );
		wp_enqueue_style( 'eclair-export', $url . 'admin.css', array(), ECLAIR_EXPORT_VERSION );
		wp_enqueue_script( 'eclair-export', $url . 'admin.js', array(), ECLAIR_EXPORT_VERSION, true );
		wp_localize_script( 'eclair-export', 'EclairExport', array(
			'ajax'     => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( self::NONCE ),
			'progress' => self::with_links( Eclair_Export_Exporter::progress( Eclair_Export_Exporter::job() ) ),
			'i18n'     => array(
				'running'   => __( 'Diagnostic in progress… (up to a minute on a large site)', 'eclair-export' ),
				'failed'    => __( 'The request failed. Check your connection, then try again.', 'eclair-export' ),
				'retry'     => __( 'Connection lost, new try in a few seconds…', 'eclair-export' ),
				'paused'    => __( 'Export paused: it will resume from where it stopped.', 'eclair-export' ),
				'confirm'   => __( 'Cancel the export and delete the files already prepared?', 'eclair-export' ),
				'download'  => __( 'Download', 'eclair-export' ),
				/* translators: %d: part number */
				'part'      => __( 'Part %d', 'eclair-export' ),
				'saved'     => __( 'Saved.', 'eclair-export' ),
				'leave'     => __( 'The export is running: if you leave, it will resume when you come back.', 'eclair-export' ),
			),
		) );
	}

	public static function page() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to export this site.', 'eclair-export' ), 403 );
		}
		$diagnostic = Eclair_Export_Diagnostic::last();
		$job        = Eclair_Export_Exporter::job();
		$branding   = Eclair_Export_Report::branding();
		$types      = Eclair_Export_Util::content_types();
		include ECLAIR_EXPORT_DIR . '/admin/view-page.php';
	}

	/** Summary of a diagnostic shown on the screen (the full report is the HTML page). */
	public static function summary( array $d ) {
		$levels = array( 'high' => __( 'To solve', 'eclair-export' ), 'medium' => __( 'To check', 'eclair-export' ), 'info' => __( 'Good to know', 'eclair-export' ) );
		$out    = '<div class="ee-cards">';
		$cards  = array(
			array( number_format_i18n( $d['score'] ) . '/100', __( 'compatibility with Éclair', 'eclair-export' ) ),
			array( number_format_i18n( $d['total_content'] ), __( 'contents', 'eclair-export' ) ),
			array( number_format_i18n( $d['media']['count'] ), Eclair_Export_Util::size( $d['media']['bytes'] ) . ( $d['media']['complete'] ? '' : '+' ) . ' ' . __( 'of media', 'eclair-export' ) ),
			array( Eclair_Export_Util::duration( $d['estimate']['export'] + $d['estimate']['import'] ), __( 'estimated transfer time', 'eclair-export' ) ),
		);
		foreach ( $cards as $card ) {
			$out .= '<div class="ee-card"><b>' . esc_html( $card[0] ) . '</b><span>' . esc_html( $card[1] ) . '</span></div>';
		}
		$out .= '</div>';
		if ( $d['plugins'] ) {
			$names = array();
			foreach ( $d['plugins'] as $p ) {
				$names[] = $p['name'] . ( '' !== $p['version'] ? ' ' . $p['version'] : '' );
			}
			$out .= '<p><strong>' . esc_html__( 'Detected:', 'eclair-export' ) . '</strong> ' . esc_html( implode( ', ', $names ) ) . '</p>';
		}
		$out .= '<ul class="ee-risks">';
		if ( ! $d['risks'] ) {
			$out .= '<li><span class="ee-pill ee-ok">' . esc_html__( 'None', 'eclair-export' ) . '</span> ' . esc_html__( 'Nothing blocks the migration.', 'eclair-export' ) . '</li>';
		}
		foreach ( $d['risks'] as $risk ) {
			$out .= '<li><span class="ee-pill ee-' . esc_attr( $risk['level'] ) . '">' . esc_html( $levels[ $risk['level'] ] ) . '</span> <strong>' . esc_html( $risk['title'] ) . '</strong><br><span class="ee-muted">' . esc_html( $risk['detail'] ) . '</span></li>';
		}
		$out .= '</ul>';
		/* translators: %s: date and time */
		$out .= '<p class="ee-muted">' . esc_html( sprintf( __( 'Diagnostic of %s.', 'eclair-export' ), date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $d['generated'] + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ) ) ) . '</p>';
		return $out;
	}

	/* ---------------------------------------------------------------- AJAX */

	private static function guard() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to export this site.', 'eclair-export' ) ), 403 );
		}
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'This page has expired: reload it.', 'eclair-export' ) ), 403 );
		}
	}

	public static function ajax_diagnose() {
		self::guard();
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$d = Eclair_Export_Diagnostic::run( 25 );
		wp_send_json_success( array( 'html' => self::summary( $d ) ) );
	}

	public static function ajax_start() {
		self::guard();
		$raw = array(
			'media'   => isset( $_POST['media'] ) ? sanitize_key( wp_unslash( $_POST['media'] ) ) : 'include',
			'part_mb' => isset( $_POST['part_mb'] ) ? (int) $_POST['part_mb'] : 100,
			'types'   => isset( $_POST['types'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['types'] ) ) : array(),
			'emails'  => ! empty( $_POST['emails'] ),
		);
		try {
			$job = Eclair_Export_Exporter::start( $raw );
		} catch ( Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
		wp_send_json_success( self::with_links( Eclair_Export_Exporter::progress( $job ) ) );
	}

	public static function ajax_step() {
		self::guard();
		session_write_close();
		$job = Eclair_Export_Exporter::step();
		wp_send_json_success( self::with_links( Eclair_Export_Exporter::progress( $job ) ) );
	}

	public static function ajax_cancel() {
		self::guard();
		Eclair_Export_Exporter::cancel();
		wp_send_json_success( Eclair_Export_Exporter::progress( null ) );
	}

	public static function ajax_branding() {
		self::guard();
		Eclair_Export_Report::save_branding( isset( $_POST['branding'] ) ? (array) wp_unslash( $_POST['branding'] ) : array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		wp_send_json_success();
	}

	/** Download links (with their nonce) of the finished parts. */
	public static function with_links( array $progress ) {
		if ( ! empty( $progress['parts'] ) ) {
			foreach ( $progress['parts'] as $i => $part ) {
				$progress['parts'][ $i ]['url']   = wp_nonce_url( admin_url( 'admin-post.php?action=eclair_export_download&file=' . rawurlencode( $part['file'] ) ), self::NONCE );
				$progress['parts'][ $i ]['human'] = Eclair_Export_Util::size( isset( $part['size'] ) ? $part['size'] : $part['bytes'] );
			}
		}
		return $progress;
	}

	/* ---------------------------------------------------------------- Downloads */

	private static function guard_get() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to export this site.', 'eclair-export' ), 403 );
		}
		check_admin_referer( self::NONCE );
	}

	/** Report as a file (or as a page to print / save as PDF), in the language asked for. */
	public static function report() {
		self::guard_get();
		$d = Eclair_Export_Diagnostic::last();
		if ( ! $d ) {
			wp_die( esc_html__( 'Run the diagnostic first.', 'eclair-export' ) );
		}
		$locale = isset( $_GET['locale'] ) ? sanitize_text_field( wp_unslash( $_GET['locale'] ) ) : '';
		$print  = ! empty( $_GET['print'] );
		$html   = self::in_locale( $locale, static function () use ( $d, $print ) {
			$d['risks'] = Eclair_Export_Diagnostic::risks( $d );
			return Eclair_Export_Report::html( $d, $print );
		} );
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		if ( ! $print ) {
			header( 'Content-Disposition: attachment; filename="' . Eclair_Export_Report::filename( $d ) . '"' );
		}
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	/** Runs $fn with the plugin texts in $locale (fr_FR, es_ES or en_US). */
	public static function in_locale( $locale, callable $fn ) {
		if ( ! array_key_exists( (string) $locale, self::LOCALES ) ) {
			return $fn();
		}
		$switched = switch_to_locale( $locale );
		unload_textdomain( 'eclair-export' );
		if ( 'en_US' !== $locale ) {
			load_textdomain( 'eclair-export', ECLAIR_EXPORT_DIR . '/languages/eclair-export-' . $locale . '.mo' );
		}
		try {
			return $fn();
		} finally {
			unload_textdomain( 'eclair-export' );
			if ( $switched ) {
				restore_previous_locale();
			}
			self::textdomain();
		}
	}

	/** One part of the archive; deleted once it has fully left the server. */
	public static function download() {
		self::guard_get();
		$name = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( $_GET['file'] ) ) : '';
		$file = Eclair_Export_Exporter::part_path( $name );
		if ( ! $file ) {
			wp_die( esc_html__( 'This file no longer exists: it was already downloaded or is more than 24 hours old.', 'eclair-export' ), 404 );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		if ( Eclair_Export_Storage::stream( $file, $name ) ) {
			Eclair_Export_Exporter::downloaded( $name );
		}
		exit;
	}
}
