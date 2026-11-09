#!/usr/bin/env bash
# Full test on a real WordPress (run by the CI, or by hand with MySQL and WP-CLI available):
#   DB_HOST=127.0.0.1 DB_USER=root DB_PASS=root bash tests/integration.sh
# Installs WordPress, Yoast SEO and Redirection, creates varied contents, runs the diagnostic and the export, then
# checks the package with tests/check-package.php.
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
WORK="${WORK:-/tmp/eclair-export-it}"
WP_DIR="$WORK/wp"
OUT="$WORK/out"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-root}"
DB_NAME="${DB_NAME:-eclair_export_it}"
WP="wp --path=$WP_DIR --allow-root --user=admin"

rm -rf "$WORK" && mkdir -p "$WP_DIR" "$OUT"

wp --path=$WP_DIR --allow-root core download --version="${WP_VERSION:-latest}" --quiet
wp --path=$WP_DIR --allow-root config create --dbname="$DB_NAME" --dbuser="$DB_USER" --dbpass="$DB_PASS" --dbhost="$DB_HOST" --skip-check --quiet
wp --path=$WP_DIR --allow-root db create --quiet || true
wp --path=$WP_DIR --allow-root core install --url=https://www.example.test --title="Site Test" --admin_user=admin --admin_password='S3cret-Pass!' --admin_email=admin@example.test --skip-email --quiet
$WP rewrite structure '/%year%/%monthnum%/%postname%/' --quiet
ln -s "$PLUGIN_DIR" "$WP_DIR/wp-content/plugins/eclair-export"
$WP plugin activate eclair-export --quiet
# The latest Yoast or Redirection may refuse an old WordPress: the test then goes on without them.
$WP plugin install wordpress-seo redirection --activate --quiet || true
YOAST=$($WP plugin is-active wordpress-seo && echo 1 || echo 0)
REDIR_ACTIVE=$($WP plugin is-active redirection && echo 1 || echo 0)
$WP redirection database install 2>/dev/null || true

# Images: 4 JPEG of about 400 KB (noise does not compress), enough to force several parts of 1 MB.
php -r 'for($n=1;$n<=4;$n++){$i=imagecreatetruecolor(1200,900);for($k=0;$k<60000;$k++){imagesetpixel($i,mt_rand(0,1199),mt_rand(0,899),mt_rand(0,0xffffff));}imagejpeg($i,"'"$WORK"'/photo-$n.jpg",95);}'
IMG1=$($WP media import "$WORK/photo-1.jpg" --title="Photo un" --alt="Alt photo un" --porcelain)
IMG2=$($WP media import "$WORK/photo-2.jpg" --porcelain)
IMG3=$($WP media import "$WORK/photo-3.jpg" --porcelain)
IMG4=$($WP media import "$WORK/photo-4.jpg" --porcelain)
IMG1_URL=$($WP post get "$IMG1" --field=guid)

CAT=$($WP term create category "Actualités" --slug=actualites --porcelain)
$WP term create post_tag "Promo" --slug=promo --quiet

GUTENBERG="<!-- wp:paragraph --><p>Intro avec un <a href=\"https://www.example.test/services/\">lien interne</a>.</p><!-- /wp:paragraph -->
<!-- wp:image {\"id\":$IMG1} --><figure class=\"wp-block-image\"><img src=\"$IMG1_URL\" alt=\"Alt photo un\" class=\"wp-image-$IMG1\"/><figcaption>Légende</figcaption></figure><!-- /wp:image -->
<!-- wp:embed {\"url\":\"https://www.youtube.com/watch?v=dQw4w9WgXcQ\"} --><figure class=\"wp-block-embed is-type-video\"><div class=\"wp-block-embed__wrapper\">
https://www.youtube.com/watch?v=dQw4w9WgXcQ
</div></figure><!-- /wp:embed -->
<!-- wp:shortcode -->[contact-form-7 id=\"1\" title=\"Contact\"]<!-- /wp:shortcode -->"
A=$($WP post create --post_title="Article Gutenberg" --post_name=article-gutenberg --post_status=publish --post_date="2024-03-15 10:00:00" --post_category="$CAT" --tags_input=promo --post_content="$GUTENBERG" --porcelain)
$WP post meta update "$A" _thumbnail_id "$IMG2" --quiet
$WP post meta update "$A" _yoast_wpseo_title '%%title%% %%sep%% %%sitename%%' --quiet
$WP post meta update "$A" _yoast_wpseo_metadesc 'Description SEO écrite à la main.' --quiet
$WP post meta update "$A" _yoast_wpseo_focuskw 'gutenberg' --quiet
$WP post meta update "$A" stripe_secret_key 'sk_live_should_never_leave' --quiet

$WP post create --post_title="Article classique" --post_name=article-classique --post_status=publish --post_date="2024-04-02 09:00:00" --post_content="Premier paragraphe.

[gallery ids=\"$IMG3,$IMG4\"]

Second paragraphe avec [orphan_shortcode foo=\"bar\"]." --quiet

$WP post create --post_title="Page Divi" --post_type=page --post_name=page-divi --post_status=publish --post_content='[et_pb_section][et_pb_row][et_pb_column type="4_4"][et_pb_text]<h2>Bienvenue</h2><p>Texte Divi.</p>[/et_pb_text][et_pb_button button_text="Réserver" button_url="https://www.example.test/contact/"][/et_pb_button][/et_pb_column][/et_pb_row][/et_pb_section]' --quiet

SERVICES=$($WP post create --post_title="Services" --post_type=page --post_name=services --post_status=publish --porcelain)
AUDIT=$($WP post create --post_title="Audit" --post_type=page --post_name=audit --post_parent="$SERVICES" --post_status=publish --post_content="<p>Page enfant.</p>" --porcelain)
$WP post meta update "$AUDIT" _yoast_wpseo_meta-robots-noindex 1 --quiet
$WP post create --post_title="Brouillon" --post_status=draft --post_content="<p>Pas encore publié.</p>" --quiet
$WP post create --post_title="Protégé" --post_status=publish --post_password=motdepasse --post_content="<p>Secret.</p>" --quiet
$WP post generate --count=30 --post_date="2023-06-01 08:00:00" --quiet

$WP option update wpseo-premium-redirects-base '[{"origin":"ancienne-page","url":"/services/","type":301,"format":"plain"}]' --format=json --quiet
if $WP db query "SHOW TABLES LIKE 'wp_redirection_items'" --skip-column-names | grep -q redirection_items; then
	$WP db query "INSERT INTO wp_redirection_items (url, match_url, match_data, regex, position, last_count, last_access, group_id, status, action_type, action_code, action_data, match_type, title) VALUES ('/vieux-lien/', '/vieux-lien', '', 0, 0, 0, NOW(), 1, 'enabled', 'url', 301, '/2024/03/article-gutenberg/', 'url', '')"
	REDIRECTION=1
else
	REDIRECTION=0
fi

# Expectations for the checker.
count() { $WP post list --post_type="$1" --post_status=publish,future,draft,pending,private --format=count; }
HASH=$($WP db query "SELECT user_pass FROM wp_users WHERE user_login='admin'" --skip-column-names)
cat > "$WORK/expect.json" <<JSON
{"post": $(count post), "page": $(count page), "media": 4, "article": $A, "audit": $AUDIT, "redirection": $REDIRECTION, "yoast": $YOAST, "redirection_active": $REDIR_ACTIVE,
 "secrets": ["$HASH", "S3cret-Pass!", "sk_live_should_never_leave", "motdepasse"]}
JSON

$WP eclair diagnose
$WP eclair diagnose --format=json --output="$OUT/diagnostic.json"
$WP eclair diagnose --format=html --locale=fr_FR --output="$OUT/diagnostic-fr.html"
$WP eclair diagnose --format=html --locale=es_ES --output="$OUT/diagnostic-es.html"
$WP eclair export --output="$OUT" --part-size=1

php "$PLUGIN_DIR/tests/check-package.php" "$OUT" "$WORK/expect.json"

# The private folder is gone once the export has been handed over.
UPLOADS="$WP_DIR/wp-content/uploads"
if find "$UPLOADS" -maxdepth 1 -name 'eclair-export-*' -type d -exec sh -c 'ls -A "$1" | grep -E "^[a-f0-9]{32}$"' _ {} \; | grep -q .; then
	echo "FAIL: export files left on the server"; exit 1
fi

# Second export with media left on the old site: one single small archive.
$WP eclair export --output="$OUT/remote" --media=remote
test "$(ls "$OUT/remote"/*.zip | wc -l)" -eq 1 || { echo "FAIL: remote export should be one archive"; exit 1; }
# Download links travel as JSON into a.href: they must be raw URLs whose nonce WordPress accepts (regression 1.0.1).
$WP eval --user=admin '
$url = Eclair_Export_Plugin::download_url( "eclair-export-site-20260101-000000-abcdef.zip" );
if ( false !== strpos( $url, "&amp;" ) ) { echo "FAIL: escaped download URL: $url\n"; exit( 1 ); }
parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
if ( empty( $q["_wpnonce"] ) || ! wp_verify_nonce( $q["_wpnonce"], Eclair_Export_Plugin::NONCE ) ) { echo "FAIL: download nonce rejected: $url\n"; exit( 1 ); }
if ( "eclair_export_download" !== $q["action"] || "eclair-export-site-20260101-000000-abcdef.zip" !== $q["file"] ) { echo "FAIL: download URL arguments: $url\n"; exit( 1 ); }
echo "Download link OK\n";
'
echo "Integration test passed."
