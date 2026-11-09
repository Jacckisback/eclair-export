# Format du paquet `eclair-export` 1.1

Un export est un ou plusieurs fichiers ZIP. La **partie 1** (`eclair-export-<site>-<date>-<aléatoire>.zip`) contient toujours `manifest.json` et toutes les données ; les parties suivantes (`…-part2.zip`, `…-part3.zip`) ne contiennent que des fichiers de `media/uploads/` et un `part.json`. Tous les textes sont en UTF-8, toutes les dates en ISO 8601 UTC.

```
manifest.json          description du paquet, empreintes SHA-256 de chaque fichier de données
content/posts.json     articles            ┐
content/pages.json     pages               │ un tableau d'objets « contenu » par type
content/products.json  produits WooCommerce │
content/cpt-<type>.json types personnalisés ┘
content/taxonomies.json termes (catégories, étiquettes, taxonomies personnalisées)
content/authors.json   auteurs (jamais de mot de passe ; e-mail seulement sur demande)
content/menus.json     menus, emplacements et arborescence
content/settings.json  réglages du site (liste fermée)
media/index.json       un objet par fichier de la médiathèque
media/uploads/…        fichiers d'origine, même arborescence que wp-content/uploads (mode « include »)
seo.json               métadonnées SEO par contenu et par terme, réglages SEO globaux
redirects.csv          redirections existantes et redirections nécessaires à la migration
urls.csv               adresses publiques de l'ancien site, à vérifier après la migration
report.json            ce qui n'a pas pu être converti, avec exemples
part.json              (parties 2 et suivantes) numéro de la partie et identifiant du paquet
```

## manifest.json

| Clé | Contenu |
|---|---|
| `format`, `format_version` | `"eclair-export"`, `"1.1"` (1.1 : `seo.json` → `global.social`). Une version mineure n'ajoute que des clés ; une version majeure peut en changer. |
| `generator` | `{name, version}` de l'extension. |
| `package` | Nom de base des fichiers ZIP (identifie les parties d'un même export). |
| `created_at` | Date de l'export. |
| `source` | `url` (accueil, sans « / » final), `site_url`, `name`, `language` (locale), `timezone`, `permalink_structure`, `wordpress`, `php`, `multisite`, `theme {name, version, template, block}`. |
| `plugins` | `detected` (clés : `yoast`, `rankmath`, `aioseo`, `elementor`, `divi`, `woocommerce`, `wpml`, `polylang`, `redirection`, `acf` → `{name, version}`) et `active` (toutes les extensions actives). |
| `multilingual` | `provider` (`wpml`, `polylang` ou `""`), `languages` (codes à 2 lettres, langue par défaut en premier). |
| `options` | Options de l'export : `media` (`include` ou `remote`), `part_mb`, `types`, `emails`. |
| `counts` | `content {type: n}`, `terms`, `authors`, `menus`, `media`, `media_bytes`, `redirects`, `shortcodes_unconverted`. |
| `parts` | `[{n, file, media_files}]`. |
| `files` | `{chemin: {bytes, sha256}}` pour chaque fichier de données de la partie 1 (sauf `manifest.json`). |

## Contenu (`content/*.json`)

| Clé | Contenu |
|---|---|
| `wp_id` | Identifiant WordPress (clé de toutes les correspondances). |
| `type`, `status` | Type de contenu ; `publish`, `future`, `draft`, `pending` ou `private`. |
| `protected` | `true` si le contenu avait un mot de passe (le mot de passe n'est pas exporté : à importer en brouillon). |
| `title`, `slug` | Titre brut, slug décodé (accents compris). |
| `url`, `path` | Adresse complète d'origine et chemin depuis la racine (`/2024/03/article/`). Pour un brouillon : `/?p=12`. Un site installé dans un sous-dossier perd ce préfixe. |
| `parent`, `menu_order`, `template` | Page parente (`wp_id`), ordre, modèle de page. |
| `date_gmt`, `modified_gmt` | Dates de publication et de modification. |
| `author` | `wp_id` de l'auteur (voir `authors.json`). |
| `lang`, `translation_group` | Langue (2 lettres) et groupe de traduction (`wpml-<trid>`, `pll-<id>` ; vide sans traduction). |
| `excerpt` | Extrait, sans balises. |
| `html` | Contenu converti en HTML propre (voir plus bas). |
| `source_format` | `gutenberg`, `classic`, `elementor` ou `divi`. |
| `featured_media` | `wp_id` de l'image à la une (0 : aucune). |
| `terms` | `{taxonomie: [slugs]}`. |
| `primary_category` | Catégorie principale choisie dans Yoast ou Rank Math (slug), sinon vide. |
| `sticky`, `comments` | Article épinglé ; nombre de commentaires (non exportés). |
| `meta` | Champs ACF `{nom: {type, label, value}}` si ACF est actif, sinon métadonnées publiques. Jamais de clé ressemblant à un secret ; `{_truncated: true}` au-delà de 64 Ko. |
| `shortcodes_left` | Shortcodes restés dans `html` tels qu'écrits. |
| `product` | (produits) `kind`, `price`, `regular_price`, `sale_price`, `currency`, `sku`, `gtin`, `stock_status`, `virtual`, `downloadable`, `short_description`, `gallery` (`wp_id`), `brand`, `external_url`, `button_text`, `variations [{wp_id, label, price, regular_price, sku, stock_status, image}]`. |

### HTML converti

Balises gardées : `p br hr strong b em i u s del ins sub sup mark small abbr code pre kbd q h1–h6 ul ol li dl dt dd blockquote cite figure figcaption a img table thead tbody tfoot tr th td caption details summary iframe video audio source`. Attributs gardés : `href title rel` (seulement `nofollow`, `sponsored`, `ugc`) sur `a` ; `src alt title width height` sur `img` ; `src` sur `iframe` (YouTube et Vimeo seulement) ; `colspan rowspan` ; `id` des titres ; `start` des listes. Les conteneurs (`div`, `span`, `section`…) disparaissent en gardant leur contenu ; `script`, `style`, `form`, `svg`, `object`… sont retirés et comptés dans `report.json`.

- Les adresses des images et des liens internes restent **celles de l'ancien site** : l'importeur les réécrit grâce à `media/index.json` (adresse d'origine et de chaque miniature) et aux chemins des contenus.
- `details/summary` = question/réponse (accordéons Elementor, bascules Divi) ; `blockquote` + `cite` = témoignage.
- Un shortcode non converti reste **écrit tel quel** dans le texte et figure dans `shortcodes_left` et `report.json`.

## Termes, auteurs, menus, réglages

- **taxonomies.json** : `wp_id`, `taxonomy`, `taxonomy_label`, `hierarchical`, `slug`, `name`, `description`, `parent`, `count`, `url`, `path`, `lang`, `translation_group`.
- **authors.json** : `wp_id`, `slug` (adresse `/author/<slug>/`), `display_name`, `first_name`, `last_name`, `bio`, `website`, `url`, `email` (vide sauf option), `role` (`admin` ou `editor`).
- **menus.json** : `wp_id`, `name`, `slug`, `locations` (emplacements du thème), `items [{wp_id, parent, order, title, type (post_type, taxonomy, custom, post_type_archive), object, object_id, url, new_tab}]`.
- **settings.json** : `name`, `description`, `url`, `language`, `timezone`, `date_format`, `show_on_front`, `page_on_front`, `page_for_posts`, `posts_per_page`, `permalink_structure`, `category_base`, `tag_base`, `site_icon`, `logo` (`wp_id` de médias), `blog_public`, `woocommerce {shop_page, currency}`.

## media/index.json

`wp_id`, `url` (adresse d'origine), `variants` (adresses des miniatures et de l'original avant réduction : toutes mènent au même média), `file` (chemin dans le paquet, `media/uploads/2024/03/photo.jpg`), `mime`, `bytes`, `width`, `height`, `alt`, `title`, `caption`, `description`, `parent` (contenu auquel le fichier est attaché), `date_gmt`, `lang`, `missing` (fichier absent du disque), `sha256` et `included` (fichier présent dans une partie du paquet ; `false` en mode `remote` : l'importeur le télécharge depuis `url`).

## seo.json

```json
{"global": {"source": "yoast", "search_engines_discouraged": false, "separator": "-", "organization": {"name": "", "logo": ""}, "robots_txt": "", "verification": {"google": "…"},
            "social": {"twitter": "compte", "image": "https://…/partage.jpg", "profiles": ["https://www.facebook.com/…"]}},
 "posts": {"123": {"title": "", "description": "", "keyword": "", "canonical": "", "noindex": false, "nofollow": false,
                   "og": {"title": "", "description": "", "image": ""}, "twitter": {"title": "", "description": "", "image": ""}, "schema": "", "source": "yoast"}},
 "terms": {"category:5": { …même forme… }}}
```

Seul ce qui a été saisi à la main est exporté, variables résolues (`%%title%%`, `%title%`, `#post_title`…). Un champ vide signifie « valeur par défaut d'Éclair ». `noindex` tient compte du réglage par type de contenu de l'extension SEO et de « Demander aux moteurs de ne pas indexer ».

## redirects.csv

En-tête : `source,target,code,origin,regex`.

- `source` : chemin depuis la racine (`/ancienne-page/`), ou l'expression telle qu'écrite si `regex` vaut `1`.
- `target` : chemin sur le site, adresse absolue ailleurs, vide pour un code 410/404, ou une **référence** que l'importeur résout avec les adresses d'Éclair :
  - `eclair:term:<taxonomie>:<slug>` (archive d'un terme), `eclair:author:<slug>`, `eclair:archive:<type>`, `eclair:shop`, `eclair:blog`, `eclair:feed`,
  - `eclair:media:<wp_id>` (page d'un fichier joint : vers le contenu parent s'il existe, sinon vers le fichier).
- `code` : 301, 302, 307, 308, 410…
- `origin` : `redirection`, `rankmath`, `aioseo`, `yoast` (redirections existantes, prioritaires) ou `migration` (générées).

Les redirections du plugin Redirection à condition (connexion, navigateur, cookie…) ne sont pas exportées : elles figurent dans `report.json` (`redirects_skipped`).

## urls.csv

En-tête : `url,kind,ref`. Toutes les adresses publiques connues de l'ancien site : contenus publiés (`content`, `<type>:<wp_id>`), termes, auteurs, archives, flux, pages de fichiers joints. Après la migration, chacune doit répondre 200, ou 301 vers la bonne page.

## report.json

`shortcodes` et `widgets` (`{nom: {count, contents, examples [{id, title, url}]}}`), `converted`, `dropped`, `formats` (contenus par éditeur), `protected`, `media_missing_count`, `media_missing`, `redirects_skipped`, `errors`.
