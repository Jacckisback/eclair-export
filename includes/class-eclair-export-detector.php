<?php
/**
 * Detection of the plugins and builders that matter for a migration.
 *
 * @package EclairExport
 */

defined( 'ABSPATH' ) || exit;

final class Eclair_Export_Detector {

	/** Plugins looked for: key => [label, constant holding the version, fallback function or class]. */
	const KNOWN = array(
		'yoast'       => array( 'Yoast SEO', 'WPSEO_VERSION', 'wpseo_init' ),
		'rankmath'    => array( 'Rank Math SEO', 'RANK_MATH_VERSION', 'RankMath' ),
		'aioseo'      => array( 'All in One SEO', 'AIOSEO_VERSION', 'aioseo' ),
		'elementor'   => array( 'Elementor', 'ELEMENTOR_VERSION', '\Elementor\Plugin' ),
		'divi'        => array( 'Divi', 'ET_BUILDER_VERSION', 'et_setup_theme' ),
		'woocommerce' => array( 'WooCommerce', 'WC_VERSION', 'WooCommerce' ),
		'wpml'        => array( 'WPML', 'ICL_SITEPRESS_VERSION', 'SitePress' ),
		'polylang'    => array( 'Polylang', 'POLYLANG_VERSION', 'pll_languages_list' ),
		'redirection' => array( 'Redirection', 'REDIRECTION_VERSION', 'Redirection' ),
		'acf'         => array( 'Advanced Custom Fields', 'ACF_VERSION', 'acf' ),
	);

	/** Detected plugins: key => ['name', 'version']. */
	public static function detected() {
		$out = array();
		foreach ( self::KNOWN as $key => $def ) {
			list( $label, $constant, $symbol ) = $def;
			$version = defined( $constant ) ? (string) constant( $constant ) : '';
			$present = '' !== $version || function_exists( ltrim( $symbol, '\\' ) ) || class_exists( ltrim( $symbol, '\\' ), false );
			if ( 'divi' === $key && ! $present ) {
				$theme   = wp_get_theme();
				$present = in_array( strtolower( (string) $theme->get_template() ), array( 'divi', 'extra' ), true );
				if ( $present ) {
					$version = $theme->parent() ? (string) $theme->parent()->get( 'Version' ) : (string) $theme->get( 'Version' );
				}
			}
			if ( $present ) {
				$out[ $key ] = array( 'name' => $label, 'version' => $version );
			}
		}
		return $out;
	}

	public static function has( $key ) {
		$detected = self::detected();
		return isset( $detected[ $key ] );
	}

	/** Every active plugin (site and network) with its name and version. */
	public static function active_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all    = get_plugins();
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		$out = array();
		foreach ( array_unique( $active ) as $file ) {
			if ( isset( $all[ $file ] ) ) {
				$out[] = array( 'file' => $file, 'name' => $all[ $file ]['Name'], 'version' => $all[ $file ]['Version'] );
			}
		}
		return $out;
	}

	/** Active theme, and its parent when it is a child theme. */
	public static function theme() {
		$theme = wp_get_theme();
		return array(
			'name'     => (string) $theme->get( 'Name' ),
			'version'  => (string) $theme->get( 'Version' ),
			'template' => (string) $theme->get_template(),
			'block'    => method_exists( $theme, 'is_block_theme' ) ? (bool) $theme->is_block_theme() : false,
		);
	}
}
