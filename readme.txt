=== Eclair Export ===
Contributors: thierrygustin
Tags: migration, export, seo, redirections, eclair
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Diagnose a WordPress site and export it as a complete package (contents, media, SEO, redirections) for Éclair CMS, without breaking URLs.

== Description ==

Eclair Export prepares the move of a WordPress site to Éclair CMS.

* Read-only diagnostic: contents, media, detected plugins (Yoast SEO, Rank Math, AIOSEO, Elementor, Divi, WooCommerce, WPML, Polylang, Redirection, ACF), shortcodes, risks, compatibility score and time estimate.
* Client report in French, Spanish or English, as an HTML page that can be saved as PDF.
* Export in short AJAX steps, resumable after a cut, with media inside the archive or downloaded at import time.
* Gutenberg, classic editor, Elementor and Divi contents converted to clean HTML; unconverted shortcodes kept as written and reported.
* SEO metadata and redirections taken over; redirections generated for WordPress archives.
* WP-CLI: `wp eclair diagnose`, `wp eclair export`, `wp eclair cleanup`.

Security: administrators only, nonces on every action, private randomly named folder, archives deleted after download or after 24 hours, no password ever exported.

== Installation ==

1. Upload the plugin ZIP in Plugins › Add New › Upload Plugin, then activate it.
2. Open Tools › Export to Éclair.

== Changelog ==

= 1.0.0 =
* First version.
