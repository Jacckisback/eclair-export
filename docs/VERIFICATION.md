# Vérifier une migration à la main, en 10 minutes

Le banc d'essai automatique (`tests/e2e/run.sh`, lancé par la CI) vérifie déjà chaque ancienne adresse : 200, ou une seule 301 vers la page qui porte le bon titre. Cette liste couvre ce qu'un robot ne voit pas : l'apparence, le contenu à l'œil, et la mise en ligne. Garde l'ancien site ouvert dans un onglet et le nouveau dans un autre.

## 1. Avant de basculer le domaine (2 min)

- [ ] **Rapport de migration** (Éclair › Import WordPress › Rapport) : le verdict dit « aucune ne finit en erreur 404 ». Sinon, ajoute à la main les redirections listées (Éclair › Redirections).
- [ ] **Éléments non migrés** (même rapport) : lis la liste. Pour chaque code court (`[contact-form-7]`…), note la page : tu remplaceras le formulaire par le bloc Contact d'Éclair.
- [ ] **Vitesse de l'ancien site** : clique sur « Mesurer » pour l'ancien site **maintenant**. Après la bascule, il ne sera plus mesurable.

## 2. Cinq pages à ouvrir côte à côte (5 min)

Pour chacune, compare l'ancien et le nouveau site : titre, texte, images, liens.

- [ ] **L'accueil.**
- [ ] **L'article le plus lu** (Google Search Console › Performances › Pages, ou Google Analytics). Vérifie aussi l'onglet du navigateur : le titre SEO doit être le même qu'avant.
- [ ] **Une page faite avec Elementor ou Divi** (liste dans le diagnostic). La mise en page est simplifiée : vérifie que les textes, boutons et questions-réponses sont tous là et dans l'ordre.
- [ ] **Une fiche produit** (si boutique) : prix, prix barré, photo. Clique sur le bouton d'achat : il doit mener au lien de paiement (à créer dans Éclair si besoin).
- [ ] **Une page en anglais** (si site bilingue) : le sélecteur de langue mène à la version française, et inversement.

## 3. Trois anciennes adresses à coller dans la barre d'adresse (1 min)

- [ ] Une **catégorie** de l'ancien site (`/category/...`) : doit arriver sur la catégorie Éclair.
- [ ] Un **ancien lien court** : `/?p=` suivi du numéro d'un article (visible dans WordPress en survolant « Modifier ») : doit arriver sur l'article.
- [ ] Un **PDF** de l'ancien site (`/wp-content/uploads/...pdf`), si le site en avait : il doit s'ouvrir depuis le nouveau site.

## 4. Le jour de la mise en ligne (2 min)

- [ ] **Réglages › SEO** : l'indexation est ouverte. Colle la balise Google Search Console si ce n'est pas fait.
- [ ] **Search Console › Sitemaps** : ajoute `/sitemap.xml`.
- [ ] **Formulaire de contact** : envoie-toi un message de test.
- [ ] **Vitesse du nouveau site** : clique sur « Mesurer » pour le nouveau site, puis télécharge le rapport final pour le client (FR, ES ou EN).
- [ ] **Ancien WordPress** : garde une sauvegarde au moins 3 mois avant de le supprimer.

## Ensuite, pendant 4 semaines

Une fois par semaine, ouvre Search Console › Pages : le nombre de pages indexées doit rester stable et les erreurs 404 à zéro. Une 404 qui apparaît = une redirection à ajouter dans Éclair.
