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

## Migration en un envoi (1.1)

1. Dans Éclair : **Administration › Import WordPress › Créer une clé de liaison**. Copiez la clé.
2. Dans WordPress : **Outils › Exporter vers Éclair**, collez la clé, cliquez sur **Lancer la migration**.
3. Le paquet est préparé puis envoyé au site Éclair par morceaux, quelle que soit sa taille. Une coupure ne fait rien perdre : l'envoi reprend.
4. Dans Éclair : **Simuler**, puis **Importer**, puis ouvrez le rapport de migration.

La clé ne permet que de déposer le paquet sur le site Éclair, elle dure deux heures et ne sert qu'une fois. Sans clé, le paquet se télécharge comme avant.

En ligne de commande : `wp eclair export --send=<clé>`.

### Avec Claude ou ChatGPT

- **Sur WordPress 6.9+** : l'extension déclare deux capacités à l'API Abilities, `eclair-export/diagnose` (lecture seule) et `eclair-export/migrate` (export puis envoi avec une clé de liaison). Avec l'adaptateur MCP de WordPress, un assistant peut diagnostiquer le site et lancer la migration. Réservé aux administrateurs.
- **Sur Éclair** : activez le connecteur MCP (Réglages › IA) et demandez « finis ma migration WordPress » : l'assistant lit le bilan de l'import (outil `get_migration`) et propose les corrections contenu par contenu. Rien n'est publié sans votre accord.

## Côté Éclair : import et rapport client

Dans Éclair CMS (fonction **Import WordPress**, livrée avec Éclair) :

1. Envoie toutes les parties du paquet, ou dépose-les par FTP dans `data/import/` si elles dépassent la limite de ton hébergeur.
2. **Simuler** : Éclair montre ce qu'il va créer (contenus, adresses gardées ou redirigées, médias, éléments non migrés) sans rien écrire.
3. **Importer** : chaque adresse est gardée à l'identique ou redirigée en 301, les liens internes et les images sont réécrits, le SEO, les menus, la page d'accueil et les redirections sont repris, la boutique est ouverte si des produits WooCommerce arrivent. Réimporter le même paquet ne crée aucun doublon.
4. **Rapport de migration** pour le client (FR, ES, EN), à imprimer en PDF : contenus, chaque ancienne adresse vérifiée (« zéro 404 » quand c'est vrai), redirections, éléments non migrés et leur raison, liste de contrôle SEO, vitesse avant/après mesurée par Google PageSpeed. Mesure l'ancien site **avant** de basculer le domaine.

En SSH, pour migrer des sites à la chaîne :

```bash
php app/bundled/wordpress/lib/cli.php import paquet.zip paquet-part2.zip --dry-run
```

```bash
php app/bundled/wordpress/lib/cli.php import paquet.zip paquet-part2.zip
```

Vérifications à faire à la main après l'import (10 minutes) : [docs/VERIFICATION.md](docs/VERIFICATION.md).

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

### Banc d'essai de migration de bout en bout

```bash
DB_HOST=127.0.0.1 DB_USER=root DB_PASS=root ECLAIR_BRANCH=main bash tests/e2e/run.sh
```

Construit un WordPress réaliste (500 articles Gutenberg, Elementor, Divi, Yoast, WooCommerce, Polylang en deux langues, Redirection), l'exporte, l'importe dans Éclair, puis demande chaque ancienne adresse publique : 200, ou une seule 301 vers la page qui porte le bon titre. Lancé par la CI à chaque envoi ; résumé dans l'onglet Actions, rapports en artefacts. Vérifications à faire à la main : [docs/VERIFICATION.md](docs/VERIFICATION.md).

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
