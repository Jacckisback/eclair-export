<?php
/**
 * Tests of the pure parts: php tests/run.php
 */

require __DIR__ . '/bootstrap.php';

$ctx = array(
	'post_id'    => 7,
	'registered' => array( 'contact-form-7', 'gallery', 'caption' ),
	'image'      => static function ( $id ) {
		return array( 'url' => 'https://www.example.com/wp-content/uploads/2024/03/img-' . $id . '.jpg', 'alt' => 'Alt ' . $id );
	},
	'attached'   => static function () {
		return array( 3, 4 );
	},
);
$convert = static function ( $html, &$report = null ) use ( $ctx ) {
	$report = Eclair_Export_Converter::empty_report();
	$out    = Eclair_Export_Converter::render( Eclair_Export_Shortcodes::parse( $html, $ctx['registered'] ), $ctx, $report );
	return Eclair_Export_Converter::clean( $out, $report );
};

/* ------------------------------------------------------------ Shortcode parser */

$tree = Eclair_Export_Shortcodes::parse( 'a [b x="1"]in[/b] c' );
same( 'parse: text, shortcode, text', array( 'text', 'sc', 'text' ), array_column( $tree, 't' ) );
same( 'parse: attribute', array( 'x' => '1' ), $tree[1]['atts'] );
same( 'parse: enclosed content', 'in', $tree[1]['children'][0]['v'] );

$text = 'Avant [gallery ids="1,2"] puis [unknown_plugin foo="bar"]contenu[/unknown_plugin] et [1] note [Note] fin.';
same( 'raw: text rebuilt byte for byte', $text, Eclair_Export_Shortcodes::raw( Eclair_Export_Shortcodes::parse( $text ) ) );
same( 'scan: footnotes and capitalised words are not shortcodes', array( 'gallery' => 1, 'unknown_plugin' => 1 ), Eclair_Export_Shortcodes::scan( $text ) );

$nested = '[et_pb_section][et_pb_row][et_pb_column][et_pb_text]Hi[/et_pb_text][/et_pb_column][/et_pb_row][/et_pb_section]';
same( 'scan: nested Divi', array( 'et_pb_section' => 1, 'et_pb_row' => 1, 'et_pb_column' => 1, 'et_pb_text' => 1 ), Eclair_Export_Shortcodes::scan( $nested ) );

$unclosed = '[gallery ids="1"] text after [/other]';
$tree     = Eclair_Export_Shortcodes::parse( $unclosed );
same( 'parse: unclosed shortcode is self-closing', array( 'sc', 'text' ), array_column( $tree, 't' ) );
same( 'raw: unclosed and stray closing tag kept', $unclosed, Eclair_Export_Shortcodes::raw( $tree ) );

same( 'parse: escaped [[tag]] shown literally', '[gallery]', Eclair_Export_Shortcodes::raw( Eclair_Export_Shortcodes::parse( '[[gallery]]' ) ) );
same( 'parse: quoted "]" inside an attribute', array( 'title' => 'a]b' ), Eclair_Export_Shortcodes::parse( '[x_y title="a]b"]' )[0]['atts'] );
same( 'atts: positional and unquoted values', array( 'https://youtu.be/abc', 'width' => '640' ), Eclair_Export_Shortcodes::atts( ' "https://youtu.be/abc" width=640' ) );
same( 'atts: self-closing slash ignored', array( 'id' => '5' ), Eclair_Export_Shortcodes::atts( ' id="5" /' ) );

/* ------------------------------------------------------------ Core shortcodes */

$out = $convert( '[caption id="attachment_5" align="alignleft" width="300"]<img src="https://www.example.com/wp-content/uploads/a.jpg" alt="A" class="size-medium wp-image-5" width="300" height="200"> Ma légende[/caption]', $report );
has( 'caption → figure', '<figure><img src="https://www.example.com/wp-content/uploads/a.jpg" alt="A" width="300" height="200"><figcaption>Ma légende</figcaption></figure>', $out );
hasnt( 'caption: class removed', 'class=', $out );
same( 'caption: counted as converted', array( 'caption' => 1 ), $report['converted'] );

$out = $convert( '[gallery ids="11,12"]' );
has( 'gallery by ids', 'img-11.jpg" alt="Alt 11"', $out );
has( 'gallery by ids (second)', 'img-12.jpg', $out );
has( 'gallery without ids: attached images', 'img-3.jpg', $convert( '[gallery]' ) );

has( 'embed YouTube → iframe', '<iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ"></iframe>', $convert( '[embed]https://www.youtube.com/watch?v=dQw4w9WgXcQ[/embed]' ) );
has( 'video file → video tag', '<video controls src="https://www.example.com/v.mp4"></video>', $convert( '[video mp4="https://www.example.com/v.mp4"]' ) );
has( 'audio → audio tag', '<audio controls src="https://www.example.com/a.mp3"></audio>', $convert( '[audio src="https://www.example.com/a.mp3"]' ) );

$out = $convert( '<p>Écrivez-nous :</p>[contact-form-7 id="12" title="Contact"]', $report );
has( 'unknown shortcode kept as written', '[contact-form-7 id="12" title="Contact"]', $out );
same( 'unknown shortcode reported', array( 'contact-form-7' => 1 ), $report['shortcodes'] );

/* ------------------------------------------------------------ Divi */

$divi = '[et_pb_section fb_built="1"][et_pb_row][et_pb_column type="4_4"][et_pb_text]<h2>Bienvenue</h2><p>Texte <strong>riche</strong>.</p>[/et_pb_text]'
	. '[et_pb_image src="https://www.example.com/wp-content/uploads/b.jpg" alt="B" url="https://www.example.com/contact/"][/et_pb_image]'
	. '[et_pb_button button_text="Réserver" button_url="https://www.example.com/reserver/"][/et_pb_button]'
	. '[et_pb_toggle title="Question ?"]Réponse.[/et_pb_toggle]'
	. '[et_pb_testimonial author="Marie" job_title="Cliente"]Super service.[/et_pb_testimonial]'
	. '[et_pb_contact_form email="x@example.com"][et_pb_contact_field field_title="Nom"][/et_pb_contact_field][/et_pb_contact_form]'
	. '[/et_pb_column][/et_pb_row][/et_pb_section]';
$out = $convert( $divi, $report );
has( 'Divi text', '<h2>Bienvenue</h2>', $out );
has( 'Divi rich text kept', '<strong>riche</strong>', $out );
has( 'Divi image with link', '<a href="https://www.example.com/contact/"><img src="https://www.example.com/wp-content/uploads/b.jpg" alt="B"></a>', $out );
has( 'Divi button → link', '<a href="https://www.example.com/reserver/">Réserver</a>', $out );
has( 'Divi toggle → details', '<details><summary>Question ?</summary><p>Réponse.</p></details>', $out );
has( 'Divi testimonial → blockquote', '<cite>Marie, Cliente</cite>', $out );
hasnt( 'Divi: no shortcode left', '[et_pb', $out );
check( 'Divi contact form reported', isset( $report['widgets']['divi:contact_form'] ), var_export( $report['widgets'], true ) );
same( 'Divi: nothing reported as unknown shortcode', array(), $report['shortcodes'] );

/* ------------------------------------------------------------ Elementor */

$elementor = json_decode( '[{"elType":"section","elements":[{"elType":"column","elements":[
	{"elType":"widget","widgetType":"heading","settings":{"title":"Nos services","header_size":"h2"}},
	{"elType":"widget","widgetType":"text-editor","settings":{"editor":"<p>Un texte <em>simple</em>.</p>"}},
	{"elType":"widget","widgetType":"image","settings":{"image":{"url":"https://www.example.com/wp-content/uploads/c.jpg","id":9,"alt":"C"},"caption":"Légende"}},
	{"elType":"widget","widgetType":"button","settings":{"text":"Devis","link":{"url":"https://www.example.com/devis/"}}},
	{"elType":"widget","widgetType":"video","settings":{"youtube_url":"https://youtu.be/abcdefghijk"}},
	{"elType":"widget","widgetType":"accordion","settings":{"tabs":[{"tab_title":"Prix ?","tab_content":"Sur devis."}]}},
	{"elType":"widget","widgetType":"icon-list","settings":{"icon_list":[{"text":"Rapide"},{"text":"Fiable","link":{"url":"https://www.example.com/a/"}}]}},
	{"elType":"widget","widgetType":"form","settings":{"form_name":"Contact","title":"Formulaire"}},
	{"elType":"widget","widgetType":"shortcode","settings":{"shortcode":"[contact-form-7 id=\"3\"]"}}
]}]}]', true );
$report = Eclair_Export_Converter::empty_report();
$base   = Eclair_Export_Converter::from_elementor( $elementor, $report );
$out    = Eclair_Export_Converter::clean( Eclair_Export_Converter::render( Eclair_Export_Shortcodes::parse( $base, $ctx['registered'] ), $ctx, $report ), $report );
has( 'Elementor heading', '<h2>Nos services</h2>', $out );
has( 'Elementor text', '<em>simple</em>', $out );
has( 'Elementor image + caption', '<figure><img src="https://www.example.com/wp-content/uploads/c.jpg" alt="C"><figcaption>Légende</figcaption></figure>', $out );
has( 'Elementor button', '<a href="https://www.example.com/devis/">Devis</a>', $out );
has( 'Elementor YouTube', 'https://www.youtube.com/embed/abcdefghijk', $out );
has( 'Elementor accordion → details', '<details><summary>Prix ?</summary><p>Sur devis.</p></details>', $out );
has( 'Elementor icon list', '<li><a href="https://www.example.com/a/">Fiable</a></li>', $out );
check( 'Elementor form reported', isset( $report['widgets']['elementor:form'] ) );
has( 'Elementor form title kept', 'Formulaire', $out );
has( 'Elementor shortcode widget kept as text', '[contact-form-7 id="3"]', $out );
same( 'Elementor shortcode reported', array( 'contact-form-7' => 1 ), $report['shortcodes'] );

/* ------------------------------------------------------------ Gutenberg output and cleaning */

$gutenberg = '<!-- wp:paragraph --><p class="has-text-color" style="color:red">Bonjour <a href="https://www.example.com/x/" target="_blank" rel="noopener nofollow" onclick="evil()">lien</a></p><!-- /wp:paragraph -->'
	. '<div class="wp-block-columns"><div class="wp-block-column"><h3 id="part" class="x">Partie</h3></div></div>'
	. '<script>alert(1)</script><style>p{}</style>'
	. '<figure class="wp-block-embed is-type-video"><div class="wp-block-embed__wrapper">https://www.youtube.com/watch?v=dQw4w9WgXcQ</div></figure>'
	. '<p><img src="data:image/gif;base64,R0lG" data-lazy-src="https://www.example.com/wp-content/uploads/d.jpg" alt="D" srcset="x 300w"></p>'
	. '<p><a href="javascript:alert(1)">piège</a></p><iframe src="https://evil.example/ad"></iframe><p> </p>'
	. '<table class="t"><tr><td colspan="2" style="x">Cellule</td></tr></table>';
$report = Eclair_Export_Converter::empty_report();
$out    = Eclair_Export_Converter::clean( preg_replace( '~<!--\s*/?wp:.*?-->~s', '', $gutenberg ), $report );
has( 'clean: paragraph without class or style', '<p>Bonjour <a href="https://www.example.com/x/" rel="nofollow">lien</a></p>', $out );
has( 'clean: heading id kept, columns unwrapped', '<h3 id="part">Partie</h3>', $out );
hasnt( 'clean: script removed', 'alert(1)</script', $out );
hasnt( 'clean: no div left', '<div', $out );
has( 'clean: embed figure → iframe', '<iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ"></iframe>', $out );
has( 'clean: lazy image real address', 'src="https://www.example.com/wp-content/uploads/d.jpg"', $out );
hasnt( 'clean: srcset removed', 'srcset', $out );
hasnt( 'clean: javascript: link removed', 'javascript:', $out );
hasnt( 'clean: foreign iframe removed', 'evil.example', $out );
has( 'clean: table cell with colspan', '<td colspan="2">Cellule</td>', $out );
same( 'clean: dropped tags counted', array( 'script' => 1, 'style' => 1, 'iframe' => 1 ), $report['dropped'] );
hasnt( 'clean: empty paragraph removed', '<p> </p>', $out );
same( 'clean: empty input', '', Eclair_Export_Converter::clean( '', $report ) );
has( 'clean: UTF-8 kept', 'Ça déjà', Eclair_Export_Converter::clean( '<p>Ça déjà</p>', $report ) );

check( 'safe_url: https', Eclair_Export_Converter::safe_url( 'https://a.b/' ) );
check( 'safe_url: relative', Eclair_Export_Converter::safe_url( '/page/' ) );
check( 'safe_url: refuses javascript (encoded)', ! Eclair_Export_Converter::safe_url( 'jav&#x61;script:alert(1)' ) );
check( 'safe_url: refuses data', ! Eclair_Export_Converter::safe_url( 'data:text/html,x' ) );

/* ------------------------------------------------------------ Paths, redirections, SEO variables */

same( 'path: same host', '/2024/03/article/', Eclair_Export_Util::path( 'https://www.example.com/2024/03/article/' ) );
same( 'path: without www', '/a/', Eclair_Export_Util::path( 'https://example.com/a/' ) );
same( 'path: other host', '', Eclair_Export_Util::path( 'https://other.com/a/' ) );
same( 'path: query kept', '/?p=12', Eclair_Export_Util::path( 'https://www.example.com/?p=12' ) );
same( 'path: relative', '/b/', Eclair_Export_Util::path( '/b/' ) );
$GLOBALS['test_home'] = 'https://www.example.com/blog';
same( 'path: sub-folder install removed', '/article/', Eclair_Export_Util::path( 'https://www.example.com/blog/article/' ) );
same( 'path: sub-folder root', '/', Eclair_Export_Util::path( 'https://www.example.com/blog' ) );
$GLOBALS['test_home'] = 'https://www.example.com';

same( 'Rank Math sources', array( array( '/old/', 0 ), array( '^old-(\d+)$', 1 ), array( '.*promo.*', 1 ), array( '^/?shop/', 1 ), array( '\.php$', 1 ) ), Eclair_Export_Redirects::rankmath_sources( array(
	array( 'pattern' => 'old/', 'comparison' => 'exact' ),
	array( 'pattern' => '^old-(\d+)$', 'comparison' => 'regex' ),
	array( 'pattern' => 'promo', 'comparison' => 'contains' ),
	array( 'pattern' => '/shop/', 'comparison' => 'start' ),
	array( 'pattern' => '.php', 'comparison' => 'end' ),
	array( 'pattern' => '', 'comparison' => 'exact' ),
) ) );

same( 'SEO variables: Yoast', 'Mon article - Site', Eclair_Export_Seo::fallback_vars( '%%title%% %%sep%% %%sitename%%', 'Mon article', 'Site', '-' ) );
same( 'SEO variables: Rank Math', 'Mon article | Site', Eclair_Export_Seo::fallback_vars( '%title% | %sitename% %page%', 'Mon article', 'Site', '-' ) );
same( 'SEO variables: AIOSEO', 'Mon article - Site', Eclair_Export_Seo::fallback_vars( '#post_title #separator_sa #site_title', 'Mon article', 'Site', '-' ) );
same( 'SEO variables: unknown removed', 'Titre', Eclair_Export_Seo::fallback_vars( 'Titre %%primary_category%%', 'x', 'Site', '-' ) );

same( 'sensitive keys', array( true, true, true, false, false ), array_map( array( 'Eclair_Export_Util', 'sensitive' ), array( 'stripe_secret', 'api_key', 'user_password', 'price', 'subtitle' ) ) );

/* ------------------------------------------------------------ PHP 7.4 compatibility (static check) */

$php8 = array( '~\bmatch\s*\(~' => 'match()', '~\?->~' => 'nullsafe ?->', '~\bstr_(contains|starts_with|ends_with)\s*\(~' => 'str_contains & co', '~function\s+\w+\s*\([^)]*\b\w+\s*\|\s*\w+\s+\$~' => 'union type', '~\):\s*(mixed|static|never)\b~' => 'mixed/static/never', '~^\s*#\[~m' => 'attribute' );
foreach ( glob( dirname( __DIR__ ) . '/{*.php,includes/*.php,admin/*.php}', GLOB_BRACE ) as $file ) {
	$code = file_get_contents( $file );
	foreach ( $php8 as $pattern => $what ) {
		check( 'PHP 7.4: ' . basename( $file ) . ' uses no ' . $what, ! preg_match( $pattern, $code ) );
	}
}

/* ------------------------------------------------------------ Download links (regression 1.0.1) */

// Links sent as JSON must not be HTML-escaped: wp_nonce_url() gives "&amp;_wpnonce" and every download failed.
$plugin_src = file_get_contents( dirname( __DIR__ ) . '/includes/class-eclair-export-plugin.php' );
check( 'download links: no wp_nonce_url() in the JSON links', ! preg_match( '~=\s*wp_nonce_url\s*\(~', $plugin_src ) );
check( 'download links: built with add_query_arg()', false !== strpos( $plugin_src, "'_wpnonce' => wp_create_nonce( self::NONCE )" ) );

/* ------------------------------------------------------------ Result */

echo $GLOBALS['passed'] . " checks passed\n";
if ( $GLOBALS['failed'] ) {
	echo count( $GLOBALS['failed'] ) . " failed:\n  - " . implode( "\n  - ", $GLOBALS['failed'] ) . "\n";
	exit( 1 );
}
