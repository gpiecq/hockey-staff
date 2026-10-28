# Lot 4 — Joueurs, équipes, prêts, dons et ventes d'occasion

> **Remplacé par `2026-10-28-stock-quantite-etat-design.md`** pour le prêt, le retour, le don et la vente pièce par pièce (lot 4b livré, voir `../decisions/2026-10-28-lot4b-decisions.md`).

Date : 2026-10-28 · Statut : validé par l'utilisateur (2026-10-28) · Spec parente : `2026-10-27-hockey-staff-domain-design.md`
(§ 4, § 7, § 9 lot 4) · Socle : `2026-10-27-lot2-stock-mouvements-design.md`

Document issu d'une séance de questions avec l'utilisateur. Ce qui vient de lui est écrit comme une décision ;
les choix de Claude sont listés au § 11, à vérifier en lecture.

## 1. Intention

Le moment qui motive le lot : au local, téléphone en main, le responsable matériel prend un casque taille L,
ouvre sa fiche et le **prête** au joueur 1, puis un autre casque L au joueur 6. En fin de saison, les joueurs
rendent le matériel ; il revient en stock **d'occasion** et peut être reprêté ou **vendu**. Le club donne aussi des
consommables (palets, scotch) à l'équipe.

Le lot 3 (scan et Hotwire Native) devait passer avant ; l'utilisateur a décidé de faire **le lot 4 avant le lot 3**
(2026-10-28), parce que le cas phare du scan (« je scanne la crosse et je l'attribue au joueur ») n'a de sens
qu'avec les joueurs et les prêts. Le scan du lot 3 atterrira sur la fiche variante créée ici (§ 7.1).

## 2. Objectifs, non-objectifs, anti-objectifs

**Objectifs**
- Joueurs (nom, prénom, numéro, poste, notes), équipes, effectif d'une équipe par saison.
- Prêt d'une pièce à un joueur, en choisissant l'état de la pièce (neuf ou occasion).
- Retour d'une pièce prêtée, avec son nouvel état, dans un lieu.
- Don à un joueur ou à une équipe (consommables comptés en quantité, ou pièce).
- Vente d'occasion d'une ou plusieurs pièces, prix = prix public × (1 − % de réduction).
- Fiche variante avec le stock par lieu et par état, et les boutons d'action.
- Fiche joueur : ce qu'il détient, historique.

**Non-objectifs**
- Scan (natif ou navigateur) : lot 3. Au lot 4, une variante se cherche au clavier comme aujourd'hui.
- **Reçu PDF**, **moyen de paiement**, **statut payé / non payé** : retirés à la demande de l'utilisateur.
- Import CSV des joueurs : une équipe de 25 joueurs se saisit à la main.
- Prêt à une équipe : n'existe pas au club (les équipes reçoivent du consommable, en don).
- Vente de matériel neuf ou de consommables : la vente ne concerne que l'occasion.
- Compte joueur, portail acheteur (spec § 2).
- Étiquette par pièce : les pièces prêtées gardent l'étiquette fournisseur (EAN) de leur variante ; aucune
  étiquette `HS-` n'est exigée pour prêter, rendre ou vendre.

**Anti-objectifs**
- Perdre la trace de qui détient une pièce.
- Une pièce prêtée qui sort de la valeur du stock : elle appartient toujours au club (spec § 7).
- Un prix de vente qui change après coup parce qu'un tarif a changé : le prix de base et le prix final sont figés
  dans la vente.
- Devoir imprimer une étiquette par casque pour pouvoir le prêter.

## 3. Joueurs et équipes

- **Joueur** : nom, prénom, poste (gardien, défenseur, attaquant), notes ; soft delete, horodatage + auteur.
- **Équipe** : nom ; soft delete. Aujourd'hui une seule équipe, le modèle en accepte plusieurs.
- **Effectif** (équipe × saison) : liste de joueurs avec leur **numéro** pour cette saison.
- « Reprendre l'effectif de la saison précédente » copie les joueurs et leurs numéros dans la saison courante ;
  on ajoute ou retire ensuite.
- Un joueur peut exister sans effectif (ancien joueur qui détient encore du matériel, par exemple).
- Gestion des joueurs, équipes et effectifs : **Manager** (spec § 6).

## 4. Prêt et retour

**Prêt** (mouvement `LOAN_OUT`, `ROLE_STOCK`)
- Uniquement des pièces (produits suivis à la pièce). Un produit suivi en quantité ne se prête pas.
- Écran « Prêter » depuis la fiche variante : joueur, lieu de départ, **état** (l'écran affiche le nombre de pièces
  dispo par état dans ce lieu, ex. Neuf 3 · Bon 1 · Usé 2), commentaire.
- L'utilisateur ne choisit pas une pièce précise : l'appli prend une pièce `IN_STOCK` de cet état dans ce lieu.
- La pièce passe au statut `LOANED`, son détenteur devient le joueur. Elle garde son lieu de départ en mémoire
  (comme une pièce sortie au lot 2), mais n'est plus « dans » ce lieu. Elle reste dans la valeur du stock.
- Le joueur doit déjà exister (saisi par le Manager).

**Retour** (mouvement `LOAN_RETURN`, `ROLE_STOCK`)
- Depuis la fiche variante (bouton « Retour » : choisir le joueur parmi ceux qui détiennent cette variante) ou
  depuis la fiche joueur (bouton « Retour » sur chaque pièce détenue).
- Si le joueur détient une seule pièce de la variante, elle est prise d'office ; sinon il choisit dans la liste.
- Saisie : lieu de retour, **nouvel état** (Neuf, Bon, Usé, Abîmé), commentaire.
- La pièce repasse `IN_STOCK` dans ce lieu, sans détenteur, avec l'état saisi.
- **Occasion** = pièce `IN_STOCK` dont l'état n'est pas Neuf. Pas de statut à part : une pièce d'occasion peut être
  reprêtée ou vendue.
- Un prêt ne se clôt pas avec la saison : la pièce reste prêtée tant qu'elle n'est pas rendue.
- Pièce perdue par le joueur : retour puis sortie au motif Perte (pas de sortie directe d'une pièce prêtée).

## 5. Dons

Mouvement `GIFT_OUT`, `ROLE_STOCK`. Bouton « Donner » sur la fiche variante.
- Bénéficiaire : **un joueur ou une équipe** (l'un ou l'autre, obligatoire).
- Produit en quantité (palets, scotch) : lieu, quantité ; sortie au CUMP comme une sortie du lot 2.
- Pièce : lieu, état (même choix que le prêt) ; la pièce passe au statut `GIFTED` et sort de la valeur du stock.
- Commentaire facultatif.

## 6. Vente d'occasion

Mouvement `SALE_OUT`, réservé au **Manager**.
- Ne concerne que des pièces `IN_STOCK` dont l'état n'est pas Neuf.
- Une vente porte une ou plusieurs pièces, **toutes du même lieu** (le lieu se choisit en tête de vente, comme
  une sortie du lot 2). Depuis la fiche variante, le bouton « Vendre » ouvre une vente avec une ligne ; d'autres
  lignes peuvent être ajoutées (recherche de variante, puis choix de la pièce d'occasion par état et code).
- Date de vente = date d'enregistrement, comme tout mouvement (pas de saisie a posteriori).
- Saisie de la vente : **acheteur en texte libre** (obligatoire : nom, contact, toute info utile), joueur lié
  facultatif, commentaire.
- Prix par ligne :
  - **Prix de base** = prix **public TTC** de la variante pour la saison courante ; à défaut, celui de la saison la
    plus récente qui en a un ; à défaut, aucun et le prix final est saisi à la main.
  - **% de réduction** saisi par l'utilisateur.
  - Prix de vente TTC = prix de base × (1 − %), arrondi au centime ; HT = TTC / (1 + TVA du tarif utilisé,
    20 % par défaut). Exemple de l'utilisateur : 300 € public, réduction 80 % → **60 € TTC**.
  - **Prix final TTC modifiable** ; le HT suit.
  - Tout est figé dans la vente : prix de base, %, TVA, prix final TTC et HT.
- La pièce passe au statut `SOLD` et sort de la valeur du stock. La vente n'est ni annulable ni modifiable
  (règle d'immuabilité du lot 2).
- Liste des ventes (Manager) : date, acheteur, pièces, total TTC.

## 7. Écrans

Mobile-first, charte graphique existante.

### 7.1 Fiche variante (nouvelle)
Désignation, attributs, EAN / code `HV-`, tarif de la saison. Stock par lieu, ventilé par état pour les pièces ;
nombre de pièces prêtées et leurs détenteurs. Boutons, selon les droits (choix B validé sur maquette) :
**Prêter**, **Retour**, **Vendre**, **Donner**, et **Sortie** (perte, casse…) à part, qui ouvre la sortie du lot 2
préremplie. Accessible depuis la fiche produit (chaque variante), la page Stock et les recherches. C'est la page
où le scan du lot 3 conduira.

### 7.2 Joueurs, équipes, effectifs
- Liste des joueurs (filtre équipe / saison, recherche), fiche joueur : infos, pièces détenues (avec « Retour »),
  dons et achats reçus, historique.
- Équipes ; effectif d'une équipe pour une saison, avec « Reprendre l'effectif de la saison précédente ».
- **Prêts en cours** : liste des pièces prêtées, filtrable par équipe et saison (effectif), pour relancer en fin de
  saison.

### 7.3 Ventes
Liste et détail des ventes ; formulaire de vente (§ 6).

### 7.4 Menu
Nouveau groupe **Joueurs** (joueurs, équipes, prêts en cours) ; **Ventes** dans le groupe Stock, visible du Manager.

## 8. Modèle (indicatif)

```
Player (lastName, firstName, position GOALIE|DEFENSE|FORWARD, notes?, soft delete, horodatage + auteur)
Team (name, soft delete)
TeamSeason (team × season, unique) ─< RosterEntry (player, number?, unique player × teamSeason)

Item : + status LOANED | GIFTED | SOLD ; + holder? (Player, renseigné si LOANED) ;
       location reste non nulle = dernier lieu connu, quel que soit le statut (modèle de REMOVED)
StockOperation : + player? ; + team? ; + sale?
Sale (auteur, buyer texte, player?, commentaire? ; date = celle de l'opération)
  ─< SaleLine (item, basePriceTtc?, discountRate (centièmes de %), vatRate, priceTtc, priceHt)
StockMovementType : + LOAN_OUT, LOAN_RETURN, GIFT_OUT, SALE_OUT
```

- Tout passe par le `StockMovementRecorder` du lot 2 (une transaction, verrous `FOR UPDATE`, contrôles de
  disponibilité). Aucune écriture directe dans `Item` ou `StockLevel`.
- Contrôles : prêt et don d'une pièce `IN_STOCK` dans le lieu choisi ; retour d'une pièce `LOANED` détenue par ce
  joueur ; vente d'une pièce `IN_STOCK` non neuve ; un don a exactement un bénéficiaire (joueur ou équipe).
- Valeur du stock (`StockQuery`) : pièces `IN_STOCK` **et `LOANED`** + niveaux de stock. `GIFTED` et `SOLD` en sont
  exclues.
- **Page Stock** : une colonne / un total « Prêté » à côté des lieux. Une variante dont toutes les pièces sont
  prêtées reste listée. Le filtre par lieu montre le stock physique du lieu, sans les prêts ; le total général
  = somme des lieux + prêté.
- Mouvements : `unitCostHt` garde son sens du lot 2 (coût d'achat HT de la pièce, ou CUMP pour un don en
  quantité), y compris pour `SALE_OUT` ; le prix de vente vit seulement dans `SaleLine`.
- Montants en centimes, taux en entiers (2000 = 20 %), comme `SeasonPrice.vatRate`.

## 9. Droits

- `PLAYER_MANAGE` (Manager) : joueurs, équipes, effectifs.
- `PLAYER_VIEW` (lecture seule et plus) : listes et fiches joueurs, prêts en cours.
- `STOCK_MOVE` (`ROLE_STOCK`, existant) : prêter, rendre, donner.
- `SALE_MANAGE` (Manager) : ventes.

## 10. Mises à jour de la spec de domaine

À reporter dans `2026-10-27-hockey-staff-domain-design.md` :
- Ordre : lot 4 avant le lot 3.
- Lot 4 : plus de reçu PDF, plus de statut payé / non payé, plus d'import CSV des joueurs.
- Revente : prix = **prix public TTC** × (1 − % de réduction), et non le prix d'achat de la pièce.
- Pas de prêt à une équipe ; don à un joueur ou à une équipe.

## 11. Choix faits par Claude (à vérifier en lecture)

- Le numéro du joueur est porté par l'effectif (il peut changer d'une saison à l'autre), pas par le joueur.
- Pas d'entité `Assignment` : le prêt en cours se lit sur la pièce (statut `LOANED` + détenteur), l'historique sur
  les mouvements `LOAN_OUT` / `LOAN_RETURN`.
- Seules les pièces se prêtent ; un produit à prêter doit être suivi à la pièce.
- Au prêt, l'appli prend la pièce la plus ancienne (plus petit code `HS-`) de l'état et du lieu choisis.
- Le joueur doit exister avant le prêt : pas de création à la volée depuis l'écran de prêt.
- Les dons sont ouverts au responsable matériel (`ROLE_STOCK`), comme les prêts.
- Don à une **équipe** (pas à l'équipe-saison) ; la saison est déjà portée par le mouvement.
- Une pièce prêtée ne se vend ni ne se donne directement : retour d'abord.
- Prix de base de vente : tarif public de la saison courante, sinon le plus récent, sinon saisie manuelle.
- Le % de réduction n'a pas de valeur par défaut.
- Un prêt survit au changement de saison.
- Une pièce prêtée, donnée ou vendue garde son dernier lieu en mémoire (colonne non nulle, comme `REMOVED`) :
  évite une migration risquée sur les lots 2 et 8.
- Page Stock : colonne « Prêté », variante entièrement prêtée toujours listée, filtre par lieu = stock physique.
- Une vente = un seul lieu ; date de vente = date d'enregistrement.
- `unitCostHt` d'une vente = coût d'achat de la pièce ; prix de vente dans `SaleLine` seulement.

## 12. Laissé au builder

- Découpage en tâches, noms de classes, routes et templates.
- Formulaire de vente multi-lignes (même approche que les opérations du lot 2).
- Présentation exacte de la fiche variante et de la fiche joueur.
- Pagination, tris et filtres des listes, à l'image de l'existant.
