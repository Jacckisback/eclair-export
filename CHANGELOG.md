# Changelog

## 1.1.0 — 2026-11-10

La migration en un seul geste : le paquet part tout seul vers le site Éclair.

- **Envoi direct vers Éclair.** Collez la clé de liaison créée dans Éclair (Import WordPress › Créer une clé de liaison) et cliquez sur « Lancer la migration » : le paquet est préparé puis envoyé au nouveau site par morceaux, quelle que soit sa taille. Plus de téléchargement, plus de limite d'envoi de l'hébergeur, plus de FTP.
  - La clé est vérifiée avant de préparer quoi que ce soit : une mauvaise clé ne coûte rien.
  - Une coupure ne fait rien perdre : l'envoi reprend là où il s'était arrêté, et chaque archive est contrôlée par son empreinte à l'arrivée.
  - La clé ne permet que de déposer le paquet, elle dure deux heures et ne sert qu'une fois. Elle n'est jamais affichée à l'écran ni gardée après l'envoi ; le paquet est supprimé de WordPress dès qu'il est arrivé.
  - Le téléchargement du paquet reste possible, et un paquet déjà prêt peut être envoyé après coup.
  - En ligne de commande : `wp eclair export --send=<clé>` et `wp eclair send <clé>` (reprise).
  - Nécessite Éclair avec la réception directe (version qui suit la 2.2.0) ; avec une version plus ancienne, un message clair le dit et le téléchargement fonctionne comme avant.
- **Nouvel écran**, en trois étapes visibles : Diagnostic, Migration, Import dans Éclair. Score de compatibilité en un coup d'œil, points d'attention classés par couleur, deux barres d'avancement (préparation, envoi), options rangées dans un volet, et ce qu'il reste à faire dans Éclair expliqué en trois lignes une fois le paquet livré.
- **SEO.**
  - Réglages du site exportés en plus (`seo.json`, format 1.1) : profils sociaux, compte X et image de partage par défaut, depuis Yoast et Rank Math. Éclair les reprend avec la validation Search Console et Bing, le logo et l'icône du site.
  - Le diagnostic signale, sans bloquer, les images sans texte alternatif et les contenus publiés sans méta-description : la migration est le bon moment pour les corriger.
- **Claude et ChatGPT.** Sur WordPress 6.9 et plus, le diagnostic et la migration sont déclarés à l'API Abilities (`eclair-export/diagnose`, `eclair-export/migrate`) : avec l'adaptateur MCP de WordPress, un assistant peut diagnostiquer le site et lancer l'envoi vers Éclair. Réservé aux administrateurs, rien n'est modifié sur le site WordPress. Côté Éclair, le connecteur MCP donne à l'assistant le bilan de l'import pour finir la migration.
- Correctif : les boutons « Reprendre » et « Annuler » restaient affichés quand ils devaient être masqués.
- Tests : clé de liaison (14 cas), API Abilities et refus d'une mauvaise clé sur un vrai WordPress, envoi direct WordPress → Éclair dans le banc d'essai de bout en bout.

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
