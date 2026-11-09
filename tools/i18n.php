<?php
/**
 * Translations: php tools/i18n.php
 *
 * 1. Reads every translatable string of the plugin (__, _e, esc_html__, esc_html_e, esc_attr__, esc_attr_e, _n) with
 *    its translator comment, and writes languages/eclair-export.pot.
 * 2. For each tools/translations/<locale>.php (English => translation, plural: [singular, plural]), writes
 *    languages/eclair-export-<locale>.po and the compiled .mo. A string without translation is reported.
 */

$root  = dirname( __DIR__ );
$files = array_merge( glob( $root . '/*.php' ), glob( $root . '/includes/*.php' ), glob( $root . '/admin/*.php' ) );
$funcs = array( '__' => 1, '_e' => 1, 'esc_html__' => 1, 'esc_html_e' => 1, 'esc_attr__' => 1, 'esc_attr_e' => 1, '_n' => 2 );
$found = array();

foreach ( $files as $file ) {
	$tokens  = token_get_all( file_get_contents( $file ) );
	$comment = '';
	$count   = count( $tokens );
	for ( $i = 0; $i < $count; $i++ ) {
		$t = $tokens[ $i ];
		if ( is_array( $t ) && T_COMMENT === $t[0] && preg_match( '~translators:\s*(.+?)\s*(\*/)?$~s', $t[1], $m ) ) {
			$comment = trim( preg_replace( '~\s+~', ' ', $m[1] ) );
			continue;
		}
		if ( ! is_array( $t ) || T_STRING !== $t[0] || ! isset( $funcs[ $t[1] ] ) ) {
			continue;
		}
		$j = $i + 1;
		while ( $j < $count && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
			$j++;
		}
		if ( '(' !== $tokens[ $j ] ) {
			continue;
		}
		$args = array();
		for ( $k = $j + 1; $k < $count && count( $args ) < $funcs[ $t[1] ]; $k++ ) {
			$a = $tokens[ $k ];
			if ( is_array( $a ) && T_CONSTANT_ENCAPSED_STRING === $a[0] ) {
				$args[] = stripcslashes( substr( $a[1], 1, -1 ) );
			} elseif ( is_array( $a ) && in_array( $a[0], array( T_WHITESPACE ), true ) || ',' === $a ) {
				continue;
			} else {
				break;
			}
		}
		if ( count( $args ) !== $funcs[ $t[1] ] ) {
			continue;
		}
		// Single-quoted PHP strings: only \' and \\ are escapes.
		$key = $args[0];
		if ( ! isset( $found[ $key ] ) ) {
			$found[ $key ] = array( 'plural' => isset( $args[1] ) ? $args[1] : null, 'refs' => array(), 'comment' => '' );
		}
		$found[ $key ]['refs'][] = str_replace( $root . '/', '', $file ) . ':' . $t[2];
		if ( '' !== $comment ) {
			$found[ $key ]['comment'] = $comment;
		}
		$comment = '';
	}
}
ksort( $found );

function po_string( $s ) {
	return '"' . addcslashes( $s, "\"\\\n\t" ) . '"';
}

function header_block( $locale, $plural_forms ) {
	return "# Eclair Export\n# Copyright (C) 2026 Thierry Gustin\n# This file is distributed under the GPL-3.0-or-later.\nmsgid \"\"\nmsgstr \"\"\n"
		. "\"Project-Id-Version: Eclair Export 1.0.0\\n\"\n\"Report-Msgid-Bugs-To: https://github.com/Jacckisback/eclair-export/issues\\n\"\n"
		. "\"MIME-Version: 1.0\\n\"\n\"Content-Type: text/plain; charset=UTF-8\\n\"\n\"Content-Transfer-Encoding: 8bit\\n\"\n"
		. ( '' !== $locale ? "\"Language: {$locale}\\n\"\n" : '' ) . "\"Plural-Forms: {$plural_forms}\\n\"\n\"X-Domain: eclair-export\\n\"\n\n";
}

$pot = header_block( '', 'nplurals=2; plural=(n != 1);' );
foreach ( $found as $msgid => $info ) {
	if ( '' !== $info['comment'] ) {
		$pot .= '#. translators: ' . $info['comment'] . "\n";
	}
	$pot .= '#: ' . implode( ' ', array_slice( array_unique( $info['refs'] ), 0, 4 ) ) . "\n";
	if ( false !== strpos( $msgid, '%' ) ) {
		$pot .= "#, php-format\n";
	}
	$pot .= 'msgid ' . po_string( $msgid ) . "\n";
	if ( null !== $info['plural'] ) {
		$pot .= 'msgid_plural ' . po_string( $info['plural'] ) . "\nmsgstr[0] \"\"\nmsgstr[1] \"\"\n\n";
	} else {
		$pot .= "msgstr \"\"\n\n";
	}
}
file_put_contents( $root . '/languages/eclair-export.pot', $pot );
echo count( $found ) . " strings → languages/eclair-export.pot\n";

$status = 0;
foreach ( glob( __DIR__ . '/translations/*.php' ) as $map_file ) {
	$locale  = basename( $map_file, '.php' );
	$map     = require $map_file;
	$po      = header_block( $locale, 'nplurals=2; plural=(n > 1);' );
	$entries = array();
	$missing = array();
	foreach ( $found as $msgid => $info ) {
		$tr = isset( $map[ $msgid ] ) ? $map[ $msgid ] : null;
		if ( null === $tr ) {
			$missing[] = $msgid;
		}
		$po .= '#: ' . implode( ' ', array_slice( array_unique( $info['refs'] ), 0, 4 ) ) . "\n" . 'msgid ' . po_string( $msgid ) . "\n";
		if ( null !== $info['plural'] ) {
			$pair = is_array( $tr ) ? $tr : array( '', '' );
			$po  .= 'msgid_plural ' . po_string( $info['plural'] ) . "\nmsgstr[0] " . po_string( $pair[0] ) . "\nmsgstr[1] " . po_string( $pair[1] ) . "\n\n";
			if ( is_array( $tr ) ) {
				$entries[ $msgid . "\0" . $info['plural'] ] = $pair[0] . "\0" . $pair[1];
			}
		} else {
			$po .= 'msgstr ' . po_string( is_string( $tr ) ? $tr : '' ) . "\n\n";
			if ( is_string( $tr ) && '' !== $tr ) {
				$entries[ $msgid ] = $tr;
			}
		}
	}
	$unused = array_diff( array_keys( $map ), array_keys( $found ) );
	file_put_contents( $root . "/languages/eclair-export-{$locale}.po", $po );
	file_put_contents( $root . "/languages/eclair-export-{$locale}.mo", mo( $entries, $locale ) );
	echo "{$locale}: " . count( $entries ) . ' translated, ' . count( $missing ) . ' missing, ' . count( $unused ) . " unused\n";
	foreach ( $missing as $m ) {
		echo "  missing: {$m}\n";
		$status = 1;
	}
	foreach ( $unused as $u ) {
		echo "  unused: {$u}\n";
	}
}
exit( $status );

/** GNU .mo file (little endian, no hash table). */
function mo( array $entries, $locale ) {
	$entries = array( '' => "Content-Type: text/plain; charset=UTF-8\nLanguage: {$locale}\nPlural-Forms: nplurals=2; plural=(n > 1);\n" ) + $entries;
	ksort( $entries, SORT_STRING );
	$n       = count( $entries );
	$ids     = '';
	$strs    = '';
	$offsets = array();
	foreach ( $entries as $id => $str ) {
		$offsets[] = array( strlen( $ids ), strlen( $id ), strlen( $strs ), strlen( $str ) );
		$ids      .= $id . "\0";
		$strs     .= $str . "\0";
	}
	$header    = 28;
	$table_ids = $header;
	$table_str = $table_ids + 8 * $n;
	$start_ids = $table_str + 8 * $n;
	$start_str = $start_ids + strlen( $ids );
	$out       = pack( 'V7', 0x950412de, 0, $n, $table_ids, $table_str, 0, $start_ids );
	foreach ( $offsets as $o ) {
		$out .= pack( 'V2', $o[1], $start_ids + $o[0] );
	}
	foreach ( $offsets as $o ) {
		$out .= pack( 'V2', $o[3], $start_str + $o[2] );
	}
	return $out . $ids . $strs;
}
