# Lot 8 — Codes-barres et étiquettes

> **Remplacé par `2026-10-28-stock-quantite-etat-design.md`** pour les étiquettes de pièce `HS-` (lot 4b livré, voir `../decisions/2026-10-28-lot4b-decisions.md`).

Date : 2026-10-27 · Statut : validé par l'utilisateur · Spec parente : `2026-10-27-hockey-staff-domain-design.md` (§ 9 lot 8)

Ce document tranche les questions ouvertes de la spec § 9 (lot 8) après échange avec l'utilisateur. Ce que
l'utilisateur a dit est noté comme tel ; les autres choix sont **des choix de Claude** (§ 9), à vérifier en lecture.

## 1. Intention

À la réception, on n'imprime rien : on scanne l'EAN déjà présent sur l'emballage fournisseur (téléphone, lot 3) ou
on saisit depuis le bon de livraison sur le web (lot 2). *(utilisateur)*

L'étiquette imprimée sert dans trois cas *(utilisateur)* :
1. les **produits sans EAN** (11 variantes du catalogue Bauer actuel) ;
2. l'**inventaire** (lot 5) : tout ce qui est en stock doit pouvoir se scanner ;
3. la **sortie de stock** : retrouver vite la pièce ou la variante au scan plutôt qu'à la recherche.

Le lot 8 précède le lot 3 : on scanne des codes déjà imprimés. Il ne livre pas le scan caméra, mais il livre la
**résolution d'un code** (n'importe quel code imprimé → la variante ou la pièce) dans les champs de recherche
existants, utilisable dès maintenant avec une douchette USB (qui tape le code comme un clavier).

## 2. Objectifs, non-objectifs, anti-objectifs

**Objectifs**
- Un code interne de variante `HV-NNNNNN`, pour chaque variante.
- La règle « quel code-barres imprimer » pour une variante et pour une pièce (§ 4).
- Un résolveur unique de code (EAN 12 ou 13 chiffres, `HV-…`, `HS-…`) branché sur la recherche du catalogue et
  sur les champs de saisie des mouvements de stock (§ 5).
- Des étiquettes PDF générées côté serveur, en deux formats : planche A4 autocollante et étiquette unitaire pour
  imprimante d'étiquettes *(utilisateur : « les deux sont possibles »)* (§ 6).
- Des points d'impression : fiche produit, pièce, sélection du catalogue, entrée de stock, variantes sans EAN (§ 7).

**Non-objectifs**
- Scan caméra, bridge natif, flux « scanner → action » : lot 3.
- QR code : Code128 suffit pour les codes `HV-`/`HS-` ; pas de dépendance `endroid/qr-code`. *(choix de Claude)*
- Code-barres basé sur la **référence fournisseur** : abandonné. Une référence couvre plusieurs tailles, elle
  n'identifie pas une variante ; le code `HV-` la remplace. *(proposé par Claude, accepté par l'utilisateur)*
- Impression directe vers une imprimante (driver, ZPL, impression silencieuse) : on produit un PDF, le navigateur
  l'imprime.
- Formats d'étiquettes paramétrables par l'utilisateur : deux formats fixes (§ 6.1).
- Historique des impressions, compteur « déjà imprimée ».

**Anti-objectifs**
- Un code-barres imprimé qui ne se scanne pas, ou qui se scanne vers une autre variante que celle de l'étiquette.
- Un code imprimé qui cesse de fonctionner : un `HV-` ou `HS-` attribué ne change jamais, même si l'EAN de la
  variante change ou est ajouté plus tard.
- Une étiquette illisible à l'œil : le code en clair figure toujours sous les barres.

## 3. Modèle

- `Variant.code` : `HV-` + 6 chiffres, séquence PostgreSQL `variant_code_seq`, non nul, unique, attribué à la
  création (formulaire, import) ; la migration l'attribue aux variantes existantes, par `id` croissant. Même
  mécanique que `Item.code` (`ItemRepository::nextCode()`, séquence `item_code_seq`). *(choix de Claude : code pour
  toutes les variantes, pas seulement celles sans EAN — une variante qui perd ou change son EAN garde un code
  stable, et les étiquettes déjà collées restent valides.)*
- Le code est affiché sur la fiche produit (ligne de chaque variante) ; il n'est pas modifiable.
- Une variante supprimée (soft delete) garde son code ; il n'est jamais réattribué.

## 4. Quel code-barres imprimer

Données du catalogue de dev (2 466 variantes vivantes) : 2 376 EAN à 12 chiffres (UPC-A, codes Bauer nord-américains),
79 à 13 chiffres, 11 sans EAN ; un seul EAN a une clé de contrôle invalide (`876369003339`).

**Variante**
| EAN de la variante | Symbologie | Valeur encodée |
|---|---|---|
| 12 chiffres, clé valide | UPC-A | l'EAN tel quel |
| 13 chiffres, clé valide | EAN-13 | l'EAN tel quel |
| absent, autre longueur, non numérique ou clé invalide | Code128 | le code `HV-…` |

*(choix de Claude)* On réimprime l'EAN du fournisseur quand il est valide : l'étiquette et l'emballage d'origine
donnent le même code au scan. Sinon le `HV-` : imprimer en EAN-13 un code à clé fausse donnerait des barres
qu'aucun lecteur n'accepte.

**Pièce suivie une à une** (`Item`) : toujours Code128 de son code `HS-…`, une étiquette par pièce. C'est la pièce
qu'on retrouve au retour d'un prêt ou à l'inventaire, pas seulement sa variante.

**Article suivi en quantité** : étiquette de variante, imprimée en N exemplaires.

## 5. Résolution d'un code

Un service unique reçoit une chaîne saisie ou scannée et renvoie une pièce, une variante, ou rien :

1. espaces de tête et de fin retirés, casse ignorée ;
2. `HS-` + chiffres → la pièce de ce code (`ItemRepository::findOneByCode`) ;
3. `HV-` + chiffres → la variante de ce code ;
4. 12 ou 13 chiffres → la variante de cet EAN, zéro de tête compris (équivalence `App\Catalogue\Ean::candidates`
   existante : `0` + 12 chiffres ≡ 12 chiffres) ;
5. sinon rien.

La résolution ne vérifie pas la clé de contrôle : c'est une recherche exacte, un code faux ne trouve simplement
rien. La variante à l'EAN invalide (`876369003339`, probablement une faute de saisie du fournisseur) se retrouve
par son étiquette `HV-` ; corriger cet EAN est une correction de données, à faire depuis la fiche variante, hors
lot. *(choix de Claude)*

Une variante supprimée ne se résout pas (filtre softdeleteable) ; une variante inactive ou archivée se résout
(elle peut encore être en stock, cf. lot 2, option `movable_only`).

Branchements :
- **Recherche du catalogue** : la redirection actuelle « EAN exact → fiche produit » devient « code résolu →
  fiche produit de la variante » ; un `HS-` mène à la fiche de la pièce (`stock_item_show`).
- **Autocomplete des variantes des mouvements** (`StockVariantAutocompleteField`) : accepte aussi un `HV-` (l'EAN
  y est déjà accepté).
- **Champ « pièce » des sorties/transferts** : accepte déjà le `HS-` ; rien à changer.

## 6. Étiquettes PDF

### 6.1 Formats *(choix de Claude, deux formats fixes)*

| Format | Support | Dimensions d'une étiquette |
|---|---|---|
| Planche A4 | 21 étiquettes (3 × 7), type Avery L7160 / équivalents | 63,5 × 38,1 mm |
| Étiquette unitaire | rouleau d'imprimante d'étiquettes, type Brother DK-11209 | 62 × 29 mm, une étiquette par page PDF |

- Planche A4 : l'utilisateur choisit la **première case libre** (1 à 21) pour finir une planche entamée.
- Le format choisi est mémorisé dans le navigateur (préférence par poste, pas en base).

### 6.2 Contenu d'une étiquette

- Désignation du produit (tronquée proprement si trop longue, jamais sur plus de 2 lignes).
- Attributs de la variante non vides : taille, fit, côté, largeur, couleur.
- Code-barres (§ 4), avec la valeur en clair sous les barres.
- Étiquette de pièce : en plus, le code `HS-…` en clair et lisible de loin (c'est lui qu'on lit à l'œil).
- Pas de prix, pas de logo. *(choix de Claude)*

### 6.3 Génération

Côté serveur : `picqer/php-barcode-generator` (déjà retenu par la spec § 7) pour les barres, `dompdf/dompdf` pour
le PDF. L'extension `gd` est présente dans le conteneur. Le choix PNG ou SVG pour intégrer les barres dans dompdf
est laissé au builder, sous la contrainte de netteté à l'impression (§ 9).

Génération synchrone (pas de Messenger) : le volume maximal d'un PDF est borné (§ 7) et un PDF de quelques
centaines d'étiquettes reste rapide. *(choix de Claude)*

## 7. Points d'impression

Tous mènent au même écran « Imprimer des étiquettes » : la liste des étiquettes demandées (variante ou pièce,
désignation, code, **nombre d'exemplaires modifiable**, possibilité de retirer une ligne), le choix du format et
de la première case, puis « Générer le PDF » (ouvert dans un nouvel onglet).

| Depuis | Étiquettes proposées | Exemplaires par défaut |
|---|---|---|
| Fiche produit | les variantes vivantes cochées (toutes cochées par défaut) | 1 |
| Fiche d'une pièce | la pièce | 1 |
| Catalogue : sélection de produits (cases à cocher dans la liste) | toutes les variantes actives des produits cochés | 1 |
| Catalogue : bouton « Étiquettes des variantes sans EAN » | les variantes actives dont l'EAN est absent ou invalide (§ 4) | 1 |
| Détail d'une opération d'entrée (achat, dotation) | ligne en pièces → une étiquette par pièce créée ; ligne en quantité → la variante | 1 par pièce ; quantité reçue pour une ligne en quantité |

- **Produit suivi pièce par pièce** (fiche produit, sélection du catalogue) : l'écran d'impression propose un choix
  *(utilisateur : « les deux avec un choix à l'écran »)* entre « une étiquette par pièce en stock » (code `HS-` de
  chaque `Item` au statut `IN_STOCK`, 1 exemplaire chacune) et « étiquettes de variante » (EAN/`HV-`). Par défaut :
  pièces en stock. *(défaut : choix de Claude)* Un produit sans pièce en stock n'offre que les étiquettes de
  variante. Le choix vaut pour toute la demande ; les produits suivis en quantité donnent toujours des étiquettes
  de variante.
- « Variantes actives » dans ce tableau signifie : variante non supprimée **et** (active **ou** encore en stock).
  Une variante archivée ou inactive qui a du stock doit rester étiquetable pour l'inventaire. *(choix de Claude)*
- Le bouton « Étiquettes des variantes sans EAN » répond au besoin « réimpression en masse après un import » : après
  un import, les nouvelles variantes sans EAN y apparaissent.
- Un filtre « sans EAN » rejoint les filtres existants du catalogue (même règle que le bouton).
- Borne : 500 étiquettes au total par PDF ; au-delà, message demandant de réduire la sélection. *(choix de Claude)*
- Droits : imprimer demande le droit des mouvements de stock (`STOCK_MOVE`, `ROLE_STOCK` et au-dessus), comme
  prévu par la spec § 5 (« étiquettes » pour le responsable matériel). Voir le code `HV-` sur la fiche produit
  reste ouvert aux lecteurs.

## 8. Ce qui est laissé au builder

- Le découpage en classes (générateur de codes-barres, mise en page PDF, résolveur), les noms de routes et de
  templates, en suivant les conventions des lots 1 et 2.
- La transmission de la sélection vers l'écran d'impression (formulaire POST ou paramètres) ; une sélection du
  catalogue porte sur la page de résultats affichée. *(choix de Claude)*
- L'emplacement exact des boutons, dans la charte graphique (`2026-10-27-charte-graphique-design.md`).
- Le détail de troncature des textes longs.

## 9. Vérification attendue

- Tests unitaires du choix de symbologie (UPC-A, EAN-13, clé invalide, absent) et du résolveur (EAN 12/13 avec et
  sans zéro de tête, `hv-` minuscule, espaces, code inconnu, variante supprimée).
- Tests fonctionnels : chaque point d'impression rend l'écran avec les bonnes lignes et le bon nombre
  d'exemplaires ; le PDF est renvoyé (`application/pdf`) ; un `ROLE_VIEWER` reçoit 403 ; la borne des 500.
- Recette manuelle : imprimer une planche A4 et une étiquette 62 × 29 mm, les scanner avec une douchette et avec
  l'appareil photo d'un téléphone (appli de lecture quelconque) — UPC-A, EAN-13 et Code128 doivent se lire.
