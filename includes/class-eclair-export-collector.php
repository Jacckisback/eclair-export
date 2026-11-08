<?php
/**
 * Records of the package: one content, the terms, the authors, the menus, the site settings, one media file.
 * Passwords are never read: users are listed field by field, never as a whole row, and every meta key that looks
 * like a secret is skipped.
 *
 * @package EclairExport
 */

defined( 'ABSPATH' ) || exit;

final class Eclair_Export_Collector {

	/** Largest custom fields block kept per content (bytes of JSON). */
	const MAX_META = 65536;

	/** One content: [record, seo record, converter report]. */
	public static function item( WP_Post $post ) {
		$converted        = Eclair_Export_Converter::post( $post );
		list( $lang, $group ) = Eclair_Export_Multilang::post( $post );
		$url              = (string) get_permalink( $post );
		$terms            = array();
		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			if ( 'post_format' === $taxonomy || 'language' === $taxonomy || 'post_translations' === $taxonomy || 'product_type' === $taxonomy || 'product_visibility' === $taxonomy ) {
				continue;
			}
			$slugs = wp_get_object_terms( $post->ID, $taxonomy, array( 'fields' => 'slugs' ) );
			if ( is_array( $slugs ) && $slugs ) {
				$terms[ $taxonomy ] = array_values( array_map( 'rawurldecode', $slugs ) );
			}
		}
		$primary = (int) get_post_meta( $post->ID, '_yoast_wpseo_primary_category', true );
		if ( ! $primary ) {
			$primary = (int) get_post_meta( $post->ID, 'rank_math_primary_category', true );
		}
		$primary_slug = '';
		if ( $primary ) {
			$term         = get_term( $primary, 'category' );
			$primary_slug = $term instanceof WP_Term ? $term->slug : '';
		}
		$record = array(
			'wp_id'             => (int) $post->ID,
			'type'              => $post->post_type,
			'status'            => $post->post_status,
			'protected'         => '' !== (string) $post->post_password,
			'title'             => html_entity_decode( (string) $post->post_title, ENT_QUOTES, 'UTF-8' ),
			'slug'              => rawurldecode( (string) $post->post_name ),
			'url'               => $url,
			'path'              => Eclair_Export_Util::path( $url ),
			'parent'            => (int) $post->post_parent,
			'menu_order'        => (int) $post->menu_order,
			'template'          => (string) get_page_template_slug( $post ),
			'date_gmt'          => self::date( $post->post_date_gmt, $post->post_date ),
			'modified_gmt'      => self::date( $post->post_modified_gmt, $post->post_modified ),
			'author'            => (int) $post->post_author,
			'lang'              => $lang,
			'translation_group' => $group,
			'excerpt'           => trim( wp_strip_all_tags( (string) $post->post_excerpt ) ),
			'html'              => $converted['html'],
			'source_format'     => $converted['format'],
			'featured_media'    => (int) get_post_thumbnail_id( $post ),
			'terms'             => (object) $terms,
			'primary_category'  => $primary_slug,
			'sticky'            => 'post' === $post->post_type && is_sticky( $post->ID ),
			'comments'          => (int) $post->comment_count,
			'meta'              => (object) self::meta( $post ),
			'shortcodes_left'   => array_keys( $converted['report']['shortcodes'] ),
		);
		if ( 'product' === $post->post_type && function_exists( 'wc_get_product' ) ) {
			$record['product'] = self::product( $post->ID );
		}
		return array( $record, Eclair_Export_Seo::post( $post ), $converted['report'] );
	}

	/** GMT date in ISO 8601 (local date when WordPress has no GMT date, as for drafts). */
	private static function date( $gmt, $local ) {
		if ( $gmt && '0000-00-00 00:00:00' !== $gmt ) {
			return gmdate( 'c', (int) strtotime( $gmt . ' UTC' ) );
		}
		return $local && '0000-00-00 00:00:00' !== $local ? (string) get_gmt_from_date( $local, 'c' ) : '';
	}

	/** Custom fields: ACF fields when ACF is active, public meta otherwise. Secrets and builder data excluded. */
	public static function meta( WP_Post $post ) {
		$out = array();
		if ( function_exists( 'get_field_objects' ) ) {
			foreach ( (array) get_field_objects( $post->ID, false ) as $name => $field ) {
				if ( ! Eclair_Export_Util::sensitive( $name ) && is_array( $field ) && 'password' !== ( isset( $field['type'] ) ? $field['type'] : '' ) ) {
					$out[ $name ] = array( 'type' => isset( $field['type'] ) ? $field['type'] : '', 'label' => isset( $field['label'] ) ? $field['label'] : '', 'value' => isset( $field['value'] ) ? $field['value'] : null );
				}
			}
		} else {
			foreach ( (array) get_post_meta( $post->ID ) as $key => $values ) {
				if ( '_' === substr( $key, 0, 1 ) || Eclair_Export_Util::sensitive( $key ) || preg_match( '~^(rank_math_|_?yoast|_elementor|_et_|_wp_|_oembed|_edit_|ekit_|_aioseo)~', $key ) ) {
					continue;
				}
				$values      = array_map( 'maybe_unserialize', (array) $values );
				$out[ $key ] = 1 === count( $values ) ? $values[0] : $values;
			}
		}
		return strlen( Eclair_Export_Util::json( $out ) ) > self::MAX_META ? array( '_truncated' => true ) : $out;
	}

	/** WooCommerce product: catalogue data only (no order, customer or payment data). */
	public static function product( $id ) {
		$p = wc_get_product( $id );
		if ( ! $p ) {
			return null;
		}
		$brand = '';
		foreach ( array( 'product_brand', 'pwb-brand', 'yith_product_brand' ) as $taxonomy ) {
			if ( taxonomy_exists( $taxonomy ) ) {
				$names = wp_get_object_terms( $id, $taxonomy, array( 'fields' => 'names' ) );
				if ( is_array( $names ) && $names ) {
					$brand = (string) $names[0];
					break;
				}
			}
		}
		$ignored = Eclair_Export_Converter::empty_report();
		$out     = array(
			'kind'          => $p->get_type(),
			'price'         => (string) $p->get_price(),
			'regular_price' => (string) $p->get_regular_price(),
			'sale_price'    => (string) $p->get_sale_price(),
			'currency'      => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
			'sku'           => (string) $p->get_sku(),
			'gtin'          => method_exists( $p, 'get_global_unique_id' ) ? (string) $p->get_global_unique_id() : (string) get_post_meta( $id, '_gtin', true ),
			'stock_status'  => (string) $p->get_stock_status(),
			'virtual'       => (bool) $p->is_virtual(),
			'downloadable'  => (bool) $p->is_downloadable(),
			'short_description' => Eclair_Export_Converter::clean( (string) $p->get_short_description(), $ignored ),
			'gallery'       => array_map( 'intval', (array) $p->get_gallery_image_ids() ),
			'brand'         => $brand,
			'external_url'  => method_exists( $p, 'get_product_url' ) ? (string) $p->get_product_url() : '',
			'button_text'   => method_exists( $p, 'get_button_text' ) ? (string) $p->get_button_text() : '',
			'variations'    => array(),
		);
		if ( $p->is_type( 'variable' ) ) {
			foreach ( array_slice( (array) $p->get_children(), 0, 100 ) as $child_id ) {
				$v = wc_get_product( $child_id );
				if ( $v ) {
					$out['variations'][] = array(
						'wp_id'        => (int) $child_id,
						'label'        => function_exists( 'wc_get_formatted_variation' ) ? wp_strip_all_tags( wc_get_formatted_variation( $v, true, false ) ) : '',
						'price'        => (string) $v->get_price(),
						'regular_price'=> (string) $v->get_regular_price(),
						'sku'          => (string) $v->get_sku(),
						'stock_status' => (string) $v->get_stock_status(),
						'image'        => (int) $v->get_image_id(),
					);
				}
			}
		}
		return $out;
	}

	/** All terms of the public taxonomies used by the exported types: [terms, seo by "taxonomy:id"]. */
	public static function terms( array $types ) {
		$terms = array();
		$seo   = array();
		foreach ( get_object_taxonomies( $types, 'objects' ) as $taxonomy ) {
			if ( empty( $taxonomy->public ) || in_array( $taxonomy->name, array( 'post_format', 'language', 'post_translations', 'term_language', 'term_translations', 'product_type', 'product_visibility', 'product_shipping_class' ), true ) ) {
				continue;
			}
			foreach ( get_terms( array( 'taxonomy' => $taxonomy->name, 'hide_empty' => false, 'lang' => '', 'number' => 5000 ) ) as $term ) {
				if ( ! $term instanceof WP_Term ) {
					continue;
				}
				list( $lang, $group ) = Eclair_Export_Multilang::term( $term );
				$link                 = get_term_link( $term );
				$terms[]              = array(
					'wp_id'             => (int) $term->term_id,
					'taxonomy'          => $term->taxonomy,
					'taxonomy_label'    => $taxonomy->labels->singular_name,
					'hierarchical'      => (bool) $taxonomy->hierarchical,
					'slug'              => rawurldecode( $term->slug ),
					'name'              => html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ),
					'description'       => (string) $term->description,
					'parent'            => (int) $term->parent,
					'count'             => (int) $term->count,
					'url'               => is_string( $link ) ? $link : '',
					'path'              => is_string( $link ) ? Eclair_Export_Util::path( $link ) : '',
					'lang'              => $lang,
					'translation_group' => $group,
				);
				$seo[ $term->taxonomy . ':' . $term->term_id ] = Eclair_Export_Seo::term( $term );
			}
		}
		return array( $terms, $seo );
	}

	/** Authors of the exported contents. Never a password; the e-mail address only when asked for. */
	public static function authors( array $types, $with_email ) {
		$out   = array();
		$users = get_users( array( 'has_published_posts' => $types, 'fields' => array( 'ID' ) ) );
		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$more         = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_author FROM {$wpdb->posts} WHERE post_type IN ($placeholders) AND post_status IN ('draft','pending','private','future')", $types ) ); // phpcs:ignore WordPress.DB
		$ids          = array_unique( array_merge( wp_list_pluck( $users, 'ID' ), array_map( 'intval', (array) $more ) ) );
		foreach ( $ids as $id ) {
			$user = get_userdata( (int) $id );
			if ( ! $user ) {
				continue;
			}
			$out[] = array(
				'wp_id'        => (int) $user->ID,
				'slug'         => (string) $user->user_nicename,
				'display_name' => (string) $user->display_name,
				'first_name'   => (string) $user->first_name,
				'last_name'    => (string) $user->last_name,
				'bio'          => (string) $user->description,
				'website'      => (string) $user->user_url,
				'url'          => get_author_posts_url( $user->ID, $user->user_nicename ),
				'email'        => $with_email ? (string) $user->user_email : '',
				'role'         => in_array( 'administrator', (array) $user->roles, true ) ? 'admin' : 'editor',
			);
		}
		return $out;
	}

	/** Menus with their location and their full tree. */
	public static function menus() {
		$locations = (array) get_nav_menu_locations();
		$out       = array();
		foreach ( wp_get_nav_menus() as $menu ) {
			$items = array();
			foreach ( (array) wp_get_nav_menu_items( $menu->term_id, array( 'update_post_term_cache' => false ) ) as $item ) {
				$items[] = array(
					'wp_id'     => (int) $item->ID,
					'parent'    => (int) $item->menu_item_parent,
					'order'     => (int) $item->menu_order,
					'title'     => html_entity_decode( (string) $item->title, ENT_QUOTES, 'UTF-8' ),
					'type'      => (string) $item->type,
					'object'    => (string) $item->object,
					'object_id' => (int) $item->object_id,
					'url'       => (string) $item->url,
					'new_tab'   => '_blank' === $item->target,
				);
			}
			$out[] = array(
				'wp_id'     => (int) $menu->term_id,
				'name'      => $menu->name,
				'slug'      => $menu->slug,
				'locations' => array_keys( array_filter( $locations, static function ( $id ) use ( $menu ) {
					return (int) $id === (int) $menu->term_id;
				} ) ),
				'items'     => $items,
			);
		}
		return $out;
	}

	/** Site settings useful to rebuild it (an allow-list: no option that could hold a key or a password). */
	public static function settings() {
		$logo = (int) get_theme_mod( 'custom_logo' );
		return array(
			'name'                => html_entity_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ),
			'description'         => html_entity_decode( (string) get_bloginfo( 'description' ), ENT_QUOTES, 'UTF-8' ),
			'url'                 => Eclair_Export_Util::home(),
			'language'            => get_locale(),
			'timezone'            => wp_timezone_string(),
			'date_format'         => (string) get_option( 'date_format' ),
			'show_on_front'       => (string) get_option( 'show_on_front' ),
			'page_on_front'       => (int) get_option( 'page_on_front' ),
			'page_for_posts'      => (int) get_option( 'page_for_posts' ),
			'posts_per_page'      => (int) get_option( 'posts_per_page' ),
			'permalink_structure' => (string) get_option( 'permalink_structure' ),
			'category_base'       => (string) get_option( 'category_base' ),
			'tag_base'            => (string) get_option( 'tag_base' ),
			'site_icon'           => (int) get_option( 'site_icon' ),
			'logo'                => $logo,
			'blog_public'         => (bool) get_option( 'blog_public' ),
			'woocommerce'         => function_exists( 'wc_get_page_id' ) ? array( 'shop_page' => (int) wc_get_page_id( 'shop' ), 'currency' => get_woocommerce_currency() ) : null,
		);
	}

	/** One media file: [record, absolute path on disk or ''] . */
	public static function media( WP_Post $attachment ) {
		$file     = (string) get_attached_file( $attachment->ID );
		$url      = (string) wp_get_attachment_url( $attachment->ID );
		$meta     = wp_get_attachment_metadata( $attachment->ID );
		$meta     = is_array( $meta ) ? $meta : array();
		$uploads  = wp_upload_dir( null, false );
		$base     = trailingslashit( str_replace( '\\', '/', $uploads['basedir'] ) );
		$relative = 0 === strpos( str_replace( '\\', '/', $file ), $base ) ? substr( str_replace( '\\', '/', $file ), strlen( $base ) ) : basename( $file );
		$variants = array();
		$dir_url  = trailingslashit( dirname( $url ) );
		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) ) {
					$variants[] = $dir_url . $size['file'];
				}
			}
		}
		if ( ! empty( $meta['original_image'] ) ) {
			$variants[] = $dir_url . $meta['original_image'];
		}
		$exists = '' !== $file && is_file( $file );
		list( $lang ) = Eclair_Export_Multilang::post( $attachment );
		return array(
			array(
				'wp_id'       => (int) $attachment->ID,
				'url'         => $url,
				'variants'    => array_values( array_unique( $variants ) ),
				'file'        => 'media/uploads/' . ltrim( $relative, '/' ),
				'mime'        => (string) $attachment->post_mime_type,
				'bytes'       => $exists ? (int) filesize( $file ) : 0,
				'width'       => isset( $meta['width'] ) ? (int) $meta['width'] : 0,
				'height'      => isset( $meta['height'] ) ? (int) $meta['height'] : 0,
				'alt'         => (string) get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ),
				'title'       => html_entity_decode( (string) $attachment->post_title, ENT_QUOTES, 'UTF-8' ),
				'caption'     => (string) $attachment->post_excerpt,
				'description' => (string) $attachment->post_content,
				'parent'      => (int) $attachment->post_parent,
				'date_gmt'    => self::date( $attachment->post_date_gmt, $attachment->post_date ),
				'lang'        => $lang,
				'missing'     => ! $exists,
				'sha256'      => '',
				'included'    => false,
			),
			$exists ? $file : '',
		);
	}
}
