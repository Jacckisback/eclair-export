<?php
/**
 * Languages and translation groups (WPML or Polylang). Without either plugin, every content is in the site language.
 *
 * @package EclairExport
 */

defined( 'ABSPATH' ) || exit;

final class Eclair_Export_Multilang {

	/** 'wpml', 'polylang' or ''. */
	public static function provider() {
		if ( defined( 'ICL_SITEPRESS_VERSION' ) && has_filter( 'wpml_element_language_details' ) ) {
			return 'wpml';
		}
		if ( function_exists( 'pll_get_post_language' ) ) {
			return 'polylang';
		}
		return '';
	}

	/** WPML filters queries by the current language: every language is read during a diagnostic or an export. */
	public static function all_languages() {
		if ( 'wpml' === self::provider() ) {
			do_action( 'wpml_switch_language', 'all' );
		}
	}

	/** Two-letter code of the site language. */
	public static function site_lang() {
		return self::code( get_locale() );
	}

	/** "fr_FR" / "pt-br" → "fr" / "pt". */
	public static function code( $locale ) {
		$locale = strtolower( (string) $locale );
		return '' === $locale ? '' : substr( preg_replace( '~[^a-z].*$~', '', $locale ), 0, 3 );
	}

	/** Languages of the site (codes), default first. */
	public static function languages() {
		$provider = self::provider();
		$langs    = array();
		if ( 'wpml' === $provider ) {
			$default = (string) apply_filters( 'wpml_default_language', null );
			$langs[] = self::code( $default );
			foreach ( array_keys( (array) apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) ) ) as $code ) {
				$langs[] = self::code( $code );
			}
		} elseif ( 'polylang' === $provider && function_exists( 'pll_languages_list' ) ) {
			if ( function_exists( 'pll_default_language' ) ) {
				$langs[] = self::code( pll_default_language( 'slug' ) );
			}
			foreach ( (array) pll_languages_list( array( 'fields' => 'slug' ) ) as $code ) {
				$langs[] = self::code( $code );
			}
		}
		$langs = array_values( array_unique( array_filter( $langs ) ) );
		return $langs ? $langs : array( self::site_lang() );
	}

	/** [language code, translation group] of a post. The group is shared by all translations of the same content. */
	public static function post( $post ) {
		$provider = self::provider();
		if ( 'wpml' === $provider ) {
			$info = apply_filters( 'wpml_element_language_details', null, array( 'element_id' => $post->ID, 'element_type' => 'post_' . $post->post_type ) );
			if ( is_object( $info ) && ! empty( $info->language_code ) ) {
				return array( self::code( $info->language_code ), ! empty( $info->trid ) ? 'wpml-' . (int) $info->trid : '' );
			}
		} elseif ( 'polylang' === $provider ) {
			$lang = (string) pll_get_post_language( $post->ID, 'slug' );
			if ( '' !== $lang ) {
				$ids = function_exists( 'pll_get_post_translations' ) ? array_map( 'intval', (array) pll_get_post_translations( $post->ID ) ) : array();
				return array( self::code( $lang ), count( $ids ) > 1 ? 'pll-' . min( $ids ) : '' );
			}
		}
		return array( self::site_lang(), '' );
	}

	/** [language code, translation group] of a term. */
	public static function term( $term ) {
		$provider = self::provider();
		if ( 'wpml' === $provider ) {
			$info = apply_filters( 'wpml_element_language_details', null, array( 'element_id' => $term->term_taxonomy_id, 'element_type' => 'tax_' . $term->taxonomy ) );
			if ( is_object( $info ) && ! empty( $info->language_code ) ) {
				return array( self::code( $info->language_code ), ! empty( $info->trid ) ? 'wpml-t' . (int) $info->trid : '' );
			}
		} elseif ( 'polylang' === $provider && function_exists( 'pll_get_term_language' ) ) {
			$lang = (string) pll_get_term_language( $term->term_id, 'slug' );
			if ( '' !== $lang ) {
				$ids = function_exists( 'pll_get_term_translations' ) ? array_map( 'intval', (array) pll_get_term_translations( $term->term_id ) ) : array();
				return array( self::code( $lang ), count( $ids ) > 1 ? 'pll-t' . min( $ids ) : '' );
			}
		}
		return array( self::site_lang(), '' );
	}
}
