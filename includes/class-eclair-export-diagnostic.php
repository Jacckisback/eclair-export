<?php
/**
 * Diagnostic: what the site holds, what will move to Éclair as is, what will be adapted, what will not move, and how
 * long it should take. Read only: nothing is exported, nothing is changed.
 *
 * @package EclairExport
 */

defined( 'ABSPATH' ) || exit;

final class Eclair_Export_Diagnostic {

	const OPTION = 'eclair_export_diagnostic';

	/** Limits of Éclair 2.x (data/index.php, media library, redirections, categories, languages). */
	const ECLAIR = array( 'contents' => 3000, 'media' => 10000, 'redirects' => 5000, 'categories' => 1000, 'languages' => array( 'fr', 'es', 'en', 'de', 'it', 'pt' ) );

	public static function last() {
		$d = get_option( self::OPTION );
		return is_array( $d ) && isset( $d['generated'] ) ? $d : null;
	}

	/** Runs the diagnostic (about $budget seconds at most for the long scans) and keeps it for the report. */
	public static function run( $budget = 20 ) {
		global $wpdb, $wp_version, $shortcode_tags;
		Eclair_Export_Multilang::all_languages();
		$until      = microtime( true ) + max( 5, (int) $budget );
		$types      = Eclair_Export_Util::content_types();
		$type_names = array_keys( $types );
		$statuses   = Eclair_Export_Util::statuses();
		$in_types   = implode( ',', array_fill( 0, count( $type_names ), '%s' ) );
		$in_stat    = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$args       = array_merge( $type_names, $statuses );

		$d = array(
			'generated' => time(),
			'version'   => ECLAIR_EXPORT_VERSION,
			'site'      => array(
				'name'        => html_entity_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ),
				'url'         => Eclair_Export_Util::home(),
				'wordpress'   => (string) $wp_version,
				'php'         => PHP_VERSION,
				'theme'       => Eclair_Export_Detector::theme(),
				'multisite'   => is_multisite(),
				'language'    => get_locale(),
				'permalinks'  => (string) get_option( 'permalink_structure' ),
				'blog_public' => (bool) get_option( 'blog_public' ),
				'server'      => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '',
				'max_time'    => (int) ini_get( 'max_execution_time' ),
				'memory'      => (string) ini_get( 'memory_limit' ),
				'zip'         => class_exists( 'ZipArchive' ),
				'dom'         => class_exists( 'DOMDocument' ),
			),
		);

		// Contents per type and status.
		$rows       = $wpdb->get_results( $wpdb->prepare( "SELECT post_type, post_status, COUNT(*) AS n FROM {$wpdb->posts} WHERE post_type IN ($in_types) AND post_status IN ($in_stat) GROUP BY post_type, post_status", $args ) ); // phpcs:ignore WordPress.DB
		$d['content'] = array();
		foreach ( $types as $name => $object ) {
			$d['content'][ $name ] = array( 'label' => $object->labels->name, 'total' => 0, 'publish' => 0, 'draft' => 0, 'other' => 0, 'custom' => ! in_array( $name, array( 'post', 'page', 'product' ), true ) );
		}
		foreach ( (array) $rows as $row ) {
			$key = 'publish' === $row->post_status ? 'publish' : ( 'draft' === $row->post_status ? 'draft' : 'other' );
			$d['content'][ $row->post_type ][ $key ]  += (int) $row->n;
			$d['content'][ $row->post_type ]['total'] += (int) $row->n;
		}
		$d['content']       = array_filter( $d['content'], static function ( $c ) {
			return $c['total'] > 0 || ! $c['custom'];
		} );
		$d['total_content'] = array_sum( wp_list_pluck( $d['content'], 'total' ) );
		$d['protected']     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ($in_types) AND post_status IN ($in_stat) AND post_password <> ''", $args ) ); // phpcs:ignore WordPress.DB

		// Terms.
		$d['terms'] = array();
		foreach ( get_object_taxonomies( $type_names, 'objects' ) as $taxonomy ) {
			if ( ! empty( $taxonomy->public ) && ! in_array( $taxonomy->name, array( 'post_format', 'language', 'post_translations', 'product_type', 'product_visibility', 'product_shipping_class' ), true ) ) {
				$count = (int) wp_count_terms( array( 'taxonomy' => $taxonomy->name, 'hide_empty' => false ) );
				if ( $count > 0 ) {
					$d['terms'][ $taxonomy->name ] = array( 'label' => $taxonomy->labels->name, 'count' => $count );
				}
			}
		}
		$d['multi_category'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM (SELECT tr.object_id FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id JOIN {$wpdb->posts} p ON p.ID = tr.object_id WHERE tt.taxonomy = 'category' AND p.post_type = 'post' AND p.post_status = 'publish' GROUP BY tr.object_id HAVING COUNT(*) > 1) x" ); // phpcs:ignore WordPress.DB

		// People, comments, menus.
		$d['authors']  = count( get_users( array( 'has_published_posts' => $type_names, 'fields' => 'ID' ) ) );
		$comments      = wp_count_comments();
		$d['comments'] = (int) $comments->approved;
		$menus         = wp_get_nav_menus();
		$d['menus']    = array( 'count' => count( $menus ), 'nested' => false, 'largest' => 0 );
		foreach ( $menus as $menu ) {
			$items = (array) wp_get_nav_menu_items( $menu->term_id );
			$d['menus']['largest'] = max( $d['menus']['largest'], count( $items ) );
			foreach ( $items as $item ) {
				if ( (int) $item->menu_item_parent > 0 ) {
					$d['menus']['nested'] = true;
				}
			}
		}

		// Media.
		$mimes      = $wpdb->get_results( "SELECT post_mime_type AS mime, COUNT(*) AS n FROM {$wpdb->posts} WHERE post_type = 'attachment' GROUP BY post_mime_type" ); // phpcs:ignore WordPress.DB
		$d['media'] = array( 'count' => 0, 'images' => 0, 'svg' => 0, 'documents' => 0, 'video' => 0, 'audio' => 0, 'other' => 0 );
		foreach ( (array) $mimes as $row ) {
			$n     = (int) $row->n;
			$mime  = (string) $row->mime;
			$kind  = 'image/svg+xml' === $mime ? 'svg' : ( in_array( $mime, array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ), true ) ? 'images' : ( 0 === strpos( $mime, 'video/' ) ? 'video' : ( 0 === strpos( $mime, 'audio/' ) ? 'audio' : ( preg_match( '~pdf|msword|officedocument|opendocument|text/|rtf|excel|powerpoint~', $mime ) ? 'documents' : ( 0 === strpos( $mime, 'image/' ) ? 'images' : 'other' ) ) ) ) );
			$d['media'][ $kind ] += $n;
			$d['media']['count'] += $n;
		}
		$bytes                  = self::media_bytes( max( 2, $until - microtime( true ) - 8 ) );
		$d['media']['bytes']    = $bytes['bytes'];
		$d['media']['complete'] = $bytes['complete'];

		// Plugins and builders.
		$d['plugins']        = Eclair_Export_Detector::detected();
		$d['plugins_active'] = count( Eclair_Export_Detector::active_plugins() );
		$d['builders']       = array(
			'elementor' => (int) $wpdb->get_var( "SELECT COUNT(DISTINCT pm.post_id) FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_elementor_edit_mode' AND pm.meta_value = 'builder' AND p.post_status IN ('publish','draft','private','future','pending') AND p.post_type <> 'elementor_library'" ), // phpcs:ignore WordPress.DB
			'divi'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status IN ('publish','draft','private','future','pending') AND post_type NOT IN ('et_pb_layout','revision') AND post_content LIKE '%[et\\_pb\\_%'" ), // phpcs:ignore WordPress.DB
			'gutenberg' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ($in_types) AND post_status IN ($in_stat) AND post_content LIKE '%<!-- wp:%'", $args ) ), // phpcs:ignore WordPress.DB
		);
		$d['builders']['classic'] = max( 0, $d['total_content'] - $d['builders']['elementor'] - $d['builders']['divi'] - $d['builders']['gutenberg'] );

		// Shortcodes used in the contents.
		$registered = array_map( 'strtolower', array_keys( (array) $shortcode_tags ) );
		$found      = array();
		$last       = 0;
		$complete   = true;
		do {
			$batch = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_content FROM {$wpdb->posts} WHERE post_type IN ($in_types) AND post_status IN ($in_stat) AND post_content LIKE %s AND ID > %d ORDER BY ID LIMIT 200", array_merge( $args, array( '%[%', $last ) ) ) ); // phpcs:ignore WordPress.DB
			foreach ( (array) $batch as $row ) {
				$last = (int) $row->ID;
				foreach ( Eclair_Export_Shortcodes::scan( $row->post_content, $registered ) as $tag => $n ) {
					if ( ! isset( $found[ $tag ] ) ) {
						$found[ $tag ] = array( 'count' => 0, 'contents' => 0, 'registered' => in_array( $tag, $registered, true ), 'handled' => self::handled( $tag ), 'example' => (int) $row->ID );
					}
					$found[ $tag ]['count']    += $n;
					$found[ $tag ]['contents'] += 1;
				}
			}
			if ( $batch && microtime( true ) > $until ) {
				$complete = false;
				break;
			}
		} while ( $batch );
		uasort( $found, static function ( $a, $b ) {
			return $b['count'] - $a['count'];
		} );
		$d['shortcodes']          = $found;
		$d['shortcodes_complete'] = $complete;

		// Languages, shop, redirections, SEO, custom fields.
		$langs              = Eclair_Export_Multilang::languages();
		$d['multilingual']  = array( 'provider' => Eclair_Export_Multilang::provider(), 'languages' => $langs, 'unsupported' => array_values( array_diff( $langs, self::ECLAIR['languages'] ) ) );
		$d['woocommerce']   = array( 'products' => isset( $d['content']['product'] ) ? $d['content']['product']['total'] : 0, 'orders' => 0, 'customers' => 0 );
		if ( function_exists( 'wc_get_orders' ) ) {
			$orders                         = wc_get_orders( array( 'limit' => 1, 'paginate' => true, 'return' => 'ids' ) );
			$d['woocommerce']['orders']     = is_object( $orders ) && isset( $orders->total ) ? (int) $orders->total : 0;
			$users                          = count_users();
			$d['woocommerce']['customers']  = isset( $users['avail_roles']['customer'] ) ? (int) $users['avail_roles']['customer'] : 0;
		}
		$d['redirects']     = array( 'existing' => Eclair_Export_Redirects::count(), 'regex' => self::regex_redirects() );
		$d['redirects']['migration'] = array_sum( wp_list_pluck( $d['terms'], 'count' ) ) + $d['authors'] + $d['media']['count'] + 12 * max( 1, (int) ceil( $d['total_content'] / 50 ) );
		$d['seo']           = array( 'source' => Eclair_Export_Seo::source() );
		$d['acf']           = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'acf-field-group' AND post_status = 'publish'" ); // phpcs:ignore WordPress.DB

		$d['risks']    = self::risks( $d );
		$d['score']    = self::score( $d['risks'] );
		$d['estimate'] = self::estimate( $d );
		update_option( self::OPTION, $d, false );
		return $d;
	}

	/** Shortcodes the converter turns into clean HTML. */
	public static function handled( $tag ) {
		return in_array( $tag, Eclair_Export_Shortcodes::KNOWN, true ) && 'playlist' !== $tag || 0 === strpos( $tag, 'et_pb_' );
	}

	/** Bytes of the original media files: ['bytes', 'complete' (false if the time ran out before the end)]. */
	public static function media_bytes( $seconds ) {
		global $wpdb;
		$until = microtime( true ) + $seconds;
		$base  = trailingslashit( wp_upload_dir( null, false )['basedir'] );
		$bytes = 0;
		$last  = 0;
		do {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND post_id > %d ORDER BY post_id LIMIT 500", $last ) ); // phpcs:ignore WordPress.DB
			foreach ( (array) $rows as $row ) {
				$last = (int) $row->post_id;
				$file = path_is_absolute( $row->meta_value ) ? $row->meta_value : $base . $row->meta_value;
				if ( is_file( $file ) ) {
					$bytes += (int) filesize( $file );
				}
			}
			if ( $rows && microtime( true ) > $until ) {
				return array( 'bytes' => $bytes, 'complete' => false );
			}
		} while ( $rows );
		return array( 'bytes' => $bytes, 'complete' => true );
	}

	private static function regex_redirects() {
		global $wpdb;
		$n = 0;
		if ( Eclair_Export_Seo::table_exists( $wpdb->prefix . 'redirection_items' ) ) {
			$n += (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}redirection_items WHERE status = 'enabled' AND regex = 1" ); // phpcs:ignore WordPress.DB
		}
		if ( Eclair_Export_Seo::table_exists( $wpdb->prefix . 'aioseo_redirects' ) ) {
			$n += (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}aioseo_redirects WHERE enabled = 1 AND regex = 1" ); // phpcs:ignore WordPress.DB
		}
		return $n;
	}

	/** Risks: ['level' => high|medium|info, 'title', 'detail']. */
	public static function risks( array $d ) {
		$r   = array();
		$add = static function ( $level, $title, $detail ) use ( &$r ) {
			$r[] = array( 'level' => $level, 'title' => $title, 'detail' => $detail );
		};
		$n = 'number_format_i18n';
		if ( ! $d['site']['blog_public'] ) {
			$add( 'high', __( 'The site asks search engines not to index it', 'eclair-export' ), __( '"Discourage search engines" is ticked in Settings › Reading: every page will be exported as noindex. Untick it if this is the live site.', 'eclair-export' ) );
		}
		if ( ! $d['site']['zip'] ) {
			$add( 'high', __( 'The PHP "zip" extension is missing', 'eclair-export' ), __( 'The archive cannot be built on this server. Ask the host to enable it, or run the export with WP-CLI on a copy of the site.', 'eclair-export' ) );
		}
		if ( isset( $d['site']['dom'] ) && ! $d['site']['dom'] ) {
			$add( 'high', __( 'The PHP "dom" extension is missing', 'eclair-export' ), __( 'The contents cannot be converted on this server. Ask the host to enable it, or run the export with WP-CLI on a copy of the site.', 'eclair-export' ) );
		}
		if ( $d['total_content'] > self::ECLAIR['contents'] ) {
			/* translators: 1: number of contents, 2: Éclair limit */
			$add( 'high', __( 'More contents than one Éclair site holds', 'eclair-export' ), sprintf( __( '%1$s contents, for %2$s at most in Éclair: split the site or leave old drafts out.', 'eclair-export' ), $n( $d['total_content'] ), $n( self::ECLAIR['contents'] ) ) );
		}
		if ( $d['media']['count'] > self::ECLAIR['media'] ) {
			/* translators: 1: number of files, 2: Éclair limit */
			$add( 'high', __( 'Very large media library', 'eclair-export' ), sprintf( __( '%1$s files, for %2$s images at most in Éclair: unused files should be cleaned first.', 'eclair-export' ), $n( $d['media']['count'] ), $n( self::ECLAIR['media'] ) ) );
		}
		$non_images = $d['media']['documents'] + $d['media']['video'] + $d['media']['audio'] + $d['media']['svg'] + $d['media']['other'];
		if ( $non_images > 0 ) {
			/* translators: %s: number of files */
			$add( 'medium', __( 'Files that are not photos', 'eclair-export' ), sprintf( __( '%s PDF, video, audio, SVG or other files: they are exported, and must be hosted on the new site before the old one is shut down.', 'eclair-export' ), $n( $non_images ) ) );
		}
		if ( $d['multilingual']['unsupported'] ) {
			/* translators: %s: language codes */
			$add( 'high', __( 'Languages not offered by Éclair', 'eclair-export' ), sprintf( __( 'Éclair offers French, Spanish, English, German, Italian and Portuguese. Not offered: %s.', 'eclair-export' ), strtoupper( implode( ', ', $d['multilingual']['unsupported'] ) ) ) );
		}
		if ( $d['woocommerce']['orders'] > 0 || $d['woocommerce']['customers'] > 0 ) {
			/* translators: 1: orders, 2: customers */
			$add( 'high', __( 'Shop orders and customer accounts stay in WooCommerce', 'eclair-export' ), sprintf( __( 'The catalogue moves (products, prices, photos, variations); the %1$s orders and %2$s customer accounts do not: keep an export of them for your accounts.', 'eclair-export' ), $n( $d['woocommerce']['orders'] ), $n( $d['woocommerce']['customers'] ) ) );
		}
		$builder = $d['builders']['elementor'] + $d['builders']['divi'];
		if ( $builder > 0 ) {
			/* translators: %s: number of pages */
			$add( 'medium', __( 'Pages built with Elementor or Divi', 'eclair-export' ), sprintf( __( '%s pages: texts, images, buttons, videos and FAQs are converted; columns, animations and design effects are not. Each such page should be looked over after the migration.', 'eclair-export' ), $n( $builder ) ) );
		}
		$left = array();
		foreach ( $d['shortcodes'] as $tag => $s ) {
			if ( ! $s['handled'] ) {
				$left[] = '[' . $tag . '] × ' . $n( $s['count'] );
			}
		}
		if ( $left ) {
			/* translators: %s: list of shortcodes */
			$add( 'medium', __( 'Shortcodes that cannot be converted', 'eclair-export' ), sprintf( __( 'They stay in the text as written and are listed in the report, to be replaced by hand: %s.', 'eclair-export' ), implode( ', ', array_slice( $left, 0, 12 ) ) . ( count( $left ) > 12 ? '…' : '' ) ) );
		}
		$custom = array();
		foreach ( $d['content'] as $type => $c ) {
			if ( $c['custom'] && $c['total'] > 0 ) {
				$custom[] = $c['label'] . ' (' . $n( $c['total'] ) . ')';
			}
		}
		if ( $custom ) {
			/* translators: %s: list of content types */
			$add( 'medium', __( 'Custom content types', 'eclair-export' ), sprintf( __( 'Éclair has pages, articles and products. These types will be imported as pages or articles, keeping their address: %s.', 'eclair-export' ), implode( ', ', $custom ) ) );
		}
		if ( $d['comments'] > 0 ) {
			/* translators: %s: number of comments */
			$add( 'medium', __( 'Comments', 'eclair-export' ), sprintf( __( '%s approved comments: Éclair has no comments, they will not be shown on the new site.', 'eclair-export' ), $n( $d['comments'] ) ) );
		}
		if ( $d['acf'] > 0 ) {
			/* translators: %s: number of field groups */
			$add( 'medium', __( 'Custom fields (ACF)', 'eclair-export' ), sprintf( __( '%s field groups: their values are exported, but a theme that displayed them must be rebuilt in Éclair.', 'eclair-export' ), $n( $d['acf'] ) ) );
		}
		if ( $d['redirects']['regex'] > 0 ) {
			/* translators: %s: number of redirections */
			$add( 'medium', __( 'Redirections with patterns', 'eclair-export' ), sprintf( __( '%s redirections use regular expressions: Éclair only redirects exact addresses, they will be listed to be rewritten.', 'eclair-export' ), $n( $d['redirects']['regex'] ) ) );
		}
		if ( $d['redirects']['existing'] + $d['redirects']['migration'] > self::ECLAIR['redirects'] ) {
			$add( 'medium', __( 'Many redirections', 'eclair-export' ), __( 'Existing and needed redirections together go beyond the 5,000 that Éclair keeps: the least useful ones (attachment pages, date archives) will be left out first.', 'eclair-export' ) );
		}
		if ( $d['menus']['nested'] || $d['menus']['largest'] > 30 ) {
			$add( 'info', __( 'Drop-down menus', 'eclair-export' ), __( 'Éclair menus have one level and 30 links: sub-menus will be flattened.', 'eclair-export' ) );
		}
		if ( isset( $d['terms']['post_tag'] ) ) {
			/* translators: %s: number of tags */
			$add( 'info', __( 'Tags', 'eclair-export' ), sprintf( __( '%s tags: their pages are redirected so that no address is lost.', 'eclair-export' ), $n( $d['terms']['post_tag']['count'] ) ) );
		}
		if ( $d['multi_category'] > 0 ) {
			/* translators: %s: number of articles */
			$add( 'info', __( 'Articles in several categories', 'eclair-export' ), sprintf( __( '%s articles: in Éclair an article has one main category (the one chosen in the SEO plugin, otherwise the first one).', 'eclair-export' ), $n( $d['multi_category'] ) ) );
		}
		if ( '' === $d['site']['permalinks'] ) {
			$add( 'info', __( 'Plain permalinks (?p=123)', 'eclair-export' ), __( 'Old addresses with ?p= and ?page_id= are redirected to the new ones by Éclair.', 'eclair-export' ) );
		}
		if ( $d['protected'] > 0 ) {
			/* translators: %s: number of contents */
			$add( 'info', __( 'Password-protected contents', 'eclair-export' ), sprintf( __( '%s contents: they are imported as drafts (their password is not exported).', 'eclair-export' ), $n( $d['protected'] ) ) );
		}
		if ( $d['site']['multisite'] ) {
			$add( 'info', __( 'Multisite network', 'eclair-export' ), __( 'Only this site of the network is exported.', 'eclair-export' ) );
		}
		return $r;
	}

	/** Compatibility score out of 100. */
	public static function score( array $risks ) {
		$score = 100;
		foreach ( $risks as $risk ) {
			$score -= 'high' === $risk['level'] ? 15 : ( 'medium' === $risk['level'] ? 4 : 0 );
		}
		return max( 5, $score );
	}

	/** Time estimates (seconds) and archive size, from simple measured averages. */
	public static function estimate( array $d ) {
		$bytes = (int) $d['media']['bytes'];
		return array(
			'export'  => (int) ( 10 + $d['total_content'] * 0.06 + $d['media']['count'] * 0.03 + $bytes / ( 25 * 1048576 ) ),
			'import'  => (int) ( 10 + $d['total_content'] * 0.25 + $d['media']['images'] * 1.2 + ( $d['media']['count'] - $d['media']['images'] ) * 0.2 ),
			'archive' => (int) ( $bytes + $d['total_content'] * 8192 ),
			'review'  => (int) ( 600 + ( $d['builders']['elementor'] + $d['builders']['divi'] ) * 120 + count( $d['shortcodes'] ) * 300 ),
		);
	}
}
