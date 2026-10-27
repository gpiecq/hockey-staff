# Règles de remplissage du modèle d'import (fournisseur Bauer / HLD)

Ce document s'adresse à l'IA qui prépare le fichier d'import du catalogue d'un club de hockey sur glace. Il est
aussi lisible par la personne du club qui lui confie le travail. Suivez les règles **dans l'ordre**, **à la
lettre**, sans en ajouter. En cas de doute sur une valeur : **laisser vide**. Une case vide se complète ensuite dans
l'application ; une valeur inventée y entre comme vraie et fausse le stock.

---

## 0. Méthode de travail

0.1. **Traiter les fichiers par programme, pas à la main.** La base EAN compte environ 14 000 lignes : utilisez
votre outil d'exécution de code / d'analyse de données (un script, ex. Python avec openpyxl) pour lire le tarif
et la base EAN, appliquer les règles ci-dessous et écrire le modèle. Ne recopiez pas les lignes une par une.

0.2. **Écrire le Gencode comme une cellule texte** (type chaîne, ex. `cell.value = "0123456789012"` avec le format
`@`), jamais comme un nombre (règle 2.2).

0.3. **Écrire les données à partir de la ligne 2 de l'onglet `Import`**, par adresse de cellule (ligne 2, 3, 4…),
et non en ajoutant des lignes « à la suite » de celles qui existeraient déjà (pas d'`append` aveugle) : aucune
ligne vide ne doit séparer l'en-tête des données.

0.4. **Ne pas toucher aux autres onglets** (`Catégories`, `Correspondances`) ni à la ligne 1 : ouvrir le modèle,
remplir `Import`, enregistrer sous un nouveau nom au format xlsx.

---

## 1. Les fichiers que vous recevez

1.1. **Le modèle d'import** (`modele-import-bauer.xlsx`), à remplir. Il contient 3 onglets :

- `Import` : l'onglet à remplir. La ligne 1 contient les 14 en-têtes, dans cet ordre :
  `Référence interne | Gencode | Désignation | Taille | Modèle/Fit | Côté | Largeur | Couleur | PU Public TTC |
  PU Club TTC | Catégorie | sous-catégorie 1 | sous-catégorie 2 | Marque`.
- `Catégories` : la **liste fermée** des catégories autorisées. Une ligne par catégorie : colonne `Chemin`
  (ex. `EQUIPEMENT › JOUEUR › PATIN`), puis ses niveaux dans les colonnes `Catégorie`, `sous-catégorie 1`,
  `sous-catégorie 2`.
- `Correspondances` : la table qui donne la catégorie (et parfois la marque) de chaque section du tarif.
  Colonnes : `onglet | section | categorie1 | categorie2 | categorie3 | marque | candidates`.

1.2. **La base EAN** du fournisseur (souvent nommée « BASE EAN … ») : un seul onglet, une ligne par **variante**
(une taille, un côté, une couleur… d'un article), identifiée par son code-barres. Pas de prix, pas de marque.
Colonnes : `Référence interne | Gencode | Désignation | Caractéristique 1 | Caracteristique 2 | Section/N1 |
Rayon/N2 | Sous-rayon/N3 | Famille/N4`. Les colonnes `Section/N1` à `Famille/N4` ne servent pas.

1.3. **Le tarif club** du fournisseur (souvent nommé « TARIF … CLUB ») : un onglet par famille de produits
(PATIN, CROSSE, CASQUE…), plus des onglets `SOMMAIRE` et `CGV`. Une ligne par **référence** (un article, toutes
tailles confondues). Dans chaque onglet, les références sont regroupées en **sections** : une ligne de titre
(ex. « PATIN SENIOR », « HOWIES ») suivie des lignes de références de cette section. Colonnes : référence (un
nombre pour Bauer, ou un code texte pour une autre marque, ex. `HOW TAPE 25`), désignation, tailles disponibles,
options, `VALEUR PRODUIT TTC` (prix public), `PRIX CLUB TTC`. Les colonnes « tailles disponibles » et « options »
ne servent pas : les tailles viennent de la base EAN.

---

## 2. Règles d'or (valables partout)

2.1. **Ne jamais inventer.** Aucun prix, aucun Gencode, aucune catégorie, aucune taille qui ne vient pas
directement des fichiers selon les règles ci-dessous. Si une valeur manque ou si vous n'êtes pas certain :
**laisser vide**, et le signaler dans le compte rendu (règle 10).

2.2. **Le Gencode est du texte.** Recopiez-le caractère par caractère : jamais converti en nombre, jamais en
notation scientifique (`6,88E+11` est faux), **zéros de tête conservés** (`0123456789012` reste
`0123456789012`), sans espace ajouté.

2.3. **Aucune catégorie hors de l'onglet `Catégories`.** Un chemin de catégorie écrit dans `Import` doit
correspondre exactement à une ligne de l'onglet `Catégories` du modèle. Sinon : laisser les 3 colonnes de
catégorie vides.

2.4. **Ne pas modifier le modèle.** Ne pas renommer, ajouter, supprimer ni déplacer de colonne ; garder la ligne
1 telle quelle ; ne pas modifier les onglets `Catégories` et `Correspondances`.

2.5. **Le compte rendu est obligatoire** (règle 10). Un fichier rendu sans compte rendu est incomplet.

---

## 3. Périmètre : quelles références entrent dans le fichier

3.1. Prendre **toutes** les références de **tous** les onglets du tarif, **sauf** les onglets `SOMMAIRE` et
`CGV`. Ne faire aucun autre tri (pas de sélection « utile pour le club »).

3.2. Dans un onglet du tarif, classer chaque ligne ainsi (la « 1re colonne » est la colonne des références) :
- **ligne vide** → ignorée ;
- **ligne d'en-têtes de colonnes** (« RÉFÉRENCE », « DÉSIGNATION », « VALEUR PRODUIT TTC », « PRIX CLUB TTC »…)
  → ignorée ; ce n'est ni une référence ni un titre de section ;
- **ligne de titre de section** : du texte dans la 1re colonne, **et** la colonne désignation vide, **et**
  aucun prix. Elle ne produit aucune ligne, mais elle donne la section des références qui suivent (règle 3.3).
  Le titre est le texte de la 1re colonne, recopié tel quel ;
- **ligne dont la 1re colonne est vide** (notes de bas de tableau, ex. `*sans lame/avec support`, `(1) …`,
  même si une autre colonne contient du texte) → ignorée ; ce n'est **pas** un titre de section ;
- **ligne de référence** : une référence dans la 1re colonne **et** une désignation.

3.3. La **section** d'une référence est le titre de la ligne de titre de section la plus proche **au-dessus**
d'elle, dans le même onglet (règle 3.2). Une référence située avant tout titre de section de l'onglet prend le
**nom de l'onglet** comme section. Le titre de section ne doit **pas** être cherché dans la table
`Correspondances` pour être reconnu : une section absente de la table reste une section (ses références ne sont
pas rattachées à la section précédente) ; c'est seulement sa catégorie qui reste vide (règle 9.4).
L'**onglet** d'une référence est le nom de l'onglet du tarif où elle se trouve.

3.4. Une référence du tarif **sans prix** entre quand même dans le fichier, avec les prix laissés vides.

3.5. Une référence du tarif **sans aucune ligne dans la base EAN** entre quand même, sur **une seule ligne** :
Gencode vide, Taille, Modèle/Fit, Côté, Largeur et Couleur vides, désignation prise **dans le tarif**.

3.6. Les lignes de la base EAN dont la référence n'est **pas** dans le tarif sont ignorées.

3.7. Si une même référence apparaît plusieurs fois dans le tarif, utiliser sa **première** apparition (onglet,
section, prix) et la signaler dans le compte rendu.

---

## 4. Construction des lignes

4.1. Écrire **une ligne dans l'onglet `Import` par ligne de la base EAN** dont la référence est dans le tarif.
Une référence qui a 8 variantes dans la base EAN donne donc 8 lignes.

4.2. **Correspondance tarif ↔ base EAN** : une ligne de la base EAN appartient à une référence du tarif quand sa
`Référence interne` est égale à la référence du tarif. Comparer les références comme du texte, sans tenir compte
des espaces en trop ni de la casse ; une référence numérique écrite `1234567.0` ou `1 234 567` dans un fichier
est la même que `1234567`.

4.3. Les lignes sont écrites à partir de la ligne 2, sans ligne vide intercalée. L'ordre conseillé est celui du
tarif (onglet par onglet, section par section), puis celui de la base EAN pour les variantes d'une référence.

4.4. Les valeurs de la référence (prix, catégorie, marque) sont **recopiées sur chacune** de ses lignes.

---

## 5. Remplissage colonne par colonne

| Colonne du modèle | Source | Règle |
|---|---|---|
| `Référence interne` | Tarif | Règle 5.1 |
| `Gencode` | Base EAN, `Gencode` | Règle 5.2 |
| `Désignation` | Base EAN, `Désignation` | Règle 5.3 |
| `Taille`, `Modèle/Fit`, `Côté`, `Largeur`, `Couleur` | Base EAN, Caractéristiques 1 et 2 | Règle 6 |
| `PU Public TTC` | Tarif, `VALEUR PRODUIT TTC` | Règle 7 |
| `PU Club TTC` | Tarif, `PRIX CLUB TTC` | Règle 7 |
| `Marque` | Table `Correspondances`, colonne `marque` | Règle 8 |
| `Catégorie`, `sous-catégorie 1`, `sous-catégorie 2` | Table `Correspondances` | Règle 9 |

5.1. **Référence interne** : la référence du tarif. Une référence numérique Bauer s'écrit en chiffres seuls, sans
décimale ni séparateur : `1234567` (et non `1234567.0`, `1 234 567` ou `1,234,567`). Une référence texte d'une
autre marque est recopiée telle quelle (ex. `HOW TEST 99`).

5.2. **Gencode** : recopié depuis la base EAN, comme du texte (règle 2.2). Ne pas corriger un Gencode de longueur
inhabituelle (8, 14 ou 3 chiffres…) : le recopier tel quel et le signaler dans le compte rendu. Vide pour une
référence sans EAN (règle 3.5).

5.3. **Désignation** : celle de la base EAN, **telle quelle** (pas de traduction, de correction ni de
reformulation). Pour une référence sans EAN : celle du tarif.

---

## 6. Attributs : Caractéristiques 1 et 2 → Taille, Modèle/Fit, Côté, Largeur, Couleur

6.1. Une valeur vide ou égale à `-` est **ignorée** (rien n'est écrit pour elle).

6.2. **Caractéristique 1 → `Taille`, toujours**, quelle que soit la valeur (une pointure, une taille de
vêtement, une longueur, et même une couleur ou un code).

6.3. **Caractéristique 2** (colonne parfois écrite `Caracteristique 2`, sans accent) :
- si la valeur figure dans la liste `Côté` de l'annexe A → colonne `Côté` ;
- sinon, si elle figure dans la liste `Modèle/Fit` de l'annexe A → colonne `Modèle/Fit` ;
- sinon, si elle figure dans la liste `Largeur` de l'annexe A → colonne `Largeur` ;
- **sinon → colonne `Couleur`**, même si la valeur n'est pas dans la liste des couleurs de l'annexe.

La comparaison avec les listes se fait sur la valeur exacte, sans tenir compte des espaces en trop ni de la casse.

6.4. Les valeurs sont recopiées **telles quelles** : ne pas traduire (`BLK` reste `BLK`, `WHT` reste `WHT`), ne
pas normaliser, ne pas compléter. L'application fait elle-même la normalisation des couleurs.

6.5. Pourquoi cette règle de position : la taille, le côté, la largeur… font partie de l'identité d'une variante.
Ranger toujours la même valeur dans la même colonne garantit qu'une variante déjà connue de l'application est
reconnue au ré-import, au lieu d'être créée en double.

6.6. Exemples (valeurs fictives) :

| Article | Caractéristique 1 | Caractéristique 2 | Taille | Modèle/Fit | Côté | Largeur | Couleur |
|---|---|---|---|---|---|---|---|
| Patin | `9.5` | `FIT2` | `9.5` | `FIT2` | | | |
| Patin | `7` | `D` | `7` | | | `D` | |
| Crosse | `87` | `LFT` | `87` | | `LFT` | | |
| Gant | `14` | `NAVY` | `14` | | | | `NAVY` |
| Jugulaire | `BLANC` | `-` | `BLANC` | | | | |
| Casque | `M` | `BLK` | `M` | | | | `BLK` |
| Rondelle | `-` | (vide) | | | | | |

---

## 7. Prix

7.1. `PU Public TTC` = la colonne `VALEUR PRODUIT TTC` de la ligne de la référence dans le tarif ;
`PU Club TTC` = la colonne `PRIX CLUB TTC` de cette même ligne. Ne jamais échanger les deux colonnes.

7.2. Écrire le prix en euros, comme un nombre, avec au plus 2 décimales, sans symbole `€` ni séparateur de
milliers : `129.99`, `1049`, `0`. **Arrondir au centime, comme la valeur affichée dans le tarif** : une cellule
peut contenir un artefact de calcul (ex. `719.9920000001` affiché `719,99 €`) ; écrire `719.99`.

7.3. Prix absent, vide ou non numérique (`NC`, `-`, `sur demande`…) → **laisser vide**. Ne jamais calculer,
estimer ou reprendre un prix d'une autre référence. Certains onglets du tarif n'ont aucun prix : c'est normal,
leurs références entrent avec des prix vides (règle 3.4).

---

## 8. Marque

8.1. Chercher le couple (onglet, section) de la référence dans l'onglet `Correspondances` (même comparaison qu'à
la règle 9.1). Si la colonne `marque` de cette ligne est remplie, écrire cette marque.

8.2. Dans tous les autres cas (colonne `marque` vide, couple absent de la table) : écrire `BAUER`.
La colonne `Marque` n'est jamais vide.

8.3. **Attention : la marque change la fiche produit.** Une marque fausse écrase la marque d'un produit déjà
connu de l'application. Ne jamais déduire une marque d'une désignation ou d'un titre de section. Quand le couple
(onglet, section) est **inconnu** de la table, écrire `BAUER` mais signaler la référence au compte rendu, sous
« couples inconnus » (rubrique 6), pour que la personne du club vérifie sa marque. Toute marque écrite autre que
`BAUER` est listée au compte rendu (rubrique 7).

---

## 9. Catégorie

9.1. Chercher le couple (onglet, section) de la référence dans l'onglet `Correspondances` : la colonne `onglet`
doit être égale au nom de l'onglet du tarif, et la colonne `section` au titre de la section. Comparer sans tenir
compte de la casse, des accents ni des espaces en trop ; aucun autre rapprochement (pas de « section voisine »,
pas de ressemblance approximative).

9.2. **Couple univoque** (colonnes `categorie1`, `categorie2`, `categorie3` remplies, `candidates` vide) :
recopier `categorie1` dans `Catégorie`, `categorie2` dans `sous-catégorie 1`, `categorie3` dans
`sous-catégorie 2` (les niveaux vides restent vides).

9.3. **Couple ambigu** (colonne `candidates` remplie) : la colonne `candidates` liste plusieurs catégories
possibles, séparées par ` | ` ; chaque catégorie est un chemin dont les niveaux sont séparés par ` › `. Choisir
**une** de ces catégories **d'après la désignation** de la ligne. Si la désignation ne permet pas de trancher
avec certitude : **laisser vide**. Ne jamais choisir une catégorie qui n'est pas dans `candidates`. Le chemin
choisi est découpé sur ` › ` : 1er niveau dans `Catégorie`, 2e dans `sous-catégorie 1`, 3e dans
`sous-catégorie 2`. Les couples ambigus connus et des indices de choix sont en annexe B.

9.4. **Couple absent de la table** (nouvel onglet ou nouvelle section, ex. une section de rollers) : **laisser
vide** et le signaler dans le compte rendu. Ne pas deviner une catégorie à partir du nom de la section.

9.5. **Contrôle final** : avant d'écrire une catégorie, vérifier que son chemin complet (niveaux non vides reliés
par ` › `) figure dans la colonne `Chemin` de l'onglet `Catégories`. S'il n'y figure pas : laisser les 3 colonnes
vides et le signaler. Ne jamais créer, renommer ni corriger une catégorie.

---

## 10. Compte rendu obligatoire

Avant de rendre le fichier, vérifiez-le, puis écrivez un court compte rendu, avec ces rubriques **dans cet
ordre** (écrire « aucune » quand une liste est vide) :

1. **Références** : nombre de références du tarif retenues (règle 3).
2. **Lignes** : nombre de lignes écrites dans l'onglet `Import`.
3. **Lignes sans prix** : nombre, et la liste des références concernées.
4. **Références sans EAN** : nombre, et la liste (règle 3.5).
5. **Lignes sans catégorie** : nombre, et la liste des références, avec la raison pour chacune : couple
   inconnu (9.4), couple ambigu non tranché (9.3), ou catégorie absente de l'onglet `Catégories` (9.5).
6. **Couples onglet + section inconnus** : la liste des couples rencontrés absents de la table
   `Correspondances`, avec leur nombre de références et la liste de ces références (catégorie laissée vide,
   marque `BAUER` écrite par défaut : à vérifier, règle 8.3).
7. **Marques autres que BAUER** : chaque marque écrite autre que `BAUER`, avec son nombre de références et les
   couples (onglet, section) d'où elle vient (règle 8).
8. **EAN en double** : les Gencode présents sur plusieurs lignes de la base EAN retenues, avec leurs
   références. Ne pas supprimer ces lignes : les recopier et les signaler.
9. **Autres points** : Gencode de longueur inhabituelle, références présentes plusieurs fois dans le tarif
   (règle 3.7), toute autre difficulté. Écrire « aucun » s'il n'y en a pas.

Exemple de compte rendu (chiffres fictifs) :

```
1. Références : 712
2. Lignes : 2 590
3. Lignes sans prix : 14 (références 1234567, 1234568, HOW TEST 99)
4. Références sans EAN : 88 (…)
5. Lignes sans catégorie : 131 — 120 couple inconnu (…), 9 ambigu non tranché (…), 2 absente de Catégories (…)
6. Couples inconnus : ROLLER › ROLLER SENIOR (31 références), STREET › CROSSE STREET (17 références)
7. Marques autres que BAUER : HOWIES (12 références, CONSOMMABLE › HOWIES)
8. EAN en double : 0123456789012 (références 1234567 et 1234570)
9. Autres points : Gencode 12345678 (référence 1234590) sur 8 chiffres
```

---

## 11. Fichier à rendre

11.1. Rendre le modèle au format **xlsx**, avec l'onglet `Import` rempli et les autres onglets inchangés.

11.2. Vérifier avant de rendre : la ligne 1 est intacte ; la colonne `Gencode` ne contient aucune notation
scientifique et a gardé ses zéros de tête ; aucune colonne `Marque` vide ; chaque catégorie écrite figure dans
l'onglet `Catégories`.

11.3. Joindre le compte rendu (règle 10) dans la réponse.

---

## 12. Exemple complet (données fictives)

Tarif, onglet `PATIN`, section `PATIN SENIOR` :

| Référence | Désignation | Tailles | Options | VALEUR PRODUIT TTC | PRIX CLUB TTC |
|---|---|---|---|---|---|
| 1234567 | PATIN TEST SR | 6 à 12 | | 899,99 € | 720,00 € |

Base EAN :

| Référence interne | Gencode | Désignation | Caractéristique 1 | Caracteristique 2 |
|---|---|---|---|---|
| 1234567 | 0123456789012 | S26 PATIN TEST SR | 9.5 | FIT2 |
| 1234567 | 0123456789029 | S26 PATIN TEST SR | 10 | FIT2 |

Table `Correspondances` : `PATIN;PATIN SENIOR;EQUIPEMENT;JOUEUR;PATIN;;` (univoque, pas de marque).

Lignes à écrire dans `Import` :

| Référence interne | Gencode | Désignation | Taille | Modèle/Fit | Côté | Largeur | Couleur | PU Public TTC | PU Club TTC | Catégorie | sous-catégorie 1 | sous-catégorie 2 | Marque |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| 1234567 | 0123456789012 | S26 PATIN TEST SR | 9.5 | FIT2 | | | | 899.99 | 720 | EQUIPEMENT | JOUEUR | PATIN | BAUER |
| 1234567 | 0123456789029 | S26 PATIN TEST SR | 10 | FIT2 | | | | 899.99 | 720 | EQUIPEMENT | JOUEUR | PATIN | BAUER |

Autres cas :

- Tarif, onglet `CONSOMMABLE`, section `HOWIES`, référence `HOW TEST 99` « RUBAN TEST », prix club `4,50 €`,
  aucune ligne dans la base EAN, aucune valeur dans `VALEUR PRODUIT TTC` → **une** ligne : `HOW TEST 99`,
  Gencode vide, désignation `RUBAN TEST`, attributs vides, PU Public TTC **vide** (absent du tarif),
  PU Club TTC `4.5`, catégorie `CONSOMMABLE` (table : `CONSOMMABLE;HOWIES;CONSOMMABLE;;;HOWIES;`), marque
  `HOWIES`, listée au compte rendu (rubrique 7).
- Tarif, onglet `ROLLER` : une ligne `ROLLER SENIOR` (1re colonne remplie, désignation vide, aucun prix) est un
  titre de section, absent de la table. La référence `1234599` « ROLLER TEST SR » placée sous ce titre est
  écrite normalement, avec catégorie **vide** et marque `BAUER` ; elle n'est **pas** rattachée à la section
  précédente de l'onglet. Le couple `ROLLER › ROLLER SENIOR` et son nombre de références sont signalés au compte
  rendu (rubrique 6), pour vérifier la catégorie et la marque.
- Tarif, onglet `PATIN`, sous les références de `PATIN SENIOR`, une ligne dont la 1re colonne est vide et qui
  contient `*sans lame/avec support` dans une autre colonne : note de bas de tableau, **ignorée**. Ce n'est pas
  un titre de section : les références suivantes restent dans `PATIN SENIOR` jusqu'au prochain vrai titre.
- Tarif, onglet `TEXTILE`, section `TEE SHIRT & POLO` (ambigu) : désignation `POLO TEST SR` → `TEXTILE` /
  `POLO` ; désignation `TEST TEAM SR` (rien ne dit si c'est un tee-shirt ou un polo) → catégorie **vide**,
  signalée au compte rendu (rubrique 5).

---

## Annexe A — Valeurs de la Caractéristique 2 par colonne

Valeurs relevées dans les fichiers 2025-2026. Pour la règle 6.3, seules comptent les listes `Côté`,
`Modèle/Fit` et `Largeur` : toute autre valeur va dans `Couleur`.

- **Côté** : `LFT`, `RHT`
- **Modèle/Fit** : `FIT1`, `FIT2`, `FIT3`
- **Largeur** : `2E`, `B`, `C`, `D`, `EE`, `R`
- **Couleur** (valeurs déjà rencontrées, liste non limitative) : `A`, `BKR`, `BKW`, `BLANC`, `BLEU`, `BLK`,
  `CHR`, `GRIS`, `GRIS MAT`, `I2`, `JAUNE`, `MAR`, `NAV`, `NAVY`, `NOIR`, `NRW`, `ORANGE`, `RED`, `ROSE`,
  `ROUGE`, `VERT`, `VIOLET`, `WBK`, `WHT`, `WNV`, `WRD`, `WSL`

Rappel : en Caractéristique 1, toute valeur (même `BLANC` ou `NOIR`) va dans `Taille`.

## Annexe B — Couples ambigus connus et indices de choix

La table `Correspondances` fait foi ; cette annexe aide seulement à choisir. Les indices ne sont pas
exhaustifs : sans certitude, **laisser vide**.

| Onglet › Section | Candidates | Indices dans la désignation |
|---|---|---|
| CULOTTE › SUR CULOTTE | `EQUIPEMENT › JOUEUR › CULOTTE` ; `EQUIPEMENT › GARDIEN › CULOTTE` | « GOAL », « GARDIEN » → gardien ; mention explicite de joueur (« PLAYER », « JOUEUR ») → joueur ; sinon vide |
| SAC › AUTRE SAC | `SAC › AUTRES` ; `ACCESSOIRE PATIN` | un sac (« SAC », « BAG », « BACKPACK ») → `SAC › AUTRES` ; un accessoire de patin qui n'est pas un sac (ex. protège-lames) → `ACCESSOIRE PATIN` |
| TEXTILE › TEE SHIRT & POLO | `TEXTILE › TEE-SHIRT` ; `TEXTILE › POLO` | « POLO » → polo ; « TEE », « T-SHIRT », « TSHIRT » → tee-shirt |
| TEXTILE › CASQUETTE & BONNET | `TEXTILE › BONNET` ; `TEXTILE › CASQUETTE` | « BONNET », « BEANIE », « TOQUE » → bonnet ; « CASQUETTE », « CAP » → casquette |
| TEXTILE › LIFESTYLE | `TEXTILE › HOODY › LIFESTYLE` ; `TEXTILE › TEE-SHIRT › LIFESTYLE` ; `TEXTILE › CASQUETTE › LIFESTYLE` ; `TEXTILE › PANTALON › LIFESTYLE` | « HOODY », « HOODIE » → hoody ; « TEE », « T-SHIRT » → tee-shirt ; « CASQUETTE », « CAP » → casquette ; « PANTALON », « PANT », « JOGGER » → pantalon |

Quelle que soit la candidate choisie, appliquer le contrôle de la règle 9.5.
