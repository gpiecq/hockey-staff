# Lot 7 — Décisions d'exécution

Plan : `docs/superpowers/plans/2026-10-27-lot7-preparation-catalogue.md`
Spec : `docs/superpowers/specs/2026-10-27-lot7-preparation-catalogue-design.md`

Décisions prises par Claude pendant l'exécution, là où la spec et le plan ne tranchaient pas ou se
contredisaient. Chacune indique ce qu'elle coûte si elle est fausse. À relire par l'utilisateur.

## Task 1 — Table de correspondance et valeurs d'attributs

- **S1 — Classement des attributs positionnel, pas par valeur.** La spec § 4.3 range chaque valeur selon sa nature
  (« couleurs → Couleur, tout le reste → Taille »). L'analyse du fichier de travail 2025-2026 montre une autre règle,
  appliquée sans exception (3 914 valeurs) : **Caractéristique 1 → Taille, toujours** (y compris une couleur, ex.
  `JUGULAIRE 2 PARTIES` / `BLANC`) ; **Caractéristique 2 → Côté, Modèle/Fit ou Largeur selon la valeur, sinon
  Couleur**. Les couleurs NOIR, BLANC, ROUGE… apparaissent dans les deux colonnes selon leur position. Les attributs
  font partie de la clé de variante : appliquer la règle de la spec déplacerait ~160 valeurs et créerait des
  variantes en double au ré-import (contraire à la recette § 8). Le document de règles (Task 7) suit donc la règle
  positionnelle et `bauer-valeurs-attributs.csv` liste les valeurs de la **Caractéristique 2**. Coût si faux :
  reformuler la règle dans `regles-ia.md`.
- **S2 — `EE` ajouté aux largeurs** bien qu'absent de la base EAN 2025-2026 (qui écrit `2E`) : il figure dans le
  tarif ; sans lui, un futur `EE` tomberait en Couleur. Coût si faux : une valeur inutile dans l'annexe.
- **S3 — `A` (semelle Aetrex) et `I2` (grille) restent en Couleur**, comme dans le fichier de travail, pour garder
  les clés de variantes stables. Coût si faux : libellé de colonne discutable sur 19 variantes.
- **S4 — Table limitée aux 99 couples présents dans le fichier de travail.** Les 52 autres sections du tarif
  (PATIN ENFANT, ROLLER, STREET, PROSHARP…) n'ont pas de correspondance : l'IA laisse leur catégorie vide et le
  manager la renseigne depuis la card « Produits sans catégorie ». Coût si faux : compléter la table à la main.
- Candidates des couples ambigus triées par fréquence décroissante dans le fichier de travail.

## Tasks 2-7

- Task 4 — un produit catégorisé pour la première fois dont le mode de suivi soumis est inchangé reçoit le mode par
  défaut de la catégorie, même si l'utilisateur voulait garder l'ancien (indiscernable).
- Task 3 — `CategoryRepository::findOrCreatePath` est conservé mais n'est plus utilisé par l'import (qui ne crée
  plus de catégorie) ; seuls des tests l'emploient comme fixture. Coût si faux : une méthode morte à supprimer.
- Task 3 — l'aperçu d'import liste les marques créées (« Marques créées ») et affiche « Sans catégorie » pour un
  nouveau produit sans catégorie, pour qu'une marque erronée de l'IA se voie avant d'appliquer. Coût si faux : un
  bloc d'aperçu à retirer.
- Task 5 — le critère « produit non supprimé » des compteurs et filtres repose sur le filtre SQL softdeleteable,
  qui s'applique aussi dans les sous-requêtes DQL (pas de `deletedAt IS NULL` explicite). Coût si faux : produits
  supprimés comptés ; ajouter la condition explicite (le test des compteurs le détecterait).
- Task 7 — exception supplémentaire `InvalidCorrespondenceTableException` levée quand le CSV de correspondance est
  introuvable ou mal formé (en-tête, nombre de colonnes), plutôt qu’une erreur générique. Coût si faux : aucun (message plus clair).
- Task 7 — les indices de désignation de l'annexe B (« GOAL », « BEANIE », « JOGGER »…) sont des hypothèses, non
  vérifiées sur le tarif réel ; à valider lors de la recette manuelle. Coût si faux : catégories ambiguës laissées
  vides ou mal tranchées, visibles dans le compte rendu (rubrique 5) et l'aperçu.

## Revue finale

- **Règle des titres de section (I2)** — dans un onglet du tarif, un titre de section est une ligne avec du texte
  en 1re colonne, désignation vide et aucun prix ; les lignes à 1re colonne vide (notes `*sans lame…`, `(1) …`)
  sont ignorées ; une référence appartient au titre le plus proche au-dessus, ou au nom de l'onglet avant tout
  titre. Le titre n'est pas exigé dans la table `Correspondances` (sinon les références d'une section non
  couverte seraient rattachées à la section précédente, avec une mauvaise catégorie). Règle vérifiée sur le tarif
  2025-2026 : elle retrouve les 99 couples. Couple inconnu → marque `BAUER` par défaut, signalé au compte rendu ;
  les marques autres que BAUER sont listées. Coût si faux : sections mal découpées sur un futur tarif de mise en
  page différente, visibles dans la rubrique 6 du compte rendu.
- **Mode de suivi à la première catégorisation par import (I3)** — quand l'import donne une catégorie à un produit
  existant qui n'en avait pas, il reçoit `TrackingModeResolver::defaultFor()` de cette catégorie, comme dans la
  fiche produit (spec § 7). Pas encore de garde « aucun mouvement » (lot 2). Coût si faux : un produit passe en
  suivi sérialisé sans que l'utilisateur l'ait choisi ; corrigeable dans la fiche tant qu'il n'a pas de mouvement.
- **Modèle d'import (I1)** — le format texte `@` de la colonne Gencode est posé au niveau de la colonne
  (`<col style>`), sans créer de cellule : l'onglet `Import` ne contient que la ligne d'en-tête, un script qui
  ajoute des lignes écrit bien à partir de la ligne 2. Coût si faux : un tableur qui ignorerait le style de
  colonne n'appliquerait pas le format texte ; les règles 0.2 et 2.2 imposent de toute façon un Gencode texte.
- **Case « Sans tarif club » (M4)** — affichée seulement s'il existe une saison courante (le manque de tarif se
  mesure sur elle). Coût si faux : aucun.

## Recette manuelle (à faire par l'utilisateur)

Hors CI (spec § 8). À réaliser sur la base de dev (catalogue Bauer importé) :

1. Ouvrir Catalogue › Imports › Préparer un import et télécharger le modèle Bauer et `regles-ia.md`.
2. Donner à une IA le modèle, les règles, la base EAN 2025-2026 et le tarif club 2025-2026, puis récupérer le xlsx
   rempli et le compte rendu.
3. Importer ce fichier (Nouvel import, saison courante) et lire l'aperçu sans l'appliquer.

Résultat attendu : aperçu **sans changement de prix ni de catégorie** sur les 633 références existantes, hors
références nouvellement incluses dans le fichier, et **aucun changement de marque sur les références existantes**.

Résultat constaté (à remplir) :

- Date de la recette : ________
- IA utilisée : ________
- Compte rendu de l'IA (références / lignes / sans prix / sans EAN / sans catégorie) : ________
- Références existantes avec changement de prix : ____ / 633
- Références existantes avec changement de catégorie : ____ / 633
- Références existantes avec changement de marque : ____ / 633
- Références nouvelles : ____
- Écarts et actions : ________
- Verdict : ________
