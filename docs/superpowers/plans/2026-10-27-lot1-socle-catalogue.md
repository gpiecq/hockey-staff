# Lot 1 — Socle + catalogue — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Staff authentifié (4 rôles) qui gère saisons, fournisseurs, marques, catégories et un catalogue
produit/variante/tarif-saison, alimenté par un import Excel fournisseur (Bauer) avec aperçu des changements
avant validation.

**Architecture:** Symfony 8.1 monolithe, UI Twig + Turbo + Stimulus (AssetMapper), mobile-first, sans SPA.
Entités Doctrine dans `src/Entity`, logique métier dans des services `src/Catalogue/…` et `src/Import/…`,
contrôleurs fins. L'analyse d'import tourne via Messenger (transport `async`), l'application est synchrone dans
une transaction.

**Tech Stack:** PHP 8.5, Symfony 8.1, Doctrine ORM 3.7 (PostgreSQL 18), Messenger, `phpoffice/phpspreadsheet`,
`stof/doctrine-extensions-bundle`, `dama/doctrine-test-bundle`, PHPUnit 13, PHPStan niveau 9, php-cs-fixer.

**Spec:** `docs/superpowers/specs/2026-10-27-hockey-staff-domain-design.md` (§4, §5, §5.1, §6, §7, §9 lot 1)
et `docs/superpowers/specs/2026-10-27-charte-graphique-design.md` (charte, layout, pagination, autocomplétion —
à lire avant tout écran).

## Global Constraints

- `declare(strict_types=1);` partout ; PHPStan niveau 9 vert ; `make qa` vert à la fin de chaque tâche.
- Toutes les commandes passent par le conteneur : `make test c="…"`, `make sf c="…"`, `make composer c="…"`,
  `make db-diff`, `make db-migrate`. Migrations dans `src/Migrations`.
- Montants en **centimes `int`**, jamais de float. TVA en **points de base `int`** (20 % = `2000`).
- HT = `intdiv(ttc * 10000 + intdiv(10000 + rate, 2), 10000 + rate)` (arrondi au centime le plus proche).
- Rôles : `ROLE_ADMIN > ROLE_MANAGER > ROLE_STOCK > ROLE_VIEWER`. Pas d'inscription publique.
- Interface et messages de validation en français. Libellés de rôles : Admin, Manager, Responsable matériel,
  Lecture seule.
- EAN unique globalement (unique nullable sur `variant.ean`). Un produit = un seul fournisseur.
- Les mouvements de stock n'existent pas encore (lot 2) : **pas de suppression définitive en lot 1** ;
  seulement soft delete (Manager+) sur Supplier, Brand, Product, Variant ; les variantes absentes d'un import
  passent `active = false` (≠ soft delete).
- Une seule saison courante. Libellé saison `^\d{4}-\d{4}$`, 2ᵉ année = 1ʳᵉ + 1, `startsOn < endsOn`.
- Aucune ligne d'import n'est ignorée sans apparaître dans le rapport d'anomalies.
- Commits : messages conventionnels, **sans** trailer `Co-Authored-By` ni mention « Generated with ».
- **Charte** (spec charte §3-§6) : nom affiché « Corsaires · Matériel », `<title>` = `<Page> · Corsaires · Matériel` ;
  jetons CSS `--navy #091626`, `--blue #2368bc`, `--band #1c4e8b`, `--light #5aa2ec`, `--bg #eef3f9`,
  `--surface #ffffff`, `--line #b7cbe4`, `--line-soft #e1e8f1`, `--text #0d1f36`, `--muted #5b6b7e`,
  `--danger #7a1f2b`, `--success #1f6b3a` ; Montserrat 500/700/800 auto-hébergée ; pas de framework CSS, pas de
  mode sombre ; menu latéral bleu ≥ 1024 px, menu bas < 1024 px.
- **Toute liste de données est paginée** : 30 par page via `App\Pagination\Page` + `templates/_pagination.html.twig`,
  compteur « 31–60 sur 633 produits », paramètre `?page=`, filtres conservés.
- **Choix** : petite liste fixe (≤ 5 valeurs connues d'avance : rôle, mode de suivi) → boutons radio
  (`expanded: true`) ; toute autre liste (catégorie, marque, fournisseur, saison, produit) → autocomplétion
  (`symfony/ux-autocomplete`, installée en Task 4). Sans JS le champ reste un `<select>` valide.
- **Menu** : chaque tâche qui livre un écran de liste ajoute son entrée au menu (latéral + panneau « Plus »)
  avec la visibilité par rôle de la spec charte §5 ; aucune entrée pour les lots futurs.

## Review Focus

1. **Gencode lu comme nombre par Excel** (`67235585931` au lieu de `067235585931`) → le reader lit la valeur
   en chaîne ; une valeur de 11 chiffres est complétée à 12 par un `0` à gauche et signalée (`EanPadded`) ;
   toute longueur ∉ {8, 12, 13, 14} → anomalie `EanInvalid`, ligne traitée sans EAN. *(Task 8)*
2. **Colonnes dans un autre ordre / colonne en plus** → mapping par **nom d'en-tête** (insensible casse/espaces/
   accents), jamais par position ; en-tête requis absent → l'analyse échoue avec un message listant les en-têtes
   manquants. *(Task 8)*
3. **Même fichier importé deux fois** → second aperçu sans aucun changement ; l'application est idempotente.
   *(Tasks 9 et 10)*
4. **Prix au format français ou vide** (`"12,5"`, `" 3 "`, `""`, `"N/A"`) → `"12,5"` = 1250 centimes ; vide =
   prix absent signalé (`PriceMissing`) ; non numérique = `PriceInvalid`. *(Task 8)*
5. **Aperçu périmé** : on valide un brouillon alors qu'un autre import du même fournisseur a été appliqué depuis
   son analyse → refus avec message « Le catalogue a été modifié depuis l'analyse, relancez l'analyse. »
   *(Task 10)*
6. **Page hors bornes** : `?page=999` ou `?page=abc` → page ramenée dans `[1, dernière]` (liste vide → page 1,
   message d'état vide), jamais d'erreur 500. *(Task 2)*

---

## File Structure

```
src/
  Entity/        User, Season, Supplier, Brand, Category, Product, Variant, SeasonPrice,
                 SupplierImportMapping, ImportRun ; Trait/TimestampableBlameableTrait
  Enum/          UserRole, TrackingMode, ImportRunStatus, ImportAnomalyCode
  Repository/    un repository par entité
  Security/      UserChecker.php, Voter/CatalogueVoter.php, Voter/UserVoter.php
  Command/       CreateUserCommand.php
  Controller/    SecurityController, DashboardController, Admin/UserController,
                 Catalogue/{Season,Supplier,Brand,Category,Product,Variant}Controller,
                 Import/ImportController
  Form/          un FormType par écran
  Catalogue/     Money/VatCalculator.php, CurrentSeasonProvider.php, TrackingModeResolver.php,
                 CatalogueSearch.php, CatalogueSearchCriteria.php
  Import/        Reader/SpreadsheetReader.php, RowNormalizer.php, NormalizedRow.php, ImportAnomaly.php,
                 CatalogueImportAnalyzer.php, ImportDiff.php, CatalogueImportApplier.php,
                 Message/AnalyzeImportRun.php, MessageHandler/AnalyzeImportRunHandler.php, Exception/*
  Pagination/    Page.php
templates/       base.html.twig (layout mobile-first + nav par rôle), _pagination.html.twig, _nav.html.twig,
                 security/, admin/, catalogue/, import/
assets/styles/app.css, assets/fonts/montserrat-latin-{500,700,800}-normal.woff2, assets/images/logo-corsaires.png
public/favicon.ico, public/icons/{icon-192,icon-512,apple-touch-icon}.png
tests/
  Support/       UserFactory.php, CatalogueSpreadsheetFactory.php (génère des .xlsx synthétiques)
  Unit/          Catalogue/…, Import/…
  Functional/    Security/…, Admin/…, Catalogue/…, Import/…, Command/…
```

---

### Task 1: Utilisateurs, authentification et layout

**Files:**
- Create: `src/Entity/User.php`, `src/Repository/UserRepository.php`, `src/Enum/UserRole.php`,
  `src/Security/UserChecker.php`, `src/Controller/SecurityController.php`, `src/Controller/DashboardController.php`,
  `src/Command/CreateUserCommand.php`, `templates/security/login.html.twig`, `templates/dashboard/index.html.twig`,
  `templates/_nav.html.twig`, `assets/styles/app.css`, `assets/fonts/montserrat-latin-{500,700,800}-normal.woff2`,
  `assets/images/logo-corsaires.png`, `public/favicon.ico`, `public/icons/{icon-192,icon-512,apple-touch-icon}.png`,
  `tests/Support/UserFactory.php`, `tests/Functional/Security/LoginTest.php`, `tests/Functional/LayoutTest.php`,
  `tests/Functional/Command/CreateUserCommandTest.php`
- Modify: `config/packages/security.yaml`, `templates/base.html.twig`, `phpunit.dist.xml` (extension DAMA),
  `composer.json`

**Interfaces:**
- Produces: `App\Entity\User` (`getId(): ?int`, `getEmail(): string`, `getFullName(): string`,
  `getRole(): UserRole`, `setRole(UserRole)`, `isEnabled(): bool`, implémente `UserInterface`,
  `PasswordAuthenticatedUserInterface`) ; `App\Enum\UserRole: string` cases `Admin='ROLE_ADMIN'`,
  `Manager='ROLE_MANAGER'`, `Stock='ROLE_STOCK'`, `Viewer='ROLE_VIEWER'` + `label(): string` ;
  `tests/Support/UserFactory::create(EntityManagerInterface $em, UserRole $role, string $email = 'user@example.test', string $password = 'password123'): User` ;
  routes `app_login` (`/login`), `app_logout`, `app_dashboard` (`/`).

- [ ] **Step 1:** `make composer c="require --dev dama/doctrine-test-bundle"` ; activer l'extension PHPUnit
  `DAMA\DoctrineTestBundle\PHPUnit\PHPUnitExtension` dans `phpunit.dist.xml`.
- [ ] **Step 2: Write failing tests** `LoginTest` :
  - `testAnonymousIsRedirectedToLogin` : GET `/` → redirection vers `/login`.
  - `testValidCredentialsReachDashboard` : utilisateur Viewer, soumission du formulaire → `/` 200, contient son
    nom complet.
  - `testInvalidPasswordShowsError` : message « Identifiants invalides. ».
  - `testDisabledUserCannotLogIn` : `enabled=false` → refusé, message « Compte désactivé. ».
  - `testRememberMeCookieIsSet` : case « Rester connecté » cochée → cookie `REMEMBERME` présent.
  - `testRoleHierarchy` : un Admin est autorisé pour `ROLE_VIEWER` et `ROLE_STOCK` ; un Stock ne l'est pas pour
    `ROLE_MANAGER`.
- [ ] **Step 3:** Run `make test c="--filter=LoginTest"` → FAIL (route/entité absentes).
- [ ] **Step 4: Implement** `User` (table `app_user` ; `email` unique, `firstName`, `lastName`, `password`,
  `role` enum stocké en string, `enabled` bool défaut true ; `getRoles()` renvoie `[$this->role->value]`).
  `security.yaml` : provider entity sur `email`, `form_login` (login_path/check_path `app_login`,
  `enable_csrf: true`), `logout`, `remember_me` (secret `%kernel.secret%`, lifetime 31536000), `user_checker`,
  `role_hierarchy` conforme aux Global Constraints, `access_control` : `^/login` PUBLIC_ACCESS, `^/` ROLE_VIEWER
  (l'entrypoint `/api` reste accessible pour le smoke test existant ou le test est adapté pour s'authentifier).
  Charte (spec charte §3-§6) : copier depuis `/Users/gpiecq/development/web/hockey-vestiaire/assets/` le logo
  (`images/logo-corsaires.png`, **inchangé**) et les polices `fonts/montserrat-latin-{500,700,800}-normal.woff2` ;
  générer une fois (`magick` ou `sips`) `public/favicon.ico` (16/32/48) et `public/icons/` (192, 512,
  apple-touch 180) depuis le logo, tout commiter. Layout `base.html.twig` : `<html lang="fr">`, meta viewport,
  `<title>{% block title %}…{% endblock %} · Corsaires · Matériel</title>`, liens favicon/apple-touch (le favicon
  SVG Symfony par défaut est retiré), menu latéral (≥ 1024 px) et menu bas + panneau « Plus » (< 1024 px)
  rendus par `_nav.html.twig` (en lot 1 à ce stade : Tableau de bord ; Déconnexion), `aria-current="page"` sur
  l'entrée active, zone flash (succès/erreur/info), barre utilisateur « Nom · Libellé du rôle ».
  `app.css` : jetons de la Global Constraint Charte + composants de la spec charte §6 (boutons, champs, radios en
  tuiles, tableaux → cartes sur mobile, badges, flash, focus `3px solid var(--light)`, cibles ≥ 44 px).
  Écran de connexion : carte blanche centrée, logo ~120 px, « Corsaires · Matériel », case « Rester connecté ».
  Tableau de bord : titre + message d'accueil (enrichi en Task 11).
- [ ] **Step 5:** `make db-diff && make db-migrate` ; Run `make test c="--filter=LoginTest"` → PASS.
- [ ] **Step 5b: Failing test** `LayoutTest` (utilisateur Viewer connecté, GET `/`) : `<title>` se termine par
  `· Corsaires · Matériel` ; `link[rel="icon"][href*="favicon.ico"]` et `link[rel="apple-touch-icon"]` présents ;
  `img[src*="logo-corsaires"]` présent ; `nav [aria-current="page"]` = « Tableau de bord » ; `GET /favicon.ico`
  → 200. Page `/login` : contient « Corsaires · Matériel » et le logo. → implémenter → PASS.
- [ ] **Step 6: Failing test** `CreateUserCommandTest::testCreatesAdmin` : `app:user:create a@b.fr Prénom Nom
  ROLE_ADMIN --password=secret123` → code 0, user en base avec un hash ≠ mot de passe ; rôle inconnu → code ≠ 0 ;
  email existant → code ≠ 0.
- [ ] **Step 7: Implement** `CreateUserCommand` (`app:user:create email firstName lastName role [--password]`,
  demande le mot de passe en masqué s'il est absent) → PASS.
- [ ] **Step 8:** `make qa` vert, commit `feat(security): add staff users, login and role hierarchy`.

### Task 2: Gestion des utilisateurs (Admin)

**Files:**
- Create: `src/Controller/Admin/UserController.php`, `src/Form/UserType.php`, `src/Security/Voter/UserVoter.php`,
  `src/Pagination/Page.php`, `templates/_pagination.html.twig`, `templates/admin/user/{index,form}.html.twig`,
  `tests/Unit/Pagination/PageTest.php`, `tests/Functional/Admin/UserControllerTest.php`
- Modify: `templates/_nav.html.twig` (entrée « Utilisateurs », groupe Admin, Admin seulement)

**Interfaces:**
- Consumes: `User`, `UserRole`, `UserFactory` (Task 1).
- Produces: routes `admin_user_index`, `admin_user_new`, `admin_user_edit` sous `/admin/users` ; `UserVoter`
  attribut `USER_EDIT`.
  - `App\Pagination\Page` (final readonly, `@template T`) : `list<T> $items`, `int $page`, `int $perPage`,
    `int $total` ; `lastPage(): int` (≥ 1) ; `firstIndex(): int` (1-based, 0 si vide) ; `lastIndex(): int` ;
    `static fromQuery(Doctrine\ORM\QueryBuilder $qb, int|string|null $page, int $perPage = 30): self` (Doctrine
    `Paginator`, page non numérique → 1, page ramenée dans `[1, lastPage]`).
  - `templates/_pagination.html.twig` : `{% include '_pagination.html.twig' with {page: <Page>, noun: 'utilisateurs'} %}`
    → compteur « {firstIndex}–{lastIndex} sur {total} {noun} » + liens précédent / 1 / … / courante ± 1 / … /
    dernière / suivant, tous les paramètres de requête courants conservés, page courante `aria-current="page"` ;
    rien d'affiché si `lastPage() == 1` sauf le compteur.

- [ ] **Step 1: Failing tests** : Manager GET `/admin/users` → 403 ; Admin liste, crée (email, prénom, nom,
  rôle, mot de passe ≥ 8 caractères) et modifie rôle/activation ; email en doublon → « Cet email est déjà
  utilisé. » ; un Admin ne peut ni se désactiver ni retirer son propre rôle Admin → erreur de formulaire ;
  le rôle est saisi par 4 boutons radio (libellés Admin, Manager, Responsable matériel, Lecture seule) ;
  35 utilisateurs → page 1 « 1–30 sur 35 utilisateurs », `?page=2` → « 31–35 sur 35 utilisateurs »,
  `?page=999` → page 2, `?page=abc` → page 1 (jamais 500).
  `PageTest` (unitaire, via le constructeur) : total 0 → `lastPage()` 1, `firstIndex()` 0 ; total 61 / 30 →
  `lastPage()` 3, page 3 → `firstIndex()` 61, `lastIndex()` 61.
- [ ] **Step 2:** Run → FAIL. **Step 3: Implement** (mot de passe optionnel en édition = inchangé).
  **Step 4:** Run → PASS. **Step 5:** `make qa`, commit `feat(admin): manage staff users`.

### Task 3: Socle doctrine (horodatage, auteur, soft delete) + saisons

**Files:**
- Create: `src/Entity/Trait/TimestampableBlameableTrait.php`, `src/Entity/Season.php`,
  `src/Repository/SeasonRepository.php`, `src/Catalogue/CurrentSeasonProvider.php`,
  `src/Controller/Catalogue/SeasonController.php`, `src/Form/SeasonType.php`, `templates/catalogue/season/*`,
  `tests/Unit/Catalogue/SeasonValidationTest.php`, `tests/Functional/Catalogue/SeasonControllerTest.php`
- Modify: `composer.json`, `config/packages/stof_doctrine_extensions.yaml`, `config/packages/doctrine.yaml`
  (filtre softdeleteable), `templates/_nav.html.twig` (« Saisons », groupe Catalogue, Viewer+)

**Interfaces:**
- Produces: trait `TimestampableBlameableTrait` (`createdAt`, `updatedAt`, `createdBy: ?User`, `updatedBy: ?User`,
  attributs Gedmo) ; soft delete via `#[Gedmo\SoftDeleteable(fieldName: 'deletedAt')]` + filtre activé ;
  `Season` (`getLabel(): string`, `getStartsOn(): \DateTimeImmutable`, `getEndsOn(): \DateTimeImmutable`,
  `isCurrent(): bool`) ; `CurrentSeasonProvider::get(): ?Season`, `CurrentSeasonProvider::makeCurrent(Season $season): void`
  (une seule courante, flush inclus) ; routes `catalogue_season_*` sous `/saisons`.

- [ ] **Step 1:** `make composer c="require stof/doctrine-extensions-bundle"` ; activer timestampable,
  blameable, softdeleteable pour l'EM par défaut.
- [ ] **Step 2: Failing tests** : `SeasonValidationTest` (validator du kernel) — `2026-2027` valide ;
  `2026-2028`, `26-27` invalides ; `startsOn >= endsOn` invalide ; libellé unique. `SeasonControllerTest` —
  Manager crée `2026-2027` (01/08/2026 → 31/07/2027) ; rendre courante une 2ᵉ saison → une seule ligne
  `is_current = true` en base ; Stock → 403 sur création ; Viewer voit la liste ; `createdBy` = l'utilisateur
  connecté.
- [ ] **Step 3:** Run → FAIL. **Step 4: Implement** (`makeCurrent` en une transaction : reset puis set ; index
  unique partiel PostgreSQL `WHERE is_current` ajouté dans la migration ; liste paginée via `Page`, saisons
  triées par libellé décroissant ; dates en `DateType` widget `single_text`). **Step 5:** `make db-diff`, migrate,
  Run → PASS. **Step 6:** `make qa`, commit `feat(catalogue): add seasons with single current season`.

### Task 4: Fournisseurs, marques, catégories

**Files:**
- Create: `src/Entity/{Supplier,Brand,Category}.php` + repositories, `src/Security/Voter/CatalogueVoter.php`,
  `src/Controller/Catalogue/{Supplier,Brand,Category}Controller.php`, forms, templates,
  `tests/Functional/Catalogue/{Supplier,Brand,Category}ControllerTest.php`,
  `tests/Functional/Catalogue/CategoryRepositoryTest.php`, `tests/Functional/Catalogue/CategoryAutocompleteTest.php`
- Modify: `composer.json` (`symfony/ux-autocomplete`), `importmap.php`, `assets/controllers.json`,
  `assets/styles/app.css` (habillage Tom Select), `templates/_nav.html.twig` (« Fournisseurs », groupe Catalogue,
  Viewer+ ; marques et catégories accessibles depuis la page Fournisseurs ou en entrées propres)

**Interfaces:**
- Consumes: trait Task 3.
- Produces: `Supplier` (`name` unique, soft delete), `Brand` (`name` unique, soft delete), `Category` (`name`,
  `parent: ?Category`, `children`, `getDepth(): int` 1-3, `getPath(): string` « EQUIPEMENT › JOUEUR › PATIN »,
  `getRoot(): Category`) ; `CategoryRepository::findOrCreatePath(list<?string> $names): Category` (crée les
  niveaux manquants, ignore les niveaux null/vides de fin) ; `CatalogueVoter` attributs `CATALOGUE_VIEW`
  (Viewer+) et `CATALOGUE_EDIT` (Manager+).

- [ ] **Step 1: Failing tests** : `CategoryRepositoryTest` — profondeur 4 refusée par validation ; unicité
  `(parent, name)` ; `findOrCreatePath(['EQUIPEMENT','JOUEUR','PATIN'])` appelé deux fois → 3 catégories en
  base ; `findOrCreatePath(['CONSOMMABLE', null, null])` → racine seule. Contrôleurs : CRUD Manager, 403 pour
  Stock en écriture, lecture Viewer, soft delete d'un fournisseur → absent de la liste, `deleted_at` non null ;
  listes fournisseurs, marques, catégories paginées (35 fournisseurs → 2 pages).
  `CategoryAutocompleteTest` : le champ « Catégorie parente » du formulaire catégorie est une autocomplétion
  serveur (attribut `data-controller` d'autocomplétion sur le champ — `symfony--ux-autocomplete--autocomplete` ou celui du contrôleur maison) ; son URL de suggestions, appelée
  avec `query=pat` par un Manager, renvoie en JSON les catégories dont le chemin contient « PAT » (insensible
  casse), libellé = `getPath()`, et pas les autres ; soumission sans JS avec l'id d'une catégorie existante →
  enregistrée.
- [ ] **Step 2:** FAIL. **Step 3: Implement** (noms normalisés : trim, espaces multiples réduits, majuscules ;
  `'PROTEGE GENOUX '` → `'PROTEGE GENOUX'`). `make composer c="require symfony/ux-autocomplete"` ; si
  incompatible Symfony 8.1/AssetMapper : Tom Select via importmap + contrôleur Stimulus maison, même contrat
  (champ `EntityType`, URL de suggestions JSON `{results: [{value, text}]}`) — décision consignée dans le
  rapport. Habillage : bordure `--blue` à l'ouverture, option survolée `#e3edf9`. **Step 4:** migrate, PASS. **Step 5:** `make qa`, commit
  `feat(catalogue): add suppliers, brands and category tree`.

### Task 5: Produits, variantes, tarifs saison

**Files:**
- Create: `src/Entity/{Product,Variant,SeasonPrice}.php` + repositories, `src/Enum/TrackingMode.php`,
  `src/Catalogue/Money/VatCalculator.php`, `src/Catalogue/TrackingModeResolver.php`,
  `tests/Unit/Catalogue/{VatCalculatorTest,TrackingModeResolverTest,VariantTest}.php`,
  `tests/Functional/Catalogue/CatalogueConstraintsTest.php`

**Interfaces:**
- Consumes: Supplier, Brand, Category, Season.
- Produces:
  - `TrackingMode: string` cases `Serialized='serialized'`, `Quantity='quantity'`.
  - `Product` : `supplier` (requis), `reference` (unique par fournisseur), `designation`, `brand`, `category`,
    `trackingMode`, `variants` ; soft delete.
  - `Variant` : `product`, `ean: ?string` (unique global), `size`, `fit`, `side`, `width`, `color` (`?string`),
    `extraAttributes: array<string,string>` (JSON), `active: bool` (défaut true), `getMatchKey(): string` =
    `implode('|', [size, fit, side, width, color])` avec `''` pour null, colonne `match_key` persistée,
    unique `(product_id, match_key)` ; `getLabel(): string` (attributs non vides joints par « · ») ; soft delete.
  - `SeasonPrice` : `variant`, `season`, `clubPriceTtc: ?int`, `publicPriceTtc: ?int`, `vatRate: int` (défaut
    2000), unique `(variant_id, season_id)`, `getClubPriceHt(): ?int` via `VatCalculator`.
  - `VatCalculator::htFromTtc(int $ttcCents, int $rateBasisPoints): int`.
  - `TrackingModeResolver::defaultFor(Category $category): TrackingMode` → `Serialized` si la racine est
    `EQUIPEMENT`, sinon `Quantity`.

- [ ] **Step 1: Failing tests** : `VatCalculatorTest` — (360, 2000) → 300 ; (50, 2000) → 42 ; (1, 2000) → 1 ;
  (0, 2000) → 0 ; (1000, 550) → 948. `TrackingModeResolverTest` — PATIN sous EQUIPEMENT › JOUEUR → Serialized ;
  CONSOMMABLE → Quantity. `VariantTest` — matchKey de (`SR L`, `FIT2`, null, null, `NOIR`) = `SR L|FIT2|||NOIR`.
  `CatalogueConstraintsTest` (DB) — même EAN sur deux variantes de produits différents → violation ; deux
  variantes sans EAN autorisées ; 2ᵉ `SeasonPrice` même variante/saison → violation ; même référence chez deux
  fournisseurs autorisée.
- [ ] **Step 2:** FAIL. **Step 3: Implement.** **Step 4:** migrate, PASS. **Step 5:** `make qa`, commit
  `feat(catalogue): add products, variants and season prices`.

### Task 6: Consultation et recherche du catalogue

**Files:**
- Create: `src/Catalogue/CatalogueSearch.php`, `src/Catalogue/CatalogueSearchCriteria.php`,
  `src/Controller/Catalogue/ProductController.php` (index, show), `templates/catalogue/product/{index,show}.html.twig`,
  `tests/Functional/Catalogue/ProductBrowseTest.php`
- Modify: `templates/_nav.html.twig` (« Produits » ; menu bas mobile : Tableau de bord, Produits, Plus)

**Interfaces:**
- Consumes: Product, Variant, SeasonPrice, `CurrentSeasonProvider::get()`, `CatalogueVoter::CATALOGUE_VIEW`,
  `Page::fromQuery()` (Task 2), autocomplétion (Task 4).
- Produces: `CatalogueSearchCriteria` (readonly : `?string $query`, `?int $categoryId`, `?int $supplierId`,
  `?int $brandId`, `bool $includeInactive = false`, `int $page = 1`) ;
  `CatalogueSearch::search(CatalogueSearchCriteria $c): Page<Product>` (`App\Pagination\Page`, 30 par page) ;
  `CatalogueSearch::findVariantByEan(string $ean): ?Variant` ; routes `catalogue_product_index` (`/catalogue`),
  `catalogue_product_show` (`/catalogue/{id}`).

- [ ] **Step 1: Failing tests** : query = EAN exact `067235585931` → redirection vers la fiche produit ;
  query `jugulaire` (insensible casse et accents) trouve « JUGULAIRE 2 PARTIES » ; query `1028478` trouve par
  référence ; filtre sur la catégorie racine inclut les sous-catégories ; produit dont toutes les variantes sont
  inactives masqué sauf `includeInactive` ; produit soft-deleted jamais affiché ; fiche : variantes avec prix club
  TTC, club HT et public TTC de la **saison courante**, « Tarif absent » si aucun, badge « Plus au catalogue »
  si inactive ; 35 produits → « 1–30 sur 35 produits », la page 2 conserve `query` et les filtres ; filtres
  Catégorie / Fournisseur / Marque en autocomplétion (Task 4) ; nav : entrée « Produits » (groupe Catalogue,
  Viewer+) active sur `/catalogue`.
- [ ] **Step 2:** FAIL. **Step 3: Implement** (`ILIKE` sur `unaccent(designation)` et `reference` ; extension
  `unaccent` créée dans une migration ; formulaire de recherche en GET dans un Turbo Frame).
  **Step 4:** PASS. **Step 5:** `make qa`, commit `feat(catalogue): browse and search catalogue`.

### Task 7: Saisie manuelle produit / variante / tarif

**Files:**
- Create: `src/Form/{ProductType,VariantType,SeasonPriceType}.php`, actions new/edit/delete dans
  `ProductController` et `src/Controller/Catalogue/VariantController.php`, templates,
  `tests/Functional/Catalogue/ProductEditTest.php`

**Interfaces:**
- Consumes: `CatalogueVoter::CATALOGUE_EDIT`, `TrackingModeResolver::defaultFor()`, `CurrentSeasonProvider::get()`.

- [ ] **Step 1: Failing tests** : Manager crée un produit (fournisseur, référence, désignation, marque, catégorie)
  → `trackingMode` pré-rempli par `defaultFor` et modifiable ; variante avec un EAN déjà pris → « Ce code EAN est
  déjà utilisé. » ; prix saisi `12,50` € → stocké `1250` ; ajout/édition du tarif de la saison courante ;
  soft delete produit (POST + CSRF) → masqué ; Stock et Viewer → 403 sur toutes ces actions ;
  formulaire produit : fournisseur, marque, catégorie en autocomplétion, mode de suivi en 2 boutons radio
  (« Sérialisé », « Quantité »).
- [ ] **Step 2:** FAIL. **Step 3: Implement** (`MoneyType` avec `divisor: 100`, `currency: 'EUR'`,
  `input: 'integer'`). **Step 4:** PASS. **Step 5:** `make qa`, commit
  `feat(catalogue): manual product and variant editing`.

### Task 8: Mapping d'import, lecture et normalisation du fichier

**Files:**
- Create: `src/Entity/SupplierImportMapping.php` + repository, `src/Import/Reader/SpreadsheetReader.php`,
  `src/Import/Exception/MissingColumnsException.php`, `src/Import/RowNormalizer.php`, `src/Import/NormalizedRow.php`,
  `src/Enum/ImportAnomalyCode.php`, `src/Import/ImportAnomaly.php`, migration de données (fournisseur + mapping
  Bauer), `tests/Support/CatalogueSpreadsheetFactory.php`,
  `tests/Unit/Import/{SpreadsheetReaderTest,RowNormalizerTest}.php`
- Modify: `composer.json`

**Interfaces:**
- Produces:
  - `SupplierImportMapping` : `supplier` (1-1), `columns: array<string,string>` (champ cible → en-tête source),
    `colorAliases: array<string,string>`. Champs cibles : `reference`, `ean`, `designation`, `size`, `fit`,
    `side`, `width`, `color`, `publicPriceTtc`, `clubPriceTtc`, `category1`, `category2`, `category3`, `brand`.
    Requis : `reference`, `designation`, `category1`, `brand`.
  - Migration de données Bauer : fournisseur `BAUER` + mapping avec les en-têtes exacts du fichier (spec §5) ;
    `colorAliases` : `BLK→NOIR`, `WHT→BLANC`, `NAVY→MARINE`, `NAV→MARINE`, `RED→ROUGE`.
  - `SpreadsheetReader::read(string $path, SupplierImportMapping $m): iterable<int, array<string, ?string>>`
    (clé = n° de ligne Excel, valeurs indexées par champ cible) ; lève `MissingColumnsException` (en-têtes
    manquants dans le message).
  - `RowNormalizer::normalize(int $line, array<string,?string> $raw, SupplierImportMapping $m): NormalizedRow`.
  - `NormalizedRow` (readonly) : `line`, `reference`, `ean: ?string`, `designation`, `size`, `fit`, `side`,
    `width`, `color` (`?string`), `clubPriceTtc: ?int`, `publicPriceTtc: ?int`, `categoryPath: list<string>`,
    `brand`, `anomalies: list<ImportAnomaly>`, `matchKey(): string` (même formule que `Variant::getMatchKey()`).
  - `ImportAnomalyCode: string` : `EanMissing`, `EanPadded`, `EanInvalid`, `EanDuplicateInFile`,
    `EanOtherSupplier`, `EanChanged`, `PriceMissing`, `PriceInvalid`, `ColorMovedFromSize`, `ColorAliased`,
    `MissingRequired`.
  - `ImportAnomaly` (readonly) : `line: int`, `code: ImportAnomalyCode`, `message: string` (français).
  - `CatalogueSpreadsheetFactory::create(list<array<string,mixed>> $rows, ?list<string> $headers = null): string`
    (écrit un .xlsx temporaire, en-têtes Bauer par défaut, retourne le chemin).

- [ ] **Step 1:** `make composer c="require phpoffice/phpspreadsheet"`.
- [ ] **Step 2: Failing tests** `SpreadsheetReaderTest` : colonnes permutées + une colonne inconnue → lecture
  correcte par en-tête ; en-tête `"  Gencode "` reconnu ; en-tête `Désignation` absent → `MissingColumnsException`
  mentionnant « Désignation » ; lignes entièrement vides ignorées ; cellule numérique `67235585931` → chaîne
  `"67235585931"` (jamais `6.72E+10`).
  `RowNormalizerTest` :
  - EAN `67235585931` → `067235585931` + `EanPadded` ; `12345` → null + `EanInvalid` ; vide → null + `EanMissing`.
  - prix `"0.5"` → 50 ; `"12,5"` → 1250 ; `" 3 "` → 300 ; `""` → null + `PriceMissing` ; `"N/A"` → null +
    `PriceInvalid`.
  - taille `NOIR` sans couleur → size null, color `NOIR` + `ColorMovedFromSize` ; taille `BLANC` avec une
    couleur déjà renseignée → taille inchangée.
  - couleur `BLK` → `NOIR` + `ColorAliased` ; `BKR` (inconnue) → inchangée, sans anomalie.
  - catégorie (`EQUIPEMENT`, `GARDIEN`, `PROTEGE GENOUX `) → `['EQUIPEMENT','GARDIEN','PROTEGE GENOUX']` ;
    (`CONSOMMABLE`, null, null) → `['CONSOMMABLE']`.
  - référence vide → `MissingRequired`.
  - mots-couleur reconnus dans la taille : `NOIR`, `BLANC`, `ROUGE`, `BLEU`, `MARINE`, `GRIS`, `ROSE`, `VERT`,
    `ORANGE`, `JAUNE`, `VIOLET` + clés de `colorAliases`.
- [ ] **Step 3:** FAIL. **Step 4: Implement** (reader read-only, valeurs lues en chaîne ; normalisation
  d'en-tête : trim, minuscules, sans accents, espaces multiples réduits). **Step 5:** PASS.
  **Step 6:** `make qa`, commit `feat(import): read and normalize supplier spreadsheets`.

### Task 9: Analyse d'import (diff) asynchrone

**Files:**
- Create: `src/Entity/ImportRun.php` + repository, `src/Enum/ImportRunStatus.php`,
  `src/Import/CatalogueImportAnalyzer.php`, `src/Import/ImportDiff.php`, `src/Import/Message/AnalyzeImportRun.php`,
  `src/Import/MessageHandler/AnalyzeImportRunHandler.php`, `tests/Functional/Import/CatalogueImportAnalyzerTest.php`,
  `tests/Functional/Import/AnalyzeImportRunHandlerTest.php`
- Modify: `config/packages/messenger.yaml` (routing `App\Import\Message\AnalyzeImportRun: async`)

**Interfaces:**
- Consumes: `SpreadsheetReader`, `RowNormalizer`, `NormalizedRow`, repositories catalogue.
- Produces:
  - `ImportRunStatus: string` : `Pending`, `Analyzed`, `Failed`, `Applied`, `Cancelled`.
  - `ImportRun` : `supplier`, `season`, `originalFilename`, `storedPath` (sous `var/imports/`), `status`,
    `diff: ?array` (JSON de `ImportDiff::toArray()`), `error: ?string`, `analyzedAt`, `appliedAt`, `createdBy`.
  - `ImportDiff` (`toArray()` / `fromArray()`) : `newProducts`, `newVariants`, `changedVariants`
    (`variantId`, `changes: array<string, array{from:?string,to:?string}>`), `priceChanges` (`variantId: ?int`,
    `line`, `club: {from:?int,to:?int}`, `public: {from:?int,to:?int}`), `deactivatedVariantIds: list<int>`,
    `reactivatedVariantIds: list<int>`, `rejectedLines: list<int>`, `anomalies: list<array{line:int, code:string, message:string}>`,
    `summary(): array<string,int>` (clés `newProducts`, `newVariants`, `changedVariants`, `priceChanges`,
    `deactivated`, `reactivated`, `rejected`, `anomalies`) ; `hasChanges(): bool`.
  - `CatalogueImportAnalyzer::analyze(ImportRun $run): ImportDiff` (lecture seule, **n'écrit rien** dans le
    catalogue).
  - `AnalyzeImportRun(int $importRunId)` ; le handler passe le run à `Analyzed` (diff stocké, `analyzedAt`) ou
    `Failed` (`error` = message lisible).

- [ ] **Step 1: Failing tests** `CatalogueImportAnalyzerTest`, fichiers générés par `CatalogueSpreadsheetFactory` :
  - catalogue vide + 3 lignes (2 variantes d'une référence, 1 autre) → 2 `newProducts`, 3 `newVariants`,
    3 `priceChanges` (from null).
  - rapprochement par EAN : désignation changée → `changedVariants` avec `designation: {from, to}` ; prix club
    300 → 350 → `priceChanges`.
  - rapprochement sans EAN par référence + attributs → aucune création.
  - variante existante absente du fichier → `deactivatedVariantIds` ; variante inactive présente →
    `reactivatedVariantIds`.
  - EAN en doublon dans le fichier → 2ᵉ ligne dans `rejectedLines` + `EanDuplicateInFile`.
  - EAN d'une variante d'un **autre** fournisseur → ligne rejetée + `EanOtherSupplier`.
  - même référence + attributs mais EAN différent de celui en base → ligne rejetée + `EanChanged`.
  - catalogue déjà à jour avec ce fichier → `hasChanges()` false.
  - après `analyze()`, nombres de `Product`, `Variant`, `SeasonPrice` en base inchangés.
  `AnalyzeImportRunHandlerTest` : run `Pending` → `Analyzed` avec diff ; fichier sans en-tête requis → `Failed`,
  `error` cite l'en-tête manquant.
- [ ] **Step 2:** FAIL. **Step 3: Implement** (variantes du fournisseur préchargées et indexées par EAN et par
  `reference|matchKey`, plus un index global des EAN des autres fournisseurs ; un fichier de 2 468 lignes
  s'analyse en < 10 s). **Step 4:** migrate, PASS. **Step 5:** `make qa`, commit
  `feat(import): analyze supplier catalogue into a diff`.

### Task 10: Application d'un import validé

**Files:**
- Create: `src/Import/CatalogueImportApplier.php`, `src/Import/Exception/StaleImportException.php`,
  `tests/Functional/Import/CatalogueImportApplierTest.php`

**Interfaces:**
- Consumes: `ImportRun` (`Analyzed`), `CatalogueImportAnalyzer::analyze()`, `CategoryRepository::findOrCreatePath()`,
  `TrackingModeResolver::defaultFor()`.
- Produces: `CatalogueImportApplier::apply(ImportRun $run): ImportDiff` (diff effectivement appliqué) ;
  `CatalogueImportApplier::cancel(ImportRun $run): void`.

- [ ] **Step 1: Failing tests** :
  - application → produits, variantes, marques, catégories et tarifs de la saison du run créés ; `trackingMode`
    via `defaultFor` ; run `Applied` + `appliedAt`.
  - `apply()` **ré-analyse** le fichier dans la transaction et applique ce résultat (le diff stocké n'est que
    l'aperçu). Si un autre run du **même fournisseur** est `Applied` avec `appliedAt` > `analyzedAt` de ce run →
    `StaleImportException` (« Le catalogue a été modifié depuis l'analyse, relancez l'analyse. »), rien n'est écrit.
  - variantes absentes → `active = false`, `deleted_at` reste null.
  - lignes rejetées : aucune écriture pour elles.
  - ré-import du même fichier juste après : nouveau run analysé → `hasChanges()` false ; `apply()` ne modifie
    aucun `updatedAt`.
  - exception simulée en cours d'application → rollback complet, run reste `Analyzed`.
  - `apply()` sur un run non `Analyzed` → `\LogicException`.
  - `cancel()` → `Cancelled`, fichier supprimé de `var/imports/`.
- [ ] **Step 2:** FAIL. **Step 3: Implement** (`wrapInTransaction` ; flush par lots de 200). **Step 4:** PASS.
  **Step 5:** `make qa`, commit `feat(import): apply validated catalogue import`.

### Task 11: Écrans d'import

**Files:**
- Create: `src/Controller/Import/ImportController.php`, `src/Form/ImportUploadType.php`,
  `templates/import/{index,new,show}.html.twig`, `tests/Functional/Import/ImportControllerTest.php`,
  `tests/Functional/DashboardTest.php`
- Modify: `templates/_nav.html.twig` (« Imports », groupe Catalogue, Manager+ ; menu bas mobile : Tableau de
  bord, Produits, Imports si Manager+, Plus), `src/Controller/DashboardController.php`,
  `templates/dashboard/index.html.twig`

**Interfaces:**
- Consumes: Tasks 9–10, `CurrentSeasonProvider::get()` (saison pré-sélectionnée).
- Produces: routes `import_index` (`/imports`), `import_new`, `import_show` (`/imports/{id}`), `import_apply`
  (POST), `import_cancel` (POST). Manager+ uniquement.

- [ ] **Step 1: Failing tests** :
  - upload `.xlsx` (fournisseur, saison) → run `Pending`, message `AnalyzeImportRun` présent sur le transport
    `async` (in-memory) ; fichier non xlsx ou > 20 Mo → erreur de formulaire ; fournisseur sans mapping →
    « Aucun mapping d'import pour ce fournisseur. ».
  - `show` d'un run `Pending` → « Analyse en cours… » et rafraîchissement automatique ; `Analyzed` → résumé
    chiffré et sections nouveautés / modifications / prix / désactivations / anomalies (n° de ligne Excel +
    message) ; `Failed` → message d'erreur.
  - POST `apply` (CSRF) → `Applied`, flash « Import appliqué : X créations, Y modifications, Z désactivations. » ;
    `StaleImportException` → flash d'erreur, run inchangé.
  - Stock → 403 sur toutes les routes ; Stock et Viewer ne voient pas l'entrée « Imports ».
  - liste des imports paginée (plus récents d'abord) ; fournisseur et saison en autocomplétion dans l'upload.
  - `DashboardTest` : saison courante affichée (ou « Aucune saison courante ») ; nombre de produits et de
    variantes actives ; Manager voit le dernier import (statut, date) et le raccourci « Nouvel import », Viewer
    ne les voit pas ; raccourci « Rechercher un produit » vers `/catalogue`.
- [ ] **Step 2:** FAIL. **Step 3: Implement.** **Step 4:** PASS. **Step 5:** `make qa`, commit
  `feat(import): upload, preview and apply catalogue imports`.

### Task 12: Recette sur le vrai fichier Bauer

**Files:**
- Modify: `README.md` (section « Import du catalogue »)

- [ ] **Step 1:** Créer un admin (`make sf c="app:user:create …"`) et la saison `2026-2027` courante. Le fichier
  `/Users/gpiecq/Documents/HPN/catalogues/bauer-catalogue-travail.xlsx` est uploadé via l'UI et **jamais commité**.
- [ ] **Step 2:** Lancer l'import → résumé attendu : 633 produits, ≈ 2 466 variantes (2 468 lignes − 2 doublons),
  anomalies incluant 11 `EanMissing`, 2 `EanDuplicateInFile` (`688698508062`, `688698508079`),
  ~85 `ColorMovedFromSize`, des `ColorAliased`, des `PriceMissing`. Toute divergence est expliquée avant validation.
- [ ] **Step 3:** Appliquer ; vérifier au navigateur (chrome-devtools MCP) : recherche EAN `067235585931`,
  recherche `jugulaire`, fiche avec prix club TTC/HT, filtre EQUIPEMENT › JOUEUR › PATIN, affichage à 375 px.
- [ ] **Step 4:** Ré-importer le même fichier → aperçu sans changement.
- [ ] **Step 5:** README (création admin, import, worker requis pour l'analyse) ; `make qa` ; `graphify update .` ;
  commit `docs: document catalogue import`.
