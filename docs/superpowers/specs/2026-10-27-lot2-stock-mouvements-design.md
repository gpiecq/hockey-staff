# Lot 2 — Stock et mouvements

> **Remplacé par `2026-10-28-stock-quantite-etat-design.md`** pour les pièces sérialisées (suivi pièce par pièce) (lot 4b livré, voir `../decisions/2026-10-28-lot4b-decisions.md`).

Date : 2026-10-27 · Statut : à valider par l'utilisateur · Spec parente : `2026-10-27-hockey-staff-domain-design.md` (§ 4, § 7, § 9 lot 2)

L'utilisateur a demandé de partir de la spec de domaine telle quelle, sans nouvelle séance de questions. Ce
document reprend ce que la spec a déjà tranché et précise le reste. Les choix ajoutés ici sont **des choix de
Claude** (§ 9), à vérifier en lecture.

## 1. Intention

Le catalogue (lots 1 et 7) dit ce qu'on *peut* avoir. Le lot 2 dit ce que le club **possède** : combien, où, et
pour quelle valeur d'achat. C'est le socle des lots suivants : scan (3), attributions et ventes (4), inventaire (5),
valorisation (6), étiquettes (8). Tous ajoutent des types de mouvements ou des vues sur ce socle, sans le
modifier.

Au lot 2, le responsable matériel enregistre une réception (achat ou dotation), une sortie (perte, casse, rebut),
un transfert entre deux lieux. Il voit l'état du stock par lieu et l'historique de chaque pièce et de chaque
variante.

## 2. Ordre des lots

**Lot 2 → lot 8 → lot 3.** Le lot 2 génère le code interne `HS-` des pièces sérialisées mais n'imprime aucune
étiquette : le lot 8 couvre toutes les étiquettes (spec § 9 : il « englobe et remplace » les étiquettes internes
du lot 2). Le lot 8 précède le lot 3 : on scanne des codes qui sont déjà imprimés.

## 3. Objectifs, non-objectifs, anti-objectifs

**Objectifs**
- Lieux de stockage, avec leur gestion.
- Entrées : achat (`PURCHASE_IN`) et dotation (`DONATION_IN`), à plusieurs lignes, dans un lieu.
- Sorties (`OUT`), avec un motif : perte, casse, rebut, erreur de saisie, autre.
- Transferts entre lieux (`TRANSFER`).
- Pièces sérialisées (`Item`) : une par unité reçue, code interne `HS-000001`, état, prix d'achat, lieu, statut.
- Produits suivis en quantité : niveau de stock par variante et par lieu, valorisé au CUMP.
- Historique des opérations, de chaque pièce et de chaque variante.
- Branchements dus aux lots 1 et 7 (§ 7).

**Non-objectifs (lots ultérieurs)**
- Étiquettes, PDF, codes-barres imprimés : lot 8.
- Scan (natif ou navigateur) : lot 3. Au lot 2, une variante se cherche au clavier, y compris par EAN.
- Prêts, retours, dons, ventes (`LOAN_*`, `GIFT_OUT`, `SALE_OUT`), joueurs et équipes : lot 4.
- Inventaire et `INVENTORY_ADJUST` : lot 5.
- Snapshots de valeur, graphiques, card de valeur au tableau de bord : lot 6.
- Suppression définitive (Admin) d'une entité sans mouvement : hors lot, faute de besoin exprimé.

**Anti-objectifs** (spec § 2)
- Une valeur de stock qui bouge quand un tarif change : la valeur repose uniquement sur les coûts figés dans les
  mouvements.
- Une perte d'historique : un mouvement ne se modifie et ne se supprime jamais, on corrige par une sortie motivée.
- Un stock négatif, ou une pièce présente dans deux lieux à la fois.

## 4. Modèle

### 4.1 Entités

```
Location (nom unique, description?, actif, soft delete, horodatage + auteur)

StockOperation (en-tête d'une saisie)
  type, date d'enregistrement, auteur, lieu source?, lieu destination?,
  motif de sortie?, référence de document? (n° de facture ou de BL), commentaire?
  ─< StockMovement (une ligne)
       variante, pièce?, quantité, coût unitaire HT (centimes),
       valeur catalogue HT (centimes, dotation seulement), saison courante

Item (pièce sérialisée)
  code interne unique HS-NNNNNN, variante, statut (IN_STOCK | REMOVED), état, lieu,
  prix d'achat HT (centimes, figé à l'entrée), n° de série fabricant?, horodatage + auteur

StockLevel (variante × lieu, produits QUANTITY uniquement)
  quantité (≥ 0), valeur totale HT (centimes, ≥ 0)
```

- Une saisie (une réception de plusieurs lignes, par exemple) forme **une opération** composée de mouvements.
  L'historique affiche des opérations lisibles (« Achat du 27/10, 12 lignes, Local matériel ») tout en gardant
  le grain du mouvement prévu par la spec.
- Une entrée sérialisée de quantité N crée **N pièces** et **N mouvements** de quantité 1, chacun lié à sa pièce.
  Une sortie ou un transfert de pièces vise des pièces précises.
- `TrackingMode` du produit : `SERIALIZED` → `Item`, `QUANTITY` → `StockLevel`.

### 4.2 Énumérations

- `StockMovementType` : `PURCHASE_IN`, `DONATION_IN`, `OUT`, `TRANSFER`. Les autres types de la spec viendront
  avec leurs lots.
- `StockOutReason` : `LOST` (perte), `BROKEN` (casse), `SCRAPPED` (rebut), `ENTRY_ERROR` (erreur de saisie),
  `OTHER` (autre, commentaire obligatoire).
- `ItemStatus` : `IN_STOCK`, `REMOVED`. Le lot 4 ajoutera `LOANED`, `GIFTED`, `SOLD`.
- `ItemCondition` : `NEW` (neuf), `GOOD` (bon), `WORN` (usé), `DAMAGED` (abîmé). Une pièce entre en `NEW`, sauf
  si la saisie précise un autre état.

### 4.3 Valeur et CUMP

- `StockLevel` stocke la **valeur totale** et non un coût moyen arrondi. Le CUMP vaut valeur / quantité (affichage).
  - Entrée : quantité += q ; valeur += q × coût unitaire.
  - Sortie de q : valeur -= round(valeur × q / quantité) ; quantité -= q. À quantité 0, la valeur passe à 0.
  - Transfert de q : la source perd comme à une sortie ; la destination gagne la quantité **et la valeur retirée**
    à la source. Le mouvement enregistre ce coût unitaire.
  - Dotation : coût 0, donc la valeur ne bouge pas et le CUMP baisse, ce qui est voulu (spec : dotation à 0 €).
- Une pièce garde à vie le prix d'achat saisi à son entrée ; un transfert ne le change pas.
- Valeur HT du stock = Σ prix d'achat des pièces `IN_STOCK` + Σ valeur des `StockLevel`. Le lot 2 l'affiche sur
  la page Stock, par lieu et au total. Les pièces prêtées s'y ajouteront au lot 4 (spec § 7).

### 4.4 Prix et coûts à l'entrée

- **Achat** : le coût unitaire HT est pré-rempli avec le prix club HT de la saison courante (TTC / (1 + TVA), via
  `VatCalculator`) et reste modifiable. S'il n'y a pas de tarif, le champ est vide et sa saisie est obligatoire.
  0 est accepté.
- **Dotation** : le coût est 0 et ne se modifie pas. La valeur catalogue est le prix club HT de la saison
  courante, figée dans le mouvement. Sans tarif, elle reste vide et la ligne est signalée « sans valeur
  catalogue » ; la saisie n'est pas bloquée.
- Les montants sont des entiers en centimes, comme partout dans l'appli.

## 5. Règles d'intégrité

- **Un seul point d'entrée** : un service applicatif (`StockMovementRecorder` ou équivalent) écrit l'opération,
  ses mouvements, les pièces et les niveaux de stock dans **une seule transaction**. Aucun contrôleur ni import
  n'écrit directement dans `Item` ou `StockLevel`.
- **Concurrence** : les `StockLevel` et les `Item` touchés sont verrouillés (`SELECT … FOR UPDATE`) avant le
  contrôle de disponibilité. Deux sorties simultanées ne peuvent pas rendre le stock négatif.
- **Garde-fous en base** : contraintes `CHECK` quantité ≥ 0 et valeur ≥ 0 ; unicité (variante, lieu) sur
  `StockLevel` ; unicité du code interne ; séquence PostgreSQL pour `HS-`.
- **Contrôles métier** : sortie ou transfert limités au disponible du lieu ; pièce à `IN_STOCK` dans le lieu
  source ; lieux source et destination différents ; variante active non archivée pour une entrée ; lieu actif.
- **Immuabilité** : `StockOperation`, `StockMovement` : ni modification ni suppression, ni par l'interface ni par
  le code. Une saisie erronée se corrige par une sortie au motif « Erreur de saisie », puis une nouvelle entrée.
- **Lieu** : soft delete ou désactivation seulement s'il est vide (aucune pièce `IN_STOCK`, aucun niveau > 0).

## 6. Écrans

Menu : nouveau groupe **Stock** (`ROLE_VIEWER`) avec les entrées Stock (principale sur mobile), Opérations, Lieux.
Mobile-first et charte graphique existante (`2026-10-27-charte-graphique-design.md`).

- **Stock** : une ligne par variante en stock, avec désignation, attributs, quantité par lieu, valeur HT. Filtres :
  lieu, catégorie, recherche (référence, désignation, EAN). Valeur totale en tête, par lieu si un lieu est filtré.
- **Fiche produit** (existante) : nouvelle section Stock par variante (quantité par lieu, liste des pièces) et
  lien vers l'historique.
- **Pièce** : code, variante, statut, état, lieu, prix d'achat, n° de série, historique des mouvements.
- **Nouvelle entrée** (achat ou dotation) : lieu, référence de document, commentaire, puis lignes ajoutées une à
  une. Chaque ligne a une recherche de variante (autocomplete : EAN, référence, désignation), une quantité et un
  coût HT pré-rempli (achat). Une ligne sérialisée peut recevoir les n° de série, facultatifs. L'écran de
  confirmation liste les codes `HS-` créés.
- **Sortie** : lieu, motif, commentaire, puis lignes (variante et quantité, ou pièce par son code `HS-`).
- **Transfert** : lieu source, lieu destination, puis lignes (variante et quantité, ou pièce).
- **Opérations** : liste paginée des opérations, filtres type / lieu / période / variante ; détail d'une
  opération avec ses mouvements.
- **Lieux** : CRUD (nom, description, actif).

## 7. Branchements dus aux lots 1 et 7

1. **Absent de l'import et sans stock → archivé** (lot 7 § 5.5). À l'application d'un import, une variante absente
   du fichier :
   - avec du stock (pièce `IN_STOCK` ou niveau > 0) : désactivée, « Plus au catalogue » (comportement actuel) ;
   - sans stock : **archivée** (`archivedAt`), en plus d'être désactivée. Elle disparaît des listes du catalogue par
     défaut (filtre « Archivés » pour la retrouver) et des recherches de saisie de stock. Son historique reste
     intact.
   - Une variante archivée qui revient dans un import est réactivée et désarchivée. L'aperçu d'import distingue
     les variantes « désactivées » des variantes « archivées ».
   - Un produit dont toutes les variantes sont archivées est masqué du catalogue par défaut.
2. **Garde-fou « aucun mouvement » sur le mode de suivi** (lot 7 § 7) : tant qu'une variante du produit a un
   mouvement, le mode ne se change plus. Dans la fiche produit, le champ est désactivé et une explication
   s'affiche. À l'import, la règle de première catégorisation ne change pas le mode et lève une anomalie non
   bloquante.
3. **EAN à zéro de tête** (ticket lot 1) : un EAN-13 qu'Excel a lu comme un nombre arrive sur 12 chiffres. On
   compare les EAN sous leur forme GTIN-13 (12 chiffres → zéro ajouté en tête), aussi bien au rapprochement à
   l'import qu'à la recherche de variante et au contrôle d'unicité. La valeur stockée ne change pas. Le lot 8
   reprendra cette équivalence pour le scan.

## 8. Droits

`StockVoter`, aligné sur la spec § 6 :
- `STOCK_VIEW` (`ROLE_VIEWER`) : pages Stock, Opérations, Pièce, valeurs.
- `STOCK_MOVE` (`ROLE_STOCK`) : entrées, sorties, transferts.
- `LOCATION_MANAGE` (`ROLE_MANAGER`) : lieux.

## 9. Choix faits par Claude (à vérifier en lecture)

- Ordre lot 2 → 8 → 3, et aucune étiquette au lot 2.
- En-tête `StockOperation` au-dessus des mouvements (la spec ne mentionnait que `StockMovement`).
- `StockLevel` en valeur totale plutôt qu'en coût moyen arrondi : cela évite la dérive d'arrondi du CUMP.
- La date d'un mouvement est sa date d'enregistrement, non modifiable : pas d'antidatage, qui fausserait l'ordre
  du CUMP. La date réelle de la facture peut aller dans la référence de document ou le commentaire.
- Pas d'annulation d'opération : la correction se fait par une sortie « Erreur de saisie » puis une nouvelle saisie.
- Motifs de sortie et états de pièce listés au § 4.2.
- N° de série fabricant facultatif sur la pièce.
- Archivage par `archivedAt` sur la variante (soft delete exclu : il masquerait l'historique).
- Équivalence des EAN 12/13 chiffres en comparaison, sans réécrire la donnée.
- Valeur totale affichée sur la page Stock. La card du tableau de bord et les snapshots restent au lot 6.
- Pas de lieu créé par défaut : la première entrée demande d'en créer un, avec un lien vers Lieux.

## 10. Laissé au builder

- Découpage en tâches, noms exacts des classes, routes et templates.
- Implémentation du formulaire multi-lignes (collection Symfony avec Stimulus, ou ajout de lignes via
  Turbo Frame).
- Format de la séquence `HS-` (6 chiffres complétés de zéros ; au-delà, la longueur s'étend).
- Pagination et tri des listes, à l'image des listes du catalogue.
