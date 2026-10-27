# Lot 7 — Préparation du catalogue fournisseur par une IA externe

Date : 2026-10-27 · Statut : à valider par l'utilisateur · Spec parente : `2026-10-27-hockey-staff-domain-design.md` (§ 9, lot 7)

## 1. Intention

Chaque saison, Bauer (distributeur HLD) fournit deux fichiers bruts qui, séparément, ne sont pas importables :

- **Base EAN** (`BASE EAN nantes.xlsx`) : 1 onglet, ~14 000 lignes, une ligne par variante (EAN), sans prix ni
  marque. Colonnes : `Référence interne | Gencode | Désignation | Caractéristique 1 | Caracteristique 2 |
  Section/N1 | Rayon/N2 | Sous-rayon/N3 | Famille/N4`.
- **Tarif club** (`TARIF HLD COMPLET 2025 CLUB.xlsx`) : ~28 onglets (un par famille : PATIN, CROSSE, CASQUE…,
  plus SOMMAIRE et CGV), une ligne par **référence**, regroupées en **sections** (ligne de titre, ex.
  « PATIN SENIOR », « HOWIES ») ; colonnes : référence (numérique Bauer ou code texte d'une autre marque, ex.
  `HOW TAPE 25`, `BS B-7028`), désignation, tailles disponibles (texte libre), options (texte libre),
  `VALEUR PRODUIT TTC` (prix public), `PRIX CLUB TTC`.

Aujourd'hui l'utilisateur croise ces fichiers à la main pour produire `bauer-catalogue-travail.xlsx` (le format
de l'import du lot 1) en reportant ligne à ligne prix et catégories : c'est long et pénible.

Le lot 7 supprime ce travail : l'appli fournit un **template d'import** et des **règles écrites** ; l'utilisateur
(ou une personne moins technique du club) donne les deux fichiers Bauer + le template + les règles à une IA
grand public (Claude, Gemini, Copilot…), récupère un fichier rempli, et l'importe via le flux existant. Ce qui
n'a pas pu être rempli (prix, catégorie) se complète ensuite dans l'appli, guidé par le tableau de bord.

## 2. Ce que l'analyse des fichiers 2025-2026 a établi

Le fichier de travail actuel (633 références / 2 468 variantes) se reconstruit à partir des deux fichiers bruts :

| Champ du template | Source | Règle | Vérification |
|---|---|---|---|
| Références retenues | Tarif | Toutes les références du tarif (voir § 4.1) | 633/633 présentes dans le tarif |
| Variantes + Gencode | Base EAN | Jointure sur `Référence interne` ; 1 ligne par EAN | 2 455/2 455 EAN retrouvés |
| Désignation | Base EAN | Désignation Bauer telle quelle (décision utilisateur) | — |
| Taille / Modèle-Fit / Côté / Largeur / Couleur | Base EAN, Caractéristiques 1 et 2 | Classement par valeur (§ 4.3) | 2 457/2 457 identiques |
| PU Public TTC / PU Club TTC | Tarif | Recopiés de la ligne de la référence | 2 468/2 468 identiques |
| Marque | Tarif, titre de section | Nom de la section si c'est une marque (HOWIES, BLUE SPORT, GRIT…), sinon BAUER | sans exception |
| Catégorie (3 niveaux) | Tarif, onglet + section | Table de correspondance fixe (§ 4.4) | 94 des 99 couples onglet+section sont univoques |

Points de données à respecter : les EAN de la base sont du **texte** (12 ou 13 chiffres, zéros de tête
significatifs) ; 7 EAN y sont en doublon ; quelques EAN ont une longueur anormale (8, 14, 3 chiffres) ; les
onglets BLACKSTONE et PROSHARP n'ont pas de prix ; ~90 références du tarif (STREET, ROLLER, ACC ROLLER…) n'ont
aucun EAN dans la base.

## 3. Objectifs / non-objectifs / anti-objectifs

**Objectifs**
- Une page « Préparer un import » d'où l'on télécharge tout le nécessaire, avec un mode d'emploi pas à pas
  compréhensible par un non-technicien.
- Un template et des règles qui fonctionnent avec n'importe quelle IA grand public, sans outil spécifique.
- Tout le tarif entre au catalogue ; l'appli signale ensuite ce qu'il reste à compléter.
- Deux cards du tableau de bord : produits sans tarif, produits sans catégorie, chacune menant à la liste à traiter.

**Non-objectifs**
- Aucun appel à une IA depuis l'appli (pas d'API, pas de clé, pas de coût côté serveur).
- Pas d'écran d'édition de la table de correspondance (fichier fixe, modifié dans le code au besoin).
- Pas de lecture directe des fichiers Bauer bruts par l'appli.
- Pas de renommage des désignations.

**Anti-objectifs (échec même si « ça marche »)**
- Une IA qui invente une catégorie, un prix ou un EAN au lieu de laisser vide.
- Un import qui crée des catégories parasites à partir de ce que l'IA a écrit.
- Un EAN tronqué de ses zéros de tête qui casse le scan.
- Un mode d'emploi que seul un développeur peut suivre.

## 4. Règles de remplissage (contenu du document de règles)

Le document de règles est la pièce maîtresse : autonome, en français clair, numéroté, avec exemples. Il couvre :

### 4.1 Périmètre
- Inclure **toutes** les références de tous les onglets du tarif, sauf SOMMAIRE et CGV (décision utilisateur :
  plus de tri manuel ; les 168 références écartées cette saison l'avaient été volontairement, mais à l'avenir tout
  entre).
- Ignorer les lignes de titre de section (pas de référence + désignation), les lignes vides et les en-têtes de
  colonnes.
- Référence du tarif sans prix → incluse, prix laissés vides.
- Référence du tarif sans aucun EAN dans la base → incluse sur **une seule ligne**, Gencode vide, attributs vides,
  désignation du tarif.
- Les lignes de la base EAN dont la référence n'est pas dans le tarif sont ignorées.

### 4.2 Jointure et lignes
- Une ligne du template par EAN de la base dont la référence est dans le tarif.
- Référence : celle du tarif (les références numériques Bauer sans décimale ni séparateur).
- Gencode recopié **comme texte**, sans le modifier (jamais converti en nombre, zéros de tête conservés).
- Désignation : celle de la base EAN.

### 4.3 Attributs (Caractéristiques 1 et 2 → 5 colonnes)
Chaque valeur non vide et différente de `-` est rangée selon sa nature : `LFT`/`RHT` → Côté ; `FIT1`…`FIT3` →
Modèle/Fit ; largeurs (`D`, `2E`, `EE`, `R`, `B`, `C`…) → Largeur ; couleurs (FR ou codes EN : NOIR, BLK, WHT,
NAVY…) → Couleur, recopiées telles quelles (la normalisation reste celle de l'import) ; tout le reste → Taille.
Les listes de valeurs exactes sont tirées des fichiers 2025-2026 et annexées au document.

### 4.4 Marque et catégorie
- Marque : titre de la section si la table de correspondance le déclare comme marque, sinon `BAUER`.
- Catégorie : chercher le couple (onglet, section) dans la **table de correspondance** (annexe fixe).
  - Couple univoque → recopier les 3 niveaux.
  - Couple marqué « ambigu » → choisir parmi les catégories candidates listées **d'après la désignation** ; si la
    désignation ne permet pas de trancher avec certitude, laisser vide.
  - Couple absent de la table (nouvel onglet/section) → laisser vide.
- Ne jamais écrire une catégorie absente de l'onglet « Catégories » du template.

Sections ambiguës connues (2025-2026) : CULOTTE › SUR CULOTTE (culotte joueur / gardien) ; SAC › AUTRE SAC
(sac autres / accessoire patin) ; TEXTILE › CASQUETTE & BONNET ; TEXTILE › TEE SHIRT & POLO ; TEXTILE › LIFESTYLE
(pantalon / hoody / tee-shirt / casquette, sous-catégorie LIFESTYLE).

### 4.5 Contrôles à faire par l'IA avant de rendre le fichier
Rendre un court compte rendu : nombre de références et de lignes produites, lignes sans prix, sans EAN, sans
catégorie, couples onglet+section inconnus rencontrés, EAN en doublon.

## 5. Ce qui change dans l'appli

### 5.1 Page « Préparer un import » (ROLE_MANAGER)
Accessible depuis la page des imports et la card « Dernier import ». Contient le mode d'emploi (4 étapes :
télécharger, donner à l'IA avec les 2 fichiers Bauer, vérifier le compte rendu, importer) et 3 téléchargements :
1. **Template xlsx**, généré à la demande : onglet de saisie avec exactement les en-têtes du mapping Bauer de
   l'import, colonne Gencode au format texte ; onglet « Catégories » listant le référentiel **actuel** (chemins
   complets) ; onglet « Correspondances » (la table fixe, pour que tout soit dans un seul fichier).
2. **Règles pour l'IA** (§ 4), texte prêt à coller ou joindre.
3. **Table de correspondance** seule (même contenu que l'onglet du template).

La table de correspondance est un **fichier de données versionné** dans le dépôt, construit à partir de l'analyse
du fichier de travail 2025-2026 (couples onglet+section → chemin de catégorie, marque éventuelle, ou liste de
candidates pour les couples ambigus).

**Production des données réelles** : la table (99 couples, marques, 5 sections ambiguës et candidates) et les
listes de valeurs d'attributs (§ 4.3) sont extraites par Claude, en session principale, par un script local
lancé sur `var/recette/` (fichiers réels, hors dépôt). Seul le résultat, qui ne contient que des libellés de
sections et de catégories, est commité. C'est la première tâche du plan. Les subagents travaillent avec ce
fichier et avec des données synthétiques, sans jamais lire `var/recette/`.

### 5.2 Import : catégorie facultative
- `Product.category` devient nullable (migration ; contrainte `onDelete: RESTRICT` conservée).
- Ligne sans catégorie : importée, plus d'anomalie bloquante `MissingRequired` pour la catégorie ; une
  information est affichée dans l'aperçu (« N produits sans catégorie »).
- Ré-import d'un produit existant avec catégorie vide dans le fichier : la catégorie existante est **conservée**
  (le manager l'a peut-être renseignée à la main).
- Mode de suivi : un produit créé sans catégorie démarre en `QUANTITY` ; quand le manager lui donne une catégorie
  et qu'aucun mouvement n'existe, le mode par défaut de la catégorie s'applique.
- Les écrans et requêtes qui supposent une catégorie (catalogue, recherche par catégorie, fiche produit, aperçu
  d'import) gèrent l'absence (« Sans catégorie »).

### 5.3 Tableau de bord (ROLE_MANAGER)
- **Produits sans tarif** : nombre de produits non supprimés ayant au moins une variante active sans tarif pour la
  saison courante ; lien vers le catalogue filtré. Masquée sans saison courante.
- **Produits sans catégorie** : nombre de produits non supprimés sans catégorie ; lien vers le catalogue filtré.
- Une card dont le compte vaut 0 affiche « Aucun » plutôt que de disparaître.

### 5.4 Catalogue : filtres et saisie
- Deux filtres sur la liste du catalogue : « sans tarif (saison courante) » et « sans catégorie ».
- Saisie du prix au niveau produit : un formulaire qui applique un prix public TTC + club TTC à toutes les
  variantes actives du produit qui n'ont pas de tarif pour la saison courante (le tarif HLD est par référence).
  L'édition par variante existante reste disponible.
- La catégorie se renseigne via l'édition produit existante.

### 5.5 Dépendance au lot 2
Règle utilisateur : un produit absent de l'import **et sans stock** est **archivé** ; absent mais en stock, il
reste « plus au catalogue » (comportement actuel). Le stock n'existant qu'au lot 2, cette règle y sera branchée ;
le lot 7 ne change pas le traitement des absents.

## 6. Contraintes
- Le flux d'import existant (analyse async, aperçu, validation, application transactionnelle) reste le seul chemin
  vers la base ; l'IA ne touche jamais la base.
- Stack et conventions du projet (Symfony 8.1, phpspreadsheet, TDD, `make qa` vert).
- Les fichiers Bauer réels restent hors dépôt (`var/recette/`, ignoré) ; les tests utilisent des fichiers
  synthétiques.

## 7. Choix faits par Claude (à vérifier en lecture)
- L'import n'accepte que des catégories **existantes** : un chemin inconnu dans le fichier est signalé en anomalie
  et le produit est importé sans catégorie (au lieu du `findOrCreatePath` actuel), pour éviter les catégories
  parasites issues de l'IA. La création de catégories se fait dans l'écran Catégories.
- Ré-import avec catégorie vide → catégorie existante conservée.
- Saisie du prix au niveau produit appliquée aux seules variantes sans tarif.
- Cards affichées même à 0.
- Référence sans EAN → une seule ligne sans gencode.
- Le document de règles est livré en Markdown téléchargeable (lisible tel quel et accepté par toutes les IA).
- **Catégorie inconnue** : nouveau code d'anomalie `CategoryUnknown`, **non bloquant** (avertissement : la
  validation reste possible, le produit est importé sans catégorie).
- **Marques** : l'import continue de créer les marques inconnues. Elles proviennent de la table fixe, donc le
  risque est faible, et une nouvelle marque Bauer est un cas légitime. Les marques créées sont listées dans
  l'aperçu.
- **Variante sans EAN** : `EanMissing` reste non bloquant (comportement du lot 1, déjà vérifié sur 11 lignes du
  fichier réel). Une ligne « référence seule, attributs vides » est identifiée par référence + attributs vides et
  doit rester stable au ré-import (aucun doublon). Un test le garantit.
- **Prix vide au ré-import** : si la variante a déjà un tarif pour la saison, il est **conservé**, comme pour la
  catégorie. Un prix vide sur une variante sans tarif ne crée **aucun** `SeasonPrice`.
- **« Produit sans tarif »** : au moins une variante active sans `SeasonPrice` pour la saison courante, ou dont
  le prix club TTC est vide. Le prix club fonde la valeur du stock. Le prix public est facultatif et 0 est un prix
  valide. Le formulaire de saisie par produit vise ces mêmes variantes.
- **Mode de suivi** : au lot 7, le mode par défaut de la catégorie s'applique quand un produit passe de « sans
  catégorie » à une catégorie. Le garde-fou « aucun mouvement » s'ajoutera au lot 2.
- La recette avec une IA réelle (§ 8) est une étape manuelle de l'utilisateur, hors tests automatisés.

## 8. Laissé au builder
- Emplacement exact des fichiers (règles, table de correspondance), nom des routes et des classes.
- Mise en page de la page « Préparer un import » et des cards.
- Format exact de la table de correspondance (CSV, YAML, PHP) tant qu'elle est versionnée et lisible.
- Rédaction finale du document de règles, à tester au moins une fois avec une IA réelle sur les fichiers
  2025-2026 : le fichier produit, importé dans une base contenant déjà le catalogue actuel, doit donner un aperçu
  sans changement de prix ni de catégorie sur les 633 références existantes (hors références nouvellement
  incluses).
