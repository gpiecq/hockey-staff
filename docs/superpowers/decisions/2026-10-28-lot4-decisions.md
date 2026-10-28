# Lot 4 — Décisions d'exécution (joueurs, équipes, attributions, ventes d'occasion)

Plan : `docs/superpowers/plans/2026-10-28-lot4-joueurs-attributions-ventes.md`
Spec : `docs/superpowers/specs/2026-10-28-lot4-joueurs-attributions-ventes-design.md`

Décisions prises par Claude pendant l'exécution, là où la spec et le plan ne tranchaient pas ou se
contredisaient. Chacune indique ce qu'elle coûte si elle est fausse. À relire par l'utilisateur.

## Arbitrages d'orchestration

- **R1 — Le bouton « Vendre » passe de la tâche 6 à la tâche 8.** La fiche variante (tâche 6) aurait référencé la route
  `sale_new` et le `SaleVoter`, créés seulement à la tâche 8 : un `path()` Twig vers une route absente plante. Le bouton
  et son test arrivent donc avec la vente. Coût si faux : le bouton arrive une tâche plus tard, sans reprise.
- **R2 — Pas de worktree, travail direct sur `develop`.** Le `CLAUDE.md` du projet impose `develop` jusqu'à la fin des
  développements. Coût si faux : aucun (arbre propre, chaque tâche commitée).
- **R3 — Joueur ou équipe supprimé (soft delete) : les pages d'historique doivent rester lisibles, et la suppression d'un
  joueur détenteur est refusée.** La tâche 3 a révélé un 500 (`EntityNotFoundException`) sur la fiche opération et la
  fiche pièce dès qu'un joueur lié était supprimé. Correctif : `player_delete` refuse tant que le joueur détient une
  pièce prêtée (« Ce joueur détient encore du matériel prêté : enregistrez d'abord le retour. ») ; les lectures
  d'historique (fiche pièce, historique de la pièce, fiche opération) désactivent le filtre softdeleteable pour `Player`
  et `Team` seulement (trait `IncludesDeletedPeopleTrait`). Une équipe n'est jamais détentrice : pas de garde. Coût si
  faux : du code de garde en plus.
- **R4 — « Retour » réservé à `STOCK_MOVE`.** Sur la fiche joueur, le bouton « Retour » n'apparaît que pour les rôles
  qui peuvent enregistrer un retour ; un lecteur voit les pièces détenues sans lien. Coût si faux : une condition de
  template.
- **R5 — Revue finale avant la documentation.** La revue de toute la branche et sa vague de corrections passent avant
  cette tâche, pour que ce fichier voie le résultat final. Coût si faux : aucun, ordre seulement.
- **R6 — Périmètre de la vague de corrections finale.** Corrigés : import du catalogue qui archivait les variantes
  prêtées, absence de prix avant une vente irréversible, impossibilité de choisir la pièce à vendre, repli sur un prix de
  saison future, lien vers un joueur supprimé, N+1 de la liste des ventes, réduction affichée alors qu'un prix final
  l'écrase, test de l'autocomplete joueur, paramètre inutile de `selectedLocation`. Les autres points mineurs sont
  consignés dans « Points connus ». Coût si faux : un peu d'interface en plus sur le formulaire de vente.
- **R7 — La revue finale réfute le mineur « flush redondant » de la tâche 2.** Le flush avant `RosterCopier` est
  nécessaire ; il est conservé.

## Décisions de conception

### Joueurs et équipes

- **R8 — Pas d'unicité sur les joueurs** (homonymes autorisés). Une équipe a un nom unique parmi les non supprimées
  (index unique partiel).
- **R9 — Redirections.** Après modification d'un joueur : fiche du joueur ; après création ou suppression : liste.
- **R10 — Lecture du roster par jointure.** Les entrées de roster sont toujours lues par une requête qui joint `Player`
  (le filtre softdeleteable masque les joueurs supprimés) ; la collection paresseuse `TeamSeason::getEntries()` n'est
  pas utilisée par l'interface ni par la copie.
- **R11 — `TeamSeason` créée seulement à l'ajout ou à la copie**, jamais à la consultation. Le paramètre de saison des
  routes de roster est `?saison=` (saison courante par défaut).
- **R12 — Doublon de joueur dans un roster :** message flash et redirection ; numéro ou joueur invalide : 422 avec
  réaffichage. Le formulaire d'ajout n'est pas lié à une entité (tableau, nom `roster_entry`).
- **R13 — Filtre de la liste des joueurs par équipe seule = n'importe quelle saison** de cette équipe ; la colonne N°
  n'apparaît que si équipe et saison sont toutes deux choisies.

### Prêt, retour, don

- **R14 — Refus de don.** Pièce sérialisée avec quantité différente de 1 : « Une pièce se donne à l'unité : quantité 1. »
  (donner 1 pièce quand l'utilisateur en demande 3 serait faux). Pièce sérialisée sans état : « Choisissez l'état de la
  pièce. ». Ni joueur ni équipe, ou les deux : « Choisissez un joueur ou une équipe. ». Le plan ne donnait pas les
  libellés. Coût si faux : changer des messages.
- **R15 — Le don ne vérifie pas que le lieu est actif** (comme la sortie du lot 2) ; le prêt et le retour le vérifient.
- **R16 — Pièce prêtée choisie : la plus ancienne (plus petit code) de l'état demandé**, verrouillée en
  `PESSIMISTIC_WRITE`. Le retour relit et verrouille la pièce, puis vérifie statut `Loaned` et détenteur.
- **R17 — Une exception dans `wrapInTransaction` ferme l'EntityManager** (comportement du lot 2, inchangé) ; les tests
  appellent `resetManager()` après un refus attendu.
- **R18 — Valeur et quantités du stock.** La page Stock compte `InStock` + `Loaned` (colonne « Prêtées ») ; avec un
  filtre de lieu, seules les pièces en stock sont comptées. Le libellé du total prend le suffixe « (pièces prêtées
  incluses) » à la fin pour ne pas casser les assertions existantes. La fiche produit affiche « dont N prêtée(s) ».
- **R19 — L'import du catalogue et la règle « étiquetable » comptent les pièces prêtées comme du stock** (correctif F1) :
  une variante absente du fichier dont la seule pièce est prêtée est désactivée, pas archivée.
- **R20 — Écran de prêt : changement de lieu rechargé par Turbo Frame** (`?lieu=<id>`, contrôleur Stimulus
  `variant-location`), compteurs par état dans les libellés (« Neuf (3) »). Les états à zéro sont marqués
  `data-empty`, pas désactivés ; le serveur refuse en cas de contournement. L'écran de retour est une page simple ;
  la pièce est présélectionnée par `?item=` ou s'il n'y en a qu'une.
- **R21 — Date de prêt = date du dernier mouvement `LoanOut`** de la pièce. Historique du joueur = opérations dont
  `operation.player` est le joueur.
- **R22 — Filtres de `/prets` : un identifiant d'équipe ou de saison inconnu affiche tout** sans erreur.

### Ventes d'occasion

- **R23 — `Sale` en OneToOne possédé par `StockOperation`**, avec `Sale::attachTo()` (`@internal`) pour renseigner le
  côté inverse : `Sale::getOperation()` n'est jamais nul en mémoire. Doctrine insère `sale` avant `stock_operation`.
- **R24 — Le commentaire n'est stocké que sur `Sale`** ; le commentaire de l'opération `SaleOut` reste nul. Acheteur
  enregistré sans espaces de bord ; commentaire vide stocké nul côté formulaire.
- **R25 — Garde de réduction 0–100 %** dans `SalePricing::price()` : hors de 0–10000 points de base, `StockException`
  « La réduction doit être comprise entre 0 et 100 %. », même si un prix final est fourni (aucun taux absurde figé).
  `discounted()` garde sa `InvalidArgumentException`.
- **R26 — Contrainte `uniq_sale_line_item`** : garantie en base qu'une pièce n'est jamais vendue deux fois.
- **R27 — Lieu inactif non vérifié pour une vente** (comme la sortie du lot 2).
- **R28 — Prix public de référence :** prix public de la saison courante s'il existe, sinon le dernier prix public par
  `season.startsOn` **sans dépasser la saison courante** (correctif F4 : une saison future n'est jamais utilisée).
- **R29 — Aperçu des prix (« Calculer »).** Le bouton « Calculer » est le premier bouton de soumission : Entrée dans un
  champ lance un aperçu, jamais une vente irréversible. L'aperçu renvoie 200, n'enregistre rien et réaffiche un
  formulaire neuf construit à partir des données soumises, donc sans erreurs de validation (« Indiquez l'acheteur »).
  Saisies illisibles : champ vide ; réduction hors plage : message de `StockException` sur la ligne. Sans réduction ni
  prix final : prix public seul, sans erreur. Total affiché seulement si toutes les lignes sont calculées. Le bouton
  d'enregistrement s'appelle « Enregistrer la vente ».
- **R30 — Choix de la pièce à vendre.** La fiche variante liste, pour `SALE_MANAGE`, les pièces d'occasion en stock avec
  un lien « Vendre » (`sale_new?item=CODE`). `?item=` l'emporte sur `?variant=` ; une pièce connue mais invendable
  (neuve, hors stock, lieu inactif) donne un formulaire vide. Le préremplissage `?variant=` exige aussi un lieu actif.
- **R31 — `datalist` des codes vendables sur tous les lieux** (et pas seulement le lieu choisi), option la plus
  simple. Chaque option porte désignation, taille, état et lieu.
- **R32 — Ligne de vente saisie par code texte uniquement** (pas de liste de choix `HS-`) ; réduction entière 0–100
  validée par `Range` puis multipliée par 100 ; prix final saisi en euros (`MoneyType`, diviseur 100).
- **R33 — Réduction « non appliquée ».** Quand un prix final remplace la réduction, la vente et l'aperçu affichent
  « 80 % (non appliquée) » (`SalePricing::isDiscountApplied`, aide en lecture seule, données inchangées).
- **R34 — Liste des ventes sans N+1** : `SaleRepository::summaries()` renvoie pièces et total TTC par vente en une
  requête groupée.
- **R35 — Joueur supprimé affiché « Nom (supprimé) » sans lien** (partiel `player/_link.html.twig`) sur les fiches
  opération, pièce, vente et la colonne détenteurs de la fiche variante. `findLoanedByVariant` lit sous
  `includingDeletedPeople`, sinon la jointure faisait disparaître la pièce.
- **R36 — Fiche variante : libellés d'état en dur** (dupliquent `ItemCondition::label`), ordre d'insertion en base.
  Le bouton « Sortie » pointe vers `stock_out_new` sans variante (voir « Points connus »).

## Points connus restants

Aucun n'affecte la justesse des données ; ce sont de petits suivis.

- **Sortie non préremplie depuis la fiche variante** : `stock_out_new` ne gère que `?item=`.
- **Formulaire de don sans bascule radio** : les champs joueur et équipe sont tous deux affichés ; le changement de lieu
  recharge le formulaire en perdant destinataire et quantité.
- **Prêts concurrents** : `findOldestInStockForUpdate` (`LIMIT 1 FOR UPDATE`) peut produire un faux « Aucune pièce » si
  deux prêts se disputent la même pièce (jamais de double prêt). `SKIP LOCKED` règlerait le cas.
- **Doublon concurrent dans un roster** (vérifier puis insérer) : 500 possible, à rattraper en `UniqueConstraintViolation`.
- **Garde de suppression d'un joueur non verrouillée** : course possible avec un prêt simultané (lecture sans risque).
- **`TeamSeason` vide après une copie de 0 joueur**, que la copie suivante prend comme saison précédente.
- **`recordLoan` / `recordGift` ne refusent pas un joueur ou une équipe supprimé** passé directement (la couche
  formulaire ne les propose pas).
- **Index des opérations** : le destinataire supprimé n'affiche pas sa ligne (pas de plantage).
- **Duplication du basculement de filtre** entre `StockQuery` (classe `final readonly`) et `IncludesDeletedPeopleTrait`
  (réactive sans condition, `Player` seulement) : à factoriser.
- **`Assert\Range` dupliqué** entre entité et formulaire (celui de l'entité n'est jamais évalué).
- **Écran de prêt** : `/retour` sans pièce prêtée affiche un formulaire vide ; l'écran de prêt reste atteignable pour une
  variante en quantité (redirection + flash).
- **Fiche joueur** : historique non paginé, date et type seulement (ni pièce ni variante).
- **Vente** : erreurs de ligne affichées après la validation du formulaire (un aller-retour de plus) ; total HT calculé
  en Twig plutôt que par `Sale::getTotalHt()` ; `Sale::$operation` non initialisé si une vente est construite hors du
  recorder ; `datalist` non bornée (échelle du club).
- **Après un refus du recorder**, `renderForm` lit encore en base alors que l'EntityManager peut être fermé : les lectures
  ne passent pas par `errorIfClosed` et `testTamperedLineIsRejected` couvre le 422 ; risque résiduel d'un 500 à voir à la
  recette.
- **Tests manquants (mineurs)** : CSRF invalide à la suppression d'un joueur ; 403 d'un lecteur sur ajout / retrait /
  copie de roster, CSRF invalide, 404 inter-équipes ; mélange `InStock` + `Loaned` dans une variante et filtre de lieu
  sur variante partiellement prêtée ; date de prêt ; falsification du lieu côté contrôleur de vente ; réduction négative
  de `discounted()` ; don sérialisé, don à un joueur, absence de destinataire, présélection `?item=`.
- **Docblocks de génériques manquants** (`Collection<int, RosterEntry>`, types de retour de `StockQuery`).
- **CSS** : `.sale-line-preview` et `.sale-total` n'ont pas de style dédié ; aucune vérification navigateur n'a été faite
  pour ce lot.
- **Retour arrière de la migration** : un `down()` laisse en base les statuts `loaned`, `gifted` et `sold` des pièces ;
  à ne pas jouer sur une base contenant des prêts, dons ou ventes.

## Recette manuelle

Faite : aucune (pas de vérification navigateur pour ce lot).

À faire :

1. Créer un joueur et une équipe, composer le roster d'une saison, le copier vers la saison suivante.
2. Prêter une pièce sérialisée depuis la fiche variante, vérifier le statut « Prêtée », la colonne « Prêtées » du stock
   et la page « Prêts en cours » ; enregistrer le retour (état et lieu).
3. Donner une pièce à un joueur et une quantité à une équipe ; vérifier que la valeur du stock les exclut.
4. Vendre une pièce d'occasion : « Calculer », vérifier prix public, réduction et total, puis « Enregistrer la vente » ;
   contrôler la fiche vente et le mouvement de stock.
5. Supprimer un joueur ayant un historique et vérifier « (supprimé) » sans lien ; tenter de supprimer un joueur qui
   détient une pièce prêtée (refus attendu).
6. Contrôler à 390 px le formulaire de vente (aperçu des prix, total) et la fiche variante.
