# Lot 8 — Décisions d'exécution (codes-barres et étiquettes)

Plan : `docs/superpowers/plans/2026-10-27-lot8-codes-barres-etiquettes.md`
Spec : `docs/superpowers/specs/2026-10-27-lot8-codes-barres-etiquettes-design.md`

Décisions prises par Claude pendant l'exécution, là où la spec et le plan ne tranchaient pas ou se
contredisaient. Chacune indique ce qu'elle coûte si elle est fausse. À relire par l'utilisateur.

## Décisions prises en cours d'exécution

- **R1 — Règle « étiquetable » appliquée partout.** La spec (§ 7) dit « variantes vivantes » pour les cases de la
  fiche produit, la contrainte globale dit « étiquetable ». Le constructeur de lignes ne garde que les variantes
  étiquetables, quel que soit le point d'entrée. Coût si faux : une variante inactive sans stock ne peut pas être
  étiquetée depuis la fiche produit.
- **R2 — Troncature de la désignation : coupe nette à 2 lignes, sans points de suspension.** La spec laisse le détail
  au développeur et la contrainte « jamais plus de 2 lignes » est respectée. Coût si faux : un petit changement PHP
  pour ajouter l'ellipse.
- **R3 — `LogicException` quand une variante sans EAN utilisable n'a pas de code `HV-`.** Cas non prévu par le plan ;
  il ne doit pas arriver, car tout code est attribué à la création (migration comprise). Coût si faux : un message
  utilisateur à la place de l'exception.
- **R4 — Largeur du code-barres selon le format.** A4 : largeur fixe de 50 mm (module ≈ 0,53 mm pour UPC-A/EAN-13,
  zone blanche mesurée ≈ 6,7 mm de chaque côté pour 5,8 mm requis). Rouleau 62 mm : largeur = nombre de modules × 0,45 mm,
  plafonnée à 52 mm (UPC-A/EAN-13, 95 modules : 42,8 mm, zone blanche ≈ 9,6 mm pour 11X = 5,0 mm ; Code128 `HV-000001`,
  ≈ 146 modules : plafond 52 mm, X ≈ 0,36 mm, zone blanche ≈ 5,8 mm pour 10X = 3,6 mm). Une première version à 52 mm fixes
  étirait l'EAN à X ≈ 0,55 mm et ne laissait que 4,65 mm, sous le minimum de 11X : corrigé. Coût si faux : ajuster
  `ROLL_MODULE_MM` après essai de scan réel (voir la recette).
- **R5 — Règle globale `[hidden] { display: none !important }`** dans `assets/styles/app.css`. Le champ « première
  case » restait visible pour le format rouleau, car `.field { display: flex }` l'emportait sur l'attribut `hidden`.
  Aucune autre règle CSS ne dépend du comportement inverse. Coût si faux : cibler seulement ce champ.
- **R6 — Refus CSRF = 403** (`createAccessDeniedException`), comme la suppression d'un lieu. Coût si faux : message
  utilisateur et redirection.
- **R7 — Sélection du catalogue limitée à la page affichée.** Les cases `produits[]` sont par page ; la sélection est
  perdue à la pagination ou au changement de filtre. Coût si faux : sélection persistante côté serveur ou en JS.
- **R8 — `forOperation` conserve toutes les lignes de pièces** sans appliquer la règle « étiquetable » (une étiquette
  par pièce créée, comme le dit la spec) ; la règle ne s'applique qu'aux lignes en quantité. Coût si faux : filtrer
  aussi les pièces.
- **R9 — Rendu PDF** avec dompdf v3.1.6 (`isRemoteEnabled = false`, police `DejaVu Sans`) et codes-barres
  `picqer/php-barcode-generator` v3.3.0. Coût si faux : changer de bibliothèque de rendu.
- **R10 — Base de test à migrer.** La base de test est distincte de la base de dev et n'a pas la séquence de codes
  `HV-` : lancer les migrations avec `APP_ENV=test` (et dans la CI). Coût si faux : tests en erreur sur une base non
  migrée.
- **R11 — Vérification navigateur faite par le contrôleur** (chrome-devtools MCP), sans correctif de sa part :
  catalogue à 390 px sans débordement, 30 cases `produits[]` + 2 boutons + filtre « Sans EAN »,
  `/etiquettes?sans_ean=1` (12 lignes : 11 sans EAN et 1 clé invalide), format rouleau qui masque la première case,
  mémorisation `hs.label.format` en localStorage, POST du PDF (200, `application/pdf`), fiche produit à 390 px (code
  `HV-` sous le libellé, case cochée, bouton présent). Coût si faux : un bug d'affichage découvert plus tard.

## Recette manuelle

Faite : la vérification navigateur décrite en R11.

À faire :

1. Imprimer une planche A4 et une étiquette 62 × 29 mm.
2. Les scanner à la douchette USB et à l'appareil photo d'un téléphone, pour les trois symbologies : UPC-A, EAN-13,
   Code128.
3. Imprimer une étiquette rouleau UPC-A/EAN-13 collée sur une surface sombre (zone blanche réduite) et la scanner.
4. Caler les marges de la planche L7160 sur l'imprimante du club.
5. Corriger l'EAN `876369003339` (clé invalide) depuis la fiche variante : correction de données, hors lot.

## Points connus / reportés

- **Désignation tronquée sans ellipse** (R2) ; le test de non-débordement ne peut pas échouer (cellules en position
  absolue) ; un mot unique très long peut déborder horizontalement (masqué).
- **Largeur de code-barres** : fixe en A4, par nombre de modules sur le rouleau (R4) ; l'étiquette rouleau coupait les
  jambages de la 2e ligne de désignation (hauteur 6,4 mm, portée à 6,9 mm), corrigé. Échelle PNG non entière, image non mise en cache par valeur.
- **Variante dont le produit est supprimé** : une recherche `HV-` redirige vers une fiche produit en 404, sans garde ni
  test. Une variante supprimée garde son code, sans test.
- **Fiche produit** : les variantes inactives ou archivées sans stock sont cochées mais ne produisent aucune étiquette.
- **Filtre « Sans EAN »** : hydrate toutes les variantes concernées pour obtenir les produits (une requête scalaire
  serait meilleure) ; `forProducts` fait une requête par produit ; liste `IN` non bornée (sans effet sur le catalogue
  actuel).
- **Contrôleur d'impression** : ré-expansion manuelle des produits, `LabelLineBuilder::forProducts` inutilisé en
  production ; un paramètre de requête falsifié (scalaire ou tableau) donne un 400 ; l'erreur 422 ré-affiche l'écran
  sans le choix de mode.
- **Recherche `HS-`** sans droit STOCK_VIEW : le repli n'est pas testé (aucun rôle n'en est privé aujourd'hui).
- **Tests manquants** : règle « étiquetable » sur les lignes postées, paramètres `produits[]`, `operation`,
  `sans_ean`, `retirer`, pièce en POST, bornes (`total` = 500), cas limites de la recherche de code (`HV-12A`,
  14 chiffres, préfixe seul), EAN de 11/14 chiffres ou vides, dotation et opérations multi-produits.
- **Règle « étiquetable » dupliquée** en PHP et en DQL ; la branche `StockLevel` n'est pas testée.
