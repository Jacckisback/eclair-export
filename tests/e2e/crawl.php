<?php
/**
 * Vérification de la migration : chaque ancienne adresse publique du paquet (urls.csv) est demandée au site Éclair.
 *   php tests/e2e/crawl.php <dossier du paquet> <url d'Éclair> <dossier de sortie>
 *
 * Attendu pour chaque adresse : 200, ou une seule 301 menant à une page 200. Pour un contenu, la page obtenue doit
 * porter son titre (la bonne page, pas seulement une page). Écrit crawl.json et crawl.md ; code de sortie 1 au moindre
 * 404, chaîne de redirections ou mauvaise page.
 */

$pkg  = rtrim( $argv[1], '/' );
$base = rtrim( $argv[2], '/' );
$out  = rtrim( $argv[3], '/' );

// Données du paquet : adresses et titres des contenus.
$files = array();
foreach ( glob( $pkg . '/*.zip' ) as $part ) {
	$zip = new ZipArchive();
	$zip->open( $part );
	foreach ( array( 'urls.csv', 'manifest.json', 'content/posts.json', 'content/pages.json', 'content/products.json' ) as $name ) {
		if ( false !== $zip->locateName( $name ) ) {
			$files[ $name ] = $zip->getFromName( $name );
		}
	}
	foreach ( range( 0, $zip->numFiles - 1 ) as $i ) {
		$name = (string) $zip->getNameIndex( $i );
		if ( preg_match( '~^content/cpt-.+\.json$~', $name ) ) {
			$files[ $name ] = $zip->getFromName( $name );
		}
	}
	$zip->close();
}
$titles = array();
foreach ( $files as $name => $json ) {
	if ( 0 === strpos( $name, 'content/' ) ) {
		foreach ( (array) json_decode( $json, true ) as $item ) {
			$titles[ (int) $item['wp_id'] ] = (string) $item['title'];
		}
	}
}
$rows = array_map( 'str_getcsv', array_filter( explode( "\n", (string) ( $files['urls.csv'] ?? '' ) ) ) );
array_shift( $rows );

$get = static function ( string $url ): array {
	$ch = curl_init( $url );
	curl_setopt_array( $ch, array( CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 30, CURLOPT_PROXY => '' ) );
	$raw  = (string) curl_exec( $ch );
	$size = (int) curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
	$loc  = preg_match( '~^location:\s*(\S+)~mi', substr( $raw, 0, $size ), $m ) ? $m[1] : '';
	return array( (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE ), $loc, substr( $raw, $size ) );
};

$result = array( 'total' => 0, 'ok' => 0, 'redirect' => 0, 'chain' => 0, 'broken' => 0, 'wrong' => 0, 'by_kind' => array(), 'problems' => array() );
$start  = microtime( true );
foreach ( $rows as $row ) {
	list( $old, $kind, $ref ) = array_pad( $row, 3, '' );
	$p    = parse_url( $old );
	$path = ( $p['path'] ?? '/' ) . ( isset( $p['query'] ) ? '?' . $p['query'] : '' );
	$url  = $base . $path;
	$hops = array();
	for ( $i = 0; $i < 5; $i++ ) {
		list( $status, $loc, $body ) = $get( $url );
		if ( $status >= 300 && $status < 400 && '' !== $loc ) {
			$hops[] = $status;
			$url    = 0 === strpos( $loc, 'http' ) ? $loc : $base . $loc;
			continue;
		}
		break;
	}
	$state = 200 !== $status ? 'broken' : ( count( $hops ) === 0 ? 'ok' : ( count( $hops ) === 1 ? 'redirect' : 'chain' ) );
	// La bonne page : celle qui porte le titre du contenu.
	if ( 'content' === $kind && 'broken' !== $state && preg_match( '~:(\d+)$~', $ref, $m ) && isset( $titles[ (int) $m[1] ] ) ) {
		$text = html_entity_decode( strip_tags( $body ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( false === strpos( $text, $titles[ (int) $m[1] ] ) ) {
			$state = 'wrong';
		}
	}
	$result['total']++;
	$result[ $state ]++;
	$result['by_kind'][ $kind ][ $state ] = ( $result['by_kind'][ $kind ][ $state ] ?? 0 ) + 1;
	if ( ! in_array( $state, array( 'ok', 'redirect' ), true ) && count( $result['problems'] ) < 100 ) {
		$result['problems'][] = array( 'old' => $old, 'state' => $state, 'status' => $status, 'hops' => count( $hops ), 'final' => $url );
	}
}
$result['seconds'] = round( microtime( true ) - $start, 1 );
file_put_contents( "$out/crawl.json", json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );

$md  = "## Vérification des anciennes adresses\n\n";
$md .= "| | Adresses |\n|---|---:|\n";
$md .= "| Servies à l'identique (200) | {$result['ok']} |\n| Une seule 301 vers la bonne page | {$result['redirect']} |\n";
$md .= "| Chaînes de redirections | {$result['chain']} |\n| Erreurs (404…) | {$result['broken']} |\n| Mauvaise page | {$result['wrong']} |\n| **Total** | **{$result['total']}** |\n\n";
$md .= "| Type d'adresse | 200 | 301 | Problèmes |\n|---|---:|---:|---:|\n";
foreach ( $result['by_kind'] as $kind => $s ) {
	$md .= "| $kind | " . ( $s['ok'] ?? 0 ) . ' | ' . ( $s['redirect'] ?? 0 ) . ' | ' . ( ( $s['chain'] ?? 0 ) + ( $s['broken'] ?? 0 ) + ( $s['wrong'] ?? 0 ) ) . " |\n";
}
foreach ( $result['problems'] as $pb ) {
	$md .= "\n- `{$pb['old']}` → {$pb['state']} ({$pb['status']}, {$pb['hops']} saut(s)) → `{$pb['final']}`";
}
file_put_contents( "$out/crawl.md", $md . "\n" );
echo $md, "\n";
$bad = $result['chain'] + $result['broken'] + $result['wrong'];
echo $bad ? "✗ $bad adresse(s) en défaut\n" : "✓ Zéro 404 : les {$result['total']} anciennes adresses mènent à la bonne page ({$result['seconds']} s)\n";
exit( $bad || 0 === $result['total'] ? 1 : 0 );
