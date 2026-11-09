#!/usr/bin/env bash
# Banc d'essai de migration de bout en bout :
#   WordPress réaliste (Gutenberg, Elementor, Divi, Yoast, WooCommerce, Polylang, Redirection, 500 articles)
#   → export (Eclair Export) → import dans Éclair (ligne de commande) → chaque ancienne adresse vérifiée.
#
#   DB_HOST=127.0.0.1 DB_USER=root DB_PASS=root ECLAIR_BRANCH=main bash tests/e2e/run.sh
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
WORK="${WORK:-/tmp/eclair-e2e}"
OUT="${OUT:-$WORK/out}"
WP_DIR="$WORK/wp"
SITE="$WORK/eclair-site"
PORT="${PORT:-8090}"
ECLAIR_REPO="${ECLAIR_REPO:-https://github.com/Jacckisback/eclair-cms}"
ECLAIR_BRANCH="${ECLAIR_BRANCH:-main}"
WPC="wp --path=$WP_DIR --allow-root"
WP="$WPC --user=admin"
SECONDS=0
step() { echo; echo "▶ $1 (${SECONDS}s)"; }

rm -rf "$WORK" && mkdir -p "$WP_DIR" "$OUT/package"

step "WordPress"
$WPC core download --quiet
$WPC config create --dbname="${DB_NAME:-eclair_e2e}" --dbuser="${DB_USER:-root}" --dbpass="${DB_PASS:-root}" --dbhost="${DB_HOST:-127.0.0.1}" --skip-check --quiet
$WPC db create --quiet || true
$WPC core install --url=https://www.boulangerie-test.example --title="Boulangerie Test" --admin_user=admin --admin_password='Admin-Pass-2026!' --admin_email=admin@example.test --skip-email --quiet
$WP language core install fr_FR --activate --quiet || true
$WP rewrite structure '/%year%/%monthnum%/%postname%/' --quiet
ln -s "$PLUGIN_DIR" "$WP_DIR/wp-content/plugins/eclair-export"
for plugin in wordpress-seo elementor woocommerce polylang redirection; do
	$WP plugin install "$plugin" --activate --quiet || echo "  (extension $plugin non installée)"
done
$WP redirection database install 2>/dev/null || true
$WP plugin activate eclair-export --quiet
$WP plugin list --status=active --fields=name,version --format=csv | tee "$OUT/plugins.csv"

step "Contenus de test"
$WP eval-file "$PLUGIN_DIR/tests/e2e/seed.php"
$WP rewrite flush --quiet

step "Éclair ($ECLAIR_BRANCH)"
git clone --quiet --depth 1 --branch "$ECLAIR_BRANCH" "$ECLAIR_REPO" "$WORK/eclair-cms"
cp -r "$WORK/eclair-cms/eclair" "$SITE"
PHP_CLI_SERVER_WORKERS=4 php -S "127.0.0.1:$PORT" -t "$SITE" "$WORK/eclair-cms/tools/router.php" > "$OUT/eclair-server.log" 2>&1 &
SERVER=$!
trap 'kill $SERVER 2>/dev/null || true' EXIT
sleep 1
php "$PLUGIN_DIR/tests/e2e/eclair.php" install "http://127.0.0.1:$PORT" "$OUT"

step "Diagnostic (Eclair Export)"
$WP eclair diagnose
$WP eclair diagnose --format=json --output="$OUT/diagnostic.json"
$WP eclair diagnose --format=html --locale=fr_FR --output="$OUT/diagnostic-fr.html"

PARTS="$SITE/data/ext/wordpress/package/parts"
if [ -f "$SITE/app/bundled/wordpress/lib/Receiver.php" ]; then
	step "Export et envoi direct vers Éclair (clé de liaison)"
	# Site Éclair de test en http sur un port local : permis ici seulement (en production : https, hôte public).
	mkdir -p "$WP_DIR/wp-content/mu-plugins"
	cat > "$WP_DIR/wp-content/mu-plugins/e2e-local.php" <<PHP
<?php
add_filter( 'eclair_export_allow_http', '__return_true' );
add_filter( 'http_request_host_is_external', '__return_true' );
add_filter( 'http_allowed_safe_ports', static function ( \$ports ) { \$ports[] = $PORT; return \$ports; } );
PHP
	KEY="$(php "$PLUGIN_DIR/tests/e2e/eclair.php" link "http://127.0.0.1:$PORT" "$OUT")"
	$WP eclair export --send="$KEY" --part-size=50
	ls "$PARTS"/eclair-export-*.zip > /dev/null || { echo "✗ aucune archive arrivée dans Éclair"; exit 1; }
	if ls "$PARTS"/*.part > /dev/null 2>&1; then echo "✗ archive incomplète laissée dans Éclair"; exit 1; fi
	if [ -f "$SITE/data/ext/wordpress/link.php" ]; then echo "✗ la clé de liaison n'a pas été retirée après l'envoi"; exit 1; fi
	if $WP eval 'exit( Eclair_Export_Exporter::job() || get_option( "eclair_export_send" ) ? 0 : 1 );'; then echo "✗ paquet ou clé gardés sur WordPress après l'envoi"; exit 1; fi
	echo "  ✓ $(ls "$PARTS"/*.zip | wc -l) archive(s) arrivée(s) dans Éclair ($(du -ch "$PARTS"/*.zip | tail -1 | cut -f1)), clé retirée, rien gardé sur WordPress"
	cp "$PARTS"/*.zip "$OUT/package/"
	echo "direct" > "$OUT/transfer.txt"
	step "Import du paquet reçu (ligne de commande)"
	php "$SITE/app/bundled/wordpress/lib/cli.php" import "$OUT"/package/*.zip --no-remote --json > "$OUT/import.json"
else
	step "Export (Eclair Export) : cette version d'Éclair n'a pas l'envoi direct, paquet remis en fichiers"
	$WP eclair export --output="$OUT/package" --part-size=50
	echo "files" > "$OUT/transfer.txt"
	step "Import du paquet (ligne de commande)"
	php "$SITE/app/bundled/wordpress/lib/cli.php" import "$OUT"/package/*.zip --no-remote --json > "$OUT/import.json"
fi
php -r '$r = json_decode(file_get_contents($argv[1]), true)["report"]; printf("  %d articles, %d pages, %d produits, %d autres · %d adresses gardées, %d changées · %d images, %d documents · %d redirections · %d liens réécrits\n", $r["posts"], $r["pages"], $r["products"], $r["custom"], $r["routes"], $r["moved"], $r["images"], $r["documents"], $r["redirects"], $r["links"]);' "$OUT/import.json"

step "Vérification de chaque ancienne adresse"
set +e
php "$PLUGIN_DIR/tests/e2e/crawl.php" "$OUT/package" "http://127.0.0.1:$PORT" "$OUT"
CRAWL=$?
set -e
php "$PLUGIN_DIR/tests/e2e/eclair.php" report "http://127.0.0.1:$PORT" "$OUT"

if [ -n "${GITHUB_STEP_SUMMARY:-}" ]; then
	{ echo "# Banc d'essai de migration WordPress → Éclair"; echo; cat "$OUT/crawl.md"; } >> "$GITHUB_STEP_SUMMARY"
fi
echo "Durée totale : ${SECONDS}s"
exit $CRAWL
