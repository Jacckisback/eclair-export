# Eclair Export

**Move a WordPress site to [Éclair CMS](https://github.com/Jacckisback/clair-cms) without losing a single address.**
A WordPress plugin that diagnoses the site, then exports it as a complete package: contents, media, SEO, menus and redirections. The Éclair importer rebuilds the site from that package.

[Français](README.md) · License GPL-3.0-or-later · WordPress 6.0+ · PHP 7.4+

## What it does

**1. Diagnostic** (read only, nothing is exported)
- Contents per type and status, media weight, authors, menus, comments.
- Detected plugins: Yoast SEO, Rank Math, All in One SEO, Elementor, Divi, WooCommerce, WPML, Polylang, Redirection, ACF.
- Shortcodes in use, converted or not, including those of deactivated plugins.
- Ranked points of attention, compatibility score, estimated time.
- **Client report** in French, Spanish or English, in the Éclair colours, with your "Prepared by" block. Download it as HTML or save it as PDF from the print dialog.

**2. Export**
- Short AJAX steps with a progress bar. Works on slow shared hosting and **resumes after a cut** without duplicates.
- Media inside the archive (split into parts of 50 MB to 1 GB) or downloaded from the old site at import time.
- Gutenberg blocks converted to clean HTML; Elementor and Divi converted as well as possible (texts, headings, images, buttons, videos, lists, accordions, testimonials). Every shortcode that cannot be converted **stays in the text as written** and is listed in the report.
- SEO from Yoast, Rank Math and AIOSEO (titles, descriptions, keyword, canonical, noindex/nofollow, Open Graph, Twitter, schema type), variables resolved.
- Redirections from Redirection, Rank Math, AIOSEO and Yoast Premium, plus those the migration needs (categories, tags, authors, date archives, feeds, attachment pages).
- List of every old public address, to check after the migration that none answers 404.

Full package format: [docs/FORMAT.md](docs/FORMAT.md) (in French).

## Security

- Screen and actions for administrators only (`manage_options`), nonce on every action.
- Files prepared in a randomly named folder of `wp-content/uploads`, closed to visitors (`.htaccess`, `web.config`, index files), randomly named archives.
- Each part is **deleted as soon as it is downloaded**; any export older than 24 h is deleted by an hourly task, and on deactivation and uninstall.
- **No password is exported**: neither account passwords nor those of protected contents. Fields whose name looks like a secret (API key, token…) are skipped. Author e-mails are included only if you tick the option.
- On Nginx, `.htaccess` files do not apply: the folder stays protected by its random name and the automatic deletion. For full protection add `location ~ /eclair-export- { deny all; }` to the server configuration.

## Install

1. Download `eclair-export-x.y.z.zip` (releases page of the repository).
2. WordPress › Plugins › Add New › Upload Plugin › choose the ZIP › Activate.
3. Open **Tools › Export to Éclair**.

## Command line (WP-CLI)

```bash
wp eclair diagnose
```

```bash
wp eclair diagnose --format=html --locale=en_US --output=diagnostic.html
```

```bash
wp eclair export --output=/path/to/exports/ --part-size=250
```

```bash
wp eclair export --media=remote --types=post,page
```

```bash
wp eclair cleanup
```

## Known limits (1.0)

- Elementor and Divi layouts are simplified (columns, animations and styles are not kept). Forms, sliders, pricing tables and plugin widgets are reported, to be rebuilt in Éclair.
- Comments, WooCommerce orders, customers and payments are not exported.
- Conditional Redirection rules (login, browser, cookie) are listed, not exported.
- Regular-expression redirections are exported as written; Éclair only redirects exact addresses.

## Tests

```bash
php tests/run.php
```

```bash
DB_HOST=127.0.0.1 DB_USER=root DB_PASS=root bash tests/integration.sh
```

The second one runs the diagnostic and the export on a real WordPress and checks the package. GitHub CI runs it on WordPress 6.0 / PHP 7.4 and on the latest WordPress / PHP 8.3.

## License

GPL-3.0-or-later. Copyright © 2026 Thierry Gustin. Approach inspired by EmDash's WordPress migration tools, without reusing their code.
