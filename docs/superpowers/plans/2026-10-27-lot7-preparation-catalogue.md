# Lot 7 — Préparation du catalogue par IA externe — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permettre de préparer le fichier d'import Bauer avec une IA grand public (template + règles + table de correspondance téléchargeables), accepter les produits sans catégorie, et guider la complétion (prix, catégorie) depuis le tableau de bord.

**Architecture:** Aucune IA côté serveur. Un module `App\CataloguePreparation` charge une table de correspondance versionnée (CSV) et génère le template xlsx ; une page manager sert les 3 téléchargements. L'import du lot 1 devient tolérant à la catégorie vide ou inconnue (avertissement, jamais de création de catégorie). Le tableau de bord et le catalogue gagnent des compteurs/filtres « sans tarif » / « sans catégorie » et une saisie de prix par produit.

**Tech Stack:** Symfony 8.1, PHP 8.5, Doctrine ORM 3.7 / PostgreSQL 18, phpspreadsheet, Twig + Turbo, PHPUnit (WebTestCase, DAMA).

**Spec:** `docs/superpowers/specs/2026-10-27-lot7-preparation-catalogue-design.md`

## Global Constraints

- Aucun appel à une IA depuis l'appli ; aucune lecture des fichiers Bauer bruts par l'appli.
- Les fichiers réels `var/recette/*` ne sont **jamais** lus par un subagent ni commités ; seule la Task 1 (session principale) les lit.
- L'import n'écrit jamais une catégorie qui n'existe pas déjà (plus de `findOrCreatePath` dans l'import).
- Gencode toujours manipulé comme texte ; colonne Gencode du template au format texte (`@`).
- Libellés UI en français ; code, classes, commits en anglais (Conventional Commits), sans trailer de co-auteur.
- Accès : page de préparation, cards, filtres de complétion et saisie prix produit réservés à `ROLE_MANAGER`.
- TDD ; une tâche est finie quand `make qa` est vert ; `graphify update .` après modification de code.
- Travail sur `develop` uniquement.

## Review Focus

1. Ré-import d'un produit existant **catégorisé à la main** avec catégorie vide dans le fichier → catégorie conservée, aucun changement affiché. (Test : Task 3, `testEmptyCategoryKeepsExistingCategory`.)
2. Catégorie du fichier avec casse/espaces différents d'une catégorie existante → rapprochée (via `NameNormalizer`), pas « inconnue ». (Test : Task 3, `testCategoryMatchIsNormalized`.)
3. Catégorie supprimée entre l'analyse et l'application → produit créé sans catégorie, pas d'exception. (Test : Task 3, `testApplyWithCategoryDeletedSinceAnalysisLeavesProductUncategorized`.)
4. Saisie de prix produit alors qu'une variante a déjà un tarif → ce tarif n'est pas écrasé. (Test : Task 5, `testProductPriceOnlyFillsVariantsWithoutPrice`.)
5. Produit supprimé (soft delete) ou variantes toutes inactives → absent des compteurs « sans tarif » / « sans catégorie ». (Test : Task 5, `testCountsIgnoreDeletedProductsAndInactiveVariants`.)

---

### Task 1: Table de correspondance et valeurs d'attributs (session principale uniquement)

Exécutée par Claude dans la session principale (lit `var/recette/`), **pas** par un subagent.

**Files:**
- Create: `resources/catalogue-preparation/bauer-correspondances.csv`
- Create: `resources/catalogue-preparation/bauer-valeurs-attributs.csv`

**Interfaces:**
- Produces: `bauer-correspondances.csv`, séparateur `;`, UTF-8, en-tête
  `onglet;section;categorie1;categorie2;categorie3;marque;candidates` :
  - ligne univoque : `categorie1..3` remplis (niveaux vides autorisés en fin), `candidates` vide ;
  - ligne ambiguë : `categorie1..3` vides, `candidates` = chemins complets séparés par ` | ` (niveaux joints par ` › `) ;
  - `marque` : nom de marque si la section en est une (HOWIES, BLUE SPORT…), sinon vide (= BAUER).
- Produces: `bauer-valeurs-attributs.csv`, en-tête `attribut;valeur`, attribut ∈ `Côté|Modèle/Fit|Largeur|Couleur` (tout le reste = Taille).

- [ ] **Step 1: Extraire** avec un script Python jetable (scratchpad, `python3 -I`) qui rejoue l'analyse de la spec § 2 : jointure tarif ↔ fichier de travail par référence, couples (onglet, section) → triples de catégorie, marques de section, valeurs de Caractéristiques 1/2 par colonne cible.
- [ ] **Step 2: Vérifier** : 99 couples, exactement 5 lignes avec `candidates` (les 5 sections de la spec § 4.4), toutes les catégories citées existent dans la base de dev, aucune donnée autre que des libellés (ni prix, ni EAN, ni référence).
- [ ] **Step 3: Commit**

```bash
git add resources/catalogue-preparation/
git commit -m "feat(preparation): add Bauer correspondence table and attribute values"
```

### Task 2: Catégorie de produit facultative

**Files:**
- Modify: `src/Entity/Product.php:54-56` (JoinColumn `nullable: true`, retirer `Assert\NotNull`)
- Create: migration via `make db-diff`
- Modify: `src/Form/ProductType.php:36-40` (contrainte `new NotNull(message: 'La catégorie est obligatoire.')` sur le champ : la saisie manuelle reste obligatoire)
- Modify: `src/Catalogue/CatalogueSearch.php:28` (`leftJoin`), `src/Import/CatalogueImportAnalyzer.php:62` (`leftJoin`)
- Modify: `templates/catalogue/product/index.html.twig:38`, `templates/catalogue/product/show.html.twig:21` (« Sans catégorie » quand vide)
- Test: `tests/Functional/Catalogue/ProductUncategorizedTest.php`

**Interfaces:**
- Produces: `Product::getCategory(): ?Category` peut retourner `null` en base.

- [ ] **Step 1: Write the failing tests**
  - `testUncategorizedProductAppearsInCatalogueList` : produit persisté sans catégorie → `GET /catalogue` contient sa désignation et « Sans catégorie ».
  - `testUncategorizedProductShowPage` : `GET /catalogue/{id}` → 200, contient « Sans catégorie ».
  - `testManualCreateStillRequiresCategory` : POST du formulaire de création sans catégorie → 422, contient « La catégorie est obligatoire. ».
- [ ] **Step 2: Run** `make test c="--filter=ProductUncategorizedTest"` → FAIL (NOT NULL / jointure interne).
- [ ] **Step 3: Implement** entité + migration (relire : seul `ALTER COLUMN category_id DROP NOT NULL`) + jointures + templates + contrainte de formulaire.
- [ ] **Step 4: Run** `make db-migrate` puis le filtre → PASS ; `make qa` vert.
- [ ] **Step 5: Commit** `feat(catalogue): allow products without category`

### Task 3: Import tolérant à la catégorie vide ou inconnue

**Files:**
- Modify: `src/Enum/ImportAnomalyCode.php` (ajouter `case CategoryUnknown = 'category_unknown';`)
- Modify: `src/Import/RowNormalizer.php:31-43` (Catégorie retirée des champs obligatoires)
- Modify: `src/Import/NormalizedRow.php:46` (docblock `isRejected`)
- Modify: `src/Import/CatalogueImportAnalyzer.php` (résolution des chemins ; `changes()` ~l. 315)
- Modify: `src/Repository/CategoryRepository.php` (nouvelle méthode)
- Modify: `src/Import/CatalogueImportApplier.php:148-171,212-214`
- Modify: `src/Import/ImportDiff.php`, `templates/import/show.html.twig` (info « N produits sans catégorie »)
- Test: `tests/Integration/Import/CatalogueImportCategoryTest.php` (+ adapter les tests lot 1 qui attendaient `MissingRequired` pour la catégorie ou la création de catégories)

**Interfaces:**
- Produces: `CategoryRepository::findByPath(list<string> $names): ?Category` — niveaux normalisés par `NameNormalizer::normalize`, niveaux vides de fin ignorés, `null` si un niveau n'existe pas ; ne crée jamais rien.
- Produces: dans le diff, `newProducts[*]['categoryPath']` vaut `[]` quand la catégorie est vide **ou** inconnue ; l'applier crée alors le produit sans catégorie, `TrackingMode::Quantity`.
- Produces: `ImportDiff::uncategorizedNewProductCount(): int` (nombre de `newProducts` à chemin vide).

Règles (spec § 5.2 et § 7) :
- Chemin non vide introuvable → anomalie `CategoryUnknown` (non bloquante), message `Catégorie « A › B › C » inconnue : produit importé sans catégorie.`, ligne traitée avec un chemin vide.
- Produit existant + chemin vide (vide ou inconnu) → **pas** de changement `category`.
- Applier : lookup par `findByPath` ; `null` (catégorie supprimée entre-temps) → produit sans catégorie.

- [ ] **Step 1: Write the failing tests**
  - `testEmptyCategoryCreatesUncategorizedProduct` : ligne sans catégorie → aucune anomalie bloquante ; après apply, `getCategory() === null` et `TrackingMode::Quantity`.
  - `testUnknownCategoryIsWarningAndNotCreated` : chemin `INCONNUE › X` → anomalie `CategoryUnknown`, run applicable ; après apply aucune catégorie `INCONNUE` en base, produit sans catégorie.
  - `testEmptyCategoryKeepsExistingCategory` : produit existant catégorisé, ré-import catégorie vide → aucun changement `category` dans le diff, catégorie inchangée après apply.
  - `testCategoryMatchIsNormalized` : catégorie existante `EQUIPEMENT › JOUEUR › PATIN`, cellules ` equipement `, `Joueur`, `patin ` → pas d'anomalie, produit rattaché.
  - `testApplyWithCategoryDeletedSinceAnalysisLeavesProductUncategorized` : analyse, suppression de la catégorie, apply → pas d'exception, produit sans catégorie.
  - `testEmptyPriceKeepsStoredPrice` : variante avec tarif saison club 10000, ré-import prix vides → aucun `priceChanges` pour elle, tarif inchangé.
  - `testEmptyPriceOnNewVariantCreatesNoSeasonPrice` : nouvelle variante sans prix → après apply, 0 `SeasonPrice` pour elle.
  - `testReferenceOnlyLineIsStableOnReimport` : ligne référence + désignation + marque, sans EAN ni attribut, importée deux fois → 1 seule variante ; second diff sans nouvelle variante ni `VariantKeyCollision`.
  - `testPreviewListsNewBrands` (fonctionnel) : marque inconnue `NOUVELLE MARQUE` dans le fichier → la page du run analysé la mentionne comme marque créée (si l'aperçu le fait déjà, le test épingle l'existant).
  - `testPreviewShowsUncategorizedCount` (fonctionnel, dans le test du contrôleur d'import) : run analysé avec 2 nouveaux produits sans catégorie → la page contient « 2 produits sans catégorie ».
- [ ] **Step 2: Run** `make test c="--filter=CatalogueImportCategoryTest"` → FAIL sur les cas catégorie (les cas prix / référence seule peuvent déjà passer : ils épinglent l'existant).
- [ ] **Step 3: Implement** ; l'analyzer résout chaque chemin une fois (cache par chemin) en s'appuyant sur les catégories préchargées (l. 57), pas une requête par ligne.
- [ ] **Step 4: Run** le filtre puis `make qa` → PASS (tests lot 1 adaptés, pas supprimés).
- [ ] **Step 5: Commit** `feat(import): accept empty or unknown categories without creating them`

### Task 4: Mode de suivi à la première catégorisation

**Files:**
- Modify: `src/Controller/Catalogue/ProductController.php:102-123` (action `edit`) ou le listener de `src/Form/ProductType.php:50-60`
- Test: `tests/Functional/Catalogue/ProductUncategorizedTest.php`

**Interfaces:**
- Consumes: `TrackingModeResolver::defaultFor(Category): TrackingMode`.

Règle : produit **sans** catégorie avant l'édition qui en reçoit une, mode de suivi soumis identique au mode d'origine → mode = `defaultFor(nouvelle catégorie)`. Un mode modifié par l'utilisateur est respecté. Produit déjà catégorisé : aucune règle automatique. Garde-fou « aucun mouvement » : lot 2.

- [ ] **Step 1: Write the failing tests**
  - `testFirstCategoryAppliesDefaultTrackingMode` : produit sans catégorie en `QUANTITY`, édition → catégorie racine `EQUIPEMENT`, mode soumis inchangé → `SERIALIZED`.
  - `testUserChangedTrackingModeWinsOnFirstCategory` : produit sans catégorie en `SERIALIZED`, édition → catégorie racine `EQUIPEMENT`, mode soumis changé en `QUANTITY` → reste `QUANTITY`.
  - `testCategoryChangeOnCategorizedProductKeepsMode` : produit catégorisé `CONSOMMABLE` en `QUANTITY`, édition → catégorie `EQUIPEMENT`, mode soumis inchangé → reste `QUANTITY`.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement** (capturer `hadCategory` et le mode d'origine avant `handleRequest`). **Step 4: Run** → PASS ; `make qa`.
- [ ] **Step 5: Commit** `feat(catalogue): apply category tracking mode on first categorization`

### Task 5: Requêtes de complétion, filtres catalogue et saisie de prix par produit

**Files:**
- Modify: `src/Repository/ProductRepository.php`
- Modify: `src/Catalogue/CatalogueSearchCriteria.php`, `src/Catalogue/CatalogueSearch.php`
- Modify: `src/Form/CatalogueFilterType.php`, `src/Controller/Catalogue/ProductController.php:29-59`
- Modify: `templates/catalogue/product/index.html.twig`, `templates/catalogue/product/show.html.twig` (bouton « Tarif produit »)
- Create: `src/Form/ProductPriceType.php`, `templates/catalogue/product/price.html.twig`
- Test: `tests/Functional/Catalogue/CatalogueCompletionTest.php`

**Interfaces:**
- Produces: `ProductRepository::countWithoutCategory(): int` — produits non supprimés, `category IS NULL`.
- Produces: `ProductRepository::countMissingClubPrice(Season $season): int` — produits non supprimés ayant ≥ 1 variante **active** sans `SeasonPrice` pour `$season` ou avec `clubPriceTtc IS NULL` (0 est un prix valide).
- Produces: `CatalogueSearchCriteria` + `public bool $withoutCategory = false`, `public ?int $missingPriceSeasonId = null`, mêmes définitions.
- Produces: champs `sansCategorie` et `sansTarif` (cases à cocher) dans `CatalogueFilterType`, affichés pour `ROLE_MANAGER` ; le contrôleur traduit `sansTarif` en `missingPriceSeasonId` = saison courante (ignoré sans saison courante). Les cards de la Task 6 lient vers ces filtres.
- Produces: route `catalogue_product_price` `GET|POST /catalogue/{id}/tarif`, `ROLE_MANAGER` : champs `clubPriceTtc` (obligatoire) + `publicPriceTtc` (facultatif), mêmes types/unités que `SeasonPriceType` ; crée un `SeasonPrice` (saison courante) pour chaque variante active sans tarif, complète `clubPriceTtc` des tarifs existants à club vide, **n'écrase aucun prix renseigné** ; flash `Tarif appliqué à N variante(s).` ; sans saison courante → message, pas de formulaire.

- [ ] **Step 1: Write the failing tests**
  - `testCountsIgnoreDeletedProductsAndInactiveVariants` : 1 produit complet, 1 sans catégorie, 1 sans catégorie supprimé, 1 dont la seule variante sans tarif est inactive, 1 avec variante active sans tarif → `countWithoutCategory() === 1`, `countMissingClubPrice($season) === 1`.
  - `testZeroClubPriceIsAPrice` : tarif club 0 → non compté.
  - `testCatalogueFilterWithoutCategory` / `testCatalogueFilterWithoutPrice` : la liste filtrée ne contient que les produits concernés.
  - `testProductPriceOnlyFillsVariantsWithoutPrice` : 3 variantes actives (club 5000 ; sans tarif ; tarif à club vide) + 1 inactive ; POST club 7000 public 9000 → 5000 inchangé, les deux autres à 7000, l'inactive sans tarif ; flash « Tarif appliqué à 2 variante(s). ».
  - `testProductPriceRequiresManager` : `ROLE_STOCK` → 403.
- [ ] **Step 2: Run** `make test c="--filter=CatalogueCompletionTest"` → FAIL.
- [ ] **Step 3: Implement** selon les interfaces.
- [ ] **Step 4: Run** → PASS ; `make qa`.
- [ ] **Step 5: Commit** `feat(catalogue): add completion filters and product-level price entry`

### Task 6: Cards du tableau de bord

**Files:**
- Modify: `src/Controller/DashboardController.php`, `templates/dashboard/index.html.twig`
- Test: `tests/Functional/DashboardTest.php`

**Interfaces:**
- Consumes: `ProductRepository::countWithoutCategory()`, `countMissingClubPrice(Season)`, filtres `sansCategorie` / `sansTarif` (Task 5).

Rendu (manager uniquement, dans `.stats`) : `data-stat="missing-price"` « Produits sans tarif » (masquée sans saison courante) et `data-stat="missing-category"` « Produits sans catégorie » ; valeur = nombre, ou « Aucun » à 0 ; lien vers le catalogue filtré quand > 0.

- [ ] **Step 1: Write the failing tests**
  - `testManagerSeesMissingPriceAndCategoryCards` : 2 sans catégorie, 1 sans tarif → valeurs `2` et `1`, liens vers le catalogue filtré.
  - `testCardsShowNoneWhenZero` : catalogue complet → « Aucun » dans les deux cards, sans lien.
  - `testMissingPriceCardHiddenWithoutCurrentSeason`.
  - `testViewerDoesNotSeeCompletionCards`.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** → PASS ; `make qa`.
- [ ] **Step 5: Commit** `feat(dashboard): add missing price and missing category cards`

### Task 7: Page « Préparer un import » et téléchargements

**Files:**
- Create: `src/CataloguePreparation/CorrespondenceTable.php`, `src/CataloguePreparation/ImportTemplateBuilder.php`
- Create: `src/Controller/Import/PreparationController.php`, `templates/import/prepare.html.twig`
- Create: `resources/catalogue-preparation/regles-ia.md`
- Modify: `templates/import/index.html.twig`, `templates/dashboard/index.html.twig` (lien « Préparer un import »)
- Test: `tests/Unit/CataloguePreparation/CorrespondenceTableTest.php`, `tests/Integration/CataloguePreparation/ImportTemplateBuilderTest.php`, `tests/Functional/Import/PreparationControllerTest.php`

**Interfaces:**
- Consumes: CSV de la Task 1 ; `SupplierImportMapping` (colonnes source par champ cible) ; `CategoryRepository::createTreeQueryBuilder()`.
- Produces: `CorrespondenceTable::fromCsv(string $path): self` ; `rows(): list<array{onglet: string, section: string, path: list<string>, brand: ?string, candidates: list<list<string>>}>` ; exception explicite si en-tête invalide.
- Produces: `ImportTemplateBuilder::build(SupplierImportMapping $mapping): Spreadsheet` — onglet `Import` : en-têtes = valeurs du mapping dans l'ordre `reference, ean, designation, size, fit, side, width, color, publicPriceTtc, clubPriceTtc, category1, category2, category3, brand`, colonne Gencode au format texte `@` (20 000 lignes) ; onglet `Catégories` : un chemin complet par ligne (`A › B › C`) + 3 colonnes de niveaux, trié ; onglet `Correspondances` : contenu de la table.
- Produces: routes `ROLE_MANAGER` :
  - `import_prepare` `GET /imports/preparer` : mode d'emploi en 4 étapes (télécharger ; donner à l'IA le template, les règles et les 2 fichiers Bauer ; vérifier son compte rendu ; importer via « Nouvel import ») + liens ; un template par fournisseur ayant un mapping.
  - `import_prepare_template` `GET /imports/preparer/{supplier}/template.xlsx` → `modele-import-<fournisseur>.xlsx`.
  - `import_prepare_rules` `GET /imports/preparer/regles.md` → `regles-ia.md`, `text/markdown; charset=UTF-8`, attachment.
  - `import_prepare_correspondences` `GET /imports/preparer/correspondances.csv` → attachment.
- Dossier des ressources injecté via `#[Autowire('%kernel.project_dir%/resources/catalogue-preparation')]`.

`regles-ia.md` : français clair, numéroté, avec exemples ; reprend **intégralement** la spec § 4.1 à § 4.5 ; annexe des valeurs d'attributs (depuis `bauer-valeurs-attributs.csv`) ; insiste : ne jamais inventer (laisser vide), Gencode en texte, aucune catégorie absente de l'onglet `Catégories`, compte rendu § 4.5 obligatoire.

- [ ] **Step 1: Write the failing tests**
  - `CorrespondenceTableTest::testParsesUnambiguousAndAmbiguousRows` (CSV synthétique : univoque, ambiguë à 2 candidates, marque) et `testRejectsInvalidHeader`.
  - `CorrespondenceTableTest::testShippedBauerTableIsValid` : le CSV de `resources/` → 99 lignes, 5 avec `candidates`.
  - `ImportTemplateBuilderTest::testImportSheetHeadersFollowMapping` (14 en-têtes ordonnés, `A1 = 'Référence interne'`, `B1 = 'Gencode'`), `testGencodeColumnIsText` (format `@`), `testCategoriesSheetListsCurrentTree` (catégorie créée en test présente, catégorie supprimée absente).
  - `PreparationControllerTest` : page 200 pour manager avec les 4 étapes et les 3 liens ; 403 pour `ROLE_STOCK` ; template → `Content-Type` xlsx + `Content-Disposition` attachment ; règles → contient « Gencode » et « laisser vide » ; correspondances → première ligne = en-tête CSV.
- [ ] **Step 2: Run** les trois filtres → FAIL.
- [ ] **Step 3: Implement** classes, contrôleur, template, puis rédiger `regles-ia.md`.
- [ ] **Step 4: Run** → PASS ; `make qa`.
- [ ] **Step 5: Commit** `feat(preparation): add import preparation page with template, AI rules and correspondences`

### Task 8: Clôture du lot

**Files:**
- Modify: `README.md` (section « Préparer un import avec une IA »), `docs/superpowers/specs/2026-10-27-hockey-staff-domain-design.md` (lot 7 livré)

- [ ] **Step 1:** README : les 4 étapes de la page, où trouver les fichiers, que faire des cards ensuite.
- [ ] **Step 2:** Recette manuelle de l'utilisateur (hors CI, spec § 8) : template + règles + fichiers 2025-2026 donnés à une IA, résultat importé sur la base de dev → aperçu sans changement de prix ni de catégorie sur les 633 références existantes ; résultat et écarts notés dans le fichier de décisions du lot.
- [ ] **Step 3:** `make qa` vert, `graphify update .`, commit `docs: document lot 7 import preparation`, push `develop`.
