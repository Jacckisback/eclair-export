<?php
/**
 * Contents → clean HTML. Gutenberg blocks are rendered then stripped of their comments, the classic editor gets its
 * paragraphs, Elementor (from its JSON) and Divi (from its shortcodes) are rebuilt with plain tags. What cannot be
 * converted is counted for the report: an unknown shortcode stays in the text as written, never silently removed.
 *
 * The pure methods (from_elementor, render, clean) take no WordPress state, so they are tested on their own.
 *
 * @package EclairExport
 */

defined( 'ABSPATH' ) || exit;

final class Eclair_Export_Converter {

	/** Tags kept, with the attributes they keep. */
	const KEEP = array(
		'p' => array(), 'br' => array(), 'hr' => array(), 'strong' => array(), 'b' => array(), 'em' => array(), 'i' => array(),
		'u' => array(), 's' => array(), 'del' => array(), 'ins' => array(), 'sub' => array(), 'sup' => array(), 'mark' => array(),
		'small' => array(), 'abbr' => array( 'title' ), 'code' => array(), 'pre' => array(), 'kbd' => array(), 'q' => array(),
		'h1' => array( 'id' ), 'h2' => array( 'id' ), 'h3' => array( 'id' ), 'h4' => array( 'id' ), 'h5' => array( 'id' ), 'h6' => array( 'id' ),
		'ul' => array(), 'ol' => array( 'start' ), 'li' => array(), 'dl' => array(), 'dt' => array(), 'dd' => array(),
		'blockquote' => array(), 'cite' => array(), 'figure' => array(), 'figcaption' => array(),
		'a' => array( 'href', 'title', 'rel' ), 'img' => array( 'src', 'alt', 'title', 'width', 'height' ),
		'table' => array(), 'thead' => array(), 'tbody' => array(), 'tfoot' => array(), 'tr' => array(), 'caption' => array(),
		'th' => array( 'colspan', 'rowspan' ), 'td' => array( 'colspan', 'rowspan' ),
		'details' => array(), 'summary' => array(),
		'iframe' => array( 'src', 'title' ), 'video' => array( 'src', 'poster', 'controls' ), 'audio' => array( 'src', 'controls' ), 'source' => array( 'src', 'type' ),
	);

	/** Tags removed with their content (counted as dropped). */
	const DROP = array( 'script', 'style', 'noscript', 'form', 'input', 'textarea', 'select', 'button', 'object', 'embed', 'svg', 'canvas', 'template', 'link', 'meta', 'map' );

	/** Video hosts turned into an embed. */
	const VIDEO = '~^https?://(?:www\.|m\.)?(?:youtube\.com/(?:watch\?v=|embed/|shorts/)|youtu\.be/|youtube-nocookie\.com/embed/|(?:player\.)?vimeo\.com/(?:video/)?)([\w-]+)~i';

	/**
	 * Converts a post. Returns ['html', 'format' (gutenberg|classic|elementor|divi), 'report' => [shortcodes, converted,
	 * widgets, dropped]].
	 */
	public static function post( $post ) {
		$report  = self::empty_report();
		$content = (string) $post->post_content;
		$format  = self::format( $post );
		$context = self::context( $post );
		$base    = '';
		if ( 'elementor' === $format ) {
			$data = json_decode( (string) get_post_meta( $post->ID, '_elementor_data', true ), true );
			$base = is_array( $data ) ? self::from_elementor( $data, $report ) : wpautop( $content );
		} elseif ( 'gutenberg' === $format ) {
			try {
				$base = do_blocks( $content );
			} catch ( Throwable $e ) {
				$base = $content;
			}
		} elseif ( 'divi' === $format ) {
			$base = $content;
		} else {
			$base = function_exists( 'shortcode_unautop' ) ? shortcode_unautop( wpautop( $content ) ) : wpautop( $content );
		}
		$base = preg_replace( '~<!--\s*/?wp:.*?-->~s', '', (string) $base );
		$html = self::render( Eclair_Export_Shortcodes::parse( $base, $context['registered'] ), $context, $report );
		$html = self::clean( $html, $report );
		return array( 'html' => $html, 'format' => $format, 'report' => $report );
	}

	public static function empty_report() {
		return array( 'shortcodes' => array(), 'converted' => array(), 'widgets' => array(), 'dropped' => array() );
	}

	/** Editor used for a post. */
	public static function format( $post ) {
		if ( 'builder' === get_post_meta( $post->ID, '_elementor_edit_mode', true ) && '' !== (string) get_post_meta( $post->ID, '_elementor_data', true ) ) {
			return 'elementor';
		}
		if ( 'on' === get_post_meta( $post->ID, '_et_pb_use_builder', true ) || false !== strpos( (string) $post->post_content, '[et_pb_' ) ) {
			return 'divi';
		}
		if ( function_exists( 'has_blocks' ) && has_blocks( $post->post_content ) ) {
			return 'gutenberg';
		}
		return 'classic';
	}

	/** What the pure renderer needs from WordPress: registered shortcodes and a way to find an image by its ID. */
	public static function context( $post ) {
		global $shortcode_tags;
		return array(
			'post_id'    => (int) $post->ID,
			'registered' => array_map( 'strtolower', array_keys( (array) $shortcode_tags ) ),
			'image'      => static function ( $id ) {
				$url = wp_get_attachment_url( (int) $id );
				return $url ? array( 'url' => $url, 'alt' => (string) get_post_meta( (int) $id, '_wp_attachment_image_alt', true ) ) : null;
			},
			'attached'   => static function ( $post_id ) {
				$ids = get_posts( array( 'post_parent' => (int) $post_id, 'post_type' => 'attachment', 'post_mime_type' => 'image', 'fields' => 'ids', 'numberposts' => 100, 'orderby' => 'menu_order ID', 'order' => 'ASC', 'suppress_filters' => true ) );
				return array_map( 'intval', (array) $ids );
			},
		);
	}

	/* ---------------------------------------------------------------- Elementor */

	/** Elementor JSON (list of sections/containers) → HTML. */
	public static function from_elementor( array $elements, array &$report ) {
		$html = '';
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			$type = isset( $el['elType'] ) ? (string) $el['elType'] : '';
			$s    = isset( $el['settings'] ) && is_array( $el['settings'] ) ? $el['settings'] : array();
			if ( 'widget' !== $type ) {
				$html .= self::from_elementor( isset( $el['elements'] ) && is_array( $el['elements'] ) ? $el['elements'] : array(), $report );
				continue;
			}
			$html .= self::widget( isset( $el['widgetType'] ) ? (string) $el['widgetType'] : '', $s, $report, $el );
		}
		return $html;
	}

	private static function widget( $name, array $s, array &$report, array $el ) {
		$get = static function ( $key, $default = '' ) use ( $s ) {
			return isset( $s[ $key ] ) && is_scalar( $s[ $key ] ) ? (string) $s[ $key ] : $default;
		};
		$link = static function ( $key ) use ( $s ) {
			return isset( $s[ $key ]['url'] ) && is_string( $s[ $key ]['url'] ) ? $s[ $key ]['url'] : '';
		};
		$img = static function ( $key, $alt = '' ) use ( $s ) {
			if ( empty( $s[ $key ]['url'] ) || ! is_string( $s[ $key ]['url'] ) ) {
				return '';
			}
			$a = isset( $s[ $key ]['alt'] ) && is_string( $s[ $key ]['alt'] ) ? $s[ $key ]['alt'] : $alt;
			return '<img src="' . self::attr( $s[ $key ]['url'] ) . '" alt="' . self::attr( $a ) . '">';
		};
		switch ( $name ) {
			case 'heading':
				$level = preg_match( '~^h([1-6])$~', $get( 'header_size', 'h2' ), $m ) ? $m[1] : '2';
				$text  = $get( 'title' );
				$url   = $link( 'link' );
				return '' === trim( $text ) ? '' : '<h' . $level . '>' . ( '' !== $url ? '<a href="' . self::attr( $url ) . '">' . $text . '</a>' : $text ) . '</h' . $level . '>';
			case 'text-editor':
				return self::autop( $get( 'editor' ) );
			case 'image':
				$out = $img( 'image' );
				if ( '' === $out ) {
					return '';
				}
				$url = $link( 'link' );
				$out = '' !== $url ? '<a href="' . self::attr( $url ) . '">' . $out . '</a>' : $out;
				$cap = $get( 'caption' );
				return '<figure>' . $out . ( '' !== $cap ? '<figcaption>' . $cap . '</figcaption>' : '' ) . '</figure>';
			case 'button':
				$text = $get( 'text' );
				$url  = $link( 'link' );
				return '' === $text ? '' : '<p>' . ( '' !== $url ? '<a href="' . self::attr( $url ) . '">' . $text . '</a>' : '<strong>' . $text . '</strong>' ) . '</p>';
			case 'video':
				$url = $get( 'youtube_url' ) ?: ( $get( 'vimeo_url' ) ?: ( $get( 'dailymotion_url' ) ?: $link( 'hosted_url' ) ) );
				return '' === $url ? '' : self::video( $url );
			case 'icon-list':
				$items = '';
				foreach ( isset( $s['icon_list'] ) && is_array( $s['icon_list'] ) ? $s['icon_list'] : array() as $item ) {
					$text = isset( $item['text'] ) && is_string( $item['text'] ) ? $item['text'] : '';
					$url  = isset( $item['link']['url'] ) && is_string( $item['link']['url'] ) ? $item['link']['url'] : '';
					if ( '' !== trim( $text ) ) {
						$items .= '<li>' . ( '' !== $url ? '<a href="' . self::attr( $url ) . '">' . $text . '</a>' : $text ) . '</li>';
					}
				}
				return '' === $items ? '' : '<ul>' . $items . '</ul>';
			case 'accordion':
			case 'toggle':
			case 'tabs':
			case 'nested-accordion':
				$out = '';
				foreach ( isset( $s['tabs'] ) && is_array( $s['tabs'] ) ? $s['tabs'] : array() as $tab ) {
					$title = isset( $tab['tab_title'] ) && is_string( $tab['tab_title'] ) ? $tab['tab_title'] : '';
					$body  = isset( $tab['tab_content'] ) && is_string( $tab['tab_content'] ) ? $tab['tab_content'] : '';
					if ( '' !== trim( $title ) ) {
						$out .= '<details><summary>' . $title . '</summary>' . self::autop( $body ) . '</details>';
					}
				}
				if ( '' === $out && ! empty( $el['elements'] ) ) {
					return self::from_elementor( $el['elements'], $report );
				}
				return $out;
			case 'divider':
				return '<hr>';
			case 'spacer':
				return '';
			case 'image-gallery':
			case 'gallery':
				$out = '';
				foreach ( isset( $s['wp_gallery'] ) && is_array( $s['wp_gallery'] ) ? $s['wp_gallery'] : ( isset( $s['gallery'] ) && is_array( $s['gallery'] ) ? $s['gallery'] : array() ) as $image ) {
					if ( ! empty( $image['url'] ) && is_string( $image['url'] ) ) {
						$out .= '<img src="' . self::attr( $image['url'] ) . '" alt="">';
					}
				}
				return '' === $out ? '' : '<figure>' . $out . '</figure>';
			case 'testimonial':
				$text = $get( 'testimonial_content' );
				$name = trim( $get( 'testimonial_name' ) . ( '' !== $get( 'testimonial_job' ) ? ', ' . $get( 'testimonial_job' ) : '' ) );
				return '' === $text ? '' : '<blockquote>' . self::autop( $text ) . ( '' !== $name ? '<cite>' . $name . '</cite>' : '' ) . '</blockquote>';
			case 'blockquote':
				$text = $get( 'blockquote_content' );
				return '' === $text ? '' : '<blockquote>' . self::autop( $text ) . ( '' !== $get( 'author_name' ) ? '<cite>' . $get( 'author_name' ) . '</cite>' : '' ) . '</blockquote>';
			case 'image-box':
			case 'icon-box':
				$title = $get( 'title_text' );
				$url   = $link( 'link' );
				return $img( 'image', $title ) . ( '' !== $title ? '<h3>' . ( '' !== $url ? '<a href="' . self::attr( $url ) . '">' . $title . '</a>' : $title ) . '</h3>' : '' ) . self::autop( $get( 'description_text' ) );
			case 'call-to-action':
				$url = $link( 'link' );
				return ( '' !== $get( 'title' ) ? '<h3>' . $get( 'title' ) . '</h3>' : '' ) . self::autop( $get( 'description' ) )
					. ( '' !== $get( 'button' ) && '' !== $url ? '<p><a href="' . self::attr( $url ) . '">' . $get( 'button' ) . '</a></p>' : '' );
			case 'counter':
				return '<p><strong>' . $get( 'prefix' ) . $get( 'ending_number' ) . $get( 'suffix' ) . '</strong> ' . $get( 'title' ) . '</p>';
			case 'progress':
				return '' === $get( 'title' ) ? '' : '<p>' . $get( 'title' ) . ' : ' . ( isset( $s['percent']['size'] ) ? (int) $s['percent']['size'] : '' ) . ' %</p>';
			case 'alert':
				return '<p><strong>' . $get( 'alert_title' ) . '</strong> ' . $get( 'alert_description' ) . '</p>';
			case 'shortcode':
				return "\n" . $get( 'shortcode' ) . "\n";
			case 'html':
				self::bump( $report['converted'], 'elementor:html' );
				return $get( 'html' );
			case 'google_maps':
				return '' === $get( 'address' ) ? '' : '<p><a href="https://www.google.com/maps/search/?api=1&amp;query=' . rawurlencode( $get( 'address' ) ) . '">' . $get( 'address' ) . '</a></p>';
			case 'social-icons':
				$items = '';
				foreach ( isset( $s['social_icon_list'] ) && is_array( $s['social_icon_list'] ) ? $s['social_icon_list'] : array() as $item ) {
					$url = isset( $item['link']['url'] ) && is_string( $item['link']['url'] ) ? $item['link']['url'] : '';
					if ( '' !== $url ) {
						$items .= '<li><a href="' . self::attr( $url ) . '">' . self::attr( (string) wp_parse_url( $url, PHP_URL_HOST ) ) . '</a></li>';
					}
				}
				return '' === $items ? '' : '<ul>' . $items . '</ul>';
		}
		// Widget not handled: its visible texts are kept, and it is reported.
		self::bump( $report['widgets'], 'elementor:' . ( '' !== $name ? $name : 'unknown' ) );
		$out = '';
		foreach ( array( 'title', 'title_text', 'heading', 'text', 'editor', 'description', 'description_text', 'content' ) as $key ) {
			if ( '' !== trim( strip_tags( $get( $key ) ) ) ) {
				$out .= self::autop( $get( $key ) );
			}
		}
		return $out . ( ! empty( $el['elements'] ) ? self::from_elementor( $el['elements'], $report ) : '' );
	}

	/* ---------------------------------------------------------------- Shortcodes (core and Divi) */

	/** Renders a shortcode tree: core shortcodes and Divi modules converted, any other shortcode put back as written. */
	public static function render( array $nodes, array $context, array &$report ) {
		$out = '';
		foreach ( $nodes as $node ) {
			if ( 'text' === $node['t'] ) {
				$out .= $node['v'];
				continue;
			}
			$tag   = strtolower( $node['tag'] );
			$inner = self::render( $node['children'], $context, $report );
			$html  = 0 === strpos( $tag, 'et_pb_' ) ? self::divi( $tag, $node['atts'], $inner, $context, $report ) : self::core( $tag, $node['atts'], $inner, $context );
			if ( null === $html ) {
				self::bump( $report['shortcodes'], $tag );
				$out .= $node['open'] . $inner . $node['close'];
				continue;
			}
			self::bump( $report['converted'], $tag );
			$out .= $html;
		}
		return $out;
	}

	/** Core shortcodes; null when not handled. */
	private static function core( $tag, array $a, $inner, array $context ) {
		switch ( $tag ) {
			case 'caption':
			case 'wp_caption':
				$caption = isset( $a['caption'] ) ? $a['caption'] : trim( preg_replace( '~<a\b[^>]*>\s*<img\b[^>]*>\s*</a>|<img\b[^>]*>~i', '', $inner ) );
				preg_match( '~(<a\b[^>]*>\s*)?<img\b[^>]*>(\s*</a>)?~i', $inner, $m );
				return '<figure>' . ( isset( $m[0] ) ? $m[0] : '' ) . ( '' !== $caption ? '<figcaption>' . $caption . '</figcaption>' : '' ) . '</figure>';
			case 'gallery':
				$ids = array();
				foreach ( array( 'ids', 'include' ) as $key ) {
					if ( ! empty( $a[ $key ] ) ) {
						$ids = array_filter( array_map( 'intval', explode( ',', $a[ $key ] ) ) );
						break;
					}
				}
				if ( ! $ids && isset( $context['attached'] ) ) {
					$ids = call_user_func( $context['attached'], isset( $a['id'] ) ? (int) $a['id'] : $context['post_id'] );
				}
				return self::gallery( $ids, $context );
			case 'embed':
				$url = trim( '' !== trim( strip_tags( $inner ) ) ? strip_tags( $inner ) : ( isset( $a['src'] ) ? $a['src'] : '' ) );
				return '' === $url ? '' : self::video( $url );
			case 'video':
				foreach ( array( 'src', 'mp4', 'm4v', 'webm', 'ogv', 'mov', 0 ) as $key ) {
					if ( ! empty( $a[ $key ] ) ) {
						return self::video( $a[ $key ], isset( $a['poster'] ) ? $a['poster'] : '' );
					}
				}
				return '';
			case 'audio':
				foreach ( array( 'src', 'mp3', 'm4a', 'ogg', 'wav', 0 ) as $key ) {
					if ( ! empty( $a[ $key ] ) ) {
						return '<audio controls src="' . self::attr( $a[ $key ] ) . '"></audio>';
					}
				}
				return '';
		}
		return null;
	}

	/** Divi modules → HTML. Layout modules (sections, rows, columns) keep only their content. */
	private static function divi( $tag, array $a, $inner, array $context, array &$report ) {
		$get = static function ( $key ) use ( $a ) {
			return isset( $a[ $key ] ) ? self::divi_text( $a[ $key ] ) : '';
		};
		$heading = static function ( $text, $level = 'h2' ) {
			return '' === trim( $text ) ? '' : '<' . $level . '>' . $text . '</' . $level . '>';
		};
		switch ( $tag ) {
			case 'et_pb_section':
			case 'et_pb_row':
			case 'et_pb_row_inner':
			case 'et_pb_column':
			case 'et_pb_column_inner':
			case 'et_pb_fullwidth_section':
			case 'et_pb_group':
				return $inner;
			case 'et_pb_text':
			case 'et_pb_fullwidth_code':
			case 'et_pb_code':
				return self::autop( $inner );
			case 'et_pb_image':
			case 'et_pb_fullwidth_image':
				if ( '' === $get( 'src' ) ) {
					return '';
				}
				$out = '<img src="' . self::attr( $get( 'src' ) ) . '" alt="' . self::attr( $get( 'alt' ) ) . '">';
				return '<figure>' . ( '' !== $get( 'url' ) ? '<a href="' . self::attr( $get( 'url' ) ) . '">' . $out . '</a>' : $out ) . '</figure>';
			case 'et_pb_button':
				return '' === $get( 'button_text' ) ? '' : '<p>' . ( '' !== $get( 'button_url' ) ? '<a href="' . self::attr( $get( 'button_url' ) ) . '">' . $get( 'button_text' ) . '</a>' : $get( 'button_text' ) ) . '</p>';
			case 'et_pb_blurb':
				$img = '' !== $get( 'image' ) ? '<img src="' . self::attr( $get( 'image' ) ) . '" alt="' . self::attr( $get( 'alt' ) ) . '">' : '';
				return $img . $heading( $get( 'title' ), 'h3' ) . self::autop( $inner );
			case 'et_pb_cta':
			case 'et_pb_promo':
				return $heading( $get( 'title' ) ) . self::autop( $inner ) . ( '' !== $get( 'button_url' ) && '' !== $get( 'button_text' ) ? '<p><a href="' . self::attr( $get( 'button_url' ) ) . '">' . $get( 'button_text' ) . '</a></p>' : '' );
			case 'et_pb_toggle':
			case 'et_pb_accordion_item':
			case 'et_pb_tab':
				return '' === $get( 'title' ) ? self::autop( $inner ) : '<details><summary>' . $get( 'title' ) . '</summary>' . self::autop( $inner ) . '</details>';
			case 'et_pb_accordion':
			case 'et_pb_tabs':
				return $inner;
			case 'et_pb_testimonial':
				$who = trim( $get( 'author' ) . ( '' !== $get( 'job_title' ) ? ', ' . $get( 'job_title' ) : '' ) . ( '' !== $get( 'company_name' ) ? ', ' . $get( 'company_name' ) : '' ), ', ' );
				return '<blockquote>' . self::autop( $inner ) . ( '' !== $who ? '<cite>' . $who . '</cite>' : '' ) . '</blockquote>';
			case 'et_pb_video':
				return '' === $get( 'src' ) ? '' : self::video( $get( 'src' ) );
			case 'et_pb_divider':
				return '<hr>';
			case 'et_pb_fullwidth_header':
				return $heading( $get( 'title' ), 'h1' ) . ( '' !== $get( 'subhead' ) ? '<p><strong>' . $get( 'subhead' ) . '</strong></p>' : '' ) . self::autop( $inner )
					. ( '' !== $get( 'button_one_url' ) && '' !== $get( 'button_one_text' ) ? '<p><a href="' . self::attr( $get( 'button_one_url' ) ) . '">' . $get( 'button_one_text' ) . '</a></p>' : '' );
			case 'et_pb_gallery':
				return self::gallery( array_filter( array_map( 'intval', explode( ',', $get( 'gallery_ids' ) ) ) ), $context );
			case 'et_pb_number_counter':
			case 'et_pb_circle_counter':
				return '<p><strong>' . $get( 'number' ) . ( 'on' === $get( 'percent_sign' ) ? ' %' : '' ) . '</strong> ' . $get( 'title' ) . '</p>';
			case 'et_pb_counters':
				return '<ul>' . $inner . '</ul>';
			case 'et_pb_counter':
				return '<li>' . trim( strip_tags( $inner ) ) . ' : ' . $get( 'percent' ) . ' %</li>';
			case 'et_pb_team_member':
				return $heading( $get( 'name' ), 'h3' ) . ( '' !== $get( 'position' ) ? '<p><em>' . $get( 'position' ) . '</em></p>' : '' ) . self::autop( $inner );
			case 'et_pb_social_media_follow':
				return '<ul>' . $inner . '</ul>';
			case 'et_pb_social_media_follow_network':
				return '' === $get( 'url' ) ? '' : '<li><a href="' . self::attr( $get( 'url' ) ) . '">' . trim( strip_tags( $inner ) ) . '</a></li>';
			case 'et_pb_post_title':
			case 'et_pb_sidebar':
			case 'et_pb_comments':
			case 'et_pb_post_nav':
			case 'et_pb_search':
			case 'et_pb_login':
			case 'et_pb_contact_form':
			case 'et_pb_contact_field':
			case 'et_pb_signup':
			case 'et_pb_blog':
			case 'et_pb_portfolio':
			case 'et_pb_filterable_portfolio':
			case 'et_pb_shop':
			case 'et_pb_menu':
			case 'et_pb_fullwidth_menu':
			case 'et_pb_map':
			case 'et_pb_map_pin':
			case 'et_pb_countdown_timer':
			case 'et_pb_pricing_tables':
			case 'et_pb_pricing_table':
			case 'et_pb_slider':
			case 'et_pb_slide':
				// Modules that show dynamic site parts (forms, lists, menus…) or need scripts: Éclair rebuilds them its own way.
				self::bump( $report['widgets'], 'divi:' . substr( $tag, 6 ) );
				return in_array( $tag, array( 'et_pb_slide', 'et_pb_pricing_table' ), true ) ? $heading( $get( 'heading' ) ?: $get( 'title' ), 'h3' ) . self::autop( $inner ) : $inner;
		}
		self::bump( $report['widgets'], 'divi:' . substr( $tag, 6 ) );
		return ( '' !== $get( 'title' ) ? $heading( $get( 'title' ), 'h3' ) : '' ) . self::autop( $inner );
	}

	/** Divi encodes some attribute values (%22, %91…). */
	private static function divi_text( $value ) {
		return strtr( (string) $value, array( '%22' => '"', '%91' => '[', '%93' => ']', '%5c' => '\\', '%5C' => '\\' ) );
	}

	private static function gallery( array $ids, array $context ) {
		$out = '';
		foreach ( $ids as $id ) {
			$image = isset( $context['image'] ) ? call_user_func( $context['image'], $id ) : null;
			if ( is_array( $image ) ) {
				$out .= '<img src="' . self::attr( $image['url'] ) . '" alt="' . self::attr( $image['alt'] ) . '">';
			}
		}
		return '' === $out ? '' : '<figure>' . $out . '</figure>';
	}

	/** A video URL → a YouTube/Vimeo embed, a <video> for a file, a link otherwise. */
	public static function video( $url, $poster = '' ) {
		$url = trim( html_entity_decode( (string) $url, ENT_QUOTES, 'UTF-8' ) );
		if ( preg_match( self::VIDEO, $url, $m ) ) {
			$src = false !== stripos( $url, 'vimeo' ) ? 'https://player.vimeo.com/video/' . $m[1] : 'https://www.youtube.com/embed/' . $m[1];
			return '<iframe src="' . self::attr( $src ) . '"></iframe>';
		}
		if ( preg_match( '~\.(mp4|m4v|webm|ogv|mov)(\?|$)~i', $url ) ) {
			return '<video controls src="' . self::attr( $url ) . '"' . ( '' !== $poster ? ' poster="' . self::attr( $poster ) . '"' : '' ) . '></video>';
		}
		return '' === $url ? '' : '<p><a href="' . self::attr( $url ) . '">' . self::attr( $url ) . '</a></p>';
	}

	/* ---------------------------------------------------------------- Clean HTML */

	/** Any HTML → only the tags and attributes of KEEP, containers unwrapped, scripts and forms dropped (and counted). */
	public static function clean( $html, array &$report ) {
		$html = trim( (string) $html );
		if ( '' === $html ) {
			return '';
		}
		$dom      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>', LIBXML_NONET | LIBXML_COMPACT );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		$body = $dom->getElementsByTagName( 'body' )->item( 0 );
		if ( null === $body ) {
			return '';
		}
		self::walk( $body, $report );
		self::videos( $dom, $body );
		$parts = array();
		foreach ( iterator_to_array( $body->childNodes ) as $child ) {
			$html = (string) $dom->saveHTML( $child );
			$html = (string) preg_replace( '~<p>(\s|&nbsp;|\x{00a0}|<br\s*/?>)*</p>~u', '', $html );
			$html = trim( (string) preg_replace( '~\s*(</?(?:p|h[1-6]|ul|ol|li|blockquote|figure|figcaption|table|thead|tbody|tr|th|td|details|summary|pre|hr|iframe|video|audio|cite)\b[^>]*>)\s*~', '$1', $html ) );
			if ( '' !== $html ) {
				$parts[] = $html;
			}
		}
		// One top-level element per line, nothing else changed.
		$out = implode( "\n", $parts );
		return trim( (string) $out );
	}

	private static function walk( DOMNode $parent, array &$report ) {
		foreach ( iterator_to_array( $parent->childNodes ) as $node ) {
			if ( $node instanceof DOMComment ) {
				$parent->removeChild( $node );
				continue;
			}
			if ( ! $node instanceof DOMElement ) {
				continue;
			}
			$tag = strtolower( $node->tagName );
			if ( in_array( $tag, self::DROP, true ) || ( 'iframe' === $tag && ! preg_match( self::VIDEO, self::lazy( $node, 'src' ) ) ) ) {
				self::bump( $report['dropped'], $tag );
				$parent->removeChild( $node );
				continue;
			}
			if ( 'picture' === $tag ) {
				foreach ( iterator_to_array( $node->getElementsByTagName( 'source' ) ) as $source ) {
					$source->parentNode->removeChild( $source );
				}
			}
			self::walk( $node, $report );
			if ( ! array_key_exists( $tag, self::KEEP ) ) {
				// Containers (div, span, section…) disappear, their content stays.
				while ( $node->firstChild ) {
					$parent->insertBefore( $node->firstChild, $node );
				}
				$parent->removeChild( $node );
				continue;
			}
			$keep = self::KEEP[ $tag ];
			$src  = 'img' === $tag || 'iframe' === $tag ? self::lazy( $node, 'src' ) : '';
			foreach ( iterator_to_array( $node->attributes ) as $attr ) {
				$name = strtolower( $attr->name );
				if ( ! in_array( $name, $keep, true ) ) {
					$node->removeAttribute( $attr->name );
				} elseif ( in_array( $name, array( 'href', 'src', 'poster' ), true ) && ! self::safe_url( $attr->value ) ) {
					$node->removeAttribute( $attr->name );
				} elseif ( 'rel' === $name ) {
					$rel = array_intersect( preg_split( '~\s+~', strtolower( $attr->value ) ), array( 'nofollow', 'sponsored', 'ugc' ) );
					if ( $rel ) {
						$node->setAttribute( 'rel', implode( ' ', $rel ) );
					} else {
						$node->removeAttribute( 'rel' );
					}
				}
			}
			if ( '' !== $src && self::safe_url( $src ) ) {
				$node->setAttribute( 'src', $src );
			}
			if ( 'img' === $tag && ! $node->hasAttribute( 'src' ) ) {
				$parent->removeChild( $node );
			}
		}
	}

	/** Real address of a lazy-loaded image (data-src, data-lazy-src…) when src is only a placeholder. */
	private static function lazy( DOMElement $node, $attr ) {
		$src = trim( (string) $node->getAttribute( $attr ) );
		if ( '' === $src || 0 === strpos( $src, 'data:' ) || false !== strpos( $src, 'lazy' ) || false !== strpos( $src, 'placeholder' ) ) {
			foreach ( array( 'data-src', 'data-lazy-src', 'data-original', 'data-orig-file', 'data-large-file' ) as $alt ) {
				$value = trim( (string) $node->getAttribute( $alt ) );
				if ( '' !== $value && 0 !== strpos( $value, 'data:' ) ) {
					return $value;
				}
			}
		}
		return $src;
	}

	/** A paragraph (or figure) holding only a YouTube or Vimeo address becomes an embed, as WordPress showed it. */
	private static function videos( DOMDocument $dom, DOMNode $body ) {
		foreach ( iterator_to_array( $body->childNodes ) as $node ) {
			if ( ! $node instanceof DOMElement || ! in_array( strtolower( $node->tagName ), array( 'p', 'figure' ), true ) ) {
				continue;
			}
			$text = trim( $node->textContent );
			if ( preg_match( self::VIDEO, $text ) && ! preg_match( '~\s~', $text ) && 0 === $node->getElementsByTagName( 'img' )->length ) {
				$fragment = $dom->createDocumentFragment();
				$fragment->appendXML( self::video( $text ) );
				$body->replaceChild( $fragment, $node );
			}
		}
	}

	/* ---------------------------------------------------------------- Helpers */

	private static function autop( $text ) {
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return '';
		}
		if ( preg_match( '~<(p|h[1-6]|ul|ol|blockquote|figure|div|table|pre)\b~i', $text ) ) {
			return $text;
		}
		return function_exists( 'wpautop' ) ? wpautop( $text ) : '<p>' . $text . '</p>';
	}

	/** http(s), mailto, tel, anchors and relative addresses only (no javascript:, data:, vbscript:). */
	public static function safe_url( $url ) {
		$url = trim( html_entity_decode( (string) $url, ENT_QUOTES, 'UTF-8' ) );
		if ( '' === $url ) {
			return false;
		}
		if ( preg_match( '~^([a-z][a-z0-9+.-]*):~i', $url, $m ) ) {
			return in_array( strtolower( $m[1] ), array( 'http', 'https', 'mailto', 'tel' ), true );
		}
		return true;
	}

	private static function attr( $value ) {
		return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
	}

	public static function bump( array &$counts, $key, $by = 1 ) {
		$counts[ $key ] = ( isset( $counts[ $key ] ) ? $counts[ $key ] : 0 ) + $by;
	}
}
