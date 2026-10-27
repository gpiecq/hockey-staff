# Lot 2 — Décisions d'exécution (stock et mouvements)

Plan : `docs/superpowers/plans/2026-10-27-lot2-stock-mouvements.md`
Spec : `docs/superpowers/specs/2026-10-27-lot2-stock-mouvements-design.md`

Décisions prises par Claude pendant l'exécution, là où la spec et le plan ne tranchaient pas ou se
contredisaient. Chacune indique ce qu'elle coûte si elle est fausse. À relire par l'utilisateur.

## Choix de la spec (§ 9) appliqués

- **Ordre des lots 2 → 8 → 3, aucune étiquette au lot 2.** Coût si faux : réordonner le découpage.
- **En-tête `StockOperation` au-dessus des mouvements.** Un formulaire multi-lignes produit une opération et
  plusieurs `StockMovement`. Coût si faux : migration pour aplatir.
- **`StockLevel` en valeur totale, pas en coût moyen arrondi.** Le CUMP est dérivé (valeur / quantité), sans dérive
  d'arrondi. Coût si faux : recalcul de la valeur depuis les mouvements.
- **Date de mouvement = date d'enregistrement, non modifiable.** Pas d'antidatage, qui fausserait l'ordre du CUMP ;
  la date de facture va dans la référence ou le commentaire. Coût si faux : ajouter un champ de date et rejouer le CUMP.
- **Pas d'annulation d'opération** : correction par une sortie « Erreur de saisie » puis une nouvelle saisie.
  Coût si faux : écrire une opération inverse.
- **Archivage de variante par `archivedAt`**, pas par soft delete (qui masquerait l'historique). Coût si faux :
  migration de colonne.
- **Équivalence des EAN 12/13 chiffres à la comparaison**, sans réécrire la donnée (voir R4 ci-dessous).
- **Valeur totale affichée sur la page Stock** ; card du tableau de bord et snapshots restent au lot 6.
- **Pas de lieu créé par défaut** : la première entrée demande d'en créer un.

## Décisions du plan

- **Coût vide = tarif club HT**, calculé côté serveur, sans JavaScript. Un coût saisi est conservé tel quel ;
  pour une dotation, le coût est forcé à 0 (le recorder ignore le coût fourni). Coût si faux : un champ pré-rempli
  en JS si le comportement est jugé trop opaque.
- **`schema_filter` Doctrine pour la séquence `item_code_seq`** : sans lui, `make db-diff` voudrait la supprimer
  à chaque migration. Coût si faux : retirer le filtre et gérer la séquence dans les migrations.
- **Suppression refusée pour les variantes, produits et lieux qui ont des mouvements** (l'historique reste
  lisible). Coût si faux : lever la garde, au risque d'un historique pointant vers une variante supprimée.
- **Rattrapage de l'archivage des variantes déjà inactives** : au ré-import, une variante absente du fichier est
  archivée seulement si elle n'a pas de stock ; celles déjà inactives sont rattrapées de la même façon.
  Coût si faux : désarchiver à la main.

## Décisions prises en cours d'exécution

- **R1 — `LocationRepository::hasStock(Location)`** (pièce `InStock` dans le lieu, ou `StockLevel` à quantité > 0)
  pour la garde de désactivation d'un lieu (spec § 5). La présence de stock par variante, ajoutée plus tard,
  ne couvre pas ce besoin. Coût si faux : une petite méthode à consolider.
- **R2 — Message d'entrée.** « Entrée enregistrée. » quand aucune pièce n'est créée ; sinon
  « Entrée enregistrée : N pièce(s) créée(s) : HS-…, HS-… ». La spec n'exige les codes que s'il y en a.
  Coût si faux : libellé.
- **R3 — Le constructeur de `StockMovement` ne s'enregistre pas lui-même** ; les appelants passent par
  `StockOperation::addMovement` (un seul chemin de cascade). Coût si faux : petit remaniement du recorder.
- **R4 — Unicité des EAN à l'équivalence GTIN-13, au niveau validation uniquement.** `UniqueEntity` sur
  `Variant.ean` utilise une méthode de dépôt basée sur `Ean::candidates` (même périmètre fournisseur qu'avant).
  L'index unique en base `uniq_variant_ean` reste une comparaison de chaînes exactes : la valeur stockée n'est
  jamais réécrite. Une écriture qui contourne la validation peut donc créer deux formes d'un même EAN.
  Coût si faux : normaliser l'EAN à l'écriture et migrer l'index.
- **R5 — Le recorder refuse** (a) une pièce dont la variante ne correspond pas à celle de la ligne :
  « La pièce {code} ne correspond pas à « {libellé} ». » ; (b) une ligne en suivi QUANTITÉ qui porte une pièce :
  « « {libellé} » est suivi en quantité : n'indiquez pas de pièce. ». Coût si faux : libellés.
- **R6 — `wrapInTransaction` ferme l'EntityManager sur `StockException`.** Les contrôleurs d'entrée, sortie et
  transfert doivent donc seulement ré-afficher le formulaire après l'avoir attrapée, sans nouvelle écriture.
  Coût si faux : une écriture ultérieure échouerait (« EntityManager is closed »).
- **R7 — Vérification navigateur groupée** des écrans d'entrée et de sortie, une fois un lieu créé en base de dev
  (voir la recette). Faite à 390 px : lignes ajoutées/retirées, tom-select sur les nouvelles lignes, autocomplete,
  pas de dépassement horizontal. Coût si faux : un bug JS découvert plus tard.
- **R8 — Transfert : la destination ne liste que les lieux actifs** (le recorder refuse de toute façon un lieu
  inactif). Ajout de l'erreur de ligne « Indiquez la quantité. ». Coût si faux : une ligne de `query_builder`.
- **R9 — Code de pièce et variante renseignés ensemble : refusé**, erreur de champ
  « Indiquez soit un code de pièce, soit une variante. » (la quantité n'entre pas dans ce contrôle, car elle vaut 1
  par défaut). Avant, la variante était écartée en silence. Coût si faux : libellé.
- **R10 — Historique et variantes supprimées.** Aucune correction à la tâche historique : les entrées refusent déjà
  les variantes supprimées, et la garde de suppression (variantes et produits avec mouvements) empêche toute variante
  à historique d'être supprimée. Seules d'éventuelles données antérieures au lot pourraient poser problème.
- **R11 — Revue finale : suppression fournisseur/marque et sorties d'articles retirés du catalogue.**
  (a) Supprimer un fournisseur ou une marque est refusé tant qu'il reste des produits non supprimés (message
  d'erreur, redirection, rien de supprimé), comme les gardes lieu/produit/variante. Sans cela, les produits
  restaient orphelins et la fiche produit répondait par une 500. (b) Les lignes de sortie et de transfert utilisent
  l'autocomplete sans filtre actif/archivé (option `movable_only`) : le stock en quantité d'une variante « Plus au
  catalogue » reste sortable et transférable. Les entrées gardent le filtre actif seul et le recorder continue de
  les refuser. (c) Le total de la page Stock est libellé « hors filtres catégorie et recherche ». Coût si faux :
  libellés et un filtre de requête.
  Coût si faux : une 500 sur la page d'historique, à vérifier en revue finale.

## Import

- **Anomalie `TrackingModeLocked`** : lors de la première catégorisation d'un produit qui a déjà des mouvements, le
  mode de suivi n'est pas modifié (message « Produit X : mode de suivi conservé, le produit a des mouvements de
  stock. »). Le mode de suivi est verrouillé dès le premier mouvement. Coût si faux : lever le verrou et gérer la
  conversion des stocks existants.
- **Archivage à l'import** : une variante absente du fichier sans stock est archivée ; avec du stock, elle est
  désactivée (`active = false`, non archivée) et reste visible sous « Plus au catalogue ». Son stock reste sortable et
  transférable (R11). Coût si faux : archivage manuel.

## Recette manuelle

Fait à 390 px, sans stock saisi : formulaire d'entrée (ajout de 2 lignes, retrait d'une, index 0/1/2), tom-select
sur les nouvelles lignes, autocomplete (« aiguiseur » renvoie la variante avec son tarif club HT), formulaire de
sortie (5 motifs en français). Aucune erreur console.

À faire, dans cet ordre :

1. Créer deux lieux (Stock > Lieux). La base de dev contient déjà le lieu « Local matériel », créé le 2026-10-27
   pour la vérification navigateur ; en créer un second.
2. Acheter : un produit suivi en quantité et un produit sérialisé (codes `HS-…` créés, listés dans le message).
3. Enregistrer une dotation, une sortie (avec motif) et un transfert entre les deux lieux.
4. Vérifier la page Stock (quantités par lieu, valeur HT totale), la fiche pièce et la section Stock du produit.
5. Vérifier l'historique des opérations (filtres, détail d'une opération, coût de la dotation à 0).
6. Ré-importer le catalogue Bauer : aucune variante en stock ne doit être archivée ; les produits avec mouvements
   ne changent pas de mode de suivi (anomalie `TrackingModeLocked` si besoin).

## Points connus / reportés

À traiter avant la revue finale ou dans un lot suivant.

- **Course à la première création d'un `StockLevel`** (deux entrées simultanées sur une nouvelle variante dans un lieu) :
  violation d'unicité remontée en 500. Les données restent cohérentes ; il suffit de rejouer.
- **Transferts simultanés en sens opposés** : interblocage possible (le niveau de destination est verrouillé
  tardivement) → 500, données intactes.
- **Désactivation d'un lieu** : la vérification « contient du stock » n'est pas verrouillée ; une entrée concurrente
  peut passer entre le contrôle et la désactivation.
- **Page Stock** : pas de contrôle de tri utilisateur (la spec prévoyait un tri à l'image du catalogue) ; le total
  affiché ignore les filtres catégorie et recherche, sans l'indiquer.
- **Historique** : un champ de filtre invalide ignore silencieusement tous les filtres ; un identifiant de variante
  inconnu est ignoré.
- **Revue finale de la branche entière** : à faire dans une nouvelle session.
