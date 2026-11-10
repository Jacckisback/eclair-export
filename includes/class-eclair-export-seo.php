<?php
/**
 * SEO metadata from Yoast SEO, Rank Math or All in One SEO, for contents and terms, with their variables resolved
 * (%%title%%, %title%, #post_title…). Only what was set by hand is exported: Éclair builds the rest itself.
 *
 * @package EclairExport
 */

defined( 'ABSPATH' ) || exit;

final class Eclair_Export_Seo {

	/** Plugin holding the SEO data: 'yoast', 'rankmath', 'aioseo' or ''. */
	public static function source() {
		foreach ( array( 'yoast', 'rankmath', 'aioseo' ) as $key ) {
			if ( Eclair_Export_Detector::has( $key ) ) {
				return $key;
			}
		}
		return '';
	}

	/** Empty record (every key always present in seo.json). */
	public static function blank() {
		return array(
			'title' => '', 'description' => '', 'keyword' => '', 'canonical' => '', 'noindex' => false, 'nofollow' => false,
			'og' => array( 'title' => '', 'description' => '', 'image' => '' ),
			'twitter' => array( 'title' => '', 'description' => '', 'image' => '' ),
			'schema' => '', 'source' => '',
		);
	}

	public static function post( $post ) {
		$seo = self::blank();
		$id  = (int) $post->ID;
		$m   = static function ( $key ) use ( $id ) {
			return (string) get_post_meta( $id, $key, true );
		};
		$source = self::source();
		if ( 'yoast' === $source || ( '' === $source && '' !== $m( '_yoast_wpseo_title' ) . $m( '_yoast_wpseo_metadesc' ) ) ) {
			$seo['source']         = 'yoast';
			$seo['title']          = self::vars( $m( '_yoast_wpseo_title' ), $post, 'yoast' );
			$seo['description']    = self::vars( $m( '_yoast_wpseo_metadesc' ), $post, 'yoast' );
			$seo['keyword']        = $m( '_yoast_wpseo_focuskw' );
			$seo['canonical']      = $m( '_yoast_wpseo_canonical' );
			$robots                = $m( '_yoast_wpseo_meta-robots-noindex' );
			$titles                = (array) get_option( 'wpseo_titles', array() );
			$seo['noindex']        = '1' === $robots || ( '' === $robots || '0' === $robots ? ! empty( $titles[ 'noindex-' . $post->post_type ] ) : false );
			$seo['nofollow']       = '1' === $m( '_yoast_wpseo_meta-robots-nofollow' );
			$seo['og']             = array( 'title' => self::vars( $m( '_yoast_wpseo_opengraph-title' ), $post, 'yoast' ), 'description' => self::vars( $m( '_yoast_wpseo_opengraph-description' ), $post, 'yoast' ), 'image' => $m( '_yoast_wpseo_opengraph-image' ) );
			$seo['twitter']        = array( 'title' => self::vars( $m( '_yoast_wpseo_twitter-title' ), $post, 'yoast' ), 'description' => self::vars( $m( '_yoast_wpseo_twitter-description' ), $post, 'yoast' ), 'image' => $m( '_yoast_wpseo_twitter-image' ) );
			$seo['schema']         = $m( '_yoast_wpseo_schema_article_type' ) ? $m( '_yoast_wpseo_schema_article_type' ) : $m( '_yoast_wpseo_schema_page_type' );
		} elseif ( 'rankmath' === $source || ( '' === $source && '' !== $m( 'rank_math_title' ) . $m( 'rank_math_description' ) ) ) {
			$seo['source']      = 'rankmath';
			$seo['title']       = self::vars( $m( 'rank_math_title' ), $post, 'rankmath' );
			$seo['description'] = self::vars( $m( 'rank_math_description' ), $post, 'rankmath' );
			$keywords           = explode( ',', $m( 'rank_math_focus_keyword' ) );
			$seo['keyword']     = trim( (string) $keywords[0] );
			$seo['canonical']   = $m( 'rank_math_canonical_url' );
			$robots             = get_post_meta( $id, 'rank_math_robots', true );
			if ( ! is_array( $robots ) || ! $robots ) {
				$titles = (array) get_option( 'rank-math-options-titles', array() );
				$key    = 'pt_' . $post->post_type;
				$robots = ! empty( $titles[ $key . '_custom_robots' ] ) && 'on' === $titles[ $key . '_custom_robots' ] && isset( $titles[ $key . '_robots' ] )
					? (array) $titles[ $key . '_robots' ] : ( isset( $titles['robots_global'] ) ? (array) $titles['robots_global'] : array() );
			}
			$seo['noindex']  = in_array( 'noindex', $robots, true );
			$seo['nofollow'] = in_array( 'nofollow', $robots, true );
			$seo['og']       = array( 'title' => self::vars( $m( 'rank_math_facebook_title' ), $post, 'rankmath' ), 'description' => self::vars( $m( 'rank_math_facebook_description' ), $post, 'rankmath' ), 'image' => $m( 'rank_math_facebook_image' ) );
			$seo['twitter']  = array( 'title' => self::vars( $m( 'rank_math_twitter_title' ), $post, 'rankmath' ), 'description' => self::vars( $m( 'rank_math_twitter_description' ), $post, 'rankmath' ), 'image' => $m( 'rank_math_twitter_image' ) );
			$seo['schema']   = $m( 'rank_math_rich_snippet' );
		} elseif ( 'aioseo' === $source ) {
			$row = self::aioseo_row( 'aioseo_posts', 'post_id', $id );
			if ( $row ) {
				$seo['source']      = 'aioseo';
				$seo['title']       = self::vars( (string) $row->title, $post, 'aioseo' );
				$seo['description'] = self::vars( (string) $row->description, $post, 'aioseo' );
				$keyphrases         = json_decode( (string) $row->keyphrases, true );
				$seo['keyword']     = isset( $keyphrases['focus']['keyphrase'] ) ? (string) $keyphrases['focus']['keyphrase'] : '';
				$seo['canonical']   = (string) $row->canonical_url;
				$default            = ! empty( $row->robots_default );
				$seo['noindex']     = $default ? self::aioseo_type_noindex( $post->post_type ) : ! empty( $row->robots_noindex );
				$seo['nofollow']    = ! $default && ! empty( $row->robots_nofollow );
				$seo['og']          = array( 'title' => self::vars( (string) $row->og_title, $post, 'aioseo' ), 'description' => self::vars( (string) $row->og_description, $post, 'aioseo' ), 'image' => isset( $row->og_image_custom_url ) ? (string) $row->og_image_custom_url : '' );
				$seo['twitter']     = array( 'title' => self::vars( (string) $row->twitter_title, $post, 'aioseo' ), 'description' => self::vars( (string) $row->twitter_description, $post, 'aioseo' ), 'image' => isset( $row->twitter_image_custom_url ) ? (string) $row->twitter_image_custom_url : '' );
				$seo['schema']      = isset( $row->schema_type ) ? (string) $row->schema_type : '';
			}
		}
		if ( ! get_option( 'blog_public' ) ) {
			$seo['noindex'] = true;
		}
		return $seo;
	}

	public static function term( $term ) {
		$seo    = self::blank();
		$source = self::source();
		if ( 'yoast' === $source ) {
			$all  = (array) get_option( 'wpseo_taxonomy_meta', array() );
			$meta = isset( $all[ $term->taxonomy ][ $term->term_id ] ) ? (array) $all[ $term->taxonomy ][ $term->term_id ] : array();
			$g    = static function ( $key ) use ( $meta ) {
				return isset( $meta[ $key ] ) ? (string) $meta[ $key ] : '';
			};
			$seo['source']      = 'yoast';
			$seo['title']       = self::vars( $g( 'wpseo_title' ), $term, 'yoast' );
			$seo['description'] = self::vars( $g( 'wpseo_desc' ), $term, 'yoast' );
			$seo['keyword']     = $g( 'wpseo_focuskw' );
			$seo['canonical']   = $g( 'wpseo_canonical' );
			$seo['noindex']     = 'noindex' === $g( 'wpseo_noindex' );
			$seo['og']          = array( 'title' => $g( 'wpseo_opengraph-title' ), 'description' => $g( 'wpseo_opengraph-description' ), 'image' => $g( 'wpseo_opengraph-image' ) );
		} elseif ( 'rankmath' === $source ) {
			$g = static function ( $key ) use ( $term ) {
				return (string) get_term_meta( $term->term_id, $key, true );
			};
			$robots             = (array) get_term_meta( $term->term_id, 'rank_math_robots', true );
			$seo['source']      = 'rankmath';
			$seo['title']       = self::vars( $g( 'rank_math_title' ), $term, 'rankmath' );
			$seo['description'] = self::vars( $g( 'rank_math_description' ), $term, 'rankmath' );
			$seo['keyword']     = $g( 'rank_math_focus_keyword' );
			$seo['canonical']   = $g( 'rank_math_canonical_url' );
			$seo['noindex']     = in_array( 'noindex', $robots, true );
			$seo['nofollow']    = in_array( 'nofollow', $robots, true );
		} elseif ( 'aioseo' === $source ) {
			$row = self::aioseo_row( 'aioseo_terms', 'term_id', (int) $term->term_id );
			if ( $row ) {
				$seo['source']      = 'aioseo';
				$seo['title']       = self::vars( (string) $row->title, $term, 'aioseo' );
				$seo['description'] = self::vars( (string) $row->description, $term, 'aioseo' );
				$seo['canonical']   = (string) $row->canonical_url;
				$seo['noindex']     = empty( $row->robots_default ) && ! empty( $row->robots_noindex );
			}
		}
		return $seo;
	}

	/** Settings that apply to the whole site. */
	public static function globals() {
		$robots = '';
		if ( is_file( ABSPATH . 'robots.txt' ) && is_readable( ABSPATH . 'robots.txt' ) ) {
			$robots = (string) file_get_contents( ABSPATH . 'robots.txt' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		} elseif ( 'rankmath' === self::source() ) {
			$general = (array) get_option( 'rank-math-options-general', array() );
			$robots  = isset( $general['robots_txt_content'] ) ? (string) $general['robots_txt_content'] : '';
		}
		$out = array(
			'source'        => self::source(),
			'search_engines_discouraged' => ! get_option( 'blog_public' ),
			'separator'     => '',
			'organization'  => array( 'name' => '', 'logo' => '' ),
			'robots_txt'    => mb_substr( $robots, 0, 20000 ),
			'verification'  => array(),
			'social'        => array( 'twitter' => '', 'image' => '', 'profiles' => array() ),
		);
		if ( 'yoast' === $out['source'] ) {
			$titles                       = (array) get_option( 'wpseo_titles', array() );
			$out['separator']             = isset( $titles['separator'] ) ? (string) $titles['separator'] : '';
			$out['organization']['name']  = isset( $titles['company_name'] ) ? (string) $titles['company_name'] : '';
			$out['organization']['logo']  = isset( $titles['company_logo'] ) ? (string) $titles['company_logo'] : '';
			$wpseo                        = (array) get_option( 'wpseo', array() );
			foreach ( array( 'googleverify' => 'google', 'msverify' => 'bing', 'yandexverify' => 'yandex', 'baiduverify' => 'baidu' ) as $k => $engine ) {
				if ( ! empty( $wpseo[ $k ] ) ) {
					$out['verification'][ $engine ] = (string) $wpseo[ $k ];
				}
			}
			$social                   = (array) get_option( 'wpseo_social', array() );
			$out['social']['twitter'] = isset( $social['twitter_site'] ) ? (string) $social['twitter_site'] : '';
			$out['social']['image']   = isset( $social['og_default_image'] ) ? (string) $social['og_default_image'] : '';
			$profiles                 = isset( $social['other_social_urls'] ) ? (array) $social['other_social_urls'] : array();
			if ( ! empty( $social['facebook_site'] ) ) {
				array_unshift( $profiles, (string) $social['facebook_site'] );
			}
			$out['social']['profiles'] = $profiles;
		} elseif ( 'rankmath' === $out['source'] ) {
			$titles                      = (array) get_option( 'rank-math-options-titles', array() );
			$out['social']['twitter']  = isset( $titles['twitter_author_names'] ) ? (string) $titles['twitter_author_names'] : '';
			$out['social']['image']    = isset( $titles['open_graph_image'] ) ? (string) $titles['open_graph_image'] : '';
			$out['social']['profiles'] = array_merge(
				empty( $titles['social_url_facebook'] ) ? array() : array( (string) $titles['social_url_facebook'] ),
				isset( $titles['social_additional_profiles'] ) ? (array) preg_split( '~\s+~', (string) $titles['social_additional_profiles'], -1, PREG_SPLIT_NO_EMPTY ) : array()
			);
			$out['separator']            = isset( $titles['title_separator'] ) ? (string) $titles['title_separator'] : '';
			$out['organization']['name'] = isset( $titles['knowledgegraph_name'] ) ? (string) $titles['knowledgegraph_name'] : '';
			$out['organization']['logo'] = isset( $titles['knowledgegraph_logo'] ) ? (string) $titles['knowledgegraph_logo'] : '';
			$general                     = (array) get_option( 'rank-math-options-general', array() );
			foreach ( array( 'google_verify' => 'google', 'bing_verify' => 'bing', 'yandex_verify' => 'yandex', 'baidu_verify' => 'baidu' ) as $k => $engine ) {
				if ( ! empty( $general[ $k ] ) ) {
					$out['verification'][ $engine ] = (string) $general[ $k ];
				}
			}
		}
		// Public profile addresses only, as written in the SEO plugin; the X account without its @.
		$out['social']['twitter']  = ltrim( trim( (string) preg_replace( '~^https?://(www\.)?(twitter|x)\.com/~i', '', $out['social']['twitter'] ) ), '@' );
		$out['social']['image']    = esc_url_raw( $out['social']['image'] );
		$out['social']['profiles'] = array_slice( array_values( array_unique( array_filter( array_map( 'esc_url_raw', array_map( 'strval', $out['social']['profiles'] ) ) ) ) ), 0, 20 );
		return $out;
	}

	/** Variables of a title or description replaced by the plugin itself when it can, by a simple fallback otherwise. */
	public static function vars( $text, $object, $plugin ) {
		$text = trim( (string) $text );
		if ( '' === $text || ( false === strpos( $text, '%' ) && false === strpos( $text, '#' ) ) ) {
			return $text;
		}
		try {
			if ( 'yoast' === $plugin && function_exists( 'wpseo_replace_vars' ) ) {
				return trim( (string) wpseo_replace_vars( $text, $object ) );
			}
			if ( 'rankmath' === $plugin && class_exists( '\RankMath\Helper' ) && method_exists( '\RankMath\Helper', 'replace_vars' ) ) {
				return trim( (string) \RankMath\Helper::replace_vars( $text, $object ) );
			}
			if ( 'aioseo' === $plugin && function_exists( 'aioseo' ) && isset( aioseo()->tags ) && $object instanceof WP_Post ) {
				return trim( (string) aioseo()->tags->replaceTags( $text, $object->ID ) );
			}
		} catch ( Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
			// The fallback below still gives a usable text.
		}
		return self::fallback_vars( $text, $object instanceof WP_Post ? $object->post_title : ( isset( $object->name ) ? $object->name : '' ), (string) get_bloginfo( 'name' ), '-' );
	}

	/** Common variables of the three plugins replaced, unknown ones removed. */
	public static function fallback_vars( $text, $title, $site, $sep ) {
		$text = strtr( (string) $text, array(
			'%%title%%' => $title, '%%sitename%%' => $site, '%%sep%%' => $sep, '%%page%%' => '', '%%term_title%%' => $title,
			'%title%' => $title, '%sitename%' => $site, '%sep%' => $sep, '%page%' => '', '%term%' => $title,
			'#post_title' => $title, '#site_title' => $site, '#separator_sa' => $sep, '#taxonomy_title' => $title,
		) );
		$text = preg_replace( '~%%[a-z0-9_]+%%|%[a-z0-9_]+%|#[a-z]+_[a-z_]+~i', '', $text );
		$text = preg_replace( '~\s{2,}~', ' ', (string) $text );
		return trim( (string) $text, " \t\n-|" );
	}

	private static function aioseo_row( $table, $column, $id ) {
		global $wpdb;
		$name = $wpdb->prefix . $table;
		if ( ! self::table_exists( $name ) ) {
			return null;
		}
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$name}` WHERE `{$column}` = %d LIMIT 1", $id ) ); // phpcs:ignore WordPress.DB
	}

	private static function aioseo_type_noindex( $type ) {
		$options = json_decode( (string) get_option( 'aioseo_options_dynamic', '' ), true );
		return ! empty( $options['searchAppearance']['postTypes'][ $type ]['advanced']['robotsMeta']['noindex'] )
			&& empty( $options['searchAppearance']['postTypes'][ $type ]['advanced']['robotsMeta']['default'] );
	}

	public static function table_exists( $name ) {
		global $wpdb;
		static $cache = array();
		if ( ! isset( $cache[ $name ] ) ) {
			$cache[ $name ] = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) ) === $name; // phpcs:ignore WordPress.DB
		}
		return $cache[ $name ];
	}
}
