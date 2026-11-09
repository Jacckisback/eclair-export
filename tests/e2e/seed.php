<?php
/**
 * Site WordPress de test réaliste, pour le banc d'essai de migration (wp eval-file tests/e2e/seed.php) :
 * 500 articles Gutenberg sur 3 ans (catégories, étiquettes, images à la une, liens internes, Yoast), pages et sous-pages,
 * page Elementor, page Divi, produits WooCommerce (simples, en promotion, variable), deux langues (Polylang),
 * redirections (plugin Redirection), menu à sous-menu, une page en noindex.
 *
 * Écrit dans STDOUT ce qui a été créé ou sauté (une extension absente ne bloque pas le reste).
 */

if ( ! defined( 'WP_CLI' ) ) {
	exit( 1 );
}
mt_srand( 42 );
$log = static function ( $msg ) {
	WP_CLI::log( '  · ' . $msg );
};

// Images : 12 photos générées (bruit + dégradé), importées dans la médiathèque.
require_once ABSPATH . 'wp-admin/includes/image.php';
$images = array();
for ( $n = 1; $n <= 12; $n++ ) {
	$img = imagecreatetruecolor( 1600, 1000 );
	for ( $y = 0; $y < 1000; $y += 4 ) {
		imagefilledrectangle( $img, 0, $y, 1599, $y + 3, imagecolorallocate( $img, ( $n * 20 + $y / 8 ) % 255, ( $y / 5 ) % 255, ( 255 - $n * 15 ) % 255 ) );
	}
	for ( $k = 0; $k < 20000; $k++ ) {
		imagesetpixel( $img, mt_rand( 0, 1599 ), mt_rand( 0, 999 ), mt_rand( 0, 0xffffff ) );
	}
	$upload = wp_upload_bits( "photo-$n.jpg", null, (string) ( static function () use ( $img ) {
		ob_start();
		imagejpeg( $img, null, 82 );
		return ob_get_clean();
	} )() );
	$id = wp_insert_attachment( array( 'post_title' => "Photo $n", 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit' ), $upload['file'] );
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) );
	update_post_meta( $id, '_wp_attachment_image_alt', "Photo numéro $n" );
	$images[] = $id;
}
// Un PDF.
$pdf    = wp_upload_bits( 'tarifs-2025.pdf', null, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n" );
$pdf_id = wp_insert_attachment( array( 'post_title' => 'Tarifs 2025', 'post_mime_type' => 'application/pdf', 'post_status' => 'inherit' ), $pdf['file'] );
$log( count( $images ) . ' images et 1 PDF' );

// Catégories et étiquettes.
$cats = array();
foreach ( array( 'Actualités', 'Recettes', 'Conseils', 'Événements', 'Portraits', 'Coulisses' ) as $name ) {
	$cats[] = (int) wp_insert_term( $name, 'category' )['term_id'];
}
$tags = array();
for ( $t = 1; $t <= 20; $t++ ) {
	$tags[] = 'etiquette-' . $t;
}

// 500 articles Gutenberg, de janvier 2023 à décembre 2025.
$posts = array();
for ( $i = 1; $i <= 500; $i++ ) {
	$date  = gmdate( 'Y-m-d H:i:s', strtotime( '2023-01-01' ) + (int) ( ( $i - 1 ) * ( 3 * 365 * 86400 / 500 ) ) );
	$link  = $posts ? get_permalink( $posts[ array_rand( $posts ) ] ) : home_url( '/' );
	$image = $images[ $i % count( $images ) ];
	$body  = "<!-- wp:paragraph --><p>Article $i : introduction avec un <a href=\"$link\">lien vers un autre article</a> et du <strong>texte en gras</strong>.</p><!-- /wp:paragraph -->\n"
		. "<!-- wp:heading --><h2 class=\"wp-block-heading\">Partie principale</h2><!-- /wp:heading -->\n"
		. "<!-- wp:list --><ul><li>Premier point</li><li>Deuxième point</li></ul><!-- /wp:list -->\n"
		. ( 0 === $i % 10 ? '<!-- wp:image {"id":' . $image . '} --><figure class="wp-block-image"><img src="' . wp_get_attachment_url( $image ) . '" alt="Photo" class="wp-image-' . $image . '"/><figcaption>Légende ' . $i . '</figcaption></figure><!-- /wp:image -->' . "\n" : '' )
		. ( 0 === $i % 25 ? '<!-- wp:paragraph --><p>Nos <a href="' . wp_get_attachment_url( $pdf_id ) . '">tarifs en PDF</a>.</p><!-- /wp:paragraph -->' . "\n" : '' )
		. ( 0 === $i % 50 ? "<!-- wp:shortcode -->[contact-form-7 id=\"9\" title=\"Contact\"]<!-- /wp:shortcode -->\n" : '' );
	$id = wp_insert_post( array(
		'post_title'    => "Article de test numéro $i",
		'post_name'     => "article-$i",
		'post_content'  => $body,
		'post_status'   => 'publish',
		'post_date'     => $date,
		'post_date_gmt' => $date,
		'post_category' => array( $cats[ $i % count( $cats ) ] ),
		'tags_input'    => array( $tags[ $i % 20 ], $tags[ ( $i * 7 ) % 20 ] ),
		'post_author'   => 1,
	) );
	set_post_thumbnail( $id, $image );
	if ( 0 === $i % 10 ) {
		update_post_meta( $id, '_yoast_wpseo_title', "%%title%% %%sep%% %%sitename%%" );
		update_post_meta( $id, '_yoast_wpseo_metadesc', "Description SEO de l'article $i." );
		update_post_meta( $id, '_yoast_wpseo_focuskw', 'test' );
	}
	$posts[] = $id;
}
$log( '500 articles' );

// Pages et sous-pages, une page en noindex.
$about    = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'À propos', 'post_name' => 'a-propos', 'post_content' => '<!-- wp:paragraph --><p>Qui sommes-nous.</p><!-- /wp:paragraph -->' ) );
$services = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Services', 'post_name' => 'services', 'post_content' => '<!-- wp:paragraph --><p>Nos services.</p><!-- /wp:paragraph -->' ) );
foreach ( array( 'Audit', 'Formation', 'Accompagnement' ) as $child ) {
	wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_parent' => $services, 'post_title' => $child, 'post_name' => sanitize_title( $child ),
		'post_content' => '<!-- wp:details --><details class="wp-block-details"><summary>Combien ça coûte ?</summary><!-- wp:paragraph --><p>Sur devis.</p><!-- /wp:paragraph --></details><!-- /wp:details -->'
			. '<!-- wp:table --><figure class="wp-block-table"><table><thead><tr><th>Offre</th><th>Prix</th></tr></thead><tbody><tr><td>Base</td><td>100 €</td></tr></tbody></table></figure><!-- /wp:table -->' ) );
}
$legal = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Mentions légales', 'post_name' => 'mentions-legales', 'post_content' => '<p>Éditeur du site.</p>' ) );
update_post_meta( $legal, '_yoast_wpseo_meta-robots-noindex', '1' );
$home = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Accueil', 'post_name' => 'accueil', 'post_content' => '<!-- wp:paragraph --><p>Bienvenue sur le site de test.</p><!-- /wp:paragraph -->' ) );
$blog = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Journal', 'post_name' => 'journal', 'post_content' => '' ) );
update_option( 'show_on_front', 'page' );
update_option( 'page_on_front', $home );
update_option( 'page_for_posts', $blog );
$log( 'pages, sous-pages, accueil statique, page des articles' );

// Page Elementor (données JSON comme Elementor les enregistre).
$elementor = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Page Elementor', 'post_name' => 'page-elementor', 'post_content' => '<p>Version texte enregistrée par Elementor.</p>' ) );
$data      = array( array( 'id' => 'a1', 'elType' => 'section', 'elements' => array( array( 'id' => 'b1', 'elType' => 'column', 'elements' => array(
	array( 'id' => 'c1', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => array( 'title' => 'Bienvenue chez nous', 'header_size' => 'h2' ) ),
	array( 'id' => 'c2', 'elType' => 'widget', 'widgetType' => 'text-editor', 'settings' => array( 'editor' => '<p>Un texte <strong>Elementor</strong>.</p>' ) ),
	array( 'id' => 'c3', 'elType' => 'widget', 'widgetType' => 'image', 'settings' => array( 'image' => array( 'url' => wp_get_attachment_url( $images[0] ), 'id' => $images[0] ) ) ),
	array( 'id' => 'c4', 'elType' => 'widget', 'widgetType' => 'button', 'settings' => array( 'text' => 'Nous contacter', 'link' => array( 'url' => get_permalink( $about ) ) ) ),
	array( 'id' => 'c5', 'elType' => 'widget', 'widgetType' => 'accordion', 'settings' => array( 'tabs' => array( array( 'tab_title' => 'Délais ?', 'tab_content' => 'Une semaine.' ) ) ) ),
	array( 'id' => 'c6', 'elType' => 'widget', 'widgetType' => 'video', 'settings' => array( 'youtube_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ' ) ),
	array( 'id' => 'c7', 'elType' => 'widget', 'widgetType' => 'form', 'settings' => array( 'form_name' => 'Contact', 'title' => 'Écrivez-nous' ) ),
) ) ) ) );
update_post_meta( $elementor, '_elementor_edit_mode', 'builder' );
update_post_meta( $elementor, '_elementor_template_type', 'wp-page' );
update_post_meta( $elementor, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
// Page Divi (shortcodes, sans le thème).
wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Page Divi', 'post_name' => 'page-divi',
	'post_content' => '[et_pb_section][et_pb_row][et_pb_column type="4_4"][et_pb_text]<h2>Divi</h2><p>Texte.</p>[/et_pb_text][et_pb_button button_text="Réserver" button_url="' . get_permalink( $about ) . '"][/et_pb_button][/et_pb_column][/et_pb_row][/et_pb_section]' ) );
$log( 'page Elementor et page Divi' );

// WooCommerce.
$products = array();
if ( class_exists( 'WC_Product_Simple' ) ) {
	$pcat = (int) wp_insert_term( 'Pains', 'product_cat' )['term_id'];
	for ( $p = 1; $p <= 8; $p++ ) {
		$prod = new WC_Product_Simple();
		$prod->set_name( "Produit $p" );
		$prod->set_slug( "produit-$p" );
		$prod->set_regular_price( (string) ( 5 + $p ) );
		if ( 0 === $p % 3 ) {
			$prod->set_sale_price( (string) ( 4 + $p ) );
		}
		$prod->set_sku( "SKU-$p" );
		$prod->set_image_id( $images[ $p % 12 ] );
		$prod->set_category_ids( array( $pcat ) );
		$prod->set_description( "Description du produit $p." );
		$prod->set_status( 'publish' );
		$products[] = $prod->save();
	}
	$attr = new WC_Product_Attribute();
	$attr->set_name( 'Taille' );
	$attr->set_options( array( 'Petit', 'Grand' ) );
	$attr->set_visible( true );
	$attr->set_variation( true );
	$var = new WC_Product_Variable();
	$var->set_name( 'Produit à variantes' );
	$var->set_slug( 'produit-variantes' );
	$var->set_attributes( array( $attr ) );
	$var->set_status( 'publish' );
	$var_id = $var->save();
	foreach ( array( 'Petit' => '6', 'Grand' => '9' ) as $size => $price ) {
		$v = new WC_Product_Variation();
		$v->set_parent_id( $var_id );
		$v->set_attributes( array( 'taille' => $size ) );
		$v->set_regular_price( $price );
		$v->save();
	}
	WC_Product_Variable::sync( $var_id );
	$products[] = $var_id;
	$log( count( $products ) . ' produits WooCommerce (dont 1 à variantes)' );
} else {
	$log( 'WooCommerce absent : produits sautés' );
}

// Deux langues (Polylang) : français par défaut, 20 articles et 2 pages traduits en anglais.
$translated = 0;
if ( function_exists( 'pll_set_post_language' ) && function_exists( 'PLL' ) ) {
	$model = PLL()->model;
	$add   = static function ( array $args ) use ( $model ) {
		if ( isset( $model->languages ) && is_object( $model->languages ) && method_exists( $model->languages, 'add' ) ) {
			return $model->languages->add( $args );
		}
		return method_exists( $model, 'add_language' ) ? $model->add_language( $args ) : null;
	};
	$add( array( 'name' => 'Français', 'slug' => 'fr', 'locale' => 'fr_FR', 'rtl' => 0, 'term_group' => 0 ) );
	$add( array( 'name' => 'English', 'slug' => 'en', 'locale' => 'en_US', 'rtl' => 0, 'term_group' => 1 ) );
	if ( method_exists( $model, 'clean_languages_cache' ) ) {
		$model->clean_languages_cache();
	}
	$options = get_option( 'polylang' );
	if ( is_array( $options ) ) {
		$options['default_lang'] = 'fr';
		update_option( 'polylang', $options );
	}
	foreach ( array_merge( $posts, array( $about, $services, $legal, $home, $blog, $elementor ), $products ) as $id ) {
		pll_set_post_language( $id, 'fr' );
	}
	foreach ( $cats as $cat ) {
		pll_set_term_language( $cat, 'fr' );
	}
	foreach ( array_merge( array_slice( $posts, 0, 20 ), array( $about, $services ) ) as $fr ) {
		$source = get_post( $fr );
		$en     = wp_insert_post( array( 'post_type' => $source->post_type, 'post_status' => 'publish', 'post_title' => 'EN ' . $source->post_title, 'post_name' => 'en-' . $source->post_name,
			'post_content' => '<!-- wp:paragraph --><p>English version.</p><!-- /wp:paragraph -->', 'post_date' => $source->post_date, 'post_date_gmt' => $source->post_date_gmt ) );
		pll_set_post_language( $en, 'en' );
		pll_save_post_translations( array( 'fr' => $fr, 'en' => $en ) );
		$translated++;
	}
	$log( "Polylang : 2 langues, $translated traductions anglaises" );
} else {
	$log( 'Polylang absent : langues sautées' );
}

// Redirections du plugin Redirection.
global $wpdb;
if ( $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}redirection_items'" ) ) {
	foreach ( array( array( '/ancien-blog/', get_permalink( $posts[0] ) ), array( '/promo-ete/', '/services/' ), array( '/contact-us/', get_permalink( $about ) ) ) as $r ) {
		$wpdb->insert( $wpdb->prefix . 'redirection_items', array( 'url' => $r[0], 'match_url' => untrailingslashit( $r[0] ), 'match_data' => '', 'regex' => 0, 'position' => 0, 'last_count' => 0,
			'last_access' => current_time( 'mysql' ), 'group_id' => 1, 'status' => 'enabled', 'action_type' => 'url', 'action_code' => 301, 'action_data' => $r[1], 'match_type' => 'url', 'title' => '' ) );
	}
	$log( '3 redirections (Redirection)' );
}

// Menu principal à sous-menu.
$menu = wp_create_nav_menu( 'Principal' );
$top  = wp_update_nav_menu_item( $menu, 0, array( 'menu-item-title' => 'Services', 'menu-item-object' => 'page', 'menu-item-object-id' => $services, 'menu-item-type' => 'post_type', 'menu-item-status' => 'publish' ) );
wp_update_nav_menu_item( $menu, 0, array( 'menu-item-title' => 'Audit', 'menu-item-object' => 'page', 'menu-item-object-id' => $services + 1, 'menu-item-type' => 'post_type', 'menu-item-parent-id' => $top, 'menu-item-status' => 'publish' ) );
wp_update_nav_menu_item( $menu, 0, array( 'menu-item-title' => 'À propos', 'menu-item-object' => 'page', 'menu-item-object-id' => $about, 'menu-item-type' => 'post_type', 'menu-item-status' => 'publish' ) );
wp_update_nav_menu_item( $menu, 0, array( 'menu-item-title' => 'Recettes', 'menu-item-object' => 'category', 'menu-item-object-id' => $cats[1], 'menu-item-type' => 'taxonomy', 'menu-item-status' => 'publish' ) );
$log( 'menu principal' );

flush_rewrite_rules();
WP_CLI::success( 'Site de test prêt.' );
