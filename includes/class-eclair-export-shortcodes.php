<?php
/**
 * Shortcode parser that does not execute anything: the text becomes a tree (text and shortcodes, nested), which the
 * converter renders tag by tag. A shortcode with no handler is put back exactly as written, never lost.
 *
 * Works without the plugin that registered the shortcode (orphan shortcodes left by a deactivated plugin are found too).
 *
 * @package EclairExport
 */

defined( 'ABSPATH' ) || exit;

final class Eclair_Export_Shortcodes {

	/** Core and builder shortcodes recognised even when no plugin registers them. */
	const KNOWN = array( 'caption', 'wp_caption', 'gallery', 'embed', 'video', 'audio', 'playlist' );

	/** Tokens: [tag attrs], [tag attrs /], [/tag]; quoted attribute values may contain "]". */
	const TOKEN = '~\[(/?)([A-Za-z][\w-]*)((?:[^\[\]"\']|"[^"]*"|\'[^\']*\')*?)(/?)\]~';

	/**
	 * Tree of $text. Nodes: ['t' => 'text', 'v' => string] or ['t' => 'sc', 'tag', 'atts', 'open' (raw), 'close' (raw or ''),
	 * 'children' => nodes]. $registered: shortcode tags known to WordPress on this site.
	 */
	public static function parse( $text, array $registered = array() ) {
		$text = (string) $text;
		if ( false === strpos( $text, '[' ) ) {
			return array( array( 't' => 'text', 'v' => $text ) );
		}
		preg_match_all( self::TOKEN, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );
		$closing = array();
		foreach ( $matches as $m ) {
			if ( '/' === $m[1][0] ) {
				$closing[ strtolower( $m[2][0] ) ] = true;
			}
		}
		$root  = array( 't' => 'root', 'children' => array() );
		$stack = array( &$root );
		$pos   = 0;
		foreach ( $matches as $m ) {
			$raw    = $m[0][0];
			$offset = $m[0][1];
			$tag    = $m[2][0];
			$lower  = strtolower( $tag );
			if ( $offset < $pos ) {
				continue;
			}
			$top = count( $stack ) - 1;
			if ( $offset > $pos ) {
				self::text( $stack[ $top ]['children'], substr( $text, $pos, $offset - $pos ) );
			}
			$pos = $offset + strlen( $raw );
			// [[tag]] is an escaped shortcode: shown as "[tag]", never interpreted.
			if ( $offset > 0 && '[' === $text[ $offset - 1 ] && isset( $text[ $pos ] ) && ']' === $text[ $pos ] ) {
				$children = &$stack[ $top ]['children'];
				$last     = count( $children ) - 1;
				if ( $last >= 0 && 'text' === $children[ $last ]['t'] && '[' === substr( $children[ $last ]['v'], -1 ) ) {
					$children[ $last ]['v'] = substr( $children[ $last ]['v'], 0, -1 );
				}
				self::text( $children, $raw );
				unset( $children );
				$pos++;
				continue;
			}
			if ( '/' === $m[1][0] ) {
				$found = -1;
				for ( $i = $top; $i > 0; $i-- ) {
					if ( strtolower( $stack[ $i ]['tag'] ) === $lower ) {
						$found = $i;
						break;
					}
				}
				if ( $found < 0 ) {
					self::text( $stack[ $top ]['children'], $raw );
					continue;
				}
				while ( count( $stack ) - 1 > $found ) {
					self::unclosed( $stack );
				}
				$stack[ $found ]['close'] = $raw;
				array_pop( $stack );
				continue;
			}
			if ( ! self::is_shortcode( $tag, $m[3][0], $registered, isset( $closing[ $lower ] ) ) ) {
				self::text( $stack[ $top ]['children'], $raw );
				continue;
			}
			$node = array( 't' => 'sc', 'tag' => $tag, 'atts' => self::atts( $m[3][0] ), 'open' => $raw, 'close' => '', 'children' => array() );
			$stack[ $top ]['children'][] = $node;
			if ( '/' !== $m[4][0] ) {
				$stack[] = &$stack[ $top ]['children'][ count( $stack[ $top ]['children'] ) - 1 ];
			}
		}
		$top = count( $stack ) - 1;
		if ( $pos < strlen( $text ) ) {
			self::text( $stack[ $top ]['children'], substr( $text, $pos ) );
		}
		while ( count( $stack ) > 1 ) {
			self::unclosed( $stack );
		}
		return $root['children'];
	}

	/** A token is a shortcode when WordPress knows it, when it is closed later, or when it looks like one. */
	private static function is_shortcode( $tag, $atts, array $registered, $closed ) {
		$lower = strtolower( $tag );
		if ( in_array( $lower, $registered, true ) || in_array( $lower, self::KNOWN, true ) || $closed ) {
			return true;
		}
		if ( preg_match( '~[_-]~', $tag ) && $lower === $tag ) {
			return true;
		}
		return $lower === $tag && false !== strpos( $atts, '=' );
	}

	/** Top of the stack was never closed: it is self-closing, and what followed it belongs to its parent. */
	private static function unclosed( array &$stack ) {
		$top      = count( $stack ) - 1;
		$children = $stack[ $top ]['children'];
		$stack[ $top ]['children'] = array();
		array_pop( $stack );
		$parent = count( $stack ) - 1;
		foreach ( $children as $child ) {
			if ( 'text' === $child['t'] ) {
				self::text( $stack[ $parent ]['children'], $child['v'] );
			} else {
				$stack[ $parent ]['children'][] = $child;
			}
		}
	}

	private static function text( array &$children, $value ) {
		if ( '' === $value ) {
			return;
		}
		$last = count( $children ) - 1;
		if ( $last >= 0 && 'text' === $children[ $last ]['t'] ) {
			$children[ $last ]['v'] .= $value;
		} else {
			$children[] = array( 't' => 'text', 'v' => $value );
		}
	}

	/** Attributes, as WordPress reads them: name="v", name='v', name=v, and positional values (0, 1…). */
	public static function atts( $text ) {
		$out  = array();
		$text = preg_replace( "/[\x{00a0}\x{200b}]+/u", ' ', (string) $text );
		$text = trim( rtrim( trim( (string) $text ), '/' ) );
		if ( '' === $text ) {
			return $out;
		}
		$pattern = '/([\w-]+)\s*=\s*"([^"]*)"(?:\s|$)|([\w-]+)\s*=\s*\'([^\']*)\'(?:\s|$)|([\w-]+)\s*=\s*([^\s\'"]+)(?:\s|$)|"([^"]*)"(?:\s|$)|\'([^\']*)\'(?:\s|$)|(\S+)(?:\s|$)/';
		if ( preg_match_all( $pattern, $text . ' ', $found, PREG_SET_ORDER ) ) {
			foreach ( $found as $m ) {
				if ( isset( $m[1] ) && '' !== $m[1] ) {
					$out[ strtolower( $m[1] ) ] = stripcslashes( $m[2] );
				} elseif ( isset( $m[3] ) && '' !== $m[3] ) {
					$out[ strtolower( $m[3] ) ] = stripcslashes( $m[4] );
				} elseif ( isset( $m[5] ) && '' !== $m[5] ) {
					$out[ strtolower( $m[5] ) ] = stripcslashes( $m[6] );
				} elseif ( isset( $m[7] ) && '' !== $m[7] ) {
					$out[] = stripcslashes( $m[7] );
				} elseif ( isset( $m[8] ) && '' !== $m[8] ) {
					$out[] = stripcslashes( $m[8] );
				} elseif ( isset( $m[9] ) ) {
					$out[] = stripcslashes( $m[9] );
				}
			}
		}
		return $out;
	}

	/** Shortcode tags used in $text => number of uses. */
	public static function scan( $text, array $registered = array() ) {
		$counts = array();
		self::count( self::parse( $text, $registered ), $counts );
		return $counts;
	}

	private static function count( array $nodes, array &$counts ) {
		foreach ( $nodes as $node ) {
			if ( 'sc' === $node['t'] ) {
				$tag            = strtolower( $node['tag'] );
				$counts[ $tag ] = ( isset( $counts[ $tag ] ) ? $counts[ $tag ] : 0 ) + 1;
				self::count( $node['children'], $counts );
			}
		}
	}

	/** Text of a tree put back exactly as it was written. */
	public static function raw( array $nodes ) {
		$out = '';
		foreach ( $nodes as $node ) {
			$out .= 'text' === $node['t'] ? $node['v'] : $node['open'] . self::raw( $node['children'] ) . $node['close'];
		}
		return $out;
	}
}
