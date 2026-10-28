# Stock en quantité avec état — fin du suivi pièce par pièce

Date : 2026-10-28 · Statut : validé par l'utilisateur (2026-10-28) · Spec parente :
`2026-10-27-hockey-staff-domain-design.md` · Remplace en partie : `2026-10-27-lot2-stock-mouvements-design.md`
(pièces sérialisées), `2026-10-28-lot4-joueurs-attributions-ventes-design.md` (prêt, retour, don, vente
pièce par pièce), `2026-10-27-lot8-codes-barres-etiquettes-design.md` (étiquettes de pièce `HS-`).

Ce qui vient de l'utilisateur est écrit comme une décision ; les choix de Claude sont listés au § 10.

## 1. Intention

Après avoir vu les écrans du lot 4, l'utilisateur abandonne le suivi pièce par pièce : **les casques, gants,
etc. n'ont pas de numéro de série**, on ne peut pas identifier une pièce de façon fiable. Ce qui compte au
local, c'est la **fiche produit et la taille** (« c'est un casque L ») et l'**état** déclaré au retour
(« il est usé »).

Le moment type : en fin de saison, un joueur rapporte son casque L un peu usé et ses gants. Le responsable
ouvre la fiche du casque L, enregistre le retour du joueur, déclare « Usé » ; même chose pour les gants.
Le matériel rejoint le stock d'occasion de sa variante et peut être reprêté ou vendu.

## 2. Objectifs, non-objectifs, anti-objectifs

**Objectifs**
- Tout le stock est compté en **quantité** par variante × lieu, et pour l'équipement **× état**.
- Deux sortes de produits : **Équipement** (état suivi, se prête, se vend d'occasion) et **Consommable**
  (toujours neuf, se donne, ne se prête pas).
- Prêt, retour, don et vente d'occasion en quantité, avec l'état pour l'équipement.
- La fiche joueur montre ce que le joueur détient ; « Prêts en cours » permet de relancer en fin de saison.

**Non-objectifs**
- Identifier une pièce physique (numéro, code `HS-`, étiquette de pièce) : supprimé.
- Valeur dépréciée selon l'état : non (§ 4).
- État sur les consommables : non.

**Anti-objectifs**
- Perdre la trace de qui détient combien de quoi.
- Une baisse de valeur du stock parce qu'un article est revenu usé : la baisse n'existe qu'au prix de vente.
- Obliger à étiqueter ou scanner une pièce individuelle.

## 3. Produits : Équipement / Consommable

- Le réglage actuel « suivi pièce par pièce / en quantité » (`TrackingMode`) est **remplacé** par
  « **Équipement** / **Consommable** ».
- Les produits aujourd'hui « pièce par pièce » deviennent Équipement (381 en dev), les autres Consommable (252).
- À l'import du catalogue, le classement automatique existant (`TrackingModeResolver`, ses règles et son
  anomalie d'import) sert désormais à proposer Équipement / Consommable.
- **Équipement** : chaque quantité porte un état parmi Neuf, Bon, Usé, Abîmé. Se prête, se donne (avec
  état), se vend d'occasion (état ≠ Neuf), sort et se transfère par état.
- **Consommable** : toujours Neuf, sans choix d'état à l'écran. Se donne, sort, se transfère. Ne se prête pas,
  ne se vend pas.

## 4. Valeur du stock

- Décision de l'utilisateur : un article garde son **coût d'achat** (CUMP) quel que soit son état. Un casque
  revenu usé vaut toujours son prix d'achat, il est simplement « en occasion ». La baisse de prix n'intervient
  qu'à la vente (prix public × (1 − % de réduction), prix final modifiable — inchangé, spec lot 4 § 6).
- Valeur du stock = stock physique (tous lieux, tous états) **+ quantités détenues par les joueurs** (prêts en
  cours). Les quantités données, vendues ou sorties en sont exclues.

## 5. Mouvements

Tous passent par le `StockMovementRecorder` (transaction, verrous, contrôles de disponibilité), comme au lot 2.
Date = date d'enregistrement. Opérations et mouvements immuables.

| Mouvement | Saisie | Effet |
|---|---|---|
| Entrée achat / dotation | variante, quantité, coût ; **état** pour l'équipement | + stock (lieu, état) |
| Sortie (perte, casse…) | variante, quantité, état (équipement), motif | − stock |
| Transfert | variante, quantité, état (équipement), lieu → lieu | stock déplacé, état conservé |
| **Prêt** (équipement) | variante, lieu, **état**, **quantité**, joueur | − stock (lieu, état) ; + détenu par le joueur |
| **Retour** | joueur, variante, **quantité** (≤ détenue), lieu, **nouvel état** | − détenu ; + stock (lieu, nouvel état) |
| **Don** | variante, lieu, quantité, état (équipement), joueur **ou** équipe | − stock |
| **Vente d'occasion** (Manager) | lignes variante × état ≠ Neuf × quantité, un seul lieu | − stock ; vente figée |

- Prêt : le joueur doit exister ; seul l'équipement se prête ; pas de prêt à une équipe (inchangé).
- Retour : partiel possible (le joueur rend 1 de ses 2 paires de gants). Un retour ne peut pas dépasser la
  quantité détenue par ce joueur pour cette variante.
- Pièce perdue par un joueur : retour puis sortie au motif Perte (inchangé).
- Don, vente : un article détenu par un joueur ne se donne ni ne se vend directement : retour d'abord.
- Vente : acheteur texte libre, joueur lié facultatif, commentaire ; prix par ligne = prix public de référence
  (saison courante, sinon la plus récente passée qui en a un, sinon saisi) × (1 − %), arrondi au centime,
  prix final modifiable, HT via la TVA du tarif. Aperçu « Calculer » avant enregistrement (déjà en place).

## 6. Ce que le joueur détient

- Le prêt en cours se lit comme une **détention** : joueur × variante → quantité (et sa valeur au coût).
- Fiche joueur : « 1 casque L », « 2 gants 13" », avec la date du dernier prêt et un bouton « Retour » par
  ligne ; historique des opérations (prêts, retours, dons, achats).
- « Prêts en cours » : liste des détentions, filtrable par équipe et saison (effectif), pour relancer en fin de
  saison.
- Fiche variante : liste des joueurs qui en détiennent, avec la quantité.
- Un joueur qui détient encore du matériel ne peut pas être supprimé (inchangé).

## 7. Écrans

- **Fiche variante** : stock par lieu ventilé par état (« Local matériel : Neuf 3 · Usé 2 ») pour l'équipement,
  quantité simple pour un consommable ; détenteurs ; boutons Prêter (équipement), Retour (s'il y a des
  détenteurs), Vendre (Manager, s'il y a de l'occasion en stock), Donner, Sortie. Les boutons sans objet
  (aucun stock) sont masqués ou désactivés.
- **Prêter** : lieu, joueur, état (avec quantité dispo par état dans ce lieu), quantité (défaut 1).
- **Retour** : joueur (parmi les détenteurs ; présélectionné s'il est unique ou s'il vient de sa fiche),
  quantité, lieu, nouvel état.
- **Vente** : lignes variante + état + quantité (choix parmi l'occasion disponible au lieu choisi), plus de
  code `HS-`.
- **Page Stock** : colonnes par lieu + « Prêtées » ; pour l'équipement, l'état est visible (ventilation ou
  filtre — au builder) ; valeur totale « pièces prêtées incluses » (inchangé).
- **Fiche produit** : réglage Équipement / Consommable ; tableau par variante (stock, dont prêté).
- **Étiquettes** : seules les étiquettes de **variante** (EAN, sinon `HV-`) restent ; le mode « pièces » et les
  codes `HS-` disparaissent. Le scan du lot 3 arrivera sur la fiche variante (inchangé).

## 8. Modèle (indicatif)

```
Product : kind EQUIPMENT | CONSUMABLE  (remplace trackingMode)
StockLevel : variant × location × condition (NEW pour un consommable) → quantity, valueHt (CUMP)
PlayerHolding : player × variant → quantity, valueHt   (prêts en cours)
StockMovement : + condition ; plus de lien vers une pièce
SaleLine : variant, condition, quantity, prix unitaire figés (base, %, TVA, TTC, HT)
Supprimés : Item, ItemStatus, codes HS-, mode d'étiquette « pièces », page pièce
```

## 9. Contraintes

- La base de dev ne contient **aucun** article, niveau de stock ni opération (vérifié le 2026-10-28) : la
  migration peut supprimer la table des pièces sans reprise de données. Le catalogue importé (633 produits) est
  conservé ; ne pas réinitialiser la base.
- Migrations relues, jamais `doctrine:schema:update`. TDD, `make qa` vert, travail sur `develop`.
- Droits inchangés : `STOCK_MOVE` (prêt, retour, don, entrées, sorties, transferts), `SALE_MANAGE` (ventes),
  `PLAYER_*` (joueurs, équipes, effectifs, prêts en cours).

## 10. Choix faits par Claude (à vérifier en lecture)

- La détention est **par joueur × variante**, sans garder l'état du prêt : on déclare l'état au retour.
  L'état au moment du prêt reste visible dans l'historique (mouvement).
- La valeur suit la marchandise : un prêt retire la quantité au CUMP du lot (lieu, état) et l'ajoute à la
  détention ; un retour la remet au CUMP de la détention dans le lot (lieu, nouvel état). Jamais de
  revalorisation liée à l'état.
- Une entrée (achat ou dotation) d'équipement choisit son état, Neuf par défaut (une dotation peut être de
  l'occasion).
- Vente : prix final saisi **par unité** ; total de ligne = quantité × prix unitaire.
- Changer un produit d'Équipement à Consommable (ou l'inverse) est refusé tant qu'il a du stock ou des
  détentions.
- Un équipement « Abîmé » peut être prêté, donné et vendu comme les autres états d'occasion.
- Une détention qui retombe à zéro disparaît (la trace reste dans les mouvements).
- L'inventaire au scan (lot 3) comptera par variante × lieu × état pour l'équipement.
- Livraison sous forme d'un nouveau lot (« lot 4b ») qui modifie le code des lots 2, 4 et 8 ; les écrans
  existants sont adaptés, pas refaits.

## 11. Laissé au builder

- Découpage en tâches, noms de classes, routes et templates.
- Présentation de l'état sur la page Stock (colonnes, ventilation ou filtre).
- Ordre de verrouillage des lignes de stock et de détention.
- Mise à jour des specs des lots 2, 4, 8 et de la spec de domaine (mention « remplacé par… »).
