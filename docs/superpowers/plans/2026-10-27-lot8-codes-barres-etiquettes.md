# Lot 8 — Codes-barres et étiquettes — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Donner à chaque variante un code interne `HV-` stable, résoudre n'importe quel code imprimé (EAN, `HV-`, `HS-`) dans les champs de recherche, et imprimer des étiquettes PDF (planche A4 ou rouleau 62 × 29 mm) depuis la fiche produit, une pièce, une sélection du catalogue, les variantes sans EAN et une entrée de stock.

**Architecture:** Un module `App\Label` porte trois pièces indépendantes : `BarcodeChoice` (quel code-barres pour une variante ou une pièce), `LabelLineBuilder` (quelles étiquettes pour une demande) et `LabelPdfRenderer` (PDF dompdf, barres picqer). `App\Catalogue\CodeResolver` résout un code scanné. Un seul contrôleur `LabelController` affiche l'écran « Imprimer des étiquettes » (GET, alimenté par des liens et des formulaires GET depuis chaque point d'impression) et renvoie le PDF (POST).

**Tech Stack:** Symfony 8.1, PHP 8.5, Doctrine ORM 3.7 / PostgreSQL 18, Twig + Turbo + Stimulus (AssetMapper), `picqer/php-barcode-generator`, `dompdf/dompdf`, PHPUnit (WebTestCase / KernelTestCase, DAMA).

**Spec:** `docs/superpowers/specs/2026-10-27-lot8-codes-barres-etiquettes-design.md` (parente : `2026-10-27-hockey-staff-domain-design.md` § 9 lot 8)

## Global Constraints

- Un code `HV-` ou `HS-` attribué ne change jamais et n'est jamais réattribué (spec § 2, § 3).
- Code variante : `HV-` + 6 chiffres, séquence `variant_code_seq` ; code pièce existant : `HS-` + 6 chiffres.
- Symbologies : UPC-A (EAN 12 chiffres, clé valide), EAN-13 (13 chiffres, clé valide), sinon Code128 du `HV-` ; pièce : toujours Code128 du `HS-` (spec § 4).
- Pas de QR code, pas d'impression directe, pas de Messenger : PDF synchrone (spec § 2, § 6.3).
- Formats : planche A4 3 × 7 de 63,5 × 38,1 mm (type Avery L7160), rouleau 62 × 29 mm une étiquette par page (spec § 6.1).
- Borne : 500 étiquettes au total par PDF (spec § 7).
- Variante « étiquetable » : non supprimée **et** (active **ou** en stock) (spec § 7).
- Droits : imprimer = `STOCK_MOVE` (`ROLE_STOCK`+) ; voir le code `HV-` = lecteurs du catalogue.
- Libellés UI en français. Code, classes et commits en anglais (Conventional Commits), **sans trailer de co-auteur**.
- Migrations générées par `make db-diff` puis relues et complétées ; jamais `doctrine:schema:update`. Ne pas réinitialiser la base de dev.
- Dépendances via `make composer c="require …"`.
- TDD. Une tâche est finie quand `make qa` est vert. `graphify update .` après toute modification de code (le graphe n'est jamais commité).
- Travail sur `develop` uniquement.

## Review Focus

1. EAN Bauer stocké sur 12 chiffres, scanné sur 13 avec un zéro de tête (et l'inverse) : la variante est trouvée. (Test : Task 3, `testEanWithOrWithoutLeadingZeroResolves`.)
2. Variante archivée ou inactive qui a encore des pièces en stock : elle reste étiquetable, depuis la fiche produit comme depuis « sans EAN ». (Test : Task 5, `testArchivedVariantWithStockIsLabelable`.)
3. Désignation très longue (120 caractères) : le PDF garde une étiquette par case, 21 étiquettes tiennent sur une seule page A4. (Test : Task 4, `testLongDesignationDoesNotOverflowCell`.)
4. Formulaire PDF trafiqué : première case hors de 1–21, exemplaires à 0 ou négatifs, identifiant de variante supprimée. Résultat attendu : 422 avec message pour la case ; une ligne à 0 ou inconnue est ignorée ; un PDF vide est refusé. (Test : Task 6, `testTamperedPdfRequestIsHandled`.)
5. Variante créée par l'import (pas par le formulaire) : elle reçoit un code `HV-`. (Test : Task 1, `testImportedVariantGetsCode`.)

---

### Task 1: Code interne de variante `HV-`

**Files:**
- Modify: `src/Entity/Variant.php` (colonne `code`)
- Modify: `src/Repository/VariantRepository.php` (`nextCode`, `findOneByCode`)
- Create: `src/Doctrine/VariantCodeListener.php`
- Modify: `config/packages/doctrine.yaml` (`schema_filter`)
- Modify: `templates/catalogue/product/show.html.twig` (code affiché sous le libellé de la variante)
- Create: migration via `make db-diff`, complétée à la main
- Test: `tests/Functional/Catalogue/VariantCodeTest.php`

**Interfaces:**
- Produces: `Variant::getCode(): ?string` (null seulement avant le premier `persist`) et `Variant::assignCode(string $code): void`. Ce dernier lève `\LogicException` si un code est déjà posé. Aucun autre setter. Colonne `code` : `VARCHAR(20) NOT NULL`, index unique **global** `uniq_variant_code` (non partiel : une variante supprimée garde son code).
- Produces: `VariantRepository::nextCode(): string` → `sprintf('HV-%06d', nextval('variant_code_seq'))`, même forme que `ItemRepository::nextCode()`.
- Produces: `VariantRepository::findOneByCode(string $code): ?Variant`, insensible à la casse et aux espaces (`strtoupper(trim())`), comme `ItemRepository::findOneByCode`.
- Produces: `VariantCodeListener`, listener Doctrine `prePersist` sur `Variant` (`#[AsEntityListener(event: Events::prePersist, entity: Variant::class)]`) qui appelle `assignCode($repo->nextCode())` si `getCode()` est null. Il couvre le formulaire, l'import (`CatalogueImportApplier`) et les fixtures de test sans les modifier.
- Migration, à compléter en plus du diff, dans cet ordre :
  - `CREATE SEQUENCE variant_code_seq START 1` ;
  - `ALTER TABLE variant ADD code VARCHAR(20) DEFAULT NULL` ;
  - remplissage par `id` croissant : `UPDATE variant v SET code = s.c FROM (SELECT id, 'HV-' || lpad(nextval('variant_code_seq')::text, 6, '0') AS c FROM (SELECT id FROM variant ORDER BY id) o) s WHERE v.id = s.id` ;
  - `ALTER TABLE variant ALTER code SET NOT NULL` ;
  - `CREATE UNIQUE INDEX uniq_variant_code ON variant (code)` ;
  - `down()` symétrique.
- `schema_filter` : `~^(?!(item_code_seq|variant_code_seq)$)~`.

- [ ] **Step 1: Write the failing tests** (`VariantCodeTest`, KernelTestCase sauf mention)
  - `testNewVariantsGetSequentialCodes` : deux variantes via `ImportFixtures::variant()`. Leurs codes respectent `/^HV-\d{6}$/` et le second vaut le premier + 1.
  - `testCodeIsImmutable` : `assignCode('HV-999999')` sur une variante persistée → `\LogicException`.
  - `testFindOneByCodeIgnoresCaseAndSpaces` : `findOneByCode('  '.strtolower($code).' ')` renvoie la variante.
  - `testImportedVariantGetsCode` : un import appliqué (`ImportFixtures::run` puis applier, comme les tests d'import existants) crée une variante dont le code respecte `/^HV-\d{6}$/`.
  - `testProductPageShowsVariantCode` (WebTestCase, `ROLE_VIEWER`) : la fiche produit contient le code de la variante.
- [ ] **Step 2: Run** `make test c="--filter=VariantCodeTest"` → FAIL.
- [ ] **Step 3: Implement** la colonne et les méthodes, le listener, la migration complétée, le `schema_filter` et l'affichage du code (`<small class="muted">` sous le libellé).
- [ ] **Step 4: Run** `make db-migrate`. Vérifier avec `make sf c="dbal:run-sql \"SELECT count(*) FROM variant WHERE code IS NULL\""` → 0. Relancer `make db-diff` → « No changes detected ». Puis le filtre → PASS, et `make qa` vert.
- [ ] **Step 5: Commit** `feat(catalogue): add stable HV- internal variant code`

### Task 2: Choix du code-barres

**Files:**
- Modify: `src/Catalogue/Ean.php` (`hasValidCheckDigit`)
- Create: `src/Label/Symbology.php`, `src/Label/LabelBarcode.php`, `src/Label/BarcodeChoice.php`
- Test: `tests/Unit/Catalogue/EanTest.php` (compléter s'il existe), `tests/Unit/Label/BarcodeChoiceTest.php`

**Interfaces:**
- Produces: `Ean::hasValidCheckDigit(string $digits): bool`. Vrai seulement pour 12 ou 13 chiffres dont la clé GS1 (modulo 10, poids 3/1 depuis la droite hors clé) est juste.
- Produces: `enum Symbology: string { case UpcA = 'upca'; case Ean13 = 'ean13'; case Code128 = 'code128'; }`.
- Produces: `final readonly class LabelBarcode { public function __construct(public Symbology $symbology, public string $value) {} }`.
- Produces: `final class BarcodeChoice` with `public static function forVariant(Variant $v): LabelBarcode` (règle du tableau spec § 4, EAN pris tel que stocké, après `trim`) and `public static function forItem(Item $i): LabelBarcode` (Code128 + `HS-`).

- [ ] **Step 1: Write the failing tests**
  - `EanTest::testCheckDigit` : `012345678905` → vrai ; `4006381333931` → vrai ; `876369003339` → faux ; `12345` → faux ; `abcdefghijkl` → faux.
  - `BarcodeChoiceTest::testTwelveDigitsValidIsUpcA` : EAN `012345678905` → `UpcA`, `012345678905`.
  - `testThirteenDigitsValidIsEan13` : `4006381333931` → `Ean13`, `4006381333931`.
  - `testInvalidOrMissingEanFallsBackToVariantCode` : EAN `876369003339`, `null`, `ABC123` → `Code128`, valeur = code `HV-` de la variante (poser le code avec `assignCode`).
  - `testItemIsCode128OfItsCode` : pièce `HS-000042` → `Code128`, `HS-000042`.
- [ ] **Step 2: Run** `make test c="--filter='EanTest|BarcodeChoiceTest'"` → FAIL.
- [ ] **Step 3: Implement** les quatre éléments.
- [ ] **Step 4: Run** le filtre → PASS ; `make qa` vert.
- [ ] **Step 5: Commit** `feat(label): choose barcode symbology for variants and items`

### Task 3: Résolution d'un code scanné

**Files:**
- Create: `src/Catalogue/CodeResolver.php`
- Modify: `src/Controller/Catalogue/ProductController.php:44-47` (redirection de la recherche)
- Modify: `src/Form/StockVariantAutocompleteField.php` (`filter_query` : `OR v.code = :code`)
- Test: `tests/Functional/Catalogue/CodeResolverTest.php` ; test existant de la recherche du catalogue ; test existant de l'autocomplete des mouvements

**Interfaces:**
- Consumes: `VariantRepository::findOneByCode` (Task 1), `ItemRepository::findOneByCode`, `CatalogueSearch::findVariantByEan`.
- Produces: `final readonly class CodeResolver { public function resolve(string $code): Variant|Item|null }`. L'ordre suit la spec § 5 : `HS-\d+` → pièce, `HV-\d+` → variante, `\d{12,13}` → EAN via `findVariantByEan`, sinon `null`. Casse et espaces ignorés. La clé de contrôle n'est pas vérifiée.
- Recherche du catalogue : si la résolution donne une variante → `catalogue_product_show` de son produit. Si elle donne une pièce → `stock_item_show` (`code`), seulement si l'utilisateur a `STOCK_VIEW`. Sinon la recherche texte continue comme aujourd'hui.

- [ ] **Step 1: Write the failing tests**
  - `CodeResolverTest::testEanWithOrWithoutLeadingZeroResolves` : stocké `123456789012`, trouvé par `0123456789012` ; stocké `0123456789012`, trouvé par `123456789012`.
  - `testVariantCodeResolvesCaseInsensitive` : le code `HV-` réel, en minuscules et entouré d'espaces → la variante.
  - `testItemCodeResolves` : code `HS-` d'une pièce reçue via `StockScenario::receive` → l'`Item`.
  - `testArchivedVariantResolves` / `testDeletedVariantDoesNotResolve` : archivée → trouvée ; soft-deletée → `null`.
  - `testUnknownCodeIsNull` : `HV-999999`, `hello`, `''` → `null`.
  - Recherche : `testSearchByVariantCodeRedirectsToProduct` (recherche avec le code `HV-` → 302 vers la fiche) ; `testSearchByItemCodeRedirectsToItem` (`ROLE_STOCK`, code `HS-` → 302 vers `/stock/pieces/HS-…`). Reprendre le nom réel du paramètre de formulaire depuis le test de recherche existant.
  - Autocomplete : `testStockAutocompleteFindsVariantByCode`, sur le modèle du test d'autocomplete existant (recherche par EAN), avec le code `HV-`.
- [ ] **Step 2: Run** les tests nouveaux et modifiés (`make test c="--filter=…"`) → FAIL sur les nouveaux tests.
- [ ] **Step 3: Implement** le résolveur, la redirection et le filtre de l'autocomplete.
- [ ] **Step 4: Run** le filtre → PASS ; `make qa` vert.
- [ ] **Step 5: Commit** `feat(catalogue): resolve EAN, HV- and HS- codes from search fields`

### Task 4: Rendu PDF des étiquettes

**Files:**
- Modify: `composer.json` / `composer.lock` via `make composer c="require picqer/php-barcode-generator dompdf/dompdf"`
- Create: `src/Label/LabelFormat.php`, `src/Label/Label.php`, `src/Label/LabelPdfRenderer.php`
- Create: `templates/label/pdf.html.twig`
- Test: `tests/Functional/Label/LabelPdfRendererTest.php` (KernelTestCase)

**Interfaces:**
- Consumes: `LabelBarcode`, `Symbology` (Task 2).
- Produces: `enum LabelFormat: string { case Sheet = 'a4'; case Roll = 'rouleau'; }` with `label(): string`: « Planche A4 (21 étiquettes) » and « Rouleau 62 × 29 mm ».
- Produces: `final readonly class Label { __construct(public string $designation, public string $attributes, public LabelBarcode $barcode, public ?string $itemCode = null) }` (`attributes` = `Variant::getLabel()`, chaîne vide possible).
- Produces: `LabelPdfRenderer::render(list<Label> $labels, LabelFormat $format, int $firstCell = 1): string` (binaire PDF). Lève `\InvalidArgumentException` si `$labels` est vide ou si `$firstCell` sort de 1–21 ; `$firstCell` est ignoré en `Roll`.
- Mise en page (valeurs fixes) :
  - `Sheet` : papier A4 portrait. Marge haute 15,15 mm, marge gauche 7,25 mm. Cellules de 63,5 × 38,1 mm, pas horizontal 66 mm, pas vertical 38,1 mm. Remplissage ligne par ligne, cases `1..firstCell-1` laissées vides sur la première page, 21 cases par page.
  - `Roll` : papier personnalisé 62 × 29 mm, soit `[0, 0, 175.75, 82.20]` en points. Une étiquette par page.
- Barres : `Picqer\Barcode\BarcodeGeneratorPNG`, types `TYPE_UPC_A`, `TYPE_EAN_13` et `TYPE_CODE_128` selon `Symbology`, intégrées en `data:image/png;base64`. Facteur de largeur ≥ 2 pour la netteté, image redimensionnée en CSS (mm). Hauteur des barres environ 12 mm en planche, 10 mm en rouleau. Valeur en clair sous les barres.
- Texte : police `DejaVu Sans` (accents). Désignation limitée à 2 lignes : `overflow: hidden` et hauteur fixe, sans compter sur `-webkit-line-clamp`, que dompdf ignore. Attributs sur une ligne. `itemCode` en gras, plus grand que la désignation.
- Options dompdf : `isRemoteEnabled = false`, `defaultFont = 'DejaVu Sans'`.

- [ ] **Step 1: Write the failing tests** (compter les pages avec `preg_match_all('#/Type\s*/Page[^s]#', $pdf)`)
  - `testSheetFitsTwentyOneLabelsPerPage` : 21 étiquettes, `Sheet` → commence par `%PDF`, 1 page ; 22 étiquettes → 2 pages.
  - `testFirstCellShiftsLabels` : 20 étiquettes, `firstCell` 3 → 2 pages (19 + 1).
  - `testRollPrintsOneLabelPerPage` : 3 étiquettes, `Roll` → 3 pages.
  - `testLongDesignationDoesNotOverflowCell` : 21 étiquettes à désignation de 120 caractères → 1 page.
  - `testEverySymbologyRenders` : une étiquette `UpcA` `012345678905`, une `Ean13` `4006381333931` et une `Code128` `HV-000001` → PDF produit sans exception.
  - `testInvalidArgumentsThrow` : liste vide, puis `firstCell` 0 et 22 → `\InvalidArgumentException`.
- [ ] **Step 2: Run** `make test c="--filter=LabelPdfRendererTest"` → FAIL.
- [ ] **Step 3: Implement** l'installation des dépendances, l'enum, le DTO, le renderer et le template PDF.
- [ ] **Step 4: Run** le filtre → PASS ; `make qa` vert. Ouvrir un PDF de test à l'œil : générer une planche dans `var/recette/` (jamais commité) et vérifier que les cases sont alignées.
- [ ] **Step 5: Commit** `feat(label): render label sheets and roll labels as PDF`

### Task 5: Construction des lignes d'étiquettes

**Files:**
- Create: `src/Label/LabelMode.php`, `src/Label/LabelLine.php`, `src/Label/LabelLineBuilder.php`
- Modify: `src/Repository/ItemRepository.php` (`findInStockByVariants`)
- Modify: `src/Repository/VariantRepository.php` (`findLabelableWithoutValidEan`)
- Test: `tests/Functional/Label/LabelLineBuilderTest.php` (KernelTestCase)

**Interfaces:**
- Consumes: `BarcodeChoice`, `Label` (Tasks 2, 4) ; `StockPresence::variantIdsInStock`.
- Produces: `enum LabelMode: string { case Items = 'pieces'; case Variants = 'variantes'; }`.
- Produces: `final readonly class LabelLine { __construct(public ?Variant $variant, public ?Item $item, public int $copies) }` with `toLabel(): Label`, `designation(): string` and `code(): string` (code-barres imprimé, pour l'écran). Exactement un des deux, `variant` ou `item`, est non nul.
- Produces: `ItemRepository::findInStockByVariants(list<Variant> $variants): list<Item>` (statut `InStock`, triées par code).
- Produces: `VariantRepository::findLabelableWithoutValidEan(): list<Variant>`. Une requête scalaire (`id`, `ean`) sur les variantes non supprimées, filtrée en PHP avec `!Ean::hasValidCheckDigit()` puis par la règle « étiquetable ». Triées par référence produit puis id.
- Produces `LabelLineBuilder`:
  - `forVariants(list<Variant> $variants, LabelMode $mode): list<LabelLine>` : ne garde que les variantes étiquetables. En mode `Items`, une variante d'un produit `TrackingMode::Serialized` qui a des pièces en stock donne une ligne par pièce (1 exemplaire), et une variante sans pièce en stock donne une ligne de variante. En mode `Variants`, ou pour un produit en quantité, la variante donne une ligne de variante (1 exemplaire).
  - `forProducts(list<Product> $products, LabelMode $mode): list<LabelLine>` : les variantes vivantes des produits, puis `forVariants`.
  - `forItem(Item $item): list<LabelLine>` : une ligne, 1 exemplaire.
  - `forOperation(StockOperation $op): list<LabelLine>` : seulement `PurchaseIn` ou `DonationIn`, sinon liste vide. Un mouvement avec pièce donne une ligne de pièce. Les mouvements sans pièce sont regroupés par variante, et le nombre d'exemplaires est la somme des quantités.
  - `withoutValidEan(): list<LabelLine>` : `findLabelableWithoutValidEan()` en lignes de variante.
  - `hasItemChoice(list<Variant> $variants): bool` : vrai si au moins une variante sérialisée a une pièce en stock. L'écran l'utilise pour afficher ou non le choix du mode.

- [ ] **Step 1: Write the failing tests** (données via `StockScenario::variant` / `receive`, `ImportFixtures`)
  - `testSerializedVariantInItemsModeGivesOneLinePerPiece` : 3 pièces reçues → 3 lignes de pièce, codes `HS-` triés.
  - `testVariantsModeGivesVariantLine` : même variante, mode `Variants` → 1 ligne de variante.
  - `testQuantityProductAlwaysGivesVariantLine` : produit en quantité, mode `Items` → ligne de variante.
  - `testArchivedVariantWithStockIsLabelable` : variante sérialisée avec 1 pièce, puis `archive()`. `forProducts` en mode `Items` → 1 ligne de pièce. La même variante, avec `ean` à null, apparaît dans `withoutValidEan()`.
  - `testInactiveVariantWithoutStockIsExcluded` : variante inactive sans stock → absente de `forProducts` et de `withoutValidEan`.
  - `testWithoutValidEanIncludesInvalidCheckDigit` : variantes d'EAN `null`, `876369003339` et `012345678905` → seules les deux premières.
  - `testEntryOperationLines` : un achat avec 2 pièces d'une variante sérialisée et 5 unités d'une variante en quantité → 2 lignes de pièce et 1 ligne de variante à 5 exemplaires. Une sortie → `[]`.
- [ ] **Step 2: Run** `make test c="--filter=LabelLineBuilderTest"` → FAIL.
- [ ] **Step 3: Implement** l'enum, la ligne, le builder et les deux méthodes de repository.
- [ ] **Step 4: Run** le filtre → PASS ; `make qa` vert.
- [ ] **Step 5: Commit** `feat(label): build label lines from products, items, entries and missing EANs`

### Task 6: Écran « Imprimer des étiquettes » et PDF

**Files:**
- Create: `src/Controller/Label/LabelController.php`
- Create: `templates/label/print.html.twig`
- Create: `assets/controllers/label_format_controller.js`
- Modify: `templates/stock/item/show.html.twig` (bouton « Imprimer l'étiquette »)
- Test: `tests/Functional/Label/LabelControllerTest.php`

**Interfaces:**
- Consumes: `LabelLineBuilder`, `LabelMode` (Task 5), `LabelPdfRenderer`, `LabelFormat` (Task 4).
- Routes, toutes `#[IsGranted('STOCK_MOVE')]` :
  - `label_print` `GET /etiquettes` : paramètres de requête combinables `variantes[]` (ids), `produits[]` (ids), `piece` (code `HS-`), `operation` (id), `sans_ean=1`, `mode` (`pieces` | `variantes`, défaut `pieces`). Les ids inconnus sont ignorés. Le builder fournit les lignes. L'écran affiche le tableau : désignation, attributs, code imprimé, champ « Exemplaires » (entier 0–500, prérempli), case « Retirer ». Il affiche aussi le choix du mode (radios qui resoumettent le GET) seulement si `hasItemChoice`, le format (select `LabelFormat`), la première case (1–21, visible pour `Sheet`) et le bouton « Générer le PDF ». Aucune ligne → message « Aucune étiquette à imprimer. ».
  - `label_pdf` `POST /etiquettes/pdf` : champs `lignes[n][type]` (`variante` | `piece`), `lignes[n][id]` (id de la variante ou code `HS-`), `lignes[n][exemplaires]`, `lignes[n][retirer]`, `format`, `premiere_case`, `_token` (CSRF, id `label_pdf`). Réponse `application/pdf`, `Content-Disposition: inline; filename="etiquettes.pdf"`.
- Règles de `label_pdf` :
  - une ligne inconnue, supprimée, à 0 exemplaire ou retirée est ignorée ;
  - un nombre d'exemplaires négatif ou non numérique compte comme 0 ;
  - total à 0 → réécran 422 « Aucune étiquette à imprimer. » ;
  - total > 500 → réécran 422 « 500 étiquettes au maximum par PDF : réduisez la sélection. » ;
  - `premiere_case` hors 1–21 en `Sheet` → 422 « La première case doit être comprise entre 1 et 21. » ;
  - format inconnu → 422.
- Formulaire HTML : `target="_blank"` et `data-turbo="false"` sur le formulaire PDF.
- `label_format_controller.js` (Stimulus) : enregistre et relit le format choisi sous la clé `localStorage` `hs.label.format`, chaque accès dans un `try/catch`. Il masque la première case quand le format est `rouleau`.
- Fiche pièce : lien `label_print` avec `piece=<code>`, visible pour `STOCK_MOVE`.

- [ ] **Step 1: Write the failing tests**
  - `testViewerIsForbidden` : `ROLE_VIEWER` → 403 sur `GET /etiquettes` et `POST /etiquettes/pdf`.
  - `testItemPageLinksToPrintScreen` : fiche pièce (`ROLE_STOCK`) → lien vers `/etiquettes?piece=HS-…` ; l'écran liste le code.
  - `testModeChoiceShownOnlyForSerializedWithStock` : `variantes[]` d'une variante sérialisée avec pièces → radios du mode présents. Variante en quantité seule → absents.
  - `testPdfIsReturned` : POST valide (2 lignes, `a4`, case 1) → 200, `Content-Type` `application/pdf`, corps commençant par `%PDF`.
  - `testTamperedPdfRequestIsHandled` : `premiere_case` = 0 → 422 + message ; ligne à `-3` exemplaires plus ligne d'id inexistant, seules → 422 « Aucune étiquette à imprimer. » ; ligne valide plus ligne d'id inexistant → 200.
  - `testMoreThanFiveHundredLabelsIsRefused` : une ligne à 501 exemplaires → 422 + message des 500.
  - `testInvalidCsrfIsRefused` : jeton faux → refusé, avec le même code que les autres formulaires CSRF du projet (cf. suppression de lieu).
- [ ] **Step 2: Run** `make test c="--filter=LabelControllerTest"` → FAIL.
- [ ] **Step 3: Implement** le contrôleur, l'écran, le contrôleur Stimulus et le lien de la fiche pièce. Suivre la charte (`docs/superpowers/specs/2026-10-27-charte-graphique-design.md`) et les classes existantes (`table`, `btn`, `field`).
- [ ] **Step 4: Run** le filtre → PASS ; `make qa` vert.
- [ ] **Step 5: Commit** `feat(label): add print screen and PDF endpoint`

### Task 7: Points d'impression du catalogue et des entrées

**Files:**
- Modify: `templates/catalogue/product/show.html.twig` (cases par variante, formulaire GET vers `label_print`)
- Modify: `templates/catalogue/product/index.html.twig` (case par produit, bouton « Étiquettes de la sélection », bouton « Étiquettes des variantes sans EAN »)
- Modify: `src/Form/CatalogueFilterType.php`, `src/Catalogue/CatalogueSearchCriteria.php`, `src/Catalogue/CatalogueSearch.php`, `src/Controller/Catalogue/ProductController.php` (filtre « Sans EAN »)
- Modify: `templates/stock/operation/show.html.twig` (bouton « Imprimer les étiquettes » pour une entrée)
- Test: `tests/Functional/Label/LabelEntryPointsTest.php` ; test existant de la recherche du catalogue

**Interfaces:**
- Consumes: route `label_print` et ses paramètres (Task 6) ; `VariantRepository::findLabelableWithoutValidEan` (Task 5).
- Fiche produit : pour `STOCK_MOVE`, une case `variantes[]` par variante vivante, toutes cochées, et un bouton « Imprimer des étiquettes » (formulaire GET, `data-turbo-frame="_top"`). Pour les lecteurs : ni cases ni bouton.
- Liste du catalogue : pour `STOCK_MOVE`, une case `produits[]` par ligne de la page affichée et un bouton « Étiquettes de la sélection ». Le formulaire des cases est distinct du formulaire de filtres et cible `_top`. Il y a aussi un lien « Étiquettes des variantes sans EAN » → `label_print?sans_ean=1`.
- Filtre catalogue : case `sansEan` « Sans EAN », visible pour tous. Critère `CatalogueSearchCriteria::$withoutValidEan` (bool, défaut false) : produits dont l'id figure parmi les produits de `findLabelableWithoutValidEan()`.
- Détail d'opération : bouton vers `label_print?operation=<id>` si le type est `PurchaseIn` ou `DonationIn` et l'utilisateur a `STOCK_MOVE`.

- [ ] **Step 1: Write the failing tests**
  - `testProductPageOffersVariantCheckboxes` : `ROLE_STOCK` → une case `variantes[]` cochée par variante vivante ; `ROLE_VIEWER` → aucune.
  - `testCatalogueSelectionLeadsToPrintScreen` : `ROLE_STOCK`, liste → cases `produits[]` présentes ; `GET /etiquettes?produits[]=<id>` liste ses variantes.
  - `testWithoutEanButtonListsVariantsWithoutValidEan` : `GET /etiquettes?sans_ean=1` liste la variante sans EAN et celle à clé invalide, pas celle à EAN valide.
  - `testSansEanFilter` : filtre « Sans EAN » coché → seul le produit qui a une variante sans EAN valide apparaît.
  - `testEntryOperationOffersLabels` : détail d'un achat → lien `operation=<id>`, l'écran montre une ligne par pièce et la ligne en quantité au bon nombre d'exemplaires. Détail d'une sortie → pas de lien.
- [ ] **Step 2: Run** `make test c="--filter=LabelEntryPointsTest"` et le test de recherche du catalogue → FAIL sur les nouveaux tests.
- [ ] **Step 3: Implement** les quatre points d'impression et le filtre.
- [ ] **Step 4: Run** le filtre → PASS ; `make qa` vert. Vérifier dans le navigateur (chrome-devtools MCP, `https://hockey-staff.localhost`, viewport mobile 390 px et bureau) : fiche produit → écran → PDF, sélection du catalogue, « sans EAN », entrée de stock. Si une page demande une connexion, demander à l'utilisateur de se connecter.
- [ ] **Step 5: Commit** `feat(label): add print entry points to catalogue and stock entries`

### Task 8: Décisions et statut du lot

**Files:**
- Create: `docs/superpowers/decisions/2026-10-27-lot8-decisions.md` (même forme que `2026-10-27-lot2-decisions.md`)
- Modify: `docs/superpowers/specs/2026-10-27-hockey-staff-domain-design.md` § 9, lot 8 : « **LIVRÉ** (recette manuelle à faire, voir les décisions du lot) », sur le modèle des lots 2 et 7.

- [ ] **Step 1:** Consigner chaque décision prise pendant le lot. Ajouter la recette manuelle restante : imprimer une planche A4 et une étiquette 62 × 29 mm, les scanner à la douchette et au téléphone (UPC-A, EAN-13, Code128), et caler les marges L7160 sur l'imprimante du club.
- [ ] **Step 2:** `make qa` vert.
- [ ] **Step 3: Commit** `docs: record lot 8 decisions and mark lot as delivered`
