# Lot 4 — Joueurs, prêts, dons et ventes d'occasion — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Gérer joueurs, équipes et effectifs par saison ; prêter une pièce à un joueur en choisissant son état, la reprendre avec un nouvel état ; donner à un joueur ou à une équipe ; vendre des pièces d'occasion au prix public moins un % ; le tout depuis une nouvelle fiche variante.

**Architecture:** Les nouveaux mouvements (`LOAN_OUT`, `LOAN_RETURN`, `GIFT_OUT`, `SALE_OUT`) passent tous par `App\Stock\StockMovementRecorder` (une transaction, verrous, contrôles), avec un DTO d'entrée par opération dans `App\Stock\Input`. Le prêt en cours se lit sur la pièce (`Item.status = loaned` + `Item.holder`) ; pas d'entité `Assignment`. Un module `App\Player` porte joueurs/équipes/effectifs ; un module `App\Sale` porte le calcul de prix des ventes. Les écrans suivent ceux du lot 2 (contrôleurs fins, `StockException` affichée dans le formulaire).

**Tech Stack:** Symfony 8.1, PHP 8.5, Doctrine ORM 3.7 / PostgreSQL 18 (Gedmo SoftDeleteable), Twig + Turbo + Stimulus (AssetMapper), ux-autocomplete, PHPUnit (WebTestCase / KernelTestCase, DAMA).

**Spec:** `docs/superpowers/specs/2026-10-28-lot4-joueurs-attributions-ventes-design.md` (socle : `2026-10-27-lot2-stock-mouvements-design.md`).

## Global Constraints

- Tout changement d'`Item` ou de `StockLevel` passe par `StockMovementRecorder` ; les méthodes d'`Item` qui changent son état sont marquées `@internal réservé au StockMovementRecorder`.
- `StockOperation`, `StockMovement`, `Sale`, `SaleLine` : immuables (ni modification ni suppression).
- Date d'une opération / d'une vente = date d'enregistrement (`StockOperation::occurredAt`), jamais saisie.
- Montants en centimes (int). Taux en points de base (int) : TVA `2000` = 20 %, réduction `8000` = 80 %.
- `Item.location` reste non nulle quel que soit le statut (dernier lieu connu, modèle de `Removed`).
- Valeur du stock = pièces `in_stock` **et `loaned`** + `StockLevel`. `gifted`, `sold`, `removed` exclues.
- Vente : pièces `in_stock` dont l'état n'est pas `New`, un seul lieu par vente ; prix de vente TTC = prix public TTC × (1 − réduction), arrondi au centime le plus proche ; HT via `VatCalculator::htFromTtc`.
- Droits : `PLAYER_VIEW` = `ROLE_VIEWER`, `PLAYER_MANAGE` = `ROLE_MANAGER`, `STOCK_MOVE` = `ROLE_STOCK` (prêt, retour, don), `SALE_MANAGE` = `ROLE_MANAGER`.
- Pas de reçu PDF, pas de moyen de paiement, pas de statut payé, pas d'import CSV, pas de prêt à une équipe.
- Libellés UI en français ; code, classes et commits en anglais (Conventional Commits), **sans trailer de co-auteur**.
- Migrations via `make db-diff`, relues ; jamais `doctrine:schema:update` ; ne pas réinitialiser la base de dev.
- TDD. Une tâche est finie quand `make qa` est vert. `graphify update .` après modification de code (jamais commité). Travail sur `develop`.

## Review Focus

1. Deux prêts successifs alors qu'il ne reste qu'un casque L « Neuf » : le second reçoit « Aucune pièce … disponible » (verrou + relecture). (Test : Task 3, `testLoanPicksOldestAndFailsWhenNoneLeft`.)
2. Retour d'une pièce par le mauvais joueur, ou d'une pièce déjà rendue : refusé avec message. (Test : Task 3, `testReturnRejectsWrongHolderOrNotLoaned`.)
3. Variante dont toutes les pièces sont prêtées : toujours listée sur la page Stock, valeur incluse dans le total, absente du filtre par lieu. (Test : Task 4, `testFullyLoanedVariantStaysListedAndValued`.)
4. Vente d'une pièce neuve, prêtée, ou d'un autre lieu que celui de la vente (formulaire trafiqué) : refusée. (Test : Task 5, `testSaleRejectsNewLoanedOrOtherLocationItem`.)
5. Variante sans aucun tarif public : vente possible avec prix final saisi, refusée sans prix final. (Test : Task 5, `testSaleWithoutPublicPriceNeedsFinalPrice`.)

---

### Task 1: Joueurs (modèle, droits, écrans)

**Files:**
- Create: `src/Enum/PlayerPosition.php`, `src/Entity/Player.php`, `src/Repository/PlayerRepository.php`
- Create: `src/Security/Voter/PlayerVoter.php`
- Create: `src/Form/PlayerType.php`, `src/Controller/Player/PlayerController.php`
- Create: `templates/player/player/index.html.twig`, `form.html.twig`, `show.html.twig`
- Modify: `templates/_nav.html.twig` (groupe « Joueurs »)
- Create: migration via `make db-diff`
- Test: `tests/Functional/Player/PlayerControllerTest.php` ; Create: `tests/Support/PlayerFixtures.php`

**Interfaces:**
- Produces: `enum PlayerPosition: string { Goalie = 'goalie'; Defense = 'defense'; Forward = 'forward' }` + `label()` (« Gardien », « Défenseur », « Attaquant »).
- Produces: `Player` (`\Stringable`, `TimestampableBlameableTrait`, Gedmo soft delete `deletedAt`) : `lastName` (150, NotBlank « Le nom est obligatoire. »), `firstName` (150, NotBlank « Le prénom est obligatoire. »), `position: PlayerPosition`, `notes: ?string` (TEXT). Setters fluents avec `trim`, comme `Location`. `getFullName(): string` = « Prénom Nom » (ex. « Hugo Durand ») ; `__toString()` = `getFullName()`.
- Produces: `PlayerVoter` (modèle `StockVoter`) : `VIEW = 'PLAYER_VIEW'` → `ROLE_VIEWER`, `MANAGE = 'PLAYER_MANAGE'` → `ROLE_MANAGER`.
- Produces: routes `player_index` (`GET /joueurs`, recherche `q` sur nom/prénom insensible aux accents comme le catalogue ; filtres équipe/saison ajoutés en Task 2), `player_new`, `player_edit` (`/joueurs/{id}/edit`), `player_show` (`/joueurs/{id}`, `PLAYER_VIEW`, contenu complété en Task 7), `player_delete` (soft delete, GET confirmation / POST CSRF, modèle `LocationController::delete`). New/edit/delete : `PLAYER_MANAGE`.
- Produces: `PlayerFixtures::player(EntityManagerInterface $em, string $lastName = 'Martin', string $firstName = 'Léo', PlayerPosition $position = PlayerPosition::Forward): Player` (persist + flush).
- Menu : groupe `{title: 'Joueurs', role: 'ROLE_VIEWER', items: [{label: 'Joueurs', route: 'player_index', prefix: 'player_'}]}` après « Stock ».

- [ ] **Step 1: Write the failing tests** (`PlayerControllerTest`, WebTestCase)
  - `testManagerCreatesPlayer` : POST (`Durand`, `Hugo`, `goalie`) → redirection ; la liste contient « Hugo Durand » et « Gardien ».
  - `testBlankNamesAreRejected` : nom vide → 422, « Le nom est obligatoire. ».
  - `testStockUserCannotManagePlayers` : `ROLE_STOCK` → `/joueurs/new` 403 ; `/joueurs` 200.
  - `testSearchIgnoresAccents` : joueur « Léo Martin », `?q=leo` → trouvé.
  - `testDeleteIsSoft` : POST delete avec jeton → absent de la liste, toujours en base avec `deletedAt` (filtre softdeleteable désactivé pour la vérification).
- [ ] **Step 2: Run** `make test c="--filter=PlayerControllerTest"` → FAIL.
- [ ] **Step 3: Implement** enum, entité, dépôt, voter, formulaire, contrôleur, templates, menu ; `make db-diff` puis relire la migration.
- [ ] **Step 4: Run** `make db-migrate`, le filtre → PASS, `make qa` vert.
- [ ] **Step 5: Commit** `feat(player): add players with positions and management screens`

### Task 2: Équipes et effectifs par saison

**Files:**
- Create: `src/Entity/Team.php`, `src/Entity/TeamSeason.php`, `src/Entity/RosterEntry.php` + dépôts
- Create: `src/Player/RosterCopier.php`
- Create: `src/Form/TeamType.php`, `src/Form/PlayerAutocompleteField.php`, `src/Controller/Player/TeamController.php`
- Create: `templates/player/team/index.html.twig`, `form.html.twig`, `roster.html.twig`
- Modify: `src/Controller/Player/PlayerController.php` + `templates/player/player/index.html.twig` (filtres équipe, saison ; n° affiché), `templates/_nav.html.twig` (entrée « Équipes »)
- Create: migration
- Test: `tests/Functional/Player/TeamRosterTest.php` ; Modify: `tests/Support/PlayerFixtures.php` (`team()`, `rosterEntry()`)

**Interfaces:**
- Consumes: `Player`, `PlayerVoter` (Task 1), `CurrentSeasonProvider::get(): ?Season`.
- Produces: `Team` (`name` 150, unique parmi les non supprimées — index partiel `deleted_at IS NULL` comme `Location`, soft delete, `\Stringable`).
- Produces: `TeamSeason(Team $team, Season $season)` unique `(team_id, season_id)` ; `getTeam()`, `getSeason()`, `getEntries(): Collection<int, RosterEntry>`.
- Produces: `RosterEntry(TeamSeason $teamSeason, Player $player, ?int $number)` unique `(team_season_id, player_id)` ; `number` 0–99 (`Assert\Range`) ; `setNumber(?int)`.
- Produces: `TeamSeasonRepository::findOrCreate(Team $team, Season $season): TeamSeason` (persist sans flush).
- Produces: `RosterCopier::copyFromPrevious(TeamSeason $target): int` — saison précédente = celle au `startsOn` le plus grand strictement antérieur à celui de la cible, pour la même équipe ; copie les joueurs non supprimés absents de la cible, avec leur numéro ; renvoie le nombre ajouté ; flush.
- Produces: `PlayerAutocompleteField` (ux-autocomplete, modèle `StockVariantAutocompleteField`, joueurs non supprimés, recherche nom/prénom) — réutilisé en Tasks 6 et 8.
- Routes (`PLAYER_MANAGE` sauf mention) : `team_index` (`/equipes`, `PLAYER_VIEW`), `team_new`, `team_edit`, `team_delete` (soft), `team_roster` (`GET /equipes/{id}/effectif?saison={seasonId}`, défaut saison courante, `PLAYER_VIEW` pour lire), `team_roster_add` (POST : joueur + numéro), `team_roster_remove` (POST CSRF ; suppression réelle de la `RosterEntry`, ce n'est pas un mouvement), `team_roster_copy` (POST CSRF → `RosterCopier`, flash « N joueur(s) repris de la saison précédente. »).
- Liste joueurs : filtres `equipe` et `saison` (via `RosterEntry`), colonne « N° » quand une équipe et une saison sont filtrées.

- [ ] **Step 1: Write the failing tests** (`TeamRosterTest`)
  - `testManagerBuildsRosterForCurrentSeason` : équipe « U15 », ajout de 2 joueurs avec n° 1 et 6 → la page effectif liste « 1 » et « 6 » avec les noms.
  - `testSamePlayerTwiceIsRejected` : ajout du même joueur → « Ce joueur est déjà dans l'effectif. », une seule entrée.
  - `testCopyFromPreviousSeason` : 2025-2026 (A n°1, B n°6, C supprimé) et 2026-2027 courante (B n°9) → copie → 2026-2027 contient A n°1 et B n°9, pas C ; flash « 1 joueur(s) repris de la saison précédente. ».
  - `testCopyWithoutPreviousSeasonAddsNothing` : flash « 0 joueur(s) repris de la saison précédente. ».
  - `testPlayerListFiltersByTeamSeason` : filtre U15 / 2026-2027 → seuls les joueurs de l'effectif.
- [ ] **Step 2: Run** `make test c="--filter=TeamRosterTest"` → FAIL.
- [ ] **Step 3: Implement** entités, dépôts, `RosterCopier`, formulaires, autocomplete, contrôleur, templates, filtres, menu ; migration.
- [ ] **Step 4: Run** `make db-migrate`, filtre → PASS, `make qa` vert.
- [ ] **Step 5: Commit** `feat(player): add teams and per-season rosters with copy from previous season`

### Task 3: Prêt, retour et don dans le recorder

**Files:**
- Modify: `src/Enum/StockMovementType.php` (+ `LoanOut = 'loan_out'` « Prêt », `LoanReturn = 'loan_return'` « Retour de prêt », `GiftOut = 'gift_out'` « Don », `SaleOut = 'sale_out'` « Vente »)
- Modify: `src/Enum/ItemStatus.php` (+ `Loaned = 'loaned'` « Prêtée », `Gifted = 'gifted'` « Donnée », `Sold = 'sold'` « Vendue »)
- Modify: `src/Entity/Item.php` (holder, méthodes), `src/Entity/StockOperation.php` (player, team)
- Create: `src/Stock/Input/StockLoan.php`, `StockReturn.php`, `StockGift.php`
- Modify: `src/Stock/StockMovementRecorder.php`, `src/Repository/ItemRepository.php`
- Modify: `templates/stock/operation/show.html.twig`, `templates/stock/operation/index.html.twig`, `templates/stock/item/show.html.twig` (bénéficiaire / détenteur affichés)
- Create: migration
- Test: `tests/Functional/Stock/LoanGiftRecorderTest.php`

**Interfaces:**
- Consumes: `Player` (Task 1), `Team` (Task 2).
- Produces: `Item::getHolder(): ?Player` (ManyToOne nullable, `onDelete: RESTRICT`) ; `@internal` : `lendTo(Player $player): void` (status `Loaned`, holder), `giveBack(Location $location, ItemCondition $condition): void` (status `InStock`, holder null, lieu, état), `gift(): void` (status `Gifted`), `sell(): void` (status `Sold`). Le lieu n'est jamais mis à null.
- Produces: `StockOperation` : `?Player $player`, `?Team $team` (ManyToOne nullables, RESTRICT), paramètres optionnels ajoutés en fin de constructeur ; `getPlayer()`, `getTeam()`.
- Produces DTOs (`final readonly`) :
  - `StockLoan(Location $location, Player $player, Variant $variant, ItemCondition $condition, ?string $comment = null)`
  - `StockReturn(Item $item, Player $player, Location $location, ItemCondition $condition, ?string $comment = null)`
  - `StockGift(Location $location, Variant $variant, int $quantity, ?ItemCondition $condition, ?Player $player, ?Team $team, ?string $comment = null)` — `$condition` requis pour une variante sérialisée, ignoré sinon.
- Produces: `ItemRepository::findOldestInStockForUpdate(Variant $variant, Location $location, ItemCondition $condition): ?Item` (plus petit `code`, `PESSIMISTIC_WRITE`, refresh) ; `countInStockByCondition(Variant $variant, Location $location): array<string, int>` (clé = valeur d'`ItemCondition`) ; `findLoanedByVariant(Variant $variant): list<Item>` (détenteur joint, tri nom joueur) ; `findLoanedByPlayer(Player $player): list<Item>`.
- Produces recorder :
  - `recordLoan(StockLoan $loan): StockOperation` — variante non sérialisée → `StockException("« X » est suivi en quantité : il ne se prête pas.")` ; lieu actif ; pièce par `findOldestInStockForUpdate`, sinon `StockException("Aucune pièce « X » à l'état Neuf disponible à « Lieu ».")` (libellé de l'état demandé) ; opération `LoanOut`, source = lieu, `player` ; mouvement quantité 1, coût = prix d'achat de la pièce ; `lendTo()`.
  - `recordReturn(StockReturn $return): StockOperation` — pièce relue avec verrou ; statut ≠ `Loaned` ou détenteur ≠ joueur → `StockException("La pièce HS-… n'est pas prêtée à Prénom Nom.")` ; lieu actif ; opération `LoanReturn`, destination = lieu, `player` ; `giveBack()`.
  - `recordGift(StockGift $gift): StockOperation` — exactement un bénéficiaire, sinon `StockException('Choisissez un joueur ou une équipe.')` ; sérialisé : quantité 1, pièce par `findOldestInStockForUpdate` (même message que le prêt), `gift()` ; en quantité : `lockAndCheck` + `StockLevel::remove`, comme `recordOut` ; opération `GiftOut`, source = lieu, `player`/`team`.
  - `recordOut` / `recordTransfer` inchangés (une pièce `Loaned` y est déjà refusée par le contrôle `InStock`).

- [ ] **Step 1: Write the failing tests** (`LoanGiftRecorderTest`, KernelTestCase ; stock posé via le recorder, état choisi par `StockEntryLine(condition: …)`)
  - `testLoanPicksOldestAndFailsWhenNoneLeft` : 2 casques Neuf + 1 Usé à « Local » ; prêt Neuf au joueur 1 → pièce au plus petit code, `Loaned`, holder joueur 1, lieu toujours « Local », opération `LoanOut` avec player ; 2ᵉ prêt Neuf → l'autre pièce ; 3ᵉ prêt Neuf → `StockException` contenant « Aucune pièce » ; prêt Usé → OK.
  - `testQuantityProductCannotBeLoaned` : `StockException` contenant « ne se prête pas ».
  - `testReturnRejectsWrongHolderOrNotLoaned` : pièce prêtée au joueur 1 ; retour par le joueur 6 → « n'est pas prêtée à » ; retour par le joueur 1 en `Worn` à « Réserve » → `InStock`, holder null, lieu « Réserve », état `Worn` ; second retour → exception.
  - `testGiftToTeamOfQuantityUsesAverageCost` : 10 palets à 100 c → don de 4 à l'équipe → `StockLevel` 6 / 600, mouvement coût 100, opération avec `team`, sans `player`.
  - `testGiftNeedsExactlyOneRecipient` : ni joueur ni équipe, puis les deux → « Choisissez un joueur ou une équipe. ».
  - `testGiftOfItemToPlayer` : pièce Bon → `Gifted`.
- [ ] **Step 2: Run** `make test c="--filter=LoanGiftRecorderTest"` → FAIL.
- [ ] **Step 3: Implement** enums, entités, DTOs, dépôt, recorder, templates d'historique (« Joueur : … » / « Équipe : … », détenteur sur la page pièce) ; migration.
- [ ] **Step 4: Run** `make db-migrate`, filtre → PASS, `make qa` vert (tests du lot 2 inclus).
- [ ] **Step 5: Commit** `feat(stock): record loans, returns and gifts to players or teams`

### Task 4: Pièces prêtées dans la page Stock et la valeur

**Files:**
- Modify: `src/Stock/StockQuery.php`, `src/Stock/StockRow.php`, `templates/stock/index.html.twig`, `templates/catalogue/product/show.html.twig` (« dont N prêtée(s) » par variante)
- Test: `tests/Functional/Stock/StockPageTest.php` (compléter)

**Interfaces:**
- Consumes: `ItemStatus::Loaned`, `Item::getHolder()` (Task 3).
- Produces: `StockRow::$loanedQuantity: int` (dernier paramètre du constructeur, défaut 0). `quantity` et `valueHt` incluent les pièces prêtées ; `quantityByLocation` ne compte que le physique.
- Règles `StockQuery` :
  - sans filtre de lieu : `rows()` liste aussi les variantes n'ayant que des pièces `Loaned` ; `aggregate()` compte `Loaned` dans `quantity`, `value` et `loaned`, pas dans `locations` ; `totalValueHt(null)` inclut `Loaned` ;
  - avec filtre de lieu : uniquement le physique `InStock` du lieu (comportement actuel), `loaned` = 0 ;
  - `byVariant()` : ajoute la clé `loaned: list<Item>` (pièces prêtées, détenteur joint).
- Page Stock : colonne « Prêtées » ; sans filtre de lieu, le libellé du total ajoute « (pièces prêtées incluses) ».

- [ ] **Step 1: Write the failing tests** (dans `StockPageTest`)
  - `testFullyLoanedVariantStaysListedAndValued` : 1 casque à 5000 c prêté, aucun autre stock → sans filtre : ligne présente, « Prêtées » 1, total « 50,00 € » ; filtre « Local » : ligne absente, total « 0,00 € ».
  - `testGiftedItemLeavesValue` : 2 pièces à 5000 c, une donnée → total « 50,00 € ».
- [ ] **Step 2: Run** `make test c="--filter=StockPageTest"` → FAIL.
- [ ] **Step 3: Implement** `StockQuery`, `StockRow`, templates.
- [ ] **Step 4: Run** filtre → PASS, `make qa` vert.
- [ ] **Step 5: Commit** `feat(stock): count loaned items in stock value and stock page`

### Task 5: Vente d'occasion — modèle, prix et recorder

**Files:**
- Create: `src/Entity/Sale.php`, `src/Entity/SaleLine.php`, `src/Repository/SaleRepository.php`
- Create: `src/Sale/SalePricing.php`, `src/Sale/SalePrice.php`
- Create: `src/Stock/Input/StockSale.php`, `src/Stock/Input/StockSaleLine.php`
- Modify: `src/Stock/StockMovementRecorder.php`, `src/Entity/StockOperation.php` (`sale`), `src/Repository/SeasonPriceRepository.php`
- Create: migration
- Test: `tests/Unit/Sale/SalePricingTest.php`, `tests/Functional/Sale/SaleRecorderTest.php`

**Interfaces:**
- Consumes: `Item::sell()` (Task 3), `VatCalculator::htFromTtc(int $ttcCents, int $rateBasisPoints): int`, `CurrentSeasonProvider`.
- Produces: `SeasonPriceRepository::findPublicReference(Variant $variant, ?Season $current): ?SeasonPrice` — tarif de la saison courante s'il a un `publicPriceTtc`, sinon celui de la saison au `startsOn` le plus récent ayant un `publicPriceTtc`, sinon null.
- Produces: `final readonly class SalePrice(public ?int $basePriceTtc, public ?int $discountRate, public int $vatRate, public int $priceTtc, public int $priceHt)`.
- Produces: `SalePricing::discounted(int $basePriceTtc, int $discountRate): int` (statique) = `intdiv($basePriceTtc * (10000 - $discountRate) + 5000, 10000)` ; réduction hors 0–10000 → `\InvalidArgumentException`.
- Produces: `SalePricing::price(Variant $variant, ?int $discountRate, ?int $finalPriceTtc): SalePrice` — base et TVA depuis `findPublicReference` (TVA `SeasonPrice::DEFAULT_VAT_RATE` sans tarif) ; prix TTC = `$finalPriceTtc` s'il est fourni, sinon `discounted()` ; sans prix final et (sans base ou sans réduction) → `StockException('Saisissez le prix de vente de « X ».')` ; prix final < 0 → `StockException('Le prix de vente ne peut pas être négatif.')`.
- Produces: `Sale` (`buyer` TEXT, `?Player $player`, `?string $comment`, `TimestampableBlameableTrait`, `getLines(): Collection<int, SaleLine>`, `getTotalTtc(): int`) ; `SaleLine(Sale $sale, Item $item, SalePrice $price)` (colonnes `base_price_ttc`, `discount_rate`, `vat_rate`, `price_ttc`, `price_ht`). `StockOperation::getSale(): ?Sale` (OneToOne nullable porté par l'opération) ; `Sale::getOperation(): StockOperation` (côté inverse).
- Produces DTOs : `StockSaleLine(Item $item, ?int $discountRate, ?int $finalPriceTtc)` ; `StockSale(Location $location, string $buyer, list<StockSaleLine> $lines, ?Player $player = null, ?string $comment = null)`.
- Produces: `StockMovementRecorder::recordSale(StockSale $sale): StockOperation` — acheteur non vide après `trim` (« Indiquez l'acheteur. ») ; au moins une ligne ; pas deux fois la même pièce ; chaque pièce relue avec verrou (ordre d'id croissant) : `InStock` **et** lieu = lieu de la vente, sinon « La pièce HS-… n'est pas en stock à « Lieu ». » ; état ≠ `New`, sinon « La pièce HS-… est neuve : seule l'occasion se vend. » ; prix via `SalePricing` ; opération `SaleOut` (source = lieu, `player`) ; un mouvement par pièce au coût d'achat de la pièce ; `Sale` + lignes ; `sell()`.

- [ ] **Step 1: Write the failing tests**
  - `SalePricingTest::testDiscountedMatchesUserExample` : `discounted(30000, 8000)` = `6000` ; `discounted(9999, 3333)` = `6666` ; `discounted(30000, 0)` = `30000` ; `discounted(30000, 10001)` → `\InvalidArgumentException`.
  - `SaleRecorderTest::testSaleUsesCurrentPublicPriceAndFreezesIt` : tarif public 30000 TTC (TVA 2000) saison courante, pièce Bon → vente à 8000 → ligne `priceTtc` 6000, `priceHt` 5000, `basePriceTtc` 30000 ; pièce `Sold`, hors valeur (`StockQuery::totalValueHt(null)`) ; modifier ensuite le tarif ne change pas la ligne.
  - `testFallsBackToLatestSeasonWithPublicPrice` : pas de tarif courant ; tarifs 2024-2025 (20000) et 2025-2026 (25000) → base 25000.
  - `testFinalPriceOverridesDiscount` : prix final 4500 avec réduction 8000 → `priceTtc` 4500, `discountRate` 8000 conservé.
  - `testSaleWithoutPublicPriceNeedsFinalPrice` : aucun tarif → sans prix final : « Saisissez le prix de vente » ; avec 2000 → OK, `basePriceTtc` null.
  - `testSaleRejectsNewLoanedOrOtherLocationItem` : pièce Neuf → « est neuve » ; pièce prêtée → « n'est pas en stock » ; pièce Bon à « Réserve » dans une vente à « Local » → « n'est pas en stock ».
  - `testBuyerIsRequired` : acheteur `'  '` → « Indiquez l'acheteur. ».
- [ ] **Step 2: Run** `make test c="--filter=Sale"` → FAIL.
- [ ] **Step 3: Implement** entités, dépôts, `SalePricing`, DTOs, recorder ; migration.
- [ ] **Step 4: Run** `make db-migrate`, filtre → PASS, `make qa` vert.
- [ ] **Step 5: Commit** `feat(sale): record second-hand sales at discounted public price`

### Task 6: Fiche variante et écrans Prêter / Retour / Donner

**Files:**
- Create: `src/Controller/Stock/VariantSheetController.php`, `src/Controller/Stock/LoanController.php`, `src/Controller/Stock/GiftController.php`
- Create: `src/Form/Stock/StockLoanType.php`, `StockReturnType.php`, `StockGiftType.php`
- Create: `templates/stock/variant/show.html.twig`, `templates/stock/loan/form.html.twig`, `templates/stock/loan/return.html.twig`, `templates/stock/gift/form.html.twig`
- Modify: `templates/catalogue/product/show.html.twig` (lien par variante vers la fiche), `templates/stock/index.html.twig` (lien par ligne)
- Test: `tests/Functional/Stock/VariantSheetTest.php`, `tests/Functional/Stock/LoanGiftControllerTest.php`

**Interfaces:**
- Consumes: `recordLoan`, `recordReturn`, `recordGift`, `countInStockByCondition`, `findLoanedByVariant` (Task 3) ; `StockQuery::byVariant` clé `loaned` (Task 4) ; `PlayerAutocompleteField` (Task 2) ; tarif courant (`SeasonPriceRepository`).
- Produces routes :
  - `stock_variant_show` `GET /stock/variantes/{id}` (`STOCK_VIEW`) — désignation, attributs, EAN, code `HV-`, tarif courant (club, public), stock par lieu (pièces ventilées par état « Neuf 3 · Bon 1 · Usé 2 » ; quantités pour un produit en quantité), pièces prêtées avec détenteur (lien fiche joueur). Boutons selon droits et pertinence : **Prêter** et **Retour** (sérialisé ; Retour seulement s'il y a une pièce prêtée), **Vendre** (`SALE_MANAGE`, lien `sale_new?variant={id}` de Task 8, seulement s'il y a une pièce d'occasion en stock), **Donner**, **Sortie** (`stock_out_new`).
  - `stock_loan_new` `GET|POST /stock/variantes/{id}/pret` (`STOCK_MOVE`) : lieu (actifs), joueur, état (choix avec compteur via `countInStockByCondition` du lieu ; changement de lieu = rechargement du formulaire en Turbo Frame), commentaire → redirige vers `stock_variant_show`, flash « {variante} prêté à {Prénom Nom} ({HS-…}). ».
  - `stock_loan_return` `GET|POST /stock/variantes/{id}/retour?item={code}` (`STOCK_MOVE`) : pièce prêtée (liste « HS-… — Prénom Nom », présélectionnée si `item` ou si une seule), lieu, nouvel état, commentaire → redirige vers `player_show` du détenteur, flash « Retour enregistré. ».
  - `stock_gift_new` `GET|POST /stock/variantes/{id}/don` (`STOCK_MOVE`) : lieu, bénéficiaire (radio Joueur / Équipe + champ correspondant), quantité (produit en quantité) ou état (sérialisé), commentaire → `stock_variant_show`, flash « Don enregistré. ».
- `StockException` → erreur de formulaire, 422 (même schéma que `StockOperationScreen::handleOperation`).

- [ ] **Step 1: Write the failing tests**
  - `VariantSheetTest::testSheetShowsStockByConditionAndHolders` : 3 Neuf + 1 Usé à « Local », 1 autre prêtée à Léo Martin → page contient « Neuf 3 », « Usé 1 », « Léo Martin », boutons Prêter/Retour/Donner/Sortie ; `ROLE_VIEWER` : aucun bouton d'action.
  - `testSellButtonOnlyForManagerWithSecondHandStock` : `ROLE_STOCK` → pas de « Vendre » ; `ROLE_MANAGER` avec pièce Usé → « Vendre » ; sans pièce d'occasion → absent.
  - `LoanGiftControllerTest::testLoanFromSheet` : POST prêt (Local, joueur, `new`) → redirection fiche variante, flash contient « prêté à Léo Martin ».
  - `testLoanWithNoItemLeftShowsError` : état sans pièce (formulaire trafiqué) → 422, « Aucune pièce ».
  - `testReturnPreselectsSingleLoanedItem` : une pièce prêtée → GET retour : pièce présélectionnée ; POST (Local, `worn`) → redirection fiche joueur.
  - `testGiftToTeam` : produit en quantité, POST bénéficiaire équipe, quantité 4 → flash « Don enregistré. ».
  - `testViewerCannotLoan` : `ROLE_VIEWER` → `/stock/variantes/{id}/pret` 403.
- [ ] **Step 2: Run** `make test c="--filter='VariantSheetTest|LoanGiftControllerTest'"` → FAIL.
- [ ] **Step 3: Implement** contrôleurs, formulaires, templates, liens.
- [ ] **Step 4: Run** filtre → PASS, `make qa` vert.
- [ ] **Step 5: Commit** `feat(stock): add variant sheet with loan, return and gift screens`

### Task 7: Fiche joueur et prêts en cours

**Files:**
- Modify: `src/Controller/Player/PlayerController.php`, `templates/player/player/show.html.twig`
- Create: `src/Controller/Player/LoanListController.php`, `templates/player/loan/index.html.twig`
- Modify: `src/Repository/ItemRepository.php`, `templates/_nav.html.twig` (« Prêts en cours »)
- Test: `tests/Functional/Player/PlayerSheetTest.php`

**Interfaces:**
- Consumes: `findLoanedByPlayer` (Task 3), route `stock_loan_return` (Task 6), `RosterEntry` (Task 2).
- Produces: fiche joueur — infos, effectifs (équipe, saison, n°), **pièces détenues** (code, variante, date du prêt = `occurredAt` du dernier `LoanOut` de la pièce, bouton « Retour » → `stock_loan_return` avec `item`), **historique** (opérations dont `player` = joueur, plus récentes d'abord : prêts, retours, dons, achats).
- Produces: `ItemRepository::loanedQuery(?Team $team, ?Season $season): QueryBuilder` — pièces `Loaned` ; avec équipe et/ou saison : détenteur présent dans un effectif correspondant.
- Produces route `loan_index` `GET /prets` (`PLAYER_VIEW`) : filtres équipe et saison (vides par défaut = tous les prêts), colonnes joueur, pièce, variante, depuis le ; paginée (`Page::fromQuery`). Menu Joueurs : « Prêts en cours ».

- [ ] **Step 1: Write the failing tests** (`PlayerSheetTest`)
  - `testSheetListsHeldItemsWithReturnLink` : 2 pièces prêtées à Léo → la fiche contient leurs codes et 2 liens vers `/retour?item=HS-…`.
  - `testSheetShowsHistory` : prêt puis retour → historique contient « Prêt » et « Retour de prêt ».
  - `testLoanListFiltersByTeamSeason` : Léo (U15 2026-2027) et Hugo (sans effectif) ont chacun une pièce → `/prets` : 2 lignes ; filtre U15 + 2026-2027 : 1 ligne (Léo).
- [ ] **Step 2: Run** `make test c="--filter=PlayerSheetTest"` → FAIL.
- [ ] **Step 3: Implement** fiche, liste, requête, menu.
- [ ] **Step 4: Run** filtre → PASS, `make qa` vert.
- [ ] **Step 5: Commit** `feat(player): add player sheet with held items and current loans list`

### Task 8: Écrans de vente

**Files:**
- Create: `src/Controller/Sale/SaleController.php`, `src/Security/Voter/SaleVoter.php`
- Create: `src/Form/Sale/SaleType.php`, `src/Form/Sale/SaleLineType.php`, `src/Form/Sale/SaleLineMapper.php`
- Create: `templates/sale/index.html.twig`, `form.html.twig`, `show.html.twig`
- Modify: `templates/_nav.html.twig` (« Ventes » dans Stock, `role: 'ROLE_MANAGER'`), `templates/stock/operation/show.html.twig` (lien vers la vente)
- Test: `tests/Functional/Sale/SaleControllerTest.php`

**Interfaces:**
- Consumes: `recordSale`, `StockSale`, `StockSaleLine`, `SalePricing`, `Sale` (Task 5) ; `ItemRepository::findOneByCode` ; `PlayerAutocompleteField` (Task 2).
- Produces: `SaleVoter` : `MANAGE = 'SALE_MANAGE'` → `ROLE_MANAGER`.
- Produces routes (`SALE_MANAGE`) :
  - `sale_index` `GET /ventes` : date, acheteur, nombre de pièces, total TTC ; paginée.
  - `sale_new` `GET|POST /ventes/new?variant={id}` : lieu, acheteur (textarea), joueur (facultatif), commentaire ; lignes (collection, ajout de ligne comme les opérations du lot 2) : code `HS-` de la pièce (champ texte, ou choix parmi les pièces d'occasion `InStock` du lieu « HS-… — variante — état »), réduction en % (entier 0–100, × 100 en points de base), prix final TTC en euros (facultatif). Avec `variant` : une ligne préremplie sur la première pièce d'occasion (plus petit code) de la variante, et son lieu. La page de confirmation (`sale_show`) affiche le prix public de base et les prix TTC / HT calculés ; aperçu live facultatif.
  - `sale_show` `GET /ventes/{id}` : acheteur, joueur, lignes (pièce, prix public, réduction, prix TTC, HT), total TTC.
- `StockException` → erreur de formulaire, 422.

- [ ] **Step 1: Write the failing tests** (`SaleControllerTest`)
  - `testManagerSellsFromVariantSheet` : pièce Bon, tarif public 300 € → GET `/ventes/new?variant=` : ligne préremplie avec le code ; POST réduction 80, acheteur « M. Petit, 06 00 00 00 00 » → redirection `sale_show`, page contient « 60,00 € » et « 50,00 € ».
  - `testTamperedLineIsRejected` : code d'une pièce neuve → 422, « est neuve ».
  - `testStockUserCannotSell` : `ROLE_STOCK` → `/ventes/new` 403 ; `/ventes` 403.
  - `testSaleListShowsTotal` : vente de deux pièces à 60 € et 40 € (prix finaux) → la liste affiche « 100,00 € ».
- [ ] **Step 2: Run** `make test c="--filter=SaleControllerTest"` → FAIL.
- [ ] **Step 3: Implement** voter, formulaires, mapper, contrôleur, templates, menu.
- [ ] **Step 4: Run** filtre → PASS, `make qa` vert.
- [ ] **Step 5: Commit** `feat(sale): add second-hand sale screens for managers`

### Task 9: Décisions et livraison du lot

**Files:**
- Create: `docs/superpowers/decisions/2026-10-28-lot4-decisions.md` (format du fichier du lot 8)
- Modify: `docs/superpowers/specs/2026-10-27-hockey-staff-domain-design.md` (lot 4 **LIVRÉ**, recette manuelle à faire)

- [ ] **Step 1:** Rassembler dans le fichier de décisions celles prises pendant l'exécution (rapports d'implémentation et de revue), avec les points connus restants.
- [ ] **Step 2:** Marquer le lot 4 livré dans la spec de domaine (même formule que les lots 2, 7, 8).
- [ ] **Step 3: Run** `make qa` → vert.
- [ ] **Step 4: Commit** `docs: record lot 4 decisions and mark lot as delivered`
