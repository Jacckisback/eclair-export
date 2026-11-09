=== Eclair Export ===
Contributors: thierrygustin
Tags: migration, export, seo, redirections, eclair
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.1.0
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
* Direct transfer to the Éclair site with a link key: no download, no upload limit, no FTP.
* WP-CLI: `wp eclair diagnose`, `wp eclair export [--send=<key>]`, `wp eclair send`, `wp eclair cleanup`.

Security: administrators only, nonces on every action, private randomly named folder, archives deleted after download or after 24 hours, no password ever exported.

== Installation ==

1. Upload the plugin ZIP in Plugins › Add New › Upload Plugin, then activate it.
2. Open Tools › Export to Éclair.
3. In Éclair, open Import WordPress, create a link key and paste it in the plugin: the site is sent there.

== Changelog ==

= 1.1.0 =
* Direct transfer: paste the link key of your Éclair site and the package is sent there by itself, in pieces, whatever its size. No download, no upload limit, no FTP. Resumes after a cut.
* New three-step screen: Diagnostic, Migration, Import in Éclair.
* SEO: social profiles and default sharing image exported; images without alternative text and contents without meta description reported.
* WordPress 6.9+: diagnostic and migration registered as abilities for AI assistants (Claude, ChatGPT) through the MCP adapter.
* WP-CLI: `wp eclair export --send=<key>`, `wp eclair send <key>`.

= 1.0.2 =
* Safe start: no more fatal error on activation (duplicate copy, old PHP/WordPress, missing file).
* Missing PHP "dom" extension reported instead of an error.

= 1.0.1 =
* Fix: downloading the export failed with "The link you followed has expired".

= 1.0.0 =
* First version.
