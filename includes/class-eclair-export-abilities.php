<?php
/**
 * Abilities (WordPress 6.9+): the diagnostic and the migration can be run by an AI assistant such as Claude or
 * ChatGPT, through the WordPress Abilities API and its MCP adapter. Nothing is registered on an older WordPress.
 * Same rights as the screen: administrators only.
 *
 * @package EclairExport
 */

defined( 'ABSPATH' ) || exit;

final class Eclair_Export_Abilities {

	public static function boot() {
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'categories' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register' ) );
	}

	public static function categories() {
		if ( function_exists( 'wp_register_ability_category' ) ) {
			wp_register_ability_category( 'eclair-export', array(
				'label'       => __( 'Migration to Éclair', 'eclair-export' ),
				'description' => __( 'Diagnose this site and move it to Éclair CMS.', 'eclair-export' ),
			) );
		}
	}

	public static function can() {
		return current_user_can( Eclair_Export_Plugin::CAP );
	}

	public static function register() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		wp_register_ability( 'eclair-export/diagnose', array(
			'label'               => __( 'Diagnose the migration to Éclair', 'eclair-export' ),
			'description'         => __( 'Read only. What this WordPress site holds (contents, media, plugins, shortcodes, SEO), what moves to Éclair CMS as is, what needs care, a compatibility score and the estimated time.', 'eclair-export' ),
			'category'            => 'eclair-export',
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => array( __CLASS__, 'diagnose' ),
			'permission_callback' => array( __CLASS__, 'can' ),
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
				'mcp'          => array( 'public' => true ),
			),
		) );
		wp_register_ability( 'eclair-export/migrate', array(
			'label'               => __( 'Export this site to Éclair', 'eclair-export' ),
			'description'         => __( 'Prepares the export package (contents, media, SEO, redirections) and, with a link key created on the Éclair site (Import WordPress › Create a link key), sends it there. Works in short steps: call it again with the same input until "stage" is "delivered" or "ready". Nothing is changed on this WordPress site. The import itself is then started by a person in Éclair.', 'eclair-export' ),
			'category'            => 'eclair-export',
			'input_schema'        => array(
				'type'                 => 'object',
				'default'              => array(),
				'properties'           => array(
					'link_key' => array( 'type' => 'string', 'description' => 'Link key of the Éclair site (starts with "eclair1."). Without it the package is only prepared, to be downloaded from Tools › Export to Éclair.' ),
					'media'    => array( 'type' => 'string', 'enum' => array( 'include', 'remote' ), 'description' => '"include" (default): files in the package. "remote": Éclair downloads them from this site during the import.' ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => array( __CLASS__, 'migrate' ),
			'permission_callback' => array( __CLASS__, 'can' ),
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
				'mcp'          => array( 'public' => true ),
			),
		) );
	}

	/** Compact diagnostic: the numbers and the points to watch, without the long lists of the report. */
	public static function diagnose( $input = null ) {
		$d = Eclair_Export_Diagnostic::run( 25 );
		return array(
			'site'          => array( 'name' => $d['site']['name'], 'url' => $d['site']['url'], 'wordpress' => $d['site']['wordpress'], 'theme' => $d['site']['theme'] ),
			'compatibility' => (int) $d['score'],
			'contents'      => array_map( static function ( $c ) {
				return array( 'label' => $c['label'], 'total' => $c['total'], 'published' => $c['publish'] );
			}, $d['content'] ),
			'media'         => array( 'files' => (int) $d['media']['count'], 'bytes' => (int) $d['media']['bytes'] ),
			'plugins'       => array_values( wp_list_pluck( $d['plugins'], 'name' ) ),
			'seo'           => isset( $d['seo'] ) ? $d['seo'] : null,
			'points'        => $d['risks'],
			'estimate'      => array( 'export_seconds' => (int) $d['estimate']['export'], 'import_seconds' => (int) $d['estimate']['import'] ),
			'screen'        => admin_url( 'tools.php?page=' . Eclair_Export_Plugin::SLUG ),
		);
	}

	/** One step of the migration: export, then transfer when a link key was given. */
	public static function migrate( $input = null ) {
		$input = is_array( $input ) ? $input : array();
		$key   = isset( $input['link_key'] ) ? (string) $input['link_key'] : '';
		try {
			$job = Eclair_Export_Exporter::job();
			if ( ! $job ) {
				Eclair_Export_Sender::cancel();
				if ( '' !== $key ) {
					Eclair_Export_Sender::connect( $key );
				}
				$job = Eclair_Export_Exporter::start( array( 'media' => isset( $input['media'] ) ? (string) $input['media'] : 'include', 'part_mb' => 100 ) );
			} elseif ( '' !== $key && ! Eclair_Export_Sender::state() ) {
				Eclair_Export_Sender::connect( $key );
			}
			if ( 'running' === $job['status'] ) {
				$job = Eclair_Export_Exporter::step( 20 );
				Eclair_Export_Sender::keepalive();
			}
		} catch ( Throwable $e ) {
			return new WP_Error( 'eclair_export', $e->getMessage() );
		}
		$progress = Eclair_Export_Exporter::progress( $job );
		if ( ! $job || 'done' !== $job['status'] ) {
			return array( 'stage' => 'export', 'percent' => $progress['percent'], 'message' => $progress['label'], 'error' => $progress['error'], 'next' => 'Call again with the same input.' );
		}
		if ( ! Eclair_Export_Sender::state() ) {
			return array( 'stage' => 'ready', 'percent' => 100, 'message' => __( 'The package is ready: download it from Tools › Export to Éclair, or call again with a link key to send it.', 'eclair-export' ), 'screen' => admin_url( 'tools.php?page=' . Eclair_Export_Plugin::SLUG ) );
		}
		$send = Eclair_Export_Sender::progress( Eclair_Export_Sender::step( 20 ) );
		if ( 'done' === $send['status'] ) {
			Eclair_Export_Sender::cancel();
			return array( 'stage' => 'delivered', 'percent' => 100, 'message' => $send['label'], 'next' => 'A person now opens the Éclair administration › Import WordPress, runs the simulation, then the import.' );
		}
		return array( 'stage' => 'transfer', 'percent' => $send['percent'], 'message' => $send['label'], 'error' => $send['error'], 'next' => 'Call again with the same input.' );
	}
}
