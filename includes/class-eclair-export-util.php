<?php
/**
 * Small helpers shared by every part of the plugin.
 *
 * @package EclairExport
 */

defined( 'ABSPATH' ) || exit;

final class Eclair_Export_Util {

	/** Meta keys that may hold secrets: never exported, whatever plugin wrote them. */
	const SENSITIVE = '~(pass(word|wd)?|secret|token|api[_-]?key|private[_-]?key|auth|nonce|session|salt|hash|credential|license[_-]?key|otp|2fa)~i';

	/** JSON for the package: UTF-8 kept readable, slashes not escaped, invalid bytes replaced instead of failing. */
	public static function json( $data, $pretty = false ) {
		$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
		if ( defined( 'JSON_INVALID_UTF8_SUBSTITUTE' ) ) {
			$flags |= JSON_INVALID_UTF8_SUBSTITUTE;
		}
		if ( $pretty ) {
			$flags |= JSON_PRETTY_PRINT;
		}
		$out = json_encode( $data, $flags );
		return false === $out ? 'null' : $out;
	}

	/** True when a meta or option key looks like it holds a secret. */
	public static function sensitive( $key ) {
		return (bool) preg_match( self::SENSITIVE, (string) $key );
	}

	/** Home URL of the site, without trailing slash. */
	public static function home() {
		return untrailingslashit( home_url() );
	}

	/**
	 * Path of a URL relative to the site home ("/2024/03/my-post/"), query string kept. Returns '' for a URL on another
	 * host. A site installed in a sub-folder ("/blog") loses that prefix, as the new site will live at the root.
	 */
	public static function path( $url ) {
		$url = (string) $url;
		if ( '' === $url ) {
			return '';
		}
		$parts = wp_parse_url( $url );
		$home  = wp_parse_url( self::home() );
		if ( ! is_array( $parts ) ) {
			return '';
		}
		if ( isset( $parts['host'] ) ) {
			$host = self::host( $parts['host'] );
			if ( ! isset( $home['host'] ) || self::host( $home['host'] ) !== $host ) {
				return '';
			}
		}
		$path = isset( $parts['path'] ) ? $parts['path'] : '/';
		$base = isset( $home['path'] ) ? untrailingslashit( $home['path'] ) : '';
		if ( '' !== $base && 0 === strpos( $path, $base . '/' ) ) {
			$path = substr( $path, strlen( $base ) );
		} elseif ( '' !== $base && $path === $base ) {
			$path = '/';
		}
		if ( '' === $path || '/' !== $path[0] ) {
			$path = '/' . $path;
		}
		return $path . ( isset( $parts['query'] ) && '' !== $parts['query'] ? '?' . $parts['query'] : '' );
	}

	/** Host without "www." and in lower case. */
	public static function host( $host ) {
		return preg_replace( '~^www\.~', '', strtolower( (string) $host ) );
	}

	/** Seconds this request may still spend on a step: half of max_execution_time, between 5 and 20 s. */
	public static function budget() {
		$max = (int) ini_get( 'max_execution_time' );
		if ( $max <= 0 ) {
			return 20;
		}
		return max( 5, min( 20, (int) floor( $max / 2 ) ) );
	}

	/** Human readable size (1.4 GB). */
	public static function size( $bytes ) {
		return size_format( max( 0, (float) $bytes ), $bytes >= 1073741824 ? 1 : 0 );
	}

	/** Human readable duration ("about 12 min"). */
	public static function duration( $seconds ) {
		$seconds = max( 1, (int) round( $seconds ) );
		if ( $seconds < 90 ) {
			/* translators: %d: number of seconds */
			return sprintf( _n( '%d second', '%d seconds', $seconds, 'eclair-export' ), $seconds );
		}
		$minutes = (int) round( $seconds / 60 );
		if ( $minutes < 120 ) {
			/* translators: %d: number of minutes */
			return sprintf( _n( '%d minute', '%d minutes', $minutes, 'eclair-export' ), $minutes );
		}
		$hours = round( $minutes / 60, 1 );
		/* translators: %s: number of hours */
		return sprintf( __( '%s hours', 'eclair-export' ), number_format_i18n( $hours, 1 ) );
	}

	/** Post types that hold real content (builder templates, menus and WooCommerce internals excluded). */
	public static function content_types() {
		$skip  = array( 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request',
			'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'wp_font_family', 'wp_font_face',
			'elementor_library', 'e-landing-page', 'elementor_snippet', 'elementor_font', 'elementor_icons', 'et_pb_layout', 'et_template',
			'et_header_layout', 'et_body_layout', 'et_footer_layout', 'et_theme_builder', 'product_variation', 'shop_order', 'shop_order_refund',
			'shop_coupon', 'shop_order_placehold', 'acf-field', 'acf-field-group', 'acf-post-type', 'acf-taxonomy', 'acf-ui-options-page' );
		$types = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $name => $object ) {
			if ( ! in_array( $name, $skip, true ) ) {
				$types[ $name ] = $object;
			}
		}
		return $types;
	}

	/** Statuses exported (published, scheduled, drafts, pending and private contents). */
	public static function statuses() {
		return array( 'publish', 'future', 'draft', 'pending', 'private' );
	}

	/** File of the package holding a post type. */
	public static function content_file( $type ) {
		$map = array( 'post' => 'posts', 'page' => 'pages', 'product' => 'products' );
		return 'content/' . ( isset( $map[ $type ] ) ? $map[ $type ] : 'cpt-' . sanitize_key( $type ) ) . '.json';
	}
}
