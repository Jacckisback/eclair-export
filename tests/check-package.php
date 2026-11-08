<?php
/**
 * Checks a package made by tests/integration.sh: php tests/check-package.php <output dir> <expect.json>
 */

$dir    = rtrim( $argv[1], '/' );
$expect = json_decode( file_get_contents( $argv[2] ), true );
$fails  = array();
$passed = 0;
$check  = static function ( $label, $ok, $detail = '' ) use ( &$fails, &$passed ) {
	if ( $ok ) {
		$passed++;
	} else {
		$fails[] = $label . ( '' !== $detail ? ' — ' . $detail : '' );
	}
};

$parts = glob( $dir . '/eclair-export-*.zip' );
sort( $parts );
$check( 'several parts with --part-size=1', count( $parts ) >= 2, count( $parts ) . ' part(s)' );
$main  = null;
$files = array();
foreach ( $parts as $part ) {
	$zip = new ZipArchive();
	$check( 'opens ' . basename( $part ), true === $zip->open( $part ) );
	for ( $i = 0; $i < $zip->numFiles; $i++ ) {
		$name = $zip->getNameIndex( $i );
		$check( 'no path outside the package: ' . $name, false === strpos( $name, '..' ) && '/' !== $name[0] );
		$files[ $name ] = $zip->getFromIndex( $i );
	}
	if ( false !== $zip->locateName( 'manifest.json' ) ) {
		$main = basename( $part );
	}
	$zip->close();
}
$check( 'one part holds manifest.json', null !== $main );
$manifest = json_decode( $files['manifest.json'], true );
$check( 'format', 'eclair-export' === $manifest['format'] && '1.0' === $manifest['format_version'] );
$check( 'source url', 'https://www.example.test' === $manifest['source']['url'] );
$check( 'Yoast detected', ! $expect['yoast'] || isset( $manifest['plugins']['detected']['yoast'] ) );
$check( 'Redirection detected', ! $expect['redirection_active'] || isset( $manifest['plugins']['detected']['redirection'] ) );
$check( 'parts listed', count( $manifest['parts'] ) === count( $parts ) );
foreach ( $manifest['files'] as $name => $sum ) {
	$check( 'checksum ' . $name, isset( $files[ $name ] ) && hash( 'sha256', $files[ $name ] ) === $sum['sha256'] );
}
$check( 'count posts', (int) $manifest['counts']['content']['post'] === (int) $expect['post'], $manifest['counts']['content']['post'] . ' / ' . $expect['post'] );
$check( 'count pages', (int) $manifest['counts']['content']['page'] === (int) $expect['page'], $manifest['counts']['content']['page'] . ' / ' . $expect['page'] );

$posts = json_decode( $files['content/posts.json'], true );
$pages = json_decode( $files['content/pages.json'], true );
$check( 'posts.json length', count( $posts ) === (int) $expect['post'] );
$by_id = array();
foreach ( array_merge( $posts, $pages ) as $item ) {
	$by_id[ $item['wp_id'] ] = $item;
}
$a = $by_id[ $expect['article'] ];
$check( 'exact path kept', '/2024/03/article-gutenberg/' === $a['path'], $a['path'] );
$check( 'Gutenberg: no block comment', false === strpos( $a['html'], '<!-- wp:' ) );
$check( 'Gutenberg: image with caption', false !== strpos( $a['html'], '<figcaption>Légende</figcaption>' ), $a['html'] );
$check( 'Gutenberg: YouTube embed', false !== strpos( $a['html'], 'youtube.com/embed/dQw4w9WgXcQ' ), $a['html'] );
$check( 'Gutenberg: unknown shortcode kept', false !== strpos( $a['html'], '[contact-form-7 id="1" title="Contact"]' ) );
$check( 'shortcode listed on the content', in_array( 'contact-form-7', $a['shortcodes_left'], true ) );
$check( 'category and tag', array( 'actualites' ) === $a['terms']['category'] && array( 'promo' ) === $a['terms']['post_tag'] );
$check( 'featured image', $a['featured_media'] > 0 );
$check( 'format gutenberg', 'gutenberg' === $a['source_format'] );
$check( 'secret meta not exported', ! isset( $a['meta']['stripe_secret_key'] ) );

$classic = null;
$divi    = null;
foreach ( array_merge( $posts, $pages ) as $item ) {
	if ( 'article-classique' === $item['slug'] ) {
		$classic = $item;
	}
	if ( 'page-divi' === $item['slug'] ) {
		$divi = $item;
	}
}
$check( 'classic: paragraphs', false !== strpos( $classic['html'], '<p>Premier paragraphe.</p>' ), $classic['html'] );
$check( 'classic: gallery converted', 2 === substr_count( $classic['html'], '<img ' ), $classic['html'] );
$check( 'classic: orphan shortcode kept', false !== strpos( $classic['html'], '[orphan_shortcode foo="bar"]' ) );
$check( 'Divi converted', false !== strpos( $divi['html'], '<h2>Bienvenue</h2>' ) && false !== strpos( $divi['html'], 'Réserver</a>' ) && false === strpos( $divi['html'], '[et_pb' ), $divi['html'] );
$check( 'child page path', '/services/audit/' === $by_id[ $expect['audit'] ]['path'] );

$seo = json_decode( $files['seo.json'], true );
$check( 'Yoast title resolved', 0 === strpos( $seo['posts'][ $expect['article'] ]['title'], 'Article Gutenberg' ) && false === strpos( $seo['posts'][ $expect['article'] ]['title'], '%%' ), $seo['posts'][ $expect['article'] ]['title'] );
$check( 'Yoast description', 'Description SEO écrite à la main.' === $seo['posts'][ $expect['article'] ]['description'] );
$check( 'Yoast keyword', 'gutenberg' === $seo['posts'][ $expect['article'] ]['keyword'] );
$check( 'Yoast noindex', ! $expect['yoast'] || true === $seo['posts'][ $expect['audit'] ]['noindex'] );
$check( 'term SEO present', isset( $seo['terms'] ) );

$redirects = array_map( 'str_getcsv', array_filter( explode( "\n", $files['redirects.csv'] ) ) );
$check( 'redirects header', array( 'source', 'target', 'code', 'origin', 'regex' ) === $redirects[0] );
$sources = array();
foreach ( array_slice( $redirects, 1 ) as $row ) {
	$sources[ $row[0] ] = $row;
}
$check( 'Yoast Premium redirection', isset( $sources['/ancienne-page'] ) && '/services/' === $sources['/ancienne-page'][1] );
$check( 'category archive redirected', isset( $sources['/category/actualites/'] ) && 'eclair:term:category:actualites' === $sources['/category/actualites/'][1] );
$check( 'tag archive redirected', isset( $sources['/tag/promo/'] ) );
$check( 'author archive redirected', isset( $sources['/author/admin/'] ) && 'eclair:author:admin' === $sources['/author/admin/'][1] );
$check( 'month archive redirected', isset( $sources['/2024/03/'] ) );
if ( $expect['redirection'] ) {
	$check( 'Redirection plugin row', isset( $sources['/vieux-lien/'] ) && '/2024/03/article-gutenberg/' === $sources['/vieux-lien/'][1] );
}

$urls = $files['urls.csv'];
$check( 'urls.csv has the article', false !== strpos( $urls, 'https://www.example.test/2024/03/article-gutenberg/' ) );
$check( 'urls.csv has no draft', false === strpos( $urls, 'brouillon' ) );

$media = json_decode( $files['media/index.json'], true );
$check( 'media count', count( $media ) === (int) $expect['media'], count( $media ) . '' );
foreach ( $media as $m ) {
	$check( 'media file in a part: ' . $m['file'], $m['included'] && isset( $files[ $m['file'] ] ) && hash( 'sha256', $files[ $m['file'] ] ) === $m['sha256'] );
	$check( 'media variants listed', count( $m['variants'] ) > 0 );
}
$first = $media[0];
$check( 'alt text', 'Alt photo un' === $first['alt'], $first['alt'] );

$authors = json_decode( $files['content/authors.json'], true );
$check( 'author exported without e-mail', 1 <= count( $authors ) && '' === $authors[0]['email'] );
$settings = json_decode( $files['content/settings.json'], true );
$check( 'settings permalinks', '/%year%/%monthnum%/%postname%/' === $settings['permalink_structure'] );

$report = json_decode( $files['report.json'], true );
$check( 'report: unconverted shortcodes', isset( $report['shortcodes']['contact-form-7'], $report['shortcodes']['orphan_shortcode'] ) );
$check( 'report: protected content counted', 1 === $report['protected'] );

$all = implode( "\n", $files );
foreach ( $expect['secrets'] as $secret ) {
	$check( 'no secret in the package: ' . substr( $secret, 0, 6 ) . '…', '' === $secret || false === strpos( $all, $secret ) );
}
$check( 'no user_pass field', false === strpos( $all, 'user_pass' ) );

$diag = json_decode( file_get_contents( $dir . '/diagnostic.json' ), true );
$check( 'diagnostic: score', is_int( $diag['score'] ) && $diag['score'] > 0 );
$check( 'diagnostic: shortcodes found', isset( $diag['shortcodes']['contact-form-7'] ) && ! $diag['shortcodes']['contact-form-7']['handled'] );
$check( 'diagnostic: Divi page counted', $diag['builders']['divi'] >= 1 );
$check( 'report FR translated', false !== strpos( file_get_contents( $dir . '/diagnostic-fr.html' ), 'Diagnostic de migration' ) );
$check( 'report ES translated', false !== strpos( file_get_contents( $dir . '/diagnostic-es.html' ), 'Diagnóstico de migración' ) );

echo $passed . " package checks passed\n";
if ( $fails ) {
	echo count( $fails ) . " failed:\n  - " . implode( "\n  - ", $fails ) . "\n";
	exit( 1 );
}
