# Eclair Export

**Passez un site WordPress sur [Éclair CMS](https://github.com/Jacckisback/clair-cms) sans perdre une seule adresse.**
Extension WordPress qui diagnostique le site, puis l'exporte en un paquet complet : contenus, médias, SEO, menus et redirections. L'importeur d'Éclair reconstruit le site à partir de ce paquet.

[English](README.en.md) · Licence GPL-3.0-or-later · WordPress 6.0+ · PHP 7.4+

## Ce que fait l'extension

**1. Diagnostic** (lecture seule, rien n'est exporté)
- Nombre de contenus par type et par statut, poids des médias, auteurs, menus, commentaires.
- Extensions détectées : Yoast SEO, Rank Math, All in One SEO, Elementor, Divi, WooCommerce, WPML, Polylang, Redirection, ACF.
- Shortcodes utilisés, convertis ou non, y compris ceux d'extensions désactivées.
- Points d'attention classés (à régler, à vérifier, bon à savoir), score de compatibilité, temps estimé.
- **Rapport client** en français, espagnol ou anglais, aux couleurs d'Éclair, avec votre bloc « Préparé par ». À télécharger en HTML ou à enregistrer en PDF depuis la fenêtre d'impression.

**2. Export**
- Traitement par petites étapes en AJAX, barre de progression. Fonctionne sur les hébergements mutualisés lents et **reprend après une coupure** sans doublon.
- Médias inclus dans l'archive (découpée en parties de 50 Mo à 1 Go) ou téléchargés depuis l'ancien site au moment de l'import.
- Blocs Gutenberg convertis en HTML propre ; Elementor et Divi convertis au mieux (textes, titres, images, boutons, vidéos, listes, accordéons, témoignages). Chaque shortcode non converti **reste dans le texte tel qu'écrit** et figure dans le rapport.
- SEO de Yoast, Rank Math et AIOSEO (titres, descriptions, mot-clé, canonical, noindex/nofollow, Open Graph, Twitter, type de schema), variables résolues.
- Redirections de Redirection, Rank Math, AIOSEO et Yoast Premium, plus celles que la migration rend nécessaires (catégories, étiquettes, auteurs, archives par date, flux, pages de fichiers joints).
- Liste de toutes les anciennes adresses publiques, pour vérifier après la migration qu'aucune ne répond en 404.

Format complet du paquet : [docs/FORMAT.md](docs/FORMAT.md).

## Sécurité

- Écran et actions réservés aux administrateurs (`manage_options`), nonce sur chaque action.
- Fichiers préparés dans un dossier au nom aléatoire de `wp-content/uploads`, fermé aux visiteurs (`.htaccess`, `web.config`, fichiers index), archives au nom aléatoire.
- Chaque partie est **supprimée dès qu'elle est téléchargée** ; tout export de plus de 24 h est supprimé par une tâche planifiée horaire, ainsi qu'à la désactivation et à la désinstallation.
- **Aucun mot de passe exporté** : ni ceux des comptes, ni ceux des contenus protégés. Les champs dont le nom ressemble à un secret (clé d'API, jeton…) sont ignorés. Les e-mails des auteurs ne sont inclus que si vous cochez l'option.
- Sur Nginx, les fichiers `.htaccess` ne s'appliquent pas : le dossier reste protégé par son nom aléatoire et la suppression automatique. Pour une protection complète, ajoutez `location ~ /eclair-export- { deny all; }` à la configuration du serveur.

## Installation

1. Téléchargez `eclair-export-x.y.z.zip` (page des versions du dépôt).
2. WordPress › Extensions › Ajouter › Téléverser une extension › choisissez le ZIP › Activer.
3. Ouvrez **Outils › Exporter vers Éclair**.

## Utilisation

1. **Lancer le diagnostic**, lire les points d'attention, télécharger le rapport pour le client.
2. **Lancer l'export** : choisissez les types de contenus et le mode des médias. Laissez l'onglet ouvert ; si la connexion coupe, rouvrez la page et cliquez sur **Reprendre l'export**.
3. Téléchargez chaque partie, puis importez-les dans Éclair.

### En ligne de commande (WP-CLI)

```bash
wp eclair diagnose
```

```bash
wp eclair diagnose --format=html --locale=fr_FR --output=diagnostic.html
```

```bash
wp eclair export --output=/chemin/vers/exports/ --part-size=250
```

```bash
wp eclair export --media=remote --types=post,page
```

```bash
wp eclair cleanup
```

Pour migrer des sites à la chaîne, lancez `wp eclair export` sur chacun (par SSH ou avec `--ssh=` de WP-CLI). L'archive est écrite dans le dossier choisi, puis le serveur est nettoyé.

## Limites connues (version 1.0)

- Les mises en page Elementor et Divi sont simplifiées (colonnes, animations et styles ne sont pas repris). Les formulaires, carrousels, tableaux de prix et widgets d'extensions sont signalés dans le rapport pour être refaits dans Éclair.
- Les commentaires, commandes, clients et paiements WooCommerce ne sont pas exportés.
- Les redirections Redirection à condition (connexion, navigateur, cookie) sont listées, pas exportées.
- Les expressions régulières (redirections avec motifs) sont exportées telles quelles ; Éclair ne redirige que des adresses exactes.

## Tests

```bash
php tests/run.php
```

Tests des parties autonomes (analyse des shortcodes, conversion Gutenberg/Elementor/Divi, nettoyage HTML, chemins, redirections, variables SEO, compatibilité PHP 7.4).

```bash
DB_HOST=127.0.0.1 DB_USER=root DB_PASS=root bash tests/integration.sh
```

Test complet sur un vrai WordPress (WP-CLI et MySQL nécessaires) : installe WordPress, Yoast et Redirection, crée des contenus variés, lance le diagnostic et l'export, puis vérifie le paquet (empreintes, adresses, SEO, redirections, aucun secret). La CI GitHub le lance sur WordPress 6.0 / PHP 7.4 et sur la dernière version de WordPress / PHP 8.3.

## Traductions

Textes sources en anglais, traductions dans `tools/translations/<locale>.php`. Pour régénérer `languages/*.pot`, `*.po` et `*.mo` :

```bash
php tools/i18n.php
```

## Construire le ZIP installable

```bash
php tools/build.php
```

Crée `dist/eclair-export-<version>.zip`, sans les tests ni les outils.

## Licence

GPL-3.0-or-later. Copyright © 2026 Thierry Gustin. Approche inspirée des outils de migration WordPress d'EmDash, sans reprise de leur code.
