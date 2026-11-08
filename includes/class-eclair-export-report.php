<?php
/**
 * Diagnostic report: one self-contained HTML page in the Éclair colours, to download, print or save as PDF (the
 * browser's "Save as PDF"), and to hand to a prospect. The "prepared by" block shows the details set on the screen.
 *
 * @package EclairExport
 */

defined( 'ABSPATH' ) || exit;

final class Eclair_Export_Report {

	const BRANDING = 'eclair_export_branding';

	public static function branding() {
		$b = get_option( self::BRANDING );
		$b = is_array( $b ) ? $b : array();
		return wp_parse_args( $b, array( 'name' => '', 'company' => '', 'email' => '', 'phone' => '', 'website' => '', 'note' => '' ) );
	}

	public static function save_branding( array $raw ) {
		$clean = array(
			'name'    => sanitize_text_field( isset( $raw['name'] ) ? $raw['name'] : '' ),
			'company' => sanitize_text_field( isset( $raw['company'] ) ? $raw['company'] : '' ),
			'email'   => sanitize_email( isset( $raw['email'] ) ? $raw['email'] : '' ),
			'phone'   => sanitize_text_field( isset( $raw['phone'] ) ? $raw['phone'] : '' ),
			'website' => esc_url_raw( isset( $raw['website'] ) ? $raw['website'] : '' ),
			'note'    => sanitize_textarea_field( isset( $raw['note'] ) ? $raw['note'] : '' ),
		);
		update_option( self::BRANDING, $clean, false );
		return $clean;
	}

	/** File name of the report. */
	public static function filename( array $d ) {
		return 'diagnostic-eclair-' . sanitize_title( Eclair_Export_Util::host( (string) wp_parse_url( $d['site']['url'], PHP_URL_HOST ) ) ) . '-' . gmdate( 'Y-m-d', $d['generated'] ) . '.html';
	}

	/** The whole report page. $print: adds the "Print / Save as PDF" button and opens the print dialog. */
	public static function html( array $d, $print = false ) {
		$b       = self::branding();
		$score   = (int) $d['score'];
		$tone    = $score >= 80 ? 'ok' : ( $score >= 55 ? 'warn' : 'bad' );
		$e       = 'esc_html';
		$n       = 'number_format_i18n';
		$levels  = array( 'high' => __( 'To solve', 'eclair-export' ), 'medium' => __( 'To check', 'eclair-export' ), 'info' => __( 'Good to know', 'eclair-export' ) );
		$verdict = $score >= 80 ? __( 'Straightforward migration: almost everything moves as is.', 'eclair-export' )
			: ( $score >= 55 ? __( 'Feasible migration, with a few points to handle by hand.', 'eclair-export' ) : __( 'Migration that needs preparation before it starts.', 'eclair-export' ) );
		$lang    = substr( get_locale(), 0, 2 );
		ob_start();
		?>
<!doctype html>
<html lang="<?php echo esc_attr( $lang ); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?php echo esc_html( sprintf( /* translators: %s: site name */ __( 'Migration diagnostic – %s', 'eclair-export' ), $d['site']['name'] ) ); ?></title>
<style>
:root{--brand:#6443cc;--brand-strong:#4f2fb8;--brand-soft:#efeafd;--ink:#1d1b2a;--muted:#5d5a70;--line:#e5e3ee;--bg:#f7f6fb;--panel:#fff;--ok:#1d7f55;--ok-soft:#e5f6ee;--warn:#a85208;--warn-soft:#fdf1e2;--bad:#be3427;--bad-soft:#fce9e7}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.55 ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.page{max-width:920px;margin:0 auto;padding:32px 20px 64px}
header.top{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:28px}
.brand{display:flex;align-items:center;gap:10px;font-weight:700;letter-spacing:-.01em}
.brand svg{width:34px;height:34px}
.tag{font-size:12px;color:var(--muted);text-align:right}
.hero{background:var(--panel);border:1px solid var(--line);border-radius:18px;padding:28px;display:grid;grid-template-columns:1fr auto;gap:24px;align-items:center}
.hero h1{margin:0 0 6px;font-size:26px;line-height:1.2;letter-spacing:-.02em}
.hero .url{color:var(--brand-strong);font-weight:600;word-break:break-all}
.hero p{margin:10px 0 0;color:var(--muted)}
.score{width:132px;height:132px;border-radius:50%;display:grid;place-items:center;text-align:center;background:conic-gradient(var(--c) calc(var(--s)*1%),var(--line) 0)}
.score div{width:104px;height:104px;border-radius:50%;background:var(--panel);display:grid;place-items:center;align-content:center}
.score b{font-size:34px;line-height:1}
.score span{font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.06em}
.ok{--c:var(--ok)}.warn{--c:var(--warn)}.bad{--c:var(--bad)}
.cards{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:16px 0 8px}
.card{background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:14px 16px}
.card b{display:block;font-size:22px;letter-spacing:-.01em}
.card span{color:var(--muted);font-size:13px}
h2{font-size:18px;margin:36px 0 12px;letter-spacing:-.01em}
h2 small{font-weight:400;color:var(--muted);font-size:13px;margin-left:6px}
.panel{background:var(--panel);border:1px solid var(--line);border-radius:14px;overflow:hidden}
table{width:100%;border-collapse:collapse;font-size:14px}
th,td{text-align:left;padding:10px 14px;border-bottom:1px solid var(--line);vertical-align:top}
th{font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);background:#fbfaff;font-weight:600}
tr:last-child td{border-bottom:0}
td.n{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
.risk{display:grid;grid-template-columns:110px 1fr;gap:14px;padding:14px 16px;border-bottom:1px solid var(--line)}
.risk:last-child{border-bottom:0}
.risk strong{display:block;margin-bottom:2px}
.risk p{margin:0;color:var(--muted);font-size:14px}
.pill{display:inline-block;font-size:12px;font-weight:600;padding:3px 10px;border-radius:999px;height:fit-content;text-align:center}
.pill.high{background:var(--bad-soft);color:var(--bad)}.pill.medium{background:var(--warn-soft);color:var(--warn)}.pill.info{background:var(--brand-soft);color:var(--brand-strong)}
.pill.yes{background:var(--ok-soft);color:var(--ok)}.pill.no{background:var(--warn-soft);color:var(--warn)}
.cols{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
.col{background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:16px}
.col h3{margin:0 0 8px;font-size:14px}
.col ul{margin:0;padding-left:18px;color:var(--muted);font-size:14px}
.col li{margin:3px 0}
.col.a h3{color:var(--ok)}.col.b h3{color:var(--warn)}.col.c h3{color:var(--bad)}
.cta{margin-top:36px;background:var(--brand);color:#fff;border-radius:18px;padding:24px 28px;display:grid;grid-template-columns:1fr auto;gap:16px;align-items:center}
.cta h2{margin:0 0 6px;color:#fff}
.cta p{margin:0;opacity:.92}
.cta .who{text-align:right;font-size:14px;line-height:1.6}
.cta a{color:#fff}
footer{margin-top:28px;color:var(--muted);font-size:12px;text-align:center}
.print{position:fixed;right:20px;bottom:20px;background:var(--brand);color:#fff;border:0;border-radius:999px;padding:12px 20px;font:600 14px/1 inherit;cursor:pointer;box-shadow:0 6px 20px rgba(60,30,140,.3)}
@media (max-width:720px){.hero{grid-template-columns:1fr}.cards{grid-template-columns:repeat(2,1fr)}.cols{grid-template-columns:1fr}.risk{grid-template-columns:1fr}.cta{grid-template-columns:1fr}.cta .who{text-align:left}}
@media print{body{background:#fff}.page{padding:0}.print{display:none}h2{break-after:avoid}.panel,.risk,.col,.card{break-inside:avoid}@page{margin:14mm}}
</style>
</head>
<body>
<div class="page">
<header class="top">
	<div class="brand"><svg viewBox="0 0 32 32" aria-hidden="true"><rect width="32" height="32" rx="9" fill="#6443cc"/><path d="M18 4 6 18h8.3L13 28l12-15.4h-8.4z" fill="#fff"/></svg><?php esc_html_e( 'Migration to Éclair', 'eclair-export' ); ?></div>
	<div class="tag"><?php echo esc_html( sprintf( /* translators: %s: date */ __( 'Diagnostic of %s', 'eclair-export' ), date_i18n( get_option( 'date_format' ), $d['generated'] ) ) ); ?><br>Eclair Export <?php echo esc_html( $d['version'] ); ?></div>
</header>

<section class="hero">
	<div>
		<h1><?php echo $e( $d['site']['name'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></h1>
		<div class="url"><?php echo $e( $d['site']['url'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
		<p><strong><?php echo $e( $verdict ); // phpcs:ignore WordPress.Security.EscapeOutput ?></strong></p>
		<p><?php echo esc_html( sprintf( /* translators: 1: WordPress version, 2: PHP version, 3: theme */ __( 'WordPress %1$s · PHP %2$s · theme %3$s', 'eclair-export' ), $d['site']['wordpress'], $d['site']['php'], $d['site']['theme']['name'] ) ); ?></p>
	</div>
	<div class="score <?php echo esc_attr( $tone ); ?>" style="--s:<?php echo (int) $score; ?>"><div><b><?php echo (int) $score; ?></b><span><?php esc_html_e( 'compatibility', 'eclair-export' ); ?></span></div></div>
</section>

<div class="cards">
	<div class="card"><b><?php echo $n( $d['total_content'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></b><span><?php esc_html_e( 'contents', 'eclair-export' ); ?></span></div>
	<div class="card"><b><?php echo $n( $d['media']['count'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></b><span><?php echo esc_html( sprintf( /* translators: %s: size */ __( 'media files · %s', 'eclair-export' ), Eclair_Export_Util::size( $d['media']['bytes'] ) . ( $d['media']['complete'] ? '' : '+' ) ) ); ?></span></div>
	<div class="card"><b><?php echo $n( $d['redirects']['existing'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></b><span><?php esc_html_e( 'existing redirections', 'eclair-export' ); ?></span></div>
	<div class="card"><b><?php echo esc_html( Eclair_Export_Util::duration( $d['estimate']['export'] + $d['estimate']['import'] ) ); ?></b><span><?php esc_html_e( 'estimated transfer time', 'eclair-export' ); ?></span></div>
</div>

<h2><?php esc_html_e( 'Points of attention', 'eclair-export' ); ?> <small><?php echo esc_html( sprintf( /* translators: %d: number of points */ _n( '%d point', '%d points', count( $d['risks'] ), 'eclair-export' ), count( $d['risks'] ) ) ); ?></small></h2>
<div class="panel">
<?php if ( ! $d['risks'] ) : ?>
	<div class="risk"><span class="pill yes"><?php esc_html_e( 'None', 'eclair-export' ); ?></span><div><strong><?php esc_html_e( 'Nothing blocks the migration.', 'eclair-export' ); ?></strong></div></div>
<?php endif; ?>
<?php foreach ( $d['risks'] as $risk ) : ?>
	<div class="risk"><span class="pill <?php echo esc_attr( $risk['level'] ); ?>"><?php echo esc_html( $levels[ $risk['level'] ] ); ?></span><div><strong><?php echo esc_html( $risk['title'] ); ?></strong><p><?php echo esc_html( $risk['detail'] ); ?></p></div></div>
<?php endforeach; ?>
</div>

<h2><?php esc_html_e( 'What moves, what is adapted, what stays behind', 'eclair-export' ); ?></h2>
<div class="cols">
	<div class="col a"><h3><?php esc_html_e( 'Moves as is', 'eclair-export' ); ?></h3><ul>
		<li><?php esc_html_e( 'Pages and articles, drafts and scheduled ones included', 'eclair-export' ); ?></li>
		<li><?php esc_html_e( 'Exact addresses (slugs, dates, .html, accents)', 'eclair-export' ); ?></li>
		<li><?php esc_html_e( 'Images, alternative texts and captions', 'eclair-export' ); ?></li>
		<li><?php esc_html_e( 'SEO titles, descriptions, canonical, noindex', 'eclair-export' ); ?></li>
		<li><?php esc_html_e( 'Existing redirections and old ?p= links', 'eclair-export' ); ?></li>
		<li><?php esc_html_e( 'Categories, languages and translations', 'eclair-export' ); ?></li>
	</ul></div>
	<div class="col b"><h3><?php esc_html_e( 'Adapted', 'eclair-export' ); ?></h3><ul>
		<li><?php esc_html_e( 'Elementor and Divi layouts, rebuilt as clean blocks', 'eclair-export' ); ?></li>
		<li><?php esc_html_e( 'Category, tag and author archives: redirected (301)', 'eclair-export' ); ?></li>
		<li><?php esc_html_e( 'Custom content types: as pages or articles', 'eclair-export' ); ?></li>
		<li><?php esc_html_e( 'Drop-down menus: one level', 'eclair-export' ); ?></li>
		<li><?php esc_html_e( 'WooCommerce products: catalogue with payment links', 'eclair-export' ); ?></li>
	</ul></div>
	<div class="col c"><h3><?php esc_html_e( 'Stays behind', 'eclair-export' ); ?></h3><ul>
		<li><?php esc_html_e( 'Comments', 'eclair-export' ); ?></li>
		<li><?php esc_html_e( 'Orders, customers and payments', 'eclair-export' ); ?></li>
		<li><?php esc_html_e( 'Forms, sliders and plugin widgets (rebuilt in Éclair)', 'eclair-export' ); ?></li>
		<li><?php esc_html_e( 'Passwords: never exported', 'eclair-export' ); ?></li>
	</ul></div>
</div>

<h2><?php esc_html_e( 'Contents', 'eclair-export' ); ?></h2>
<div class="panel"><table>
	<thead><tr><th><?php esc_html_e( 'Type', 'eclair-export' ); ?></th><th class="n"><?php esc_html_e( 'Published', 'eclair-export' ); ?></th><th class="n"><?php esc_html_e( 'Drafts', 'eclair-export' ); ?></th><th class="n"><?php esc_html_e( 'Other', 'eclair-export' ); ?></th><th class="n"><?php esc_html_e( 'Total', 'eclair-export' ); ?></th></tr></thead>
	<tbody>
	<?php foreach ( $d['content'] as $c ) : ?>
		<tr><td><?php echo esc_html( $c['label'] ); ?></td><td class="n"><?php echo esc_html( $n( $c['publish'] ) ); ?></td><td class="n"><?php echo esc_html( $n( $c['draft'] ) ); ?></td><td class="n"><?php echo esc_html( $n( $c['other'] ) ); ?></td><td class="n"><strong><?php echo esc_html( $n( $c['total'] ) ); ?></strong></td></tr>
	<?php endforeach; ?>
	<?php foreach ( $d['terms'] as $t ) : ?>
		<tr><td><?php echo esc_html( $t['label'] ); ?></td><td class="n" colspan="4"><?php echo esc_html( $n( $t['count'] ) ); ?></td></tr>
	<?php endforeach; ?>
		<tr><td><?php esc_html_e( 'Authors', 'eclair-export' ); ?></td><td class="n" colspan="4"><?php echo esc_html( $n( $d['authors'] ) ); ?></td></tr>
		<tr><td><?php esc_html_e( 'Menus', 'eclair-export' ); ?></td><td class="n" colspan="4"><?php echo esc_html( $n( $d['menus']['count'] ) ); ?></td></tr>
		<tr><td><?php esc_html_e( 'Approved comments', 'eclair-export' ); ?></td><td class="n" colspan="4"><?php echo esc_html( $n( $d['comments'] ) ); ?></td></tr>
	</tbody>
</table></div>

<h2><?php esc_html_e( 'Media', 'eclair-export' ); ?></h2>
<div class="panel"><table>
	<tbody>
		<tr><td><?php esc_html_e( 'Photos and images', 'eclair-export' ); ?></td><td class="n"><?php echo esc_html( $n( $d['media']['images'] ) ); ?></td></tr>
		<tr><td><?php esc_html_e( 'Documents (PDF, office files)', 'eclair-export' ); ?></td><td class="n"><?php echo esc_html( $n( $d['media']['documents'] ) ); ?></td></tr>
		<tr><td><?php esc_html_e( 'Videos', 'eclair-export' ); ?></td><td class="n"><?php echo esc_html( $n( $d['media']['video'] ) ); ?></td></tr>
		<tr><td><?php esc_html_e( 'Audio', 'eclair-export' ); ?></td><td class="n"><?php echo esc_html( $n( $d['media']['audio'] ) ); ?></td></tr>
		<tr><td><?php esc_html_e( 'SVG and other files', 'eclair-export' ); ?></td><td class="n"><?php echo esc_html( $n( $d['media']['svg'] + $d['media']['other'] ) ); ?></td></tr>
		<tr><td><strong><?php esc_html_e( 'Weight of the original files', 'eclair-export' ); ?></strong></td><td class="n"><strong><?php echo esc_html( Eclair_Export_Util::size( $d['media']['bytes'] ) . ( $d['media']['complete'] ? '' : ' +' ) ); ?></strong></td></tr>
	</tbody>
</table></div>

<h2><?php esc_html_e( 'Plugins and editors detected', 'eclair-export' ); ?> <small><?php echo esc_html( sprintf( /* translators: %d: number of plugins */ __( '%d active plugins in all', 'eclair-export' ), $d['plugins_active'] ) ); ?></small></h2>
<div class="panel"><table>
	<thead><tr><th><?php esc_html_e( 'Plugin', 'eclair-export' ); ?></th><th><?php esc_html_e( 'Version', 'eclair-export' ); ?></th><th><?php esc_html_e( 'What is taken over', 'eclair-export' ); ?></th></tr></thead>
	<tbody>
	<?php
	$what = array(
		'yoast'       => __( 'SEO titles and descriptions, canonical, noindex, Open Graph, redirections (Premium)', 'eclair-export' ),
		'rankmath'    => __( 'SEO titles and descriptions, canonical, robots, Open Graph, redirections', 'eclair-export' ),
		'aioseo'      => __( 'SEO titles and descriptions, canonical, robots, Open Graph, redirections', 'eclair-export' ),
		'elementor'   => __( 'Texts, headings, images, buttons, videos, lists, accordions (layout simplified)', 'eclair-export' ),
		'divi'        => __( 'Texts, images, buttons, blurbs, toggles, testimonials (layout simplified)', 'eclair-export' ),
		'woocommerce' => __( 'Products, prices, SKU, stock, gallery, variations (catalogue only)', 'eclair-export' ),
		'wpml'        => __( 'Language of each content and links between translations', 'eclair-export' ),
		'polylang'    => __( 'Language of each content and links between translations', 'eclair-export' ),
		'redirection' => __( 'All exact redirections (patterns listed apart)', 'eclair-export' ),
		'acf'         => __( 'Values of the custom fields', 'eclair-export' ),
	);
	if ( ! $d['plugins'] ) :
		?>
		<tr><td colspan="3"><?php esc_html_e( 'None of the plugins that matter for a migration.', 'eclair-export' ); ?></td></tr>
	<?php endif; ?>
	<?php foreach ( $d['plugins'] as $key => $p ) : ?>
		<tr><td><strong><?php echo esc_html( $p['name'] ); ?></strong></td><td><?php echo esc_html( $p['version'] ); ?></td><td><?php echo esc_html( isset( $what[ $key ] ) ? $what[ $key ] : '' ); ?></td></tr>
	<?php endforeach; ?>
	</tbody>
</table></div>

<h2><?php esc_html_e( 'How the contents were written', 'eclair-export' ); ?></h2>
<div class="panel"><table><tbody>
	<tr><td><?php esc_html_e( 'Block editor (Gutenberg)', 'eclair-export' ); ?></td><td class="n"><?php echo esc_html( $n( $d['builders']['gutenberg'] ) ); ?></td></tr>
	<tr><td><?php esc_html_e( 'Classic editor', 'eclair-export' ); ?></td><td class="n"><?php echo esc_html( $n( $d['builders']['classic'] ) ); ?></td></tr>
	<tr><td>Elementor</td><td class="n"><?php echo esc_html( $n( $d['builders']['elementor'] ) ); ?></td></tr>
	<tr><td>Divi</td><td class="n"><?php echo esc_html( $n( $d['builders']['divi'] ) ); ?></td></tr>
</tbody></table></div>

<?php if ( $d['shortcodes'] ) : ?>
<h2><?php esc_html_e( 'Shortcodes used', 'eclair-export' ); ?><?php if ( ! $d['shortcodes_complete'] ) : ?> <small><?php esc_html_e( '(partial count: very large site)', 'eclair-export' ); ?></small><?php endif; ?></h2>
<div class="panel"><table>
	<thead><tr><th><?php esc_html_e( 'Shortcode', 'eclair-export' ); ?></th><th class="n"><?php esc_html_e( 'Uses', 'eclair-export' ); ?></th><th class="n"><?php esc_html_e( 'Contents', 'eclair-export' ); ?></th><th><?php esc_html_e( 'Conversion', 'eclair-export' ); ?></th></tr></thead>
	<tbody>
	<?php foreach ( array_slice( $d['shortcodes'], 0, 60, true ) as $tag => $s ) : ?>
		<tr><td><code>[<?php echo esc_html( $tag ); ?>]</code><?php echo $s['registered'] ? '' : ' <small>(' . esc_html__( 'plugin no longer active', 'eclair-export' ) . ')</small>'; ?></td><td class="n"><?php echo esc_html( $n( $s['count'] ) ); ?></td><td class="n"><?php echo esc_html( $n( $s['contents'] ) ); ?></td><td><span class="pill <?php echo $s['handled'] ? 'yes' : 'no'; ?>"><?php echo $s['handled'] ? esc_html__( 'Converted', 'eclair-export' ) : esc_html__( 'Kept as text, to replace', 'eclair-export' ); ?></span></td></tr>
	<?php endforeach; ?>
	</tbody>
</table></div>
<?php endif; ?>

<h2><?php esc_html_e( 'Estimate', 'eclair-export' ); ?></h2>
<div class="panel"><table><tbody>
	<tr><td><?php esc_html_e( 'Export from WordPress', 'eclair-export' ); ?></td><td class="n"><?php echo esc_html( Eclair_Export_Util::duration( $d['estimate']['export'] ) ); ?></td></tr>
	<tr><td><?php esc_html_e( 'Import into Éclair (images optimised in WebP)', 'eclair-export' ); ?></td><td class="n"><?php echo esc_html( Eclair_Export_Util::duration( $d['estimate']['import'] ) ); ?></td></tr>
	<tr><td><?php esc_html_e( 'Review by hand after the migration', 'eclair-export' ); ?></td><td class="n"><?php echo esc_html( Eclair_Export_Util::duration( $d['estimate']['review'] ) ); ?></td></tr>
	<tr><td><?php esc_html_e( 'Size of the export archive', 'eclair-export' ); ?></td><td class="n"><?php echo esc_html( Eclair_Export_Util::size( $d['estimate']['archive'] ) ); ?></td></tr>
</tbody></table></div>

<section class="cta">
	<div>
		<h2><?php esc_html_e( 'Next step', 'eclair-export' ); ?></h2>
		<p><?php echo esc_html( '' !== $b['note'] ? $b['note'] : __( 'The migration keeps every address, redirects what changes and checks that no old link ends on an error page.', 'eclair-export' ) ); ?></p>
	</div>
	<?php if ( '' !== $b['name'] . $b['company'] . $b['email'] . $b['phone'] . $b['website'] ) : ?>
	<div class="who">
		<?php esc_html_e( 'Prepared by', 'eclair-export' ); ?><br>
		<strong><?php echo esc_html( trim( $b['name'] . ( '' !== $b['company'] ? ' · ' . $b['company'] : '' ), ' ·' ) ); ?></strong><br>
		<?php if ( '' !== $b['email'] ) : ?><a href="mailto:<?php echo esc_attr( $b['email'] ); ?>"><?php echo esc_html( $b['email'] ); ?></a><br><?php endif; ?>
		<?php if ( '' !== $b['phone'] ) : ?><?php echo esc_html( $b['phone'] ); ?><br><?php endif; ?>
		<?php if ( '' !== $b['website'] ) : ?><a href="<?php echo esc_url( $b['website'] ); ?>"><?php echo esc_html( preg_replace( '~^https?://~', '', untrailingslashit( $b['website'] ) ) ); ?></a><?php endif; ?>
	</div>
	<?php endif; ?>
</section>

<footer><?php esc_html_e( 'Read-only diagnostic: nothing was exported or changed on the site.', 'eclair-export' ); ?></footer>
</div>
<?php if ( $print ) : ?>
<button class="print" type="button" onclick="window.print()"><?php esc_html_e( 'Print / Save as PDF', 'eclair-export' ); ?></button>
<?php endif; ?>
</body>
</html>
		<?php
		return (string) ob_get_clean();
	}
}
