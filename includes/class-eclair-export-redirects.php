<?php
/**
 * Redirections: the existing ones (Redirection, Rank Math, AIOSEO, Yoast Premium) and those the migration needs for the
 * WordPress addresses that have no equivalent in Éclair (category, tag and author archives, attachment pages, feeds,
 * date archives). A migration target is a reference resolved by the importer ("eclair:term:category:news"), because only
 * Éclair knows its own addresses.
 *
 * @package EclairExport
 */

defined( 'ABSPATH' ) || exit;

final class Eclair_Export_Redirects {

	const HEADER = array( 'source', 'target', 'code', 'origin', 'regex' );

	/**
	 * Existing redirections: ['rows' => [[source, target, code, origin, regex]], 'skipped' => [[source, origin, reason]]].
	 */
	public static function existing() {
		$rows    = array();
		$skipped = array();
		self::redirection( $rows, $skipped );
		self::rankmath( $rows, $skipped );
		self::aioseo( $rows, $skipped );
		self::yoast( $rows, $skipped );
		return array( 'rows' => $rows, 'skipped' => $skipped );
	}

	/** Number of existing redirections, for the diagnostic (fast: counts only). */
	public static function count() {
		global $wpdb;
		$n = 0;
		foreach ( array( 'redirection_items' => "status = 'enabled'", 'rank_math_redirections' => "status = 'active'", 'aioseo_redirects' => 'enabled = 1' ) as $table => $where ) {
			if ( Eclair_Export_Seo::table_exists( $wpdb->prefix . $table ) ) {
				$n += (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$wpdb->prefix}{$table}` WHERE {$where}" ); // phpcs:ignore WordPress.DB
			}
		}
		$n += count( (array) get_option( 'wpseo-premium-redirects-base', array() ) );
		return $n;
	}

	private static function redirection( array &$rows, array &$skipped ) {
		global $wpdb;
		$table = $wpdb->prefix . 'redirection_items';
		if ( ! Eclair_Export_Seo::table_exists( $table ) ) {
			return;
		}
		$items = $wpdb->get_results( "SELECT url, regex, action_type, action_code, action_data, match_type FROM `{$table}` WHERE status = 'enabled' ORDER BY position, id LIMIT 50000" ); // phpcs:ignore WordPress.DB
		foreach ( (array) $items as $item ) {
			$code = (int) $item->action_code;
			if ( 'url' !== $item->match_type ) {
				$skipped[] = array( $item->url, 'redirection', 'condition: ' . $item->match_type );
				continue;
			}
			if ( 'error' === $item->action_type ) {
				$rows[] = array( self::source( $item->url, (int) $item->regex ), '', in_array( $code, array( 404, 410 ), true ) ? $code : 410, 'redirection', (int) $item->regex );
				continue;
			}
			if ( 'url' !== $item->action_type ) {
				$skipped[] = array( $item->url, 'redirection', 'action: ' . $item->action_type );
				continue;
			}
			$target = maybe_unserialize( $item->action_data );
			$target = is_array( $target ) ? ( isset( $target['url'] ) ? $target['url'] : '' ) : (string) $target;
			$rows[] = array( self::source( $item->url, (int) $item->regex ), self::target( $target ), $code ? $code : 301, 'redirection', (int) $item->regex );
		}
	}

	private static function rankmath( array &$rows, array &$skipped ) {
		global $wpdb;
		$table = $wpdb->prefix . 'rank_math_redirections';
		if ( ! Eclair_Export_Seo::table_exists( $table ) ) {
			return;
		}
		$items = $wpdb->get_results( "SELECT sources, url_to, header_code FROM `{$table}` WHERE status = 'active' LIMIT 50000" ); // phpcs:ignore WordPress.DB
		foreach ( (array) $items as $item ) {
			foreach ( self::rankmath_sources( maybe_unserialize( $item->sources ) ) as $source ) {
				$rows[] = array( $source[0], self::target( (string) $item->url_to ), (int) $item->header_code ? (int) $item->header_code : 301, 'rankmath', $source[1] );
			}
		}
	}

	/** Rank Math sources → [[pattern, regex 0/1]]; "contains", "starts with" and "ends with" become regular expressions. */
	public static function rankmath_sources( $sources ) {
		$out = array();
		foreach ( is_array( $sources ) ? $sources : array() as $source ) {
			if ( ! is_array( $source ) || ! isset( $source['pattern'] ) || '' === (string) $source['pattern'] ) {
				continue;
			}
			$pattern    = (string) $source['pattern'];
			$comparison = isset( $source['comparison'] ) ? (string) $source['comparison'] : 'exact';
			switch ( $comparison ) {
				case 'regex':
					$out[] = array( $pattern, 1 );
					break;
				case 'contains':
					$out[] = array( '.*' . preg_quote( $pattern, '~' ) . '.*', 1 );
					break;
				case 'start':
					$out[] = array( '^/?' . preg_quote( ltrim( $pattern, '/' ), '~' ), 1 );
					break;
				case 'end':
					$out[] = array( preg_quote( $pattern, '~' ) . '$', 1 );
					break;
				default:
					$out[] = array( '/' . ltrim( $pattern, '/' ), 0 );
			}
		}
		return $out;
	}

	private static function aioseo( array &$rows, array &$skipped ) {
		global $wpdb;
		$table = $wpdb->prefix . 'aioseo_redirects';
		if ( ! Eclair_Export_Seo::table_exists( $table ) ) {
			return;
		}
		$items = $wpdb->get_results( "SELECT source_url, target_url, type, regex FROM `{$table}` WHERE enabled = 1 LIMIT 50000" ); // phpcs:ignore WordPress.DB
		foreach ( (array) $items as $item ) {
			$rows[] = array( self::source( $item->source_url, (int) $item->regex ), self::target( (string) $item->target_url ), (int) $item->type ? (int) $item->type : 301, 'aioseo', (int) $item->regex );
		}
	}

	private static function yoast( array &$rows, array &$skipped ) {
		foreach ( (array) get_option( 'wpseo-premium-redirects-base', array() ) as $item ) {
			if ( ! is_array( $item ) || empty( $item['origin'] ) ) {
				continue;
			}
			$code   = isset( $item['type'] ) ? (int) $item['type'] : 301;
			$regex  = isset( $item['format'] ) && 'regex' === $item['format'] ? 1 : 0;
			$rows[] = array( self::source( $item['origin'], $regex ), in_array( $code, array( 410, 451 ), true ) ? '' : self::target( isset( $item['url'] ) ? (string) $item['url'] : '' ), $code, 'yoast', $regex );
		}
	}

	/**
	 * Redirections the migration needs, with public URLs to check after it: [rows, urls ([url, kind, ref])].
	 * $types: exported post types.
	 */
	public static function migration( array $types ) {
		$rows = array();
		$urls = array();
		$add  = static function ( $url, $target, $kind, $ref ) use ( &$rows, &$urls ) {
			$path = Eclair_Export_Util::path( $url );
			if ( '' === $path || '/' === $path || false !== strpos( $path, '?' ) ) {
				return;
			}
			$rows[] = array( $path, $target, 301, 'migration', 0 );
			$urls[] = array( $url, $kind, $ref );
		};
		// Term archives (categories, tags, custom taxonomies, product categories).
		$taxonomies = get_object_taxonomies( $types, 'objects' );
		foreach ( $taxonomies as $taxonomy ) {
			if ( empty( $taxonomy->public ) || 'post_format' === $taxonomy->name ) {
				continue;
			}
			foreach ( get_terms( array( 'taxonomy' => $taxonomy->name, 'hide_empty' => true, 'lang' => '' ) ) as $term ) {
				if ( $term instanceof WP_Term ) {
					$link = get_term_link( $term );
					if ( is_string( $link ) ) {
						$add( $link, 'eclair:term:' . $term->taxonomy . ':' . $term->slug, 'term', $term->taxonomy . ':' . $term->term_id );
					}
				}
			}
		}
		// Author archives.
		foreach ( get_users( array( 'has_published_posts' => $types, 'fields' => array( 'ID', 'user_nicename' ) ) ) as $user ) {
			$add( get_author_posts_url( (int) $user->ID, $user->user_nicename ), 'eclair:author:' . $user->user_nicename, 'author', (string) $user->ID );
		}
		// Post type archives.
		foreach ( $types as $type ) {
			$link = get_post_type_archive_link( $type );
			if ( is_string( $link ) && 'post' !== $type ) {
				$add( $link, 'product' === $type ? 'eclair:shop' : 'eclair:archive:' . $type, 'archive', $type );
			}
		}
		// Blog page, feeds and date archives.
		$posts_page = (int) get_option( 'page_for_posts' );
		if ( $posts_page && 'page' === get_option( 'show_on_front' ) ) {
			$urls[] = array( get_permalink( $posts_page ), 'blog', (string) $posts_page );
		}
		$add( get_feed_link(), 'eclair:feed', 'feed', '' );
		$add( get_feed_link( 'comments_rss2' ), 'eclair:feed', 'feed', 'comments' );
		global $wpdb;
		if ( in_array( 'post', $types, true ) ) {
			$months = $wpdb->get_results( "SELECT DISTINCT YEAR(post_date) AS y, MONTH(post_date) AS m FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish' ORDER BY y, m" ); // phpcs:ignore WordPress.DB
			$years  = array();
			foreach ( (array) $months as $row ) {
				$add( get_month_link( (int) $row->y, (int) $row->m ), 'eclair:blog', 'date', $row->y . '-' . $row->m );
				$years[ (int) $row->y ] = true;
			}
			foreach ( array_keys( $years ) as $year ) {
				$add( get_year_link( $year ), 'eclair:blog', 'date', (string) $year );
			}
		}
		// Attachment pages: to the content the file belongs to, or to the file itself.
		$attachments = $wpdb->get_results( "SELECT ID, post_parent FROM {$wpdb->posts} WHERE post_type = 'attachment' ORDER BY ID LIMIT 20000" ); // phpcs:ignore WordPress.DB
		foreach ( (array) $attachments as $row ) {
			$add( get_attachment_link( (int) $row->ID ), 'eclair:media:' . (int) $row->ID, 'attachment', (string) $row->ID );
		}
		return array( $rows, $urls );
	}

	/** Source of a redirection: a path from the site root (or the regular expression as written). */
	private static function source( $url, $regex = 0 ) {
		$url = trim( (string) $url );
		if ( $regex ) {
			return $url;
		}
		$path = Eclair_Export_Util::path( $url );
		return '' !== $path ? $path : $url;
	}

	/** Target: a path on this site, or an absolute address elsewhere. */
	private static function target( $url ) {
		$url  = trim( (string) $url );
		$path = Eclair_Export_Util::path( $url );
		return ( '' !== $path && preg_match( '~^https?://~i', $url ) ) || ( '' !== $url && '/' === $url[0] ) ? ( '' !== $path ? $path : $url ) : $url;
	}
}
