# Lot 4b — Stock en quantité avec état — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remplacer le suivi pièce par pièce par un stock en quantité par variante × lieu × état (équipement), avec prêt, retour, don et vente d'occasion en quantité et une détention joueur × variante.

**Architecture:** `StockLevel` gagne un état (`StockCondition`, toujours `New` pour un consommable) ; une nouvelle entité `PlayerHolding` (joueur × variante → quantité, valeur) porte les prêts en cours. Tous les mouvements restent dans `App\Stock\StockMovementRecorder` (transaction, verrous, CUMP qui suit la marchandise). La bascule se fait opération par opération (prêt/retour → don → vente → écrans pièce → entrées/sorties/transferts) : jusqu'à la tâche 6, les lectures (page Stock, fiche variante, `StockPresence`) agrègent encore pièces et niveaux, comme aujourd'hui ; la tâche 6 supprime `Item`.

**Tech Stack:** Symfony 8.1, PHP 8.5, Doctrine ORM 3.7 / PostgreSQL 18, Twig + Turbo + Stimulus (AssetMapper), ux-autocomplete, PHPUnit (WebTestCase / KernelTestCase, DAMA).

**Spec:** `docs/superpowers/specs/2026-10-28-stock-quantite-etat-design.md` (adapte le code des lots 2, 4 et 8).

## Global Constraints

- Tout changement de `StockLevel` ou de `PlayerHolding` passe par `StockMovementRecorder` (méthodes de mutation marquées `@internal réservé au StockMovementRecorder`).
- `StockOperation`, `StockMovement`, `Sale`, `SaleLine` : immuables. Date = date d'enregistrement.
- Montants en centimes (int), taux en points de base (TVA `2000` = 20 %, réduction `8000` = 80 %).
- Valeur = CUMP **quel que soit l'état** ; jamais de revalorisation liée à l'état. Valeur du stock = niveaux (tous lieux, tous états) + détentions. Donné, vendu, sorti : exclus.
- Un prêt retire la quantité au CUMP du niveau (lieu, état) et l'ajoute à la détention ; un retour la retire au CUMP de la détention et l'ajoute au niveau (lieu, nouvel état). Mouvement : `unitCostHt = round(valeur / quantité)`.
- Consommable : état toujours `New`, aucun choix d'état à l'écran, ne se prête pas, ne se vend pas. Équipement : se prête, se donne, se vend (état ≠ `New`), sort et se transfère par état ; `Damaged` se traite comme les autres états d'occasion.
- Détention à zéro : supprimée (la trace reste dans les mouvements). Un niveau de stock à zéro reste (comportement du lot 2).
- Verrouillage : toujours les niveaux de stock d'abord (tri variante id, lieu id, état), puis les détentions (tri joueur id, variante id).
- Changer Équipement ↔ Consommable est refusé tant que le produit a du stock (niveau > 0) ou des détentions.
- Droits inchangés : `STOCK_MOVE` (entrées, sorties, transferts, prêt, retour, don), `SALE_MANAGE` (ventes), `PLAYER_*` (joueurs, équipes, prêts en cours).
- Libellés UI en français ; code, classes, commits en anglais (Conventional Commits), **sans trailer de co-auteur ni mention Claude**.
- Migrations via `make db-diff`, **relues et corrigées à la main** (renommages, mappage de valeurs) ; jamais `doctrine:schema:update` ; ne jamais réinitialiser la base de dev (633 produits).
- TDD. Une tâche est finie quand `make qa` est vert. `graphify update .` après modification de code (jamais commité). Travail direct sur `develop`, pas de worktree.

## Review Focus

1. Retour d'une quantité supérieure à celle détenue, ou par un joueur qui ne détient rien de cette variante : refusé avec message, rien ne bouge. (Test : Task 2, `testReturnCannotExceedHeldQuantity`.)
2. Retour partiel : la valeur part au CUMP de la détention ; la valeur totale du stock est identique avant et après. (Test : Task 2, `testPartialReturnMovesValueAtHoldingAverage`.)
3. Prêt de 2 « Usé » alors qu'il y a 5 « Neuf » mais 1 seul « Usé » au lieu : refusé, « 1 disponible(s) » (les autres états ne comptent pas). (Test : Task 2, `testLoanRefusesConsumableAndShortStock`.)
4. Formulaire de vente trafiqué : ligne « Neuf », consommable, quantité au-delà du stock du lieu, ou état présent seulement dans un autre lieu : refusé. (Test : Task 4, `testSaleRejectsNewConsumableShortOrOtherLocation`.)
5. Passage Équipement ↔ Consommable alors qu'il reste du stock ou une détention : refusé ; permis une fois tout sorti/rendu. (Tests : Task 1, `testKindLockedWhileProductHasStock` ; Task 2, `testKindLockedWhileHeldByPlayer`.)

---

### Task 1: Vocabulaire — Équipement / Consommable et `StockCondition`

**Files:**
- Create: `src/Enum/ProductKind.php` ; Delete: `src/Enum/TrackingMode.php`
- Rename: `src/Enum/ItemCondition.php` → `src/Enum/StockCondition.php` (tous les usages)
- Rename: `src/Catalogue/TrackingModeResolver.php` → `src/Catalogue/ProductKindResolver.php` ; `tests/Unit/Catalogue/TrackingModeResolverTest.php` → `ProductKindResolverTest.php`
- Modify: `src/Entity/Product.php`, `src/Form/ProductType.php`, `src/Controller/Catalogue/ProductController.php`, `src/Import/CatalogueImportApplier.php`, `src/Import/CatalogueImportAnalyzer.php`, `src/Enum/ImportAnomalyCode.php`, `src/Stock/StockPresence.php`, `src/Stock/StockMovementRecorder.php`, `src/Label/LabelLineBuilder.php`, `src/Controller/Stock/GiftController.php`
- Modify: `templates/catalogue/product/form.html.twig`, `templates/catalogue/product/show.html.twig`, `templates/stock/variant/show.html.twig`
- Modify: `tests/Support/StockScenario.php` et tous les tests qui utilisent `TrackingMode` / `ItemCondition`
- Create: migration (écrite à la main, voir Step 3)
- Test: `tests/Functional/Catalogue/ProductEditTest.php`, `tests/Functional/Import/ImportStockRulesTest.php`

**Interfaces:**
- Produces: `enum ProductKind: string { Equipment = 'equipment'; Consumable = 'consumable' }` + `label()` (« Équipement », « Consommable »).
- Produces: `Product::getKind(): ProductKind`, `Product::setKind(ProductKind $kind): static` ; colonne `kind` (length 20), défaut `ProductKind::Consumable`. `getTrackingMode`/`setTrackingMode` disparaissent.
- Produces: `ProductKindResolver::defaultFor(Category $category): ProductKind` (racine `EQUIPEMENT` → `Equipment`, sinon `Consumable`).
- Produces: `enum StockCondition: string` — mêmes cas, valeurs et libellés qu'`ItemCondition` (`New`/`new` « Neuf », `Good`/`good` « Bon », `Worn`/`worn` « Usé », `Damaged`/`damaged` « Abîmé »).
- Produces: `StockPresence::productHasStock(Product $product): bool` — pièces `in_stock`/`loaned` ou niveau > 0 (étendu aux détentions en Task 2, pièces retirées en Task 6). Remplace `productHasMovements` pour le verrou du type (formulaire et import) ; `productHasMovements` reste si d'autres appelants l'utilisent.
- Produces: `ImportAnomalyCode::ProductKindLocked = 'tracking_mode_locked'` (valeur conservée : elle est stockée dans les analyses d'import existantes), message « Produit %s : type Équipement/Consommable conservé, le produit a du stock. ».
- Produces: `StockScenario::variant(EntityManagerInterface $em, ProductKind $kind, string $reference, string $designation, ?string $ean = null): Variant`.
- Formulaire produit : champ `kind`, libellé « Type », choix « Équipement » / « Consommable », désactivé si `productHasStock`, aide « Le produit a du stock ou du matériel prêté : son type ne peut plus changer. ». Fiches : « Type » au lieu de « Suivi ».
- Partout ailleurs : `TrackingMode::Serialized` → `ProductKind::Equipment`, `TrackingMode::Quantity` → `ProductKind::Consumable` (sans changer de comportement dans cette tâche).

- [ ] **Step 1: Write the failing tests**
  - `ProductEditTest::testKindLockedWhileProductHasStock` : consommable avec 3 en stock (entrée via `StockScenario::receive`) → le champ `product[kind]` est `disabled` et un POST qui force `equipment` laisse `consumable` en base.
  - `ProductEditTest::testKindEditableAfterStockLeft` : même produit, sortie des 3 → le champ est actif ; POST `equipment` → `ProductKind::Equipment` en base (le produit a des mouvements mais plus de stock).
  - `ProductKindResolverTest` : catégorie sous `EQUIPEMENT` → `Equipment` ; autre racine → `Consumable`.
  - `ImportStockRulesTest` : adapter le test de l'anomalie de verrou (produit sans catégorie **avec stock** → anomalie `ProductKindLocked` ; avec mouvements mais sans stock → pas d'anomalie, type par défaut appliqué).
- [ ] **Step 2: Run** `make test c="--filter='ProductEditTest|ProductKindResolverTest|ImportStockRulesTest'"` → FAIL (classes absentes).
- [ ] **Step 3: Implement** enum, renommages, `productHasStock`, formulaire et fiches. Migration : `make db-diff` puis remplacer le drop/add par `ALTER TABLE product RENAME COLUMN tracking_mode TO kind` + `UPDATE product SET kind = CASE kind WHEN 'serialized' THEN 'equipment' ELSE 'consumable' END` (down symétrique) ; reporter tout index qui portait sur `tracking_mode`.
- [ ] **Step 4: Run** `make db-migrate`, puis `make sf c="dbal:run-sql 'SELECT kind, COUNT(*) FROM product GROUP BY kind'"` → `equipment 381`, `consumable 252`. Filtre → PASS ; `make qa` vert.
- [ ] **Step 5: Commit** `refactor(catalogue): replace tracking mode with equipment/consumable kind`

### Task 2: Prêt et retour en quantité, détentions

**Files:**
- Create: `src/Entity/Trait/ValuedQuantityTrait.php`, `src/Entity/PlayerHolding.php`, `src/Repository/PlayerHoldingRepository.php`
- Modify: `src/Entity/StockLevel.php`, `src/Entity/StockMovement.php`, `src/Repository/StockLevelRepository.php`
- Modify: `src/Stock/Input/StockLoan.php`, `src/Stock/Input/StockReturn.php`, `src/Stock/StockMovementRecorder.php` (`recordLoan`, `recordReturn`)
- Modify: `src/Controller/Stock/LoanController.php`, `src/Controller/Stock/VariantFormScreen.php`, `src/Form/Stock/StockLoanType.php`, `src/Form/Stock/StockReturnType.php`, `src/Form/Stock/ConditionChoices.php`
- Modify: `src/Controller/Player/PlayerController.php` (show, delete), `src/Controller/Player/LoanListController.php`, `src/Controller/Stock/VariantSheetController.php`
- Modify: `src/Stock/StockQuery.php`, `src/Stock/StockPresence.php`, `src/Repository/VariantRepository.php` (règle « étiquetable »)
- Modify: `templates/stock/loan/form.html.twig`, `templates/stock/loan/return.html.twig`, `templates/player/player/show.html.twig`, `templates/player/loan/index.html.twig`, `templates/stock/variant/show.html.twig`, CSS des radios d'état
- Create: migration
- Test: `tests/Functional/Stock/LoanGiftRecorderTest.php`, `LoanGiftControllerTest.php`, `tests/Functional/Player/PlayerSheetTest.php`, `tests/Functional/Stock/StockPageTest.php`, `StockSchemaTest.php`, `tests/Unit/Stock/StockLevelTest.php`, `tests/Functional/Catalogue/ProductEditTest.php` ; Modify: `tests/Support/StockFixtures.php`

**Interfaces:**
- Consumes: `ProductKind`, `StockCondition`, `StockPresence::productHasStock` (Task 1).
- Produces: `trait ValuedQuantityTrait` — colonnes `quantity` et `valueHt` (int, défaut 0), `getQuantity()`, `getValueHt()`, `averageCostHt(): ?int`, `add(int $quantity, int $valueHt): void`, `remove(int $quantity): int` : déplacés tels quels depuis `StockLevel`.
- Produces: `StockLevel::__construct(Variant $variant, Location $location, StockCondition $condition = StockCondition::New)`, `getCondition(): StockCondition` ; colonne `condition` (length 20, défaut `'new'`) ; unique `uniq_stock_level_variant_location_condition` (`variant_id`, `location_id`, `condition`) à la place de l'ancienne.
- Produces: `PlayerHolding::__construct(Player $player, Variant $variant)`, `getId()`, `getPlayer()`, `getVariant()` + trait ; unique `uniq_player_holding` (`player_id`, `variant_id`) ; FK `RESTRICT`.
- Produces: `StockMovement` : colonne `condition` (défaut `'new'`), paramètre de constructeur **en dernier** `StockCondition $condition = StockCondition::New`, `getCondition(): StockCondition`.
- Produces: `StockLevelRepository::findForUpdate(Variant $variant, Location $location, StockCondition $condition = StockCondition::New): ?StockLevel` ; `quantitiesByCondition(Variant $variant, Location $location): array<string, int>` (valeur d'état → quantité > 0).
- Produces: `PlayerHoldingRepository` : `findForUpdate(Player, Variant): ?PlayerHolding` ; `findByPlayer(Player): list<PlayerHolding>` (variante et produit joints) ; `findByVariant(Variant): list<PlayerHolding>` (joueurs supprimés inclus, tri nom puis prénom) ; `listQuery(?Team, ?Season): QueryBuilder` (mêmes filtres que l'ancien `ItemRepository::loanedQuery`) ; `loanDates(list<PlayerHolding>): array<int, \DateTimeImmutable>` (id de détention → date de la dernière opération `LoanOut` de ce joueur pour cette variante) ; `playerHoldsAnything(Player): bool`.
- Produces: `StockLoan(Location $location, Player $player, Variant $variant, StockCondition $condition, int $quantity, ?string $comment = null)` ; `StockReturn(Player $player, Variant $variant, int $quantity, Location $location, StockCondition $condition, ?string $comment = null)`.
- Recorder — messages exacts :
  - consommable : `« %s » est un consommable : il ne se prête pas.`
  - quantité < 1 : `La quantité doit être au moins 1.`
  - stock court : `Stock insuffisant pour « %s » (%s) à « %s » : %d disponible(s).` (libellé variante, libellé d'état, lieu)
  - retour excessif : `%s détient %d « %s » : retour de %d impossible.` (nom complet, quantité détenue — 0 sans détention —, libellé variante, quantité demandée)
  - lieu inactif : message existant.
  Prêt : opération `LoanOut` (source = lieu, joueur), mouvement avec l'état du niveau. Retour : opération `LoanReturn` (destination = lieu, joueur), mouvement avec le nouvel état ; niveau créé s'il n'existe pas ; détention supprimée à 0.
- `VariantFormScreen::selectedLocation()` : sans lieu soumis ni `?lieu=`, renvoie le lieu actif s'il est unique. `countsAt(StockLevelRepository $levels, Variant, ?Location): array<string, int>` remplace la version `ItemRepository`.
- `ConditionChoices::options()` : choix à quantité 0 rendus `disabled` ; radios espacés (classe CSS sur le groupe, espacement pris dans les variables de la charte).
- Écran Prêter : lieu, joueur, état (« Usé (2) »), quantité (défaut 1). Écran Retour (`stock_loan_return`, `?joueur=<id>`) : joueur parmi les détenteurs (présélectionné s'il est unique ou passé en paramètre), quantité (défaut 1), lieu, nouvel état ; redirection vers la fiche joueur.
- Fiche joueur : tableau « Matériel détenu » (article lié à la fiche variante, quantité, valeur au coût, date du dernier prêt, bouton « Retour » pour `STOCK_MOVE` vers `?joueur=`). Suppression refusée si `playerHoldsAnything` (message existant).
- `/prets` : une ligne par détention (joueur, article, quantité, depuis) ; filtres équipe/saison inchangés.
- Fiche variante : tableau « Détenteurs » (joueur via `player/_link.html.twig`, quantité, lien « Retour »). Le stock par lieu inclut les niveaux par état de l'équipement (« Neuf 3 · Usé 2 »).
- `StockQuery` : quantité/valeur « Prêtées » et `totalValueHt(null)` incluent les détentions ; une variante entièrement détenue reste listée (sans filtre de lieu). `StockPresence::variantIdsInStock` et `productHasStock`, et la règle « étiquetable » de `VariantRepository`, comptent les détentions.
- Produces: `StockFixtures::level(EntityManagerInterface $em, Variant $variant, Location $location, int $quantity, int $unitCostHt, StockCondition $condition = StockCondition::New): StockLevel` — seule façon de créer du stock d'équipement en quantité avant la Task 6 (l'entrée crée encore des pièces).

- [ ] **Step 1: Write the failing tests** (recorder : `KernelTestCase` ; écrans : `WebTestCase`). Les anciens tests de prêt/retour pièce sont remplacés.
  - `testLoanMovesQuantityAndValueToHolding` : niveau Usé 3 / 30000 au « Local matériel » ; prêt de 2 à Léo → niveau 1 / 10000, détention 2 / 20000, mouvement `LoanOut` qté 2, état `worn`, `unitCostHt` 10000.
  - `testLoanRefusesConsumableAndShortStock` : consommable → message consommable ; équipement Neuf 5 + Usé 1, prêt de 2 Usé → `Stock insuffisant pour « … » (Usé) à « Local matériel » : 1 disponible(s).`
  - `testPartialReturnMovesValueAtHoldingAverage` : détention 2 / 20000 ; retour de 1 au « Local matériel » en Abîmé → détention 1 / 10000, niveau Abîmé 1 / 10000 ; `StockQuery::totalValueHt(null)` identique avant et après.
  - `testReturnCannotExceedHeldQuantity` : Léo détient 1, retour de 2 → `Léo Martin détient 1 « … » : retour de 2 impossible.` ; Hugo (rien) → `… détient 0 …` ; aucune écriture.
  - `testHoldingDisappearsWhenFullyReturned` : retour de toute la quantité → plus de ligne `player_holding`.
  - `testLoanFormPreselectsSingleActiveLocationAndDisablesEmptyConditions` : un seul lieu actif → option sélectionnée ; état à 0 → `disabled`.
  - `testReturnFromPlayerSheetPreselectsPlayer` : `?joueur=<id>` → joueur sélectionné ; POST → redirection fiche joueur.
  - `PlayerSheetTest::testSheetListsHoldingsWithQuantityAndLoanDate` ; `testPlayerWithHoldingCannotBeDeleted`.
  - `LoanGiftControllerTest::testLoanListShowsHoldingsFilteredByTeam`.
  - `StockPageTest::testFullyLoanedVariantStaysListedAndValued` (réécrit avec niveaux + détention).
  - `ProductEditTest::testKindLockedWhileHeldByPlayer` : stock entièrement prêté → type verrouillé.
  - `StockSchemaTest` : nouvelle contrainte unique de `stock_level`, table `player_holding`.
- [ ] **Step 2: Run** `make test c="--filter='LoanGift|PlayerSheet|StockPage|StockSchema|StockLevelTest|ProductEditTest'"` → FAIL.
- [ ] **Step 3: Implement** modèle, dépôts, recorder, formulaires, écrans ; `make db-diff`, relire la migration (colonnes `condition` avec défaut `'new'`, remplacement de la contrainte unique, table `player_holding`).
- [ ] **Step 4: Run** `make db-migrate`, le filtre → PASS ; `make qa` vert.
- [ ] **Step 5: Commit** `feat(stock): lend and return equipment in quantity with player holdings`

### Task 3: Don en quantité

**Files:**
- Modify: `src/Stock/StockMovementRecorder.php` (`recordGift`), `src/Controller/Stock/GiftController.php`, `src/Form/Stock/StockGiftType.php`, `templates/stock/gift/form.html.twig`
- Create: `assets/controllers/recipient_controller.js`
- Test: `tests/Functional/Stock/LoanGiftRecorderTest.php`, `LoanGiftControllerTest.php`

**Interfaces:**
- Consumes: `StockLevelRepository::findForUpdate(..., StockCondition)`, `quantitiesByCondition`, `selectedLocation`/`countsAt` (Task 2).
- `StockGift` garde sa forme (`condition: ?StockCondition`). Recorder : quantité ≥ 1 pour tous ; équipement sans état → `Choisissez l'état.` ; consommable : état ignoré, `New`. Retrait du niveau (variante, lieu, état) avec le message « Stock insuffisant » de la Task 2 ; mouvement `GiftOut` avec l'état. Le message R14 (« Une pièce se donne à l'unité ») disparaît.
- `StockGiftType` : `quantity` toujours présent (défaut 1) ; `condition` (via `ConditionChoices`) seulement pour l'équipement ; option `serialized` renommée `equipment`.
- Bascule joueur/équipe : contrôleur Stimulus `recipient` sur le formulaire (cibles `player` et `team`, action sur le radio `recipient`) qui masque le champ non choisi ; sans JavaScript les deux champs restent visibles.

- [ ] **Step 1: Write the failing tests**
  - `testGiftEquipmentInQuantityWithCondition` : niveau Usé 3 ; don de 2 à l'équipe U15 → niveau 1, mouvement `GiftOut` qté 2 état `worn`, opération liée à l'équipe.
  - `testGiftConsumableIgnoresCondition` : consommable 5, don de 2 avec `condition = Worn` → niveau `new` à 3.
  - `testGiftFormHasRecipientToggleAndQuantity` : équipement → `data-controller="recipient"`, champs `quantity` et `condition` présents ; consommable → pas de `condition`.
- [ ] **Step 2: Run** `make test c="--filter=LoanGift"` → FAIL.
- [ ] **Step 3: Implement** recorder, formulaire, contrôleur, Stimulus.
- [ ] **Step 4: Run** le filtre → PASS ; `make qa` vert.
- [ ] **Step 5: Commit** `feat(stock): gift equipment and consumables in quantity`

### Task 4: Vente d'occasion par variante × état × quantité

**Files:**
- Modify: `src/Entity/SaleLine.php`, `src/Entity/Sale.php`, `src/Stock/Input/StockSaleLine.php`, `src/Stock/StockMovementRecorder.php` (`recordSale`)
- Modify: `src/Form/Sale/SaleLineType.php`, `src/Form/Sale/SaleLineMapper.php`, `src/Form/Sale/SaleType.php`, `src/Sale/SalePreviewer.php`, `src/Sale/SaleLinePreview.php`, `src/Controller/Sale/SaleController.php`, `src/Repository/SaleRepository.php`, `src/Repository/StockLevelRepository.php`
- Modify: `src/Controller/Stock/VariantSheetController.php` (occasion lue sur les niveaux)
- Modify: `templates/sale/form.html.twig`, `_line.html.twig`, `_lines.html.twig`, `show.html.twig`, `index.html.twig`, `templates/stock/variant/show.html.twig`
- Create: migration
- Test: `tests/Functional/Sale/SaleRecorderTest.php`, `SaleControllerTest.php`

**Interfaces:**
- Consumes: niveaux par état (Task 2), `SalePricing::price(Variant, ?int $discountRate, ?int $finalPriceTtc): SalePrice` et `publicReferencePrice(Variant): ?int` (inchangés : prix **unitaire**).
- Produces: `StockSaleLine(Variant $variant, StockCondition $condition, int $quantity, ?int $discountRate, ?int $finalPriceTtc)` (`finalPriceTtc` = prix final **unitaire**).
- Produces: `SaleLine::__construct(Sale $sale, Variant $variant, StockCondition $condition, int $quantity, SalePrice $unitPrice)` ; colonnes `variant_id` (FK `RESTRICT`), `condition`, `quantity`, et les prix unitaires existants ; `getVariant()`, `getCondition()`, `getQuantity()`, `getTotalTtc(): int` (= quantité × `priceTtc`), `getTotalHt(): int`. `item_id` et `uniq_sale_line_item` disparaissent (aucune vente en base de dev). `Sale::getTotalTtc()` additionne les totaux de ligne.
- Recorder — messages exacts :
  - état neuf : `« %s » neuf : seule l'occasion se vend.`
  - consommable : `« %s » est un consommable : il ne se vend pas.`
  - doublon variante × état : `« %s » (%s) figure deux fois : augmentez la quantité.`
  - stock : message « Stock insuffisant » de la Task 2 (lieu de la vente).
  Lignes verrouillées dans l'ordre global ; une opération `SaleOut`, un mouvement par ligne (qté, état, CUMP retiré).
- Produces: `StockLevelRepository::findSecondHand(?Location $location): list<StockLevel>` (équipement, état ≠ `New`, quantité > 0, variante/produit/lieu joints).
- `SaleLineType` : option `sellable: list<StockLevel>` ; champ `stock` (`ChoiceType`, valeur `"<variantId>-<condition>"`, libellé « Désignation · taille — Usé (3) ») ; `quantity` (défaut 1, ≥ 1) ; `discount` ; `finalPrice` libellé « Prix final unitaire TTC (€) » avec `'currency' => false` (supprime le « € » orphelin). Le choix du lieu recharge le formulaire (Turbo Frame + contrôleur `variant-location`, paramètre `lieu`) ; sans lieu, aide « Choisissez d'abord le lieu. ».
- `SaleController::new` : préremplissage `?variant=<id>&etat=<value>&lieu=<id>` ; `initialData` n'utilise plus `ItemRepository`. `SalePreviewer`/`SaleLinePreview` portent `variant`, `condition`, `quantity`, prix unitaire et total de ligne.
- `SaleRepository::summaries()` : `articles` = `SUM(quantity)`, `totalTtc` = `SUM(quantity * priceTtc)`.
- Fiche variante (`SALE_MANAGE`) : « Occasion en stock » = une ligne par lieu × état ≠ Neuf (quantité, lien « Vendre » vers `?variant&etat&lieu`).

- [ ] **Step 1: Write the failing tests**
  - `testSaleOfSecondHandQuantityFreezesUnitPrices` : niveau Usé 3 au « Local matériel », prix public 10000 TTC, TVA 2000 ; vente de 2 à 80 % → ligne qté 2, `priceTtc` 2000, `priceHt` 1667, total TTC 4000 ; niveau Usé 1 ; mouvement `SaleOut` qté 2 état `worn`.
  - `testSaleRejectsNewConsumableShortOrOtherLocation` : une ligne Neuf, une ligne consommable, 4 Usé pour 3 en stock, Usé présent seulement à « Vestiaire » → chaque cas refusé avec son message, aucune vente créée.
  - `testDuplicateVariantConditionLineRejected`.
  - `testSaleWithoutPublicPriceNeedsFinalPrice` (repris du lot 4, en quantité).
  - `SaleControllerTest::testSaleFormListsSecondHandAtChosenLocation` : `?lieu=` → options « Usé (3) » de ce lieu seulement ; libellé « Prix final unitaire TTC (€) » et aucun `€` hors libellé dans le bloc du prix final.
  - `SaleControllerTest::testSaleShowAndIndexShowQuantities` : fiche (qté, unitaire, total) ; liste (nombre d'articles, total).
- [ ] **Step 2: Run** `make test c="--filter='Sale'"` → FAIL.
- [ ] **Step 3: Implement** ; `make db-diff`, relire la migration de `sale_line`.
- [ ] **Step 4: Run** `make db-migrate`, le filtre → PASS ; `make qa` vert.
- [ ] **Step 5: Commit** `feat(sale): sell second-hand stock by variant, condition and quantity`

### Task 5: Fin des écrans et codes de pièce

**Files:**
- Delete: `src/Controller/Stock/ItemController.php`, `templates/stock/item/show.html.twig`, `src/Label/LabelMode.php`, `tests/Functional/Stock/ItemPageTest.php`
- Modify: `src/Label/LabelLine.php`, `src/Label/LabelLineBuilder.php`, `src/Label/BarcodeChoice.php`, `src/Controller/Label/LabelController.php`, `templates/label/print.html.twig`, `assets/controllers/label_format_controller.js` (si le mode y est lu)
- Modify: `src/Catalogue/CodeResolver.php`
- Modify: `src/Controller/Stock/StockOperationScreen.php`, `src/Controller/Stock/OutController.php`, `src/Controller/Stock/TransferController.php`
- Modify: `templates/stock/operation/show.html.twig`, `templates/catalogue/product/show.html.twig` (tableau des pièces retiré), tout lien restant vers `stock_item_show`
- Test: `tests/Functional/Label/*`, `tests/Unit/Label/BarcodeChoiceTest.php`, `tests/Functional/Catalogue/CodeResolverTest.php`, `tests/Functional/Stock/OutTransferControllerTest.php`

**Interfaces:**
- Produces: `LabelLine(Variant $variant, int $copies)` ; `LabelLineBuilder::forVariants(list<Variant>): list<LabelLine>`, `forProducts(list<Product>): list<LabelLine>`, `forOperation(StockOperation): list<LabelLine>`, `withoutValidEan(): list<LabelLine>` ; `forItem`, `hasItemChoice` et `BarcodeChoice::forItem` disparaissent.
- `LabelController` : plus de paramètre `mode` ni `piece` ; lignes postées de type `variante` seulement.
- Produces: `CodeResolver::resolve(string $code): ?Variant` (`HV-…` ou EAN) ; un code `HS-…` ne résout plus rien.
- `StockOperationScreen::initialData()` : `?variant=<id>` (et `?lieu=<id>`) préremplit la ligne et le lieu, à la place de `?item=HS-…`.
- Route `stock_item_show` supprimée ; l'historique des opérations n'a plus de colonne « Pièce ».

- [ ] **Step 1: Write the failing tests**
  - `LabelControllerTest::testPrintOffersVariantLabelsOnly` : pas de sélecteur de mode ; `?mode=pieces` ignoré ; une variante d'équipement en stock donne une ligne de variante.
  - `CodeResolverTest::testPieceCodeNoLongerResolves` : `HS-000001` → `null` ; `HV-…` et EAN inchangés.
  - `OutTransferControllerTest::testOutFormPrefilledFromVariant` : `?variant=<id>&lieu=<id>` → ligne et lieu préremplis.
  - `testItemPageIsGone` : `GET /stock/pieces/HS-000001` → 404.
- [ ] **Step 2: Run** `make test c="--filter='Label|CodeResolver|OutTransfer'"` → FAIL.
- [ ] **Step 3: Implement** les suppressions et adaptations.
- [ ] **Step 4: Run** le filtre → PASS ; `make qa` vert.
- [ ] **Step 5: Commit** `refactor(stock): drop piece pages, piece labels and HS- codes`

### Task 6: Entrées, sorties, transferts par état ; suppression d'`Item`

**Files:**
- Delete: `src/Entity/Item.php`, `src/Repository/ItemRepository.php`, `src/Enum/ItemStatus.php`
- Modify: `src/Stock/Input/StockEntryLine.php`, `src/Stock/Input/StockLine.php`, `src/Stock/StockMovementRecorder.php` (`recordEntry`, `recordOut`, `recordTransfer`, `assertLines`, `lockAndCheck`, `planLine` ; plus aucune référence à `Item`)
- Modify: `src/Entity/StockMovement.php` (paramètre et colonne `item` retirés), `src/Repository/StockMovementRepository.php` (`findByItem` retiré)
- Modify: `src/Form/Stock/StockEntryLineType.php`, `src/Form/Stock/StockLineType.php`, `src/Form/Stock/StockLineMapper.php`, `src/Controller/Stock/EntryController.php`, `templates/stock/entry/_line.html.twig`, `templates/stock/_operation_line.html.twig`
- Modify: `src/Stock/StockQuery.php` (`rows`, `totalValueHt`, `byVariant`), `src/Stock/StockPresence.php`, `src/Repository/LocationRepository.php`, `src/Repository/VariantRepository.php`, `src/Controller/Stock/VariantSheetController.php`, `templates/catalogue/product/show.html.twig`
- Modify: `tests/Support/StockScenario.php` (`receive` crée des niveaux pour tout produit)
- Create: migration
- Test: `tests/Functional/Stock/StockEntryRecorderTest.php`, `StockOutTransferRecorderTest.php`, `EntryControllerTest.php`, `OutTransferControllerTest.php`, `StockSchemaTest.php`, `StockPageTest.php`, `tests/Functional/Catalogue/ProductBrowseTest.php`, et tout test encore fondé sur des pièces

**Interfaces:**
- Produces: `StockEntryLine(Variant $variant, int $quantity, ?int $unitCostHt = null, StockCondition $condition = StockCondition::New)` (plus de numéros de série) ; `StockLine(Variant $variant, int $quantity, StockCondition $condition = StockCondition::New)` (plus de pièce).
- Recorder : l'état d'une ligne de consommable est forcé à `New` ; entrée → niveau (variante, lieu, état) ; sortie et transfert verrouillent et contrôlent par (variante, état) avec le message « Stock insuffisant » de la Task 2 ; le transfert conserve l'état. Mouvements avec leur état.
- `StockEntryLineType` : champ « Numéros de série » retiré ; « État » reste (défaut Neuf, aide « Ignoré pour un consommable. »). `StockLineType` : `itemCode` retiré, champ `condition` ajouté (même libellé et aide). `StockLineMapper` ne dépend plus d'`ItemRepository`.
- Produces: `StockQuery::byVariant(Product $product): array<int, array{levels: list<StockLevel>, held: int}>` ; fiche produit : par variante, stock par lieu (ventilé par état pour l'équipement) et « dont N prêté(s) ».
- `StockQuery`, `StockPresence`, `LocationRepository::hasStock`, règle « étiquetable » : niveaux + détentions uniquement.
- Migration : supprime `stock_movement.item_id`, la table `item` et la séquence `item_code_seq` (aucune pièce en base de dev, vérifié le 2026-10-28).

- [ ] **Step 1: Write the failing tests**
  - `testEquipmentEntryCreatesConditionLevel` : dotation de 2 Usé → niveau `worn` 2 / 0 ; achat de 3 Neuf à 5000 → niveau `new` 3 / 15000.
  - `testConsumableLinesAreAlwaysNew` : entrée et sortie d'un consommable avec `condition = Worn` → niveau et mouvements en `new`.
  - `testOutAndTransferByCondition` : transfert de 1 Usé du « Local matériel » vers « Vestiaire » → niveau Usé à Vestiaire 1, CUMP conservé ; sortie de 1 Abîmé sans stock Abîmé → `Stock insuffisant pour « … » (Abîmé) à « Local matériel » : 0 disponible(s).`
  - `EntryControllerTest` : plus de champ de numéros de série ; `OutTransferControllerTest` : plus de champ de code de pièce, champ État présent.
  - `ProductBrowseTest::testProductSheetShowsStockByConditionAndHeld`.
  - `StockSchemaTest::testItemTableIsGone`.
- [ ] **Step 2: Run** `make test c="--filter='StockEntry|StockOutTransfer|EntryController|OutTransfer|StockSchema|ProductBrowse'"` → FAIL.
- [ ] **Step 3: Implement** ; `make db-diff`, relire la migration (ordre : FK et colonne de `stock_movement`, puis `item`, puis séquence).
- [ ] **Step 4: Run** `make db-migrate` ; `grep -rnE '\bItem(Repository|Status)?\b|HS-' src templates` → aucun résultat hors faux positifs ; `make qa` vert.
- [ ] **Step 5: Commit** `refactor(stock): count all stock by condition and remove serialized items`

### Task 7: Fiche variante et page Stock — états et actions

**Files:**
- Modify: `src/Controller/Stock/VariantSheetController.php`, `templates/stock/variant/show.html.twig`
- Modify: `src/Stock/StockRow.php`, `src/Stock/StockQuery.php` (`rows`), `templates/stock/index.html.twig`
- Modify: feuille de style (espacement `.variant-actions`)
- Test: `tests/Functional/Stock/VariantSheetTest.php`, `StockPageTest.php`

**Interfaces:**
- Consumes: niveaux, détentions, `findSecondHand` (Tasks 2, 4, 6).
- Fiche variante — boutons masqués quand ils sont sans objet : Prêter (`STOCK_MOVE`, équipement, stock > 0) ; Retour (`STOCK_MOVE`, au moins un détenteur) ; Donner et Sortie (`STOCK_MOVE`, stock > 0 ; Sortie vers `stock_out_new?variant=<id>`) ; Vendre (`SALE_MANAGE`, équipement, occasion en stock). Stock par lieu : « Local matériel : Neuf 3 · Usé 2 » (équipement), « Local matériel : 5 » (consommable). Méta « Type ». Bloc d'actions séparé du bloc d'infos.
- Produces: `StockRow::$conditionsByLocation: array<int, array<string, int>>` (lieu id → état → quantité, équipement seulement). Page Stock : sous la quantité d'un lieu, ligne discrète « Neuf 3 · Usé 2 » quand l'équipement a de l'occasion dans ce lieu.

- [ ] **Step 1: Write the failing tests**
  - `VariantSheetTest::testActionsHiddenWithoutStock` : variante sans stock ni détenteur → aucun des liens Prêter/Retour/Donner/Sortie/Vendre.
  - `VariantSheetTest::testEquipmentSheetShowsConditionsHoldersAndActions` : Neuf 3 + Usé 2, un détenteur → « Neuf 3 · Usé 2 », Prêter, Retour, Donner, Sortie, Vendre (manager).
  - `VariantSheetTest::testConsumableSheetShowsPlainQuantityWithoutLoan` : « Local matériel : 5 », pas de Prêter ni Vendre.
  - `StockPageTest::testEquipmentRowShowsConditionBreakdown`.
- [ ] **Step 2: Run** `make test c="--filter='VariantSheet|StockPage'"` → FAIL.
- [ ] **Step 3: Implement**.
- [ ] **Step 4: Run** le filtre → PASS ; `make qa` vert ; vérifier la fiche variante, le prêt, le don et la vente dans le navigateur (chrome-devtools MCP, https://hockey-staff.localhost) : boutons, espacement, radios, bascule joueur/équipe.
- [ ] **Step 5: Commit** `feat(stock): show stock by condition and contextual actions on variant sheet`

### Task 8: Décisions et specs

**Files:**
- Create: `docs/superpowers/decisions/2026-10-28-lot4b-decisions.md`
- Modify: `docs/superpowers/specs/2026-10-28-stock-quantite-etat-design.md` (statut livré), `2026-10-27-hockey-staff-domain-design.md` (lot 4b livré), `2026-10-27-lot2-stock-mouvements-design.md`, `2026-10-28-lot4-joueurs-attributions-ventes-design.md`, `2026-10-27-lot8-codes-barres-etiquettes-design.md` (mention « remplacé par `2026-10-28-stock-quantite-etat-design.md` » sur les parties pièce par pièce)

- [ ] **Step 1:** Consigner les décisions prises pendant le lot (présentation de l'état sur la page Stock, ordre de verrouillage, valeur `tracking_mode_locked` conservée, messages, choix faits par les implémenteurs).
- [ ] **Step 2:** Mettre à jour les specs ; `make qa` vert.
- [ ] **Step 3: Commit** `docs: record lot 4b decisions and mark lot as delivered`
