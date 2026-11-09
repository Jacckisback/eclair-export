# Changelog

## 1.0.2 — 2026-11-09

- Démarrage sûr : l'extension ne provoque plus jamais d'« erreur fatale » à l'activation.
  - Deux copies installées (par exemple `eclair-export` et `eclair-export-main`, le dossier créé par « Download ZIP » de GitHub) : la seconde refuse de s'activer avec un message clair qui nomme le dossier à supprimer. Avant, WordPress affichait « erreur fatale » (`Cannot declare class`).
  - PHP ou WordPress trop ancien, fichier manquant, toute erreur au chargement : message clair à la place de l'erreur fatale.
  - Supprimer la copie en double n'efface plus les données de la copie utilisée.
- Extension PHP « dom » absente : signalée par le diagnostic et à l'export, au lieu d'une erreur.
- Test sur un vrai WordPress : activation d'une seconde copie, suppression de la copie.

## 1.0.1 — 2026-11-09

- Correctif : le bouton « Télécharger » renvoyait toujours l'écran WordPress « Le lien suivi est expiré ». Le lien était échappé pour le HTML (`&amp;`) puis placé tel quel par le JavaScript : le jeton de sécurité n'arrivait jamais. Les liens sont maintenant des URL brutes, vérifiées par un test unitaire et par le test d'intégration WordPress.
- Si un lien de téléchargement a vraiment expiré (page restée ouverte trop longtemps, reconnexion), un message clair s'affiche avec un bouton de retour à la page d'export, en français, espagnol et anglais.

## 1.0.0 — 2026-11-08

Première version.

- Écran Outils › Exporter vers Éclair, en français, espagnol et anglais.
- Diagnostic en lecture seule : contenus, médias, extensions détectées (Yoast, Rank Math, AIOSEO, Elementor, Divi, WooCommerce, WPML, Polylang, Redirection, ACF), shortcodes, points d'attention, score de compatibilité, estimation de durée.
- Rapport client HTML aux couleurs d'Éclair (FR/ES/EN), imprimable en PDF, avec bloc « Préparé par » personnalisable.
- Export par étapes AJAX avec reprise après coupure, archive découpée en parties, médias inclus ou téléchargés à l'import.
- Conversion Gutenberg, éditeur classique, Elementor et Divi en HTML propre ; shortcodes non convertis gardés tels quels et listés.
- SEO Yoast, Rank Math, AIOSEO ; redirections Redirection, Rank Math, AIOSEO, Yoast Premium, et redirections générées pour les archives WordPress.
- Commandes WP-CLI : `wp eclair diagnose`, `wp eclair export`, `wp eclair cleanup`.
- Sécurité : réservé à `manage_options`, nonces, dossier privé au nom aléatoire, suppression après téléchargement ou au bout de 24 h, aucun mot de passe exporté.
- Format de paquet `eclair-export` 1.0 documenté dans `docs/FORMAT.md`.
- La page des articles de WordPress est listée comme « blog » dans `urls.csv` (Éclair la remplace par son blog), plus comme un contenu.
- Banc d'essai de bout en bout (`tests/e2e/`, lancé par la CI) : WordPress réaliste (500 articles, Elementor, Divi, Yoast, WooCommerce, Polylang, Redirection) → export → import dans Éclair → 635 anciennes adresses vérifiées : 0 erreur 404, 0 chaîne de redirections, chaque contenu sur la page qui porte son titre.
- Vérification manuelle en 10 minutes : `docs/VERIFICATION.md`.
- Nécessite, côté Éclair, la fonction Import WordPress avec l'import de paquet (Éclair après 2.0.1).
