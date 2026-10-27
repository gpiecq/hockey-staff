# Lot 2 — Stock et mouvements — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Enregistrer ce que le club possède : lieux, entrées (achat, dotation), sorties motivées, transferts, pièces sérialisées `HS-`, niveaux de stock valorisés au CUMP, historique. Brancher aussi les règles laissées en attente par les lots 1 et 7.

**Architecture:** Un module `App\Stock` porte un service unique, `StockMovementRecorder`. Il écrit dans une transaction, sous verrou, une `StockOperation` (en-tête), ses `StockMovement` (lignes), les `Item` (pièces) et les `StockLevel` (variante × lieu : quantité + valeur totale). Les contrôleurs `App\Controller\Stock\*` se contentent de traduire les formulaires en objets d'entrée du recorder. Les vues (stock, pièce, opérations) lisent via des requêtes dédiées. L'import du lot 1 consulte le stock pour archiver ou désactiver les variantes absentes.

**Tech Stack:** Symfony 8.1, PHP 8.5, Doctrine ORM 3.7 / PostgreSQL 18, Twig + Turbo + Stimulus (AssetMapper), ux-autocomplete, PHPUnit (WebTestCase / KernelTestCase, DAMA).

**Spec:** `docs/superpowers/specs/2026-10-27-lot2-stock-mouvements-design.md` (parente : `2026-10-27-hockey-staff-domain-design.md`)

## Global Constraints

- Toute variation de stock passe par `StockMovementRecorder`. Aucun autre code ne change le statut ou le lieu d'un `Item`, ni un `StockLevel` (spec § 5).
- `StockOperation` et `StockMovement` : aucun setter public, aucune route de modification ou de suppression.
- Montants : `int` en centimes HT ; jamais de float pour stocker ou cumuler.
- Ni étiquette, ni PDF, ni scan dans ce lot (lots 8 et 3).
- Droits : `STOCK_VIEW` = `ROLE_VIEWER`, `STOCK_MOVE` = `ROLE_STOCK`, `LOCATION_MANAGE` = `ROLE_MANAGER`.
- Libellés UI en français. Code, classes et commits en anglais (Conventional Commits), **sans trailer de co-auteur**.
- Migrations générées par `make db-diff` puis relues ; jamais `doctrine:schema:update`. Ne pas réinitialiser la base de dev.
- TDD. Une tâche est finie quand `make qa` est vert. `graphify update .` après toute modification de code (le graphe n'est jamais commité).
- Travail sur `develop` uniquement.

## Review Focus

1. Double soumission d'une sortie sur la dernière unité : la seconde est refusée avec un message, le stock reste à 0 et ne devient jamais négatif. (Test : Task 5, `testSecondOutOfLastUnitIsRefused`.)
2. Entrée de plusieurs lignes dont une invalide (achat sans tarif ni coût saisi) : **rien** n'est écrit, pas même les lignes valides. (Test : Task 4, `testInvalidLineRollsBackWholeEntry`.)
3. Tarif de la saison modifié après un achat : la valeur du stock ne bouge pas. (Test : Task 8, `testStockValueIgnoresTariffChange`.)
4. Variante, produit ou lieu qui a des mouvements : la suppression (douce) est refusée, sinon l'historique perdrait sa cible. L'historique d'une variante archivée reste lisible. (Tests : Task 10, `testVariantWithMovementsCannotBeDeleted` ; Task 4, `testLocationWithMovementsCannotBeDeleted` ; Task 9, `testOperationShowsArchivedVariant`.)
5. Sortie ou transfert d'une pièce absente du lieu source, ou déjà sortie : refusé, rien n'est écrit. (Test : Task 5, `testItemFromOtherLocationIsRefused`.)

---

### Task 1: Lieux, droits du stock et menu

**Files:**
- Create: `src/Entity/Location.php`, `src/Repository/LocationRepository.php`
- Create: `src/Security/Voter/StockVoter.php`
- Create: `src/Form/LocationType.php`, `src/Controller/Stock/LocationController.php`, `templates/stock/location/{index,form}.html.twig`
- Modify: `templates/_nav.html.twig` (groupe Stock)
- Create: migration via `make db-diff`
- Test: `tests/Functional/Stock/LocationControllerTest.php`, `tests/Support/StockFixtures.php`

**Interfaces:**
- Produces: `Location`, sur le modèle de `Supplier` (`TimestampableBlameableTrait`, soft delete, unique partiel sur `name` `WHERE deleted_at IS NULL`) : `getId(): ?int`, `getName(): string` (150 car., `trim`, **sans** passage en majuscules), `getDescription(): ?string` (255 car.), `isActive(): bool` (défaut `true`), setters fluides, `__toString()` qui renvoie le nom.
- Produces: `StockVoter::VIEW = 'STOCK_VIEW'`, `MOVE = 'STOCK_MOVE'`, `LOCATION_MANAGE = 'LOCATION_MANAGE'` (même forme que `CatalogueVoter`).
- Produces: `LocationRepository::findActive(): list<Location>` (triés par nom).
- Produces: `StockFixtures::location(EntityManagerInterface $em, string $name = 'Local matériel', bool $active = true): Location`.
- Routes : `stock_location_index` (`GET /lieux`), `stock_location_new`, `stock_location_edit`, `stock_location_delete`. Même schéma que `SupplierController` : confirmation en GET, suppression en POST avec CSRF.
- Menu : groupe `{title: 'Stock', role: 'ROLE_VIEWER'}` placé juste après le groupe sans titre. Entrée Lieux : `prefix: 'stock_location'`, `role: 'ROLE_MANAGER'`. Les entrées Stock et Opérations arrivent aux Tasks 8 et 9.

- [ ] **Step 1: Write the failing tests**
  - `testViewerCannotManageLocations` : un Viewer reçoit 403 sur `GET /lieux` ; pas de lien `/lieux` dans `.sidebar`.
  - `testManagerCreatesLocation` : POST `{name: 'Local matériel', description: 'Sous la tribune'}` → redirection ; la liste contient « Local matériel » (casse conservée).
  - `testDuplicateNameIsRejected` : deux lieux « Vestiaire » → 422, « Ce nom est déjà utilisé. ».
  - `testStockRoleCannotManageLocations` : `ROLE_STOCK` reçoit 403 sur `/lieux/new`.
  - `testEmptyLocationCanBeDeleted` : POST de suppression → le lieu disparaît de la liste.
- [ ] **Step 2: Run** `make test c="--filter=LocationControllerTest"` → FAIL.
- [ ] **Step 3: Implement** l'entité, la migration (à relire : seulement la table `location` et son index unique partiel), le voter, le formulaire (`name`, `description`, `active`), le contrôleur, les templates et le menu.
- [ ] **Step 4: Run** le filtre → PASS ; `make qa` vert.
- [ ] **Step 5: Commit** `feat(stock): add locations and stock permissions`

### Task 2: Modèle du stock

**Files:**
- Create: `src/Enum/StockMovementType.php`, `src/Enum/StockOutReason.php`, `src/Enum/ItemStatus.php`, `src/Enum/ItemCondition.php`
- Create: `src/Entity/StockOperation.php`, `src/Entity/StockMovement.php`, `src/Entity/Item.php`, `src/Entity/StockLevel.php`, avec leurs repositories
- Modify: `src/Entity/Variant.php` (archivage), `config/packages/doctrine.yaml` (`schema_filter`)
- Create: migration via `make db-diff`, complétée à la main (CHECK, séquence)
- Test: `tests/Unit/Stock/StockLevelTest.php`, `tests/Functional/Stock/StockSchemaTest.php`

**Interfaces:**
- Produces (enums adossés à une chaîne, chacun avec `label(): string` en français) :
  - `StockMovementType` : `PurchaseIn = 'purchase_in'` (Achat), `DonationIn = 'donation_in'` (Dotation), `Out = 'out'` (Sortie), `Transfer = 'transfer'` (Transfert).
  - `StockOutReason` : `Lost = 'lost'` (Perte), `Broken = 'broken'` (Casse), `Scrapped = 'scrapped'` (Rebut), `EntryError = 'entry_error'` (Erreur de saisie), `Other = 'other'` (Autre).
  - `ItemStatus` : `InStock = 'in_stock'` (En stock), `Removed = 'removed'` (Sortie).
  - `ItemCondition` : `New = 'new'` (Neuf), `Good = 'good'` (Bon), `Worn = 'worn'` (Usé), `Damaged = 'damaged'` (Abîmé).
- Produces: `StockOperation`, avec le trait horodatage + auteur (`createdBy` = auteur) :
  - constructeur `__construct(StockMovementType $type, ?Location $source, ?Location $destination, ?StockOutReason $outReason = null, ?string $documentReference = null, ?string $comment = null)` ;
  - `occurredAt` = `new \DateTimeImmutable()` posé au constructeur ;
  - getters, `addMovement(StockMovement): void` (cascade persist), `getMovements(): Collection<int, StockMovement>` triés par id ;
  - `documentReference` : 100 caractères.
- Produces: `StockMovement` : `__construct(StockOperation $operation, Variant $variant, int $quantity, int $unitCostHt, ?Item $item = null, ?int $catalogueValueHt = null, ?Season $season = null)` ; getters seulement.
- Produces: `Item` (avec le trait) :
  - constructeur `__construct(string $code, Variant $variant, Location $location, int $purchaseCostHt, ItemCondition $condition = ItemCondition::New, ?string $serialNumber = null)`, statut initial `InStock` ;
  - getters, plus deux mutateurs `@internal` réservés au recorder : `moveTo(Location)` et `remove()` (statut `Removed`, le lieu est conservé) ;
  - `code` unique (20 car.), `serialNumber` 100 car.
- Produces: `StockLevel` :
  - constructeur `__construct(Variant, Location)`, quantité et valeur à 0 ;
  - `getQuantity(): int`, `getValueHt(): int`, `averageCostHt(): ?int` (`null` à quantité 0, sinon valeur / quantité arrondie au plus proche) ;
  - `@internal add(int $quantity, int $valueHt): void` ;
  - `@internal remove(int $quantity): int` : retire `round(valeur × q / quantité)` et **renvoie la valeur retirée** ; la valeur passe exactement à 0 quand la quantité tombe à 0 ; lève `\LogicException` si q dépasse la quantité ;
  - unique (`variant_id`, `location_id`).
- Produces: `ItemRepository::nextCode(): string` → `sprintf('HS-%06d', nextval('item_code_seq'))`.
- Produces: `Variant::isArchived(): bool`, `getArchivedAt(): ?\DateTimeImmutable`, `archive(): static` (pose `archivedAt` et `active = false`), `unarchive(): static` (efface `archivedAt` et repasse `active = true`).
- Produces: `LocationRepository::hasMovements(Location): bool` : vrai s'il existe une opération qui a ce lieu en source ou en destination.
- Migration, à compléter à la main en plus du diff :
  - `CREATE SEQUENCE item_code_seq START 1` ;
  - `CHECK (quantity >= 0)` et `CHECK (value_ht >= 0)` sur `stock_level`, `CHECK (quantity > 0)` sur `stock_movement` ;
  - colonne `variant.archived_at` ;
  - clés étrangères des mouvements et des pièces vers variante et lieu en `RESTRICT` ;
  - `doctrine.dbal.schema_filter: ~^(?!item_code_seq$)~`, pour que `make db-diff` ne propose jamais de supprimer la séquence.

- [ ] **Step 1: Write the failing tests**
  - `StockLevelTest::testWeightedAverage` : `add(3, 3000)` puis `add(1, 0)` → quantité 4, valeur 3000 ; `remove(2)` renvoie 1500 → 2 / 1500 ; `remove(2)` renvoie 1500 → 0 / 0.
  - `StockLevelTest::testRemoveRoundsAndEmptiesToZero` : `add(3, 1000)` ; `remove(1)` renvoie 333, valeur 667 ; `remove(2)` renvoie 667, valeur 0.
  - `StockLevelTest::testRemoveMoreThanAvailableThrows` → `\LogicException`.
  - `StockSchemaTest::testNegativeQuantityRejectedByDatabase` : `UPDATE stock_level SET quantity = -1` en DBAL → violation de contrainte.
  - `StockSchemaTest::testItemCodesAreSequential` : deux appels à `nextCode()` → `HS-` suivi de 6 chiffres, le second vaut le premier + 1.
- [ ] **Step 2: Run** `make test c="--filter='StockLevelTest|StockSchemaTest'"` → FAIL.
- [ ] **Step 3: Implement** les enums, les entités, les repositories, la migration complétée et le `schema_filter`.
- [ ] **Step 4: Run** `make db-migrate`, puis le filtre → PASS. Relancer `make db-diff` → « No changes detected ». `make qa` vert.
- [ ] **Step 5: Commit** `feat(stock): add stock operation, movement, item and level model`

### Task 3: Équivalence des EAN 12 / 13 chiffres

**Files:**
- Create: `src/Catalogue/Ean.php`
- Modify: `src/Catalogue/CatalogueSearch.php:76-90` (`findVariantByEan`), `src/Import/CatalogueImportAnalyzer.php:95-200` (index par clé GTIN-13)
- Test: `tests/Unit/Catalogue/EanTest.php`, `tests/Functional/Catalogue/ProductBrowseTest.php`, `tests/Functional/Import/CatalogueImportAnalyzerTest.php`

**Interfaces:**
- Produces: `Ean::key(string $ean): string` : la chaîne est passée par `trim` ; 12 chiffres → `'0'.$ean` ; tout le reste est renvoyé tel quel.
- Produces: `Ean::candidates(string $ean): list<string>` : les formes stockées possibles du même code. Si `key()` donne 13 chiffres commençant par `0` : `[clé, clé sans le 0 de tête]` ; sinon `[clé]`.
- Règle : la valeur stockée ne change jamais ; seules les comparaisons passent par `key()`.
  - Dans l'analyzer, les index `$variantByEan`, `$otherSupplierEans`, `$deletedHolders` et `$seenEans` sont indexés par `Ean::key()`.
  - `findVariantByEan` cherche `v.ean IN (:candidates)`.

- [ ] **Step 1: Write the failing tests**
  - `EanTest::testKey` : `key('123456789012') === '0123456789012'` ; `key(' 0123456789012 ') === '0123456789012'` ; `key('12345670') === '12345670'`.
  - `EanTest::testCandidates` : `candidates('123456789012') === ['0123456789012', '123456789012']` ; `candidates('4006381333931') === ['4006381333931']`.
  - `ProductBrowseTest::testSearchByEanMatchesLeadingZeroVariant` : variante stockée `'0123456789012'` ; `GET /catalogue?q=123456789012` redirige vers la fiche du produit, comme la recherche EAN exacte actuelle.
  - `CatalogueImportAnalyzerTest::testTwelveDigitEanMatchesExistingThirteenDigitVariant` : variante existante `'0123456789012'`, Gencode `'123456789012'` dans le fichier → variante rapprochée ; aucune nouvelle variante, aucune anomalie `EanChanged`.
  - `CatalogueImportAnalyzerTest::testTwelveDigitDuplicateInFileIsDetected` : `'0123456789012'` et `'123456789012'` dans le même fichier → `EanDuplicateInFile` sur la seconde ligne.
- [ ] **Step 2: Run** les trois filtres → FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** → PASS ; `make qa` vert, sans modifier aucun test des lots 1 et 7.
- [ ] **Step 5: Commit** `feat(catalogue): treat 12-digit and leading-zero 13-digit EANs as the same code`

### Task 4: Recorder — entrées (achat, dotation)

**Files:**
- Create: `src/Stock/StockMovementRecorder.php`, `src/Stock/StockException.php`, `src/Stock/EntryCostProvider.php`
- Create: `src/Stock/Input/StockEntry.php`, `src/Stock/Input/StockEntryLine.php`
- Modify: `src/Repository/StockLevelRepository.php` (`findForUpdate`)
- Modify: `src/Controller/Stock/LocationController.php` (refus de suppression d'un lieu qui a des mouvements)
- Test: `tests/Functional/Stock/StockEntryRecorderTest.php` (KernelTestCase), `tests/Functional/Stock/LocationControllerTest.php`

**Interfaces:**
- Consumes: Task 2 (entités, `ItemRepository::nextCode()`, `LocationRepository::hasMovements()`), `CurrentSeasonProvider::get(): ?Season`, `SeasonPriceRepository`, `VatCalculator::htFromTtc(int $ttcCents, int $rateBasisPoints): int`.
- Produces: `final readonly class StockEntryLine(Variant $variant, int $quantity, ?int $unitCostHt = null, ItemCondition $condition = ItemCondition::New, list<string> $serialNumbers = [])`.
- Produces: `final readonly class StockEntry(StockMovementType $type, Location $location, list<StockEntryLine> $lines, ?string $documentReference = null, ?string $comment = null)`, avec `$type` ∈ {`PurchaseIn`, `DonationIn`}.
- Produces: `EntryCostProvider::clubPriceHt(Variant $variant): ?int` : prix club HT de la saison courante ; `null` s'il n'y a pas de saison courante, pas de tarif, ou un prix club vide.
- Produces: `StockMovementRecorder::recordEntry(StockEntry $entry): StockOperation`.
- Produces: `StockException extends \DomainException`, dont le message, en français, s'affiche tel quel à l'utilisateur.
- Produces: `StockLevelRepository::findForUpdate(Variant, Location): ?StockLevel` (verrou `PESSIMISTIC_WRITE`) ; le recorder crée le niveau s'il n'existe pas.
- Produces (helper privé du recorder, réutilisé à la Task 5) : le libellé d'une variante = `référence produit + ' ' + Variant::getLabel()`, noté `{libellé}` dans les messages.

Règles :
- Tout est contrôlé **avant** la première écriture, puis écrit dans `EntityManager::wrapInTransaction`.
- Achat :
  - coût = `unitCostHt` saisi, sinon `clubPriceHt()` ;
  - si les deux manquent → `StockException('Coût unitaire manquant pour « {libellé} » : aucun tarif club pour la saison courante.')` ;
  - coût < 0 → `'Le coût unitaire ne peut pas être négatif.'`.
- Dotation : coût 0 (un `unitCostHt` fourni est ignoré) ; `catalogueValueHt = clubPriceHt()`, qui peut être `null`.
- Chaque mouvement porte la saison courante (`null` s'il n'y en a pas).
- Produit `SERIALIZED` :
  - N pièces (code par `nextCode()`, état de la ligne, n° de série dans l'ordre fourni, passés par `trim`, les vides ignorés) et N mouvements de quantité 1 ;
  - plus de n° de série que de pièces → `'Plus de numéros de série que de pièces pour « {libellé} ».'`.
- Produit `QUANTITY` : un mouvement de quantité q, et `StockLevel::add(q, q × coût)`.
- Refus :
  - aucune ligne → `'Ajoutez au moins une ligne.'` ;
  - quantité < 1 → `'La quantité doit être au moins 1.'` ;
  - lieu inactif → `'Le lieu « {nom} » est inactif.'` ;
  - variante archivée, inactive ou supprimée → `'« {libellé} » n'est plus au catalogue : entrée impossible.'`.
- Suppression d'un lieu qui a des mouvements : refusée avec le flash d'erreur « Ce lieu a des mouvements : désactivez-le plutôt. ».
- Désactivation d'un lieu qui contient du stock (pièce `InStock` ou niveau > 0) : refusée, erreur sur le champ `active` « Ce lieu contient du stock : videz-le avant de le désactiver. » (spec § 5).

- [ ] **Step 1: Write the failing tests**
  - `testPurchaseOfQuantityProductCreatesLevel` : produit `QUANTITY`, 3 × 1250 → 1 opération `PurchaseIn`, 1 mouvement de quantité 3 au coût 1250, niveau 3 / 3750.
  - `testPurchaseCostDefaultsToClubPriceHt` : tarif de la saison courante club TTC 12000, TVA 2000, coût non saisi → mouvement au coût 10000.
  - `testPurchaseOfSerializedProductCreatesItems` : produit `SERIALIZED`, quantité 2, n° de série `['SN1']` → 2 pièces `HS-` distinctes, `InStock` au lieu, coût figé ; la 1re porte `SN1`, la 2e `null` ; 2 mouvements de quantité 1, chacun lié à sa pièce.
  - `testDonationIsFreeWithCatalogueValue` : dotation, tarif club TTC 12000 → coût 0, `catalogueValueHt` 10000, valeur du niveau 0.
  - `testDonationWithoutTariffIsAccepted` : pas de tarif → `catalogueValueHt` `null`, pas d'exception.
  - `testInvalidLineRollsBackWholeEntry` : ligne 1 valide, ligne 2 = achat sans tarif ni coût → `StockException` ; 0 opération, 0 niveau, 0 pièce en base.
  - `testArchivedVariantCannotBeReceived`, `testInactiveLocationIsRefused`, `testEmptyEntryIsRefused` : messages exacts donnés plus haut.
  - `LocationControllerTest::testLocationWithMovementsCannotBeDeleted` : lieu qui a reçu une entrée → le POST de suppression redirige avec le flash d'erreur, le lieu est toujours là.
  - `LocationControllerTest::testLocationWithStockCannotBeDeactivated` : lieu contenant 1 unité, édition avec `active` décoché → 422, message exact, lieu toujours actif.
- [ ] **Step 2: Run** `make test c="--filter='StockEntryRecorderTest|LocationControllerTest'"` → FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** → PASS ; `make qa` vert.
- [ ] **Step 5: Commit** `feat(stock): record purchase and donation entries`

### Task 5: Recorder — sorties et transferts

**Files:**
- Modify: `src/Stock/StockMovementRecorder.php`
- Create: `src/Stock/Input/StockLine.php`, `src/Stock/Input/StockOut.php`, `src/Stock/Input/StockTransfer.php`
- Test: `tests/Functional/Stock/StockOutTransferRecorderTest.php`

**Interfaces:**
- Produces: `final readonly class StockLine(Variant $variant, int $quantity, ?Item $item = null)`. Produit `SERIALIZED` : `item` obligatoire et quantité 1. Produit `QUANTITY` : `item` nul.
- Produces: `final readonly class StockOut(Location $location, StockOutReason $reason, list<StockLine> $lines, ?string $comment = null)`.
- Produces: `final readonly class StockTransfer(Location $from, Location $to, list<StockLine> $lines, ?string $comment = null)`.
- Produces: `StockMovementRecorder::recordOut(StockOut): StockOperation`, `recordTransfer(StockTransfer): StockOperation`.

Règles :
- Dans la transaction, verrouiller (`PESSIMISTIC_WRITE`) les niveaux et les pièces visés, **puis** contrôler la disponibilité.
- Sortie `QUANTITY` : `valeur = StockLevel::remove(q)` ; coût unitaire du mouvement = valeur / q arrondi au plus proche.
- Sortie `SERIALIZED` : `Item::remove()` ; coût = prix d'achat de la pièce.
- Transfert `QUANTITY` : `valeur = source->remove(q)`, puis `destination->add(q, valeur)` (niveau créé au besoin).
- Transfert `SERIALIZED` : `Item::moveTo($to)`.
- L'opération de transfert renseigne la source et la destination.
- Plusieurs lignes sur la même variante dans une même saisie : leurs quantités se cumulent pour le contrôle de disponibilité.
- Refus :
  - stock insuffisant → `'Stock insuffisant pour « {libellé} » à « {lieu} » : {n} disponible(s).'` ;
  - pièce absente du lieu ou déjà sortie → `'La pièce {code} n'est pas en stock à « {lieu} ».'` ;
  - produit sérialisé sans pièce → `'Indiquez la pièce (code HS-) pour « {libellé} ».'` ;
  - même pièce deux fois → `'La pièce {code} figure deux fois.'` ;
  - motif `Other` sans commentaire → `'Précisez le motif en commentaire.'` ;
  - lieux identiques → `'Les lieux de départ et d'arrivée doivent être différents.'` ;
  - lieu de destination inactif → message de la Task 4.

- [ ] **Step 1: Write the failing tests**
  - `testOutOfQuantityUsesAverageCost` : entrées 2 × 1000 puis 2 × 2000 ; sortie de 1 pour casse (`Broken`) → mouvement au coût 1500, niveau 3 / 4500.
  - `testOutOfSerializedItem` : pièce achetée 8000 ; sortie pour perte (`Lost`) → statut `Removed`, mouvement au coût 8000 lié à la pièce.
  - `testSecondOutOfLastUnitIsRefused` : 1 unité ; première sortie de 1 acceptée ; seconde refusée avec « Stock insuffisant … 0 disponible(s). » ; niveau 0 / 0.
  - `testItemFromOtherLocationIsRefused` : pièce au lieu A, sortie demandée depuis B → message exact, pièce inchangée, aucune opération ajoutée.
  - `testTransferMovesQuantityAndValue` : A à 4 / 6000 ; transfert de 1 vers B → A 3 / 4500, B 1 / 1500, une opération `Transfer` (source A, destination B).
  - `testTransferMovesItemKeepingCost` : pièce à 8000 transférée de A vers B → lieu B, prix d'achat toujours 8000.
  - `testSameLocationTransferIsRefused`, `testOtherReasonRequiresComment`, `testSerializedLineWithoutItemIsRefused`, `testSameItemTwiceIsRefused` : messages exacts donnés plus haut.
  - `testLinesOnSameVariantAreSummed` : 2 en stock, deux lignes de 2 → refus pour stock insuffisant.
- [ ] **Step 2: Run** `make test c="--filter=StockOutTransferRecorderTest"` → FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** → PASS ; `make qa` vert.
- [ ] **Step 5: Commit** `feat(stock): record stock outs and transfers`

### Task 6: Écran de nouvelle entrée (achat, dotation)

**Files:**
- Create: `src/Form/StockVariantAutocompleteField.php`, `src/Form/Stock/StockEntryType.php`, `src/Form/Stock/StockEntryLineType.php`
- Create: `assets/controllers/form_collection_controller.js`
- Create: `src/Controller/Stock/EntryController.php`, `templates/stock/entry/form.html.twig`
- Create: `src/Controller/Stock/OperationController.php` (action `show` seule), `templates/stock/operation/show.html.twig` (minimal : type, lieu, lignes)
- Test: `tests/Functional/Stock/EntryControllerTest.php`

**Interfaces:**
- Consumes: `StockMovementRecorder::recordEntry`, `StockEntry`, `StockEntryLine`, `StockException`, `EntryCostProvider`, `Ean::candidates`, `LocationRepository::findActive`.
- Produces: `StockVariantAutocompleteField` (`#[AsEntityAutocompleteField]`, `security: 'ROLE_STOCK'`, 20 résultats) :
  - ne propose que les variantes actives, non archivées, de produits non supprimés ;
  - recherche sur la référence, la désignation (`LOWER(UNACCENT(...))`) ou l'EAN (`IN Ean::candidates`) ;
  - libellé `RÉF — Désignation — attributs (EAN)`, suivi de ` · tarif club HT 85,00 €` quand un tarif existe.
- Routes : `stock_entry_new`, `GET|POST /stock/entree/{type}`, avec `type` ∈ `achat|dotation`. En cas de succès, redirection vers `stock_operation_show` (Task 9) avec le flash « Entrée enregistrée : N pièce(s) créée(s) : HS-…, HS-… ». La route `stock_operation_show` est créée dans cette tâche avec un template minimal ; la Task 9 la complète.
- Formulaire (le contrôleur traduit les données en DTO) :
  - en-tête : `location` (lieux actifs), `documentReference`, `comment`, `lines` (collection, `allow_add`, `allow_delete`, au moins une ligne) ;
  - ligne : `variant` (autocomplete), `quantity` (entier ≥ 1, 1 par défaut), `unitCostHt` (`MoneyType`, `divisor: 100`, EUR, facultatif, **absent pour une dotation**), `condition` (`ItemCondition`, Neuf par défaut), `serialNumbers` (texte, un n° par ligne, facultatif).
- Coût : un coût laissé vide prend le tarif club HT (règle du recorder), et l'aide du champ l'indique (« Vide = tarif club HT de la saison »). Aucun appel JS pour pré-remplir.
- `form_collection_controller.js` : ajoute une ligne à partir du `data-prototype` (en remplaçant `__name__` par un index croissant) et retire une ligne. Rien d'autre.
- `StockException` → erreur globale du formulaire, réponse 422, rien d'écrit.
- Aucun lieu actif → la page affiche « Créez d'abord un lieu », avec un lien vers `stock_location_new` si l'utilisateur a `LOCATION_MANAGE`.

- [ ] **Step 1: Write the failing tests**
  - `testViewerCannotOpenEntry` : 403.
  - `testStockUserRecordsPurchase` : POST d'une ligne (variante `QUANTITY`, quantité 3, coût « 12,50 ») → redirection vers le détail de l'opération ; niveau 3 / 3750.
  - `testSerializedPurchaseShowsCreatedCodes` : variante `SERIALIZED`, quantité 2 → la page suivante contient deux codes `HS-` distincts.
  - `testDonationFormHasNoCostField` : `GET /stock/entree/dotation` → aucun champ `unitCostHt`.
  - `testRecorderErrorIsShownAndNothingWritten` : achat sans tarif ni coût → 422, la page contient « Coût unitaire manquant », 0 opération.
  - `testNoActiveLocationShowsHint` : aucun lieu → « Créez d'abord un lieu ».
  - `testAutocompleteFindsByTwelveDigitEanAndHidesArchived` : `GET /autocomplete/stock_variant_autocomplete_field?query=123456789012` → la variante `'0123456789012'` est proposée ; une variante archivée de même référence ne l'est pas.
- [ ] **Step 2: Run** `make test c="--filter=EntryControllerTest"` → FAIL.
- [ ] **Step 3: Implement.** Vérifier au navigateur (chrome-devtools, https://hockey-staff.localhost, largeur 390 px) l'ajout et le retrait de lignes et l'autocomplete.
- [ ] **Step 4: Run** → PASS ; `make qa` vert.
- [ ] **Step 5: Commit** `feat(stock): add purchase and donation entry screen`

### Task 7: Écrans de sortie et de transfert

**Files:**
- Create: `src/Form/Stock/StockOutType.php`, `src/Form/Stock/StockTransferType.php`, `src/Form/Stock/StockLineType.php`
- Create: `src/Controller/Stock/OutController.php`, `src/Controller/Stock/TransferController.php`, `templates/stock/out/form.html.twig`, `templates/stock/transfer/form.html.twig`
- Modify: `src/Repository/ItemRepository.php` (`findOneByCode`)
- Test: `tests/Functional/Stock/OutTransferControllerTest.php`

**Interfaces:**
- Consumes: `recordOut`, `recordTransfer`, `StockLine`, `StockOut`, `StockTransfer`, `StockVariantAutocompleteField`, `form_collection_controller.js`, `stock_operation_show`.
- Produces: `ItemRepository::findOneByCode(string $code): ?Item` (passé par `trim`, insensible à la casse).
- Routes : `stock_out_new` (`GET|POST /stock/sortie`) et `stock_transfer_new` (`GET|POST /stock/transfert`). En cas de succès, redirection vers `stock_operation_show` avec le flash « Sortie enregistrée. » ou « Transfert enregistré. ».
- Ligne : soit `itemCode` (texte, code `HS-`), soit `variant` + `quantity`.
  - Code renseigné → la variante est celle de la pièce.
  - Code inconnu → erreur de champ « Pièce {code} introuvable. ».
  - Ni code ni variante → « Indiquez une variante ou un code de pièce. ».
- Sortie : `location` (tous les lieux non supprimés), `reason` (`StockOutReason`, en boutons radio), `comment`. Transfert : `from`, `to`, `comment`.
- Préremplissage : `GET /stock/sortie?item=HS-000123` (et `/stock/transfert?item=…`) ouvre le formulaire avec la pièce et son lieu. Cette entrée sert depuis la fiche pièce (Task 8).

- [ ] **Step 1: Write the failing tests**
  - `testOutByItemCode` : pièce en stock, POST avec le code `hs-000001` (minuscules) et le motif Casse → pièce `Removed`.
  - `testOutByQuantity` : niveau 3, sortie de 2 → niveau 1.
  - `testUnknownItemCodeIsFieldError` : 422, « Pièce HS-999999 introuvable. ».
  - `testInsufficientStockShowsRecorderMessage` : 422, « Stock insuffisant ».
  - `testTransferScreen` : transfert d'une unité de A vers B → niveaux mis à jour, flash « Transfert enregistré. ».
  - `testItemPrefill` : `GET /stock/sortie?item=HS-000001` → champ `itemCode` pré-rempli, lieu de la pièce sélectionné.
  - `testViewerCannotOpenOutOrTransfer` : 403 sur les deux écrans.
- [ ] **Step 2: Run** `make test c="--filter=OutTransferControllerTest"` → FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** → PASS ; `make qa` vert.
- [ ] **Step 5: Commit** `feat(stock): add stock out and transfer screens`

### Task 8: Page Stock, fiche pièce, stock dans la fiche produit

**Files:**
- Create: `src/Stock/StockQuery.php`, `src/Stock/StockRow.php`
- Create: `src/Controller/Stock/StockController.php`, `src/Controller/Stock/ItemController.php`, `templates/stock/index.html.twig`, `templates/stock/item/show.html.twig`
- Modify: `src/Controller/Catalogue/ProductController.php` (action `show`), `templates/catalogue/product/show.html.twig` (section Stock), `templates/_nav.html.twig` (entrée Stock, `main: true`)
- Test: `tests/Functional/Stock/StockPageTest.php`, `tests/Functional/Stock/ItemPageTest.php`

**Interfaces:**
- Consumes: les entités et repositories des Tasks 2, 4 et 5.
- Produces: `final readonly class StockRow(Variant $variant, int $quantity, int $valueHt, array $quantityByLocation)`, où `$quantityByLocation` est de type `array<int, int>` (id du lieu → quantité).
- Produces: `StockQuery::rows(?Location $location, ?int $categoryId, ?string $search, int|string|null $page): Page`.
  - Une ligne par variante qui a une pièce `InStock` ou un niveau > 0.
  - Quantité = pièces `InStock` + niveaux ; valeur = Σ prix d'achat des pièces `InStock` + Σ valeur des niveaux.
  - Tri par désignation, puis par libellé de variante.
- Produces: `StockQuery::totalValueHt(?Location $location): int`.
- Produces: `StockQuery::byVariant(Product $product): array<int, array{levels: list<StockLevel>, items: list<Item>}>` (id de variante → niveaux > 0 et pièces `InStock`).
- Routes : `stock_index` (`GET /stock`, filtres `lieu`, `categorie`, `q` ; recherche sur la référence, la désignation, ou l'EAN via `Ean::candidates`) ; `stock_item_show` (`GET /stock/pieces/{code}`).
- Page Stock : valeur totale HT en tête (« Valeur du stock : 1 234,50 € HT »), restreinte au lieu filtré. Boutons Achat, Dotation, Sortie, Transfert visibles avec `STOCK_MOVE`.
- Fiche pièce :
  - code, produit et variante (en liens), statut, état, lieu, prix d'achat HT, n° de série ;
  - historique des mouvements : date, type, lieux, auteur, lien vers l'opération ;
  - boutons Sortie et Transfert (`?item=`) si l'utilisateur a `STOCK_MOVE` et que la pièce est `InStock`.
- Fiche produit : une colonne « Stock » par variante (quantité totale, détail par lieu) et la liste des pièces `InStock` (chaque code mène à sa fiche).

- [ ] **Step 1: Write the failing tests**
  - `testStockPageListsQuantitiesAndTotalValue` : niveau 3 / 3750 au lieu A, pièce à 8000 au lieu B → deux lignes, total « 117,50 € HT ».
  - `testLocationFilterRestrictsRowsAndTotal` : `?lieu=<id A>` → seule la ligne de A, total « 37,50 € HT ».
  - `testRemovedItemsAreNotCounted` : pièce sortie → absente de la page, non comptée dans le total.
  - `testStockValueIgnoresTariffChange` : achat 3 × 1250, puis modification du `SeasonPrice` club → total toujours « 37,50 € HT ».
  - `testViewerSeesStockButNoActionButtons`.
  - `ItemPageTest::testItemShowsHistory` : pièce achetée puis transférée → 2 lignes d'historique (Achat, Transfert).
  - `ItemPageTest::testProductPageShowsStock` : la fiche produit affiche la quantité de la variante et le code `HS-` de la pièce.
- [ ] **Step 2: Run** `make test c="--filter='StockPageTest|ItemPageTest'"` → FAIL.
- [ ] **Step 3: Implement**, avec des agrégats en SQL/DQL plutôt qu'une boucle PHP sur toutes les pièces.
- [ ] **Step 4: Run** → PASS ; `make qa` vert.
- [ ] **Step 5: Commit** `feat(stock): add stock page, item page and product stock section`

### Task 9: Historique des opérations

**Files:**
- Create: `templates/stock/operation/index.html.twig`, `src/Form/Stock/OperationFilterType.php`
- Modify: `src/Controller/Stock/OperationController.php` (action `index` ; `show` complété), `templates/stock/operation/show.html.twig`
- Modify: `src/Repository/StockOperationRepository.php` (requête filtrée), `templates/_nav.html.twig` (entrée Opérations), `templates/catalogue/product/show.html.twig` (lien Historique)
- Test: `tests/Functional/Stock/OperationControllerTest.php`

**Interfaces:**
- Produces: `StockOperationRepository::search(?StockMovementType $type, ?Location $location, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to, ?Variant $variant): QueryBuilder`.
  - Le lieu filtre sur la source ou la destination ; la variante, sur les opérations qui ont au moins un mouvement sur elle.
  - Tri : `occurredAt` décroissant, puis id décroissant.
- Routes :
  - `stock_operation_index` (`GET /stock/operations`) : pagination `Page` à 30 par page ; colonnes date, type, lieu(x), nombre de lignes, auteur, référence de document.
  - `stock_operation_show` (`GET /stock/operations/{id}`) : en-tête, puis les mouvements (variante, pièce, quantité, coût unitaire HT, valeur catalogue pour une dotation).
- Fiche produit : un lien « Historique » par variante, vers `stock_operation_index?variante={id}`.
- Les mouvements d'une variante archivée ou désactivée s'affichent normalement, avec le badge « Archivée » ou « Plus au catalogue ».

- [ ] **Step 1: Write the failing tests**
  - `testListIsNewestFirstAndPaginated` : 31 opérations → 30 en page 1, 1 en page 2, la plus récente en tête.
  - `testFilterByTypeAndLocation` : achat en A, transfert de A vers B ; `?type=transfer&lieu=<id B>` → seule l'opération de transfert.
  - `testFilterByVariant` : ne liste que les opérations qui touchent la variante.
  - `testShowListsMovements` : achat sérialisé de quantité 2 → deux codes `HS-` et le coût.
  - `testOperationShowsArchivedVariant` : variante archivée après son achat → `GET /stock/operations/{id}` répond 200 avec le badge « Archivée ».
  - `testNoEditOrDeleteRoutes` : `POST /stock/operations/{id}/delete` et `GET /stock/operations/{id}/edit` → 404.
- [ ] **Step 2: Run** `make test c="--filter=OperationControllerTest"` → FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** → PASS ; `make qa` vert.
- [ ] **Step 5: Commit** `feat(stock): add operation history`

### Task 10: Branchements des lots 1 et 7 — archivage, mode de suivi, suppressions

**Files:**
- Modify: `src/Import/CatalogueImportAnalyzer.php:289-330`, `src/Import/ImportDiffBuilder.php`, `src/Import/ImportDiff.php`, `src/Import/CatalogueImportApplier.php:215-230,266-276`, `templates/import/show.html.twig` (rubrique « Absentes du fichier »)
- Modify: `src/Enum/ImportAnomalyCode.php` (`TrackingModeLocked = 'tracking_mode_locked'`)
- Create: `src/Stock/StockPresence.php`
- Modify: `src/Catalogue/CatalogueSearch.php:64-70` et `CatalogueSearchCriteria` (`includeArchived`), `src/Form/CatalogueFilterType.php` (case « Archivés »), `templates/catalogue/product/show.html.twig` (badge « Archivée »)
- Modify: `src/Form/ProductType.php:44-62`, `src/Controller/Catalogue/ProductController.php` (actions `edit` et `delete`), `src/Controller/Catalogue/VariantController.php` (action `delete`)
- Test: `tests/Functional/Import/ImportStockRulesTest.php`, `tests/Functional/Catalogue/CatalogueStockGuardsTest.php`

**Interfaces:**
- Produces: `StockPresence::variantIdsInStock(list<int> $variantIds): array<int, true>` (variantes qui ont une pièce `InStock` ou un niveau > 0).
- Produces: `StockPresence::variantIdsWithMovements(list<int> $variantIds): array<int, true>`.
- Produces: `StockPresence::productHasMovements(Product): bool`.
- Produces: `ImportDiff::$archivedVariantIds` (`list<int>`), sérialisé comme `deactivatedVariantIds` ; un diff stocké sans cette clé se relit avec `[]`. `ImportDiffBuilder::archive(int $variantId)`.

Règles :
1. Analyzer, pour une variante en base absente du fichier :
   - active et en stock → `deactivate` (comportement actuel) ;
   - active et sans stock → `archive` ;
   - inactive, non archivée et sans stock → `archive` (rattrapage des variantes désactivées avant ce lot) ;
   - déjà archivée → rien.
   Une variante archivée présente dans le fichier → `reactivate`, et l'applier appelle `unarchive()`.
2. Applier : `archive()` sur `archivedVariantIds`, `unarchive()` sur `reactivatedVariantIds`. Le stock est relu au moment de l'application : une variante à archiver qui a reçu du stock entre l'analyse et l'application est seulement désactivée.
3. Aperçu : deux rubriques séparées, « Absentes du fichier, en stock : désactivées (n) » et « Absentes du fichier, sans stock : archivées (n) ».
4. Catalogue : un produit dont toutes les variantes vivantes sont archivées est masqué par défaut ; la case « Archivés » (`includeArchived`) le montre. Fiche produit : badge « Archivée » sur la variante.
5. Mode de suivi d'un produit qui a des mouvements :
   - dans la fiche, le champ `trackingMode` est `disabled`, avec l'aide « Le produit a des mouvements de stock : le mode de suivi ne peut plus changer. » ;
   - à la première catégorisation par import, l'applier ne change pas le mode, et l'analyzer ajoute l'anomalie non bloquante `TrackingModeLocked` (« Produit {réf} : mode de suivi conservé, le produit a des mouvements de stock. »).
6. Suppression (douce) refusée, avec un flash d'erreur :
   - variante qui a des mouvements : « Cette variante a des mouvements de stock : elle ne peut pas être supprimée. » ;
   - produit dont une variante en a : « Ce produit a des mouvements de stock : il ne peut pas être supprimé. ».

- [ ] **Step 1: Write the failing tests**
  - `ImportStockRulesTest::testAbsentVariantWithoutStockIsArchived` : variante absente du fichier, sans stock → après application, archivée et inactive.
  - `testAbsentVariantInStockIsOnlyDeactivated` : variante avec une pièce `InStock` → inactive, non archivée.
  - `testStockReceivedAfterAnalysisPreventsArchiving` : analyse, puis entrée de stock, puis application → inactive, non archivée.
  - `testArchivedVariantBackInFileIsRestored` : variante archivée, présente au ré-import → active, non archivée.
  - `testPreviewSeparatesArchivedAndDeactivated` (fonctionnel) : la page du run affiche les deux rubriques avec leurs compteurs.
  - `testFirstCategorizationKeepsModeWhenMovements` : produit sans catégorie en `QUANTITY`, avec un achat ; l'import lui donne la catégorie `EQUIPEMENT` → mode `QUANTITY` conservé, anomalie `TrackingModeLocked`.
  - `CatalogueStockGuardsTest::testTrackingModeLockedInProductForm` : produit avec mouvement → champ désactivé et texte d'aide affiché ; un POST qui change le mode le laisse inchangé.
  - `testVariantWithMovementsCannotBeDeleted`, `testProductWithMovementsCannotBeDeleted` : flash d'erreur, l'entité est toujours visible.
  - `testArchivedProductsHiddenByDefault` : produit dont la seule variante est archivée → absent de `/catalogue`, présent quand la case « Archivés » est cochée.
- [ ] **Step 2: Run** `make test c="--filter='ImportStockRulesTest|CatalogueStockGuardsTest'"` → FAIL.
- [ ] **Step 3: Implement**, en lisant le stock par lot d'identifiants (pas une requête par variante).
- [ ] **Step 4: Run** → PASS ; `make qa` vert. Les tests des lots 1 et 7 sur la désactivation sont adaptés au cas « sans stock → archivée », pas supprimés.
- [ ] **Step 5: Commit** `feat(import): archive absent variants without stock and lock tracking mode once stocked`

### Task 11: Documentation du lot

**Files:**
- Create: `docs/superpowers/decisions/2026-10-27-lot2-decisions.md`
- Modify: `docs/superpowers/specs/2026-10-27-hockey-staff-domain-design.md` (§ 9 : lot 2 **LIVRÉ**), `README.md` si une section y décrit les fonctionnalités

- [ ] **Step 1: Write** le fichier de décisions, au format du lot 7 (décision, raison, coût si faux). Il reprend les choix de la spec § 9 effectivement appliqués, plus ceux du plan :
  - coût vide = tarif club HT, calculé côté serveur, sans JS ;
  - `schema_filter` pour la séquence `item_code_seq` ;
  - suppression refusée pour les variantes, produits et lieux qui ont des mouvements ;
  - rattrapage de l'archivage des variantes déjà inactives ;
  - toute décision prise en cours d'exécution.
- [ ] **Step 2: Add** une courte procédure de recette manuelle : créer deux lieux ; acheter (un produit en quantité et un sérialisé) ; enregistrer une dotation, une sortie, un transfert ; vérifier la valeur et l'historique ; ré-importer le catalogue Bauer sans perte (aucune variante en stock ne doit être archivée).
- [ ] **Step 3: Commit** `docs: document lot 2 stock and movements`
