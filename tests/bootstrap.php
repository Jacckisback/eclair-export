<?php
/**
 * Minimal WordPress stand-ins, enough to test the pure parts of the plugin (parser, converter, redirections, SEO
 * variables, paths) without a WordPress install. The full export is tested on a real site (see README, "Tests").
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'ECLAIR_EXPORT_VERSION', 'test' );
define( 'ECLAIR_EXPORT_FORMAT', '1.1' );

$GLOBALS['test_home'] = 'https://www.example.com';

function __( $t ) { return $t; }
function _n( $s, $p, $n ) { return 1 === (int) $n ? $s : $p; }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $t ) { return esc_html( $t ); }
function home_url() { return $GLOBALS['test_home']; }
function untrailingslashit( $s ) { return rtrim( (string) $s, '/\\' ); }
function trailingslashit( $s ) { return untrailingslashit( $s ) . '/'; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, $d ); }
function size_format( $bytes, $d = 0 ) { return round( $bytes / 1048576, $d ) . ' MB'; }
function get_bloginfo() { return 'Site'; }
function esc_url_raw( $url, $protocols = null ) { return preg_match( '~^(' . implode( '|', $protocols ? $protocols : array( 'http', 'https' ) ) . ')://[^\s<>"\']+$~i', (string) $url ) ? (string) $url : ''; }
function apply_filters( $tag, $value ) { return isset( $GLOBALS['test_filters'][ $tag ] ) ? $GLOBALS['test_filters'][ $tag ] : $value; }
function wpautop( $t ) {
	$paras = preg_split( '~\n\s*\n~', trim( (string) $t ) );
	return implode( "\n", array_map( static function ( $p ) {
		return preg_match( '~^\s*<(p|h[1-6]|ul|ol|blockquote|figure|div|table|pre)\b~i', $p ) ? $p : '<p>' . trim( $p ) . '</p>';
	}, array_filter( $paras, 'strlen' ) ) );
}

require dirname( __DIR__ ) . '/includes/class-eclair-export-util.php';
require dirname( __DIR__ ) . '/includes/class-eclair-export-shortcodes.php';
require dirname( __DIR__ ) . '/includes/class-eclair-export-converter.php';
require dirname( __DIR__ ) . '/includes/class-eclair-export-redirects.php';
require dirname( __DIR__ ) . '/includes/class-eclair-export-seo.php';
require dirname( __DIR__ ) . '/includes/class-eclair-export-sender.php';

$GLOBALS['passed'] = 0;
$GLOBALS['failed'] = array();

function check( $label, $ok, $detail = '' ) {
	if ( $ok ) {
		$GLOBALS['passed']++;
		return;
	}
	$GLOBALS['failed'][] = $label . ( '' !== $detail ? "\n      " . $detail : '' );
}

function same( $label, $expected, $actual ) {
	check( $label, $expected === $actual, 'expected: ' . var_export( $expected, true ) . "\n      actual:   " . var_export( $actual, true ) );
}

function has( $label, $needle, $haystack ) {
	check( $label, false !== strpos( (string) $haystack, $needle ), 'missing: ' . $needle . "\n      in: " . $haystack );
}

function hasnt( $label, $needle, $haystack ) {
	check( $label, false === strpos( (string) $haystack, $needle ), 'unexpected: ' . $needle . "\n      in: " . $haystack );
}
