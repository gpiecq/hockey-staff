# Lot 3a — Scan web et préparation Hotwire Native Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Scanner un code (caméra web, app native ou douchette) mène à la fiche variante, d'où partent toutes les actions de stock ; les pages Symfony sont prêtes pour les coques Hotwire Native (lot 3b).

**Architecture:** Un `ScanController` (`/scan`) s'appuie sur `CodeResolver` (lot 8) et redirige vers `stock_variant_show`. La fiche variante gagne les actions Achat / Dotation / Transfert. Une extension Twig détecte l'app native (User-Agent) pour masquer la navigation web ; une page `/menu` la remplace. Deux routes JSON publiques servent la path configuration. Côté JS : un contrôleur Stimulus de scan caméra (`@zxing/browser`) et un bridge component `barcode-scanner` (`@hotwired/hotwire-native-bridge`) qui délègue au scanner natif quand il existe.

**Tech Stack:** Symfony 8.1, PHP 8.5, Twig, AssetMapper/importmap, Stimulus, Turbo 8, `@zxing/browser`, `@hotwired/hotwire-native-bridge`, PHPUnit `WebTestCase`.

**Spec:** `docs/superpowers/specs/2026-10-29-lot3-scan-hotwire-native-design.md` (plan 3a uniquement ; 3b = coques natives, plan séparé).

## Global Constraints

- Tout passe par le `Makefile` : `make test c="--filter=…"`, `make qa`, `make sf c="…"`. Jamais `docker-compose` ni `symfony serve`.
- Une tâche est terminée quand `make qa` est vert (php-cs-fixer + PHPStan niveau 9 + PHPUnit).
- TDD : test rouge d'abord. Tests fonctionnels `WebTestCase`, données via `tests/Support/*` (`UserFactory`, `StockScenario`, `StockFixtures`, `PlayerFixtures`).
- Interface et libellés en français ; code, noms et messages de commit en anglais (Conventional Commits), **sans trailer `Co-Authored-By`** ni mention d'outil.
- `declare(strict_types=1);` et `final` partout, comme le code existant.
- Toute décision prise en cours de lot va dans `docs/superpowers/decisions/2026-10-29-lot3a-decisions.md`, numérotée à partir de **R50** (dernière : R49, lot 4b).
- Droits : `STOCK_VIEW` (Viewer+), `STOCK_MOVE` (Stock+) — `App\Security\Voter\StockVoter`.
- User-Agent natif : contient la sous-chaîne `Hotwire Native` (ex. `Mozilla/5.0 … Hotwire Native iOS; Turbo Native iOS; bridge-components: [barcode-scanner]`).
- Contrat bridge : composant `barcode-scanner`, événement `scan`, réponse `{ code: string }`, annulation = pas de réponse.
- Formats de scan : EAN-13, EAN-8, UPC-A, UPC-E, Code128, QR.
- Ne pas réinitialiser la base de dev (catalogue Bauer importé).
- Après modification de code : `graphify update .` (graphe local, jamais commité).

## Review Focus

1. Douchette ou saisie avec espaces, retour chariot, tabulation ou minuscules (`" hv-12\n"`) → résout quand même (test Task 1).
2. Code `HV-` d'une variante dont le produit est supprimé (soft delete) → message « Code inconnu », jamais 500 ni fiche cassée (test Task 1).
3. Stock uniquement dans un lieu inactif, ou dans deux lieux actifs → Transfert / Sortie sans paramètre `lieu` (test Task 2).
4. Path configuration demandée sans être connecté → 200 JSON, pas de redirection vers `/login` (test Task 4).
5. Caméra refusée, absente ou page non HTTPS → message clair, saisie manuelle toujours possible, aucun flux vidéo resté ouvert après navigation (vérification navigateur Task 5).

---

## File Structure

| Fichier | Rôle |
|---|---|
| `src/Controller/Stock/ScanController.php` (créé) | `GET /scan` : formulaire, résolution, redirection |
| `templates/scan/index.html.twig` (créé) | Page scan : bouton caméra, bouton natif, champ code, message code inconnu |
| `src/Repository/VariantRepository.php` (modifié) | `findOneByCode` ignore les variantes dont le produit est supprimé |
| `templates/_nav.html.twig` (modifié) | Entrée « Scanner » ; liens groupés réutilisables par `/menu` |
| `templates/stock/variant/show.html.twig`, `src/Controller/Stock/VariantSheetController.php` (modifiés) | Actions Achat / Dotation / Transfert, `lieu` si lieu unique |
| `src/Twig/NativeAppExtension.php` (créé) | Fonction Twig `is_native_app()` |
| `templates/base.html.twig` (modifié) | Navigation web masquée en natif |
| `src/Controller/MenuController.php`, `templates/menu/index.html.twig` (créés) | Page `/menu` |
| `src/HotwireNative/PathConfiguration.php`, `src/Controller/HotwireNativeController.php` (créés) | Path configuration JSON |
| `config/packages/security.yaml` (modifié) | `^/hotwire-native/` en `PUBLIC_ACCESS` |
| `assets/controllers/scanner_controller.js` (créé) | Scan caméra web |
| `assets/controllers/bridge/barcode_scanner_controller.js` (créé) | Bridge component `barcode-scanner` |
| `importmap.php` (modifié) | `@zxing/browser`, `@hotwired/hotwire-native-bridge` |
| `tests/Functional/Stock/ScanTest.php`, `tests/Functional/NativeAppTest.php`, `tests/Functional/HotwireNativePathConfigurationTest.php` (créés), `tests/Functional/Stock/VariantSheetTest.php` (modifié) | Tests |

---

### Task 1: Page de scan et résolution vers la fiche variante

**Files:**
- Create: `src/Controller/Stock/ScanController.php`, `templates/scan/index.html.twig`, `tests/Functional/Stock/ScanTest.php`, `docs/superpowers/decisions/2026-10-29-lot3a-decisions.md`
- Modify: `src/Repository/VariantRepository.php:35-40`, `templates/_nav.html.twig` (groupe Stock)

**Interfaces:**
- Consumes: `App\Catalogue\CodeResolver::resolve(string $code): ?Variant` (existant).
- Produces: route `scan_index` (`GET /scan`, param GET `code`), template `scan/index.html.twig` avec `<form method="get" data-scan-form>` contenant `<input name="code" id="scan-code">` et un `<div data-scan-zone>` vide où Task 5 ajoute les boutons caméra / natif.

- [ ] **Step 1: Write the failing tests** in `tests/Functional/Stock/ScanTest.php` (`final class ScanTest extends WebTestCase`, login via `UserFactory::create($em, $role, $email)` comme `VariantSheetTest`, variantes via `StockScenario::variant($em, ProductKind::Equipment, 'REF', 'Désignation', '<ean>')`) :
  - `testScanPageShowsCodeField` : Viewer, `GET /scan` → 200, `input[name="code"]` présent, `h1` = `Scanner`, aucun `.flash-error`.
  - `testEanRedirectsToVariantSheet` : variante EAN `0012345678905`, `GET /scan?code=0012345678905` → `assertResponseRedirects('/stock/variantes/'.$id)`.
  - `testEanWithoutLeadingZeroRedirects` : même variante, `?code=012345678905` (UPC-A 12 chiffres) → même redirection.
  - `testInternalCodeRedirectsWhateverCaseAndWhitespace` : `?code=` + `' '.strtolower($variant->getCode())."\n"` → redirection vers la fiche.
  - `testUnknownCodeShowsMessageAndCatalogueLink` : `?code=999` → 200, texte `Code inconnu : 999`, `input[name="code"]` a la valeur `999`, un lien `Chercher dans le catalogue` vers la recherche du catalogue avec `999` comme terme (relever le nom réel du formulaire `CatalogueFilterType` pour la clé du paramètre).
  - `testInactiveVariantStillResolves` : variante `setActive(false)` + flush → redirection vers la fiche.
  - `testArchivedVariantStillResolves` : variante archivée (méthode d'archivage de `Variant`) → redirection vers la fiche, qui affiche le badge `Archivée`.
  - `testDeletedProductCodeIsUnknown` : `$em->remove($variant->getProduct()); $em->flush();` puis `?code=<HV-…>` et `?code=<ean>` → 200 + `Code inconnu`.
  - `testEmptyCodeShowsPageWithoutMessage` : `?code=%20` → 200, pas de `Code inconnu`.
  - `testScanEntryInMenu` : Viewer, `GET /` → lien `nav.bottombar a[href="/scan"]` présent avec le texte `Scanner`.

- [ ] **Step 2: Run tests to verify they fail**

Run: `make test c="--filter=ScanTest"`
Expected: FAIL (404 sur `/scan`).

- [ ] **Step 3: Implement**
  - `ScanController` : `#[IsGranted('STOCK_VIEW')]`, `#[Route('/scan', name: 'scan_index', methods: ['GET'])] public function index(Request $request, CodeResolver $resolver): Response`. Code = `trim($request->query->getString('code'))` ; vide → page simple ; résolu et `getProduct()` non nul → `redirectToRoute('stock_variant_show', ['id' => …])` ; sinon page avec `unknown: true`. Pas de formulaire Symfony (GET simple, comme une douchette).
  - `VariantRepository::findOneByCode` : joindre `v.product` (le filtre softdeleteable s'applique à la jointure, comme `CatalogueSearch::findVariantByEan`) pour qu'un produit supprimé donne `null`. Vérifier que les autres appelants (`grep -rn findOneByCode src`) restent corrects.
  - Template : `{% extends 'base.html.twig' %}`, titre `Scanner`, `h1` `Scanner`, aide « Scannez l'étiquette ou saisissez le code (EAN ou HV-…). », champ `code` avec `autofocus`, `autocomplete="off"`, bouton « Ouvrir », message `.flash .flash-error` `role="alert"` quand inconnu, lien « Chercher dans le catalogue ».
  - `_nav.html.twig` : en tête du groupe Stock `{label: 'Scanner', route: 'scan_index', main: true}` ; retirer `main: true` de « Imports » (barre du bas : Tableau de bord, Scanner, Stock, Produits, Plus).
  - Décisions : créer le fichier (même en-tête que `2026-10-28-lot4b-decisions.md`) avec R50 (scan → fiche variante, recherche catalogue → fiche produit inchangée), R51 (Imports quitte la barre du bas au profit de Scanner), R52 (produit supprimé = code inconnu, aussi pour `HV-`).

- [ ] **Step 4: Run tests**

Run: `make test c="--filter=ScanTest"` puis `make test` (suite complète : appelants de `findOneByCode`, menu).
Expected: PASS.

- [ ] **Step 5: QA and commit**

Run: `make qa` → vert.
```bash
git add src/Controller/Stock/ScanController.php templates/scan src/Repository/VariantRepository.php templates/_nav.html.twig tests/Functional/Stock/ScanTest.php docs/superpowers/decisions/2026-10-29-lot3a-decisions.md
git commit -m "feat(scan): add scan page resolving codes to the variant sheet"
```

---

### Task 2: Fiche variante, toutes les actions de stock

**Files:**
- Modify: `src/Controller/Stock/VariantSheetController.php`, `templates/stock/variant/show.html.twig`, `tests/Functional/Stock/VariantSheetTest.php`, `docs/superpowers/decisions/2026-10-29-lot3a-decisions.md`

**Interfaces:**
- Consumes: préremplissage existant `?variant=<id>&lieu=<id>` (`StockOperationScreen::initialData`) ; routes `stock_entry_new` (`type` = `achat|dotation`), `stock_transfer_new`, `stock_out_new`.
- Produces: variable Twig `singleLocationId: ?int` passée par `VariantSheetController::show` = id du seul lieu **actif** ayant une quantité > 0, sinon `null`.

- [ ] **Step 1: Write the failing tests** (ajouts à `VariantSheetTest`, réutiliser `sheet()` / `login()`) :
  - `testEntryActionsAlwaysShownForStockRole` : Stock, variante sans stock → liens `Achat` (`href` = `/stock/entree/achat?variant=<id>`) et `Dotation` (`/stock/entree/dotation?variant=<id>`) ; `Transfert` et `Sortie` absents.
  - `testTransferAndOutPrefillSingleActiveLocation` : stock dans le seul lieu actif `Local` → `Transfert` href = `/stock/transfert?variant=<id>&lieu=<localId>`, `Sortie` href = `/stock/sortie?variant=<id>&lieu=<localId>`.
  - `testNoLocationPrefillWhenSeveralActiveLocations` : stock dans `Local` et `Vestiaire` (actifs) → `Transfert` et `Sortie` sans `lieu`.
  - `testNoLocationPrefillWhenOnlyInactiveLocation` : stock posé par `StockFixtures::level()` dans un lieu `active = false` → `Sortie` sans `lieu`.
  - `testViewerSeesNoActions` : Viewer → aucun des liens `Achat`, `Dotation`, `Transfert`, `Sortie`, `Prêter`.
  - Adapter `testActionsHiddenWithoutStock` existant : sans stock, seuls `Achat` / `Dotation` restent visibles pour STOCK_MOVE.

- [ ] **Step 2: Run tests to verify they fail**

Run: `make test c="--filter=VariantSheetTest"`
Expected: FAIL (liens `Achat`, `Transfert` absents).

- [ ] **Step 3: Implement**
  - Contrôleur : calculer `singleLocationId` depuis `$levels` (quantité > 0 et `getLocation()->isActive()`, ids distincts ; exactement un → cet id).
  - Template : ordre des boutons `Achat`, `Dotation`, `Prêter`, `Retour`, `Donner`, `Transfert`, `Sortie`, `Vendre`. `Achat` / `Dotation` dès `is_granted('STOCK_MOVE')` ; `Transfert` sous `canMove` ; `lieu` ajouté aux paramètres de `Transfert` et `Sortie` seulement si `singleLocationId` non nul. Vérifier dans `TransferController` / `OutController` le champ lieu passé à `initialData` pour que `?lieu=` préremplisse bien la **source**.
  - Décision R53 : la fiche variante est la page d'actions du scan (pas d'écran intermédiaire), `lieu` prérempli seulement si un seul lieu actif.

- [ ] **Step 4: Run tests**

Run: `make test c="--filter=VariantSheetTest"`
Expected: PASS.

- [ ] **Step 5: QA and commit**

Run: `make qa` → vert.
```bash
git commit -am "feat(stock): offer every stock action from the variant sheet"
```

---

### Task 3: Détection de l'app native et page Menu

**Files:**
- Create: `src/Twig/NativeAppExtension.php`, `src/Controller/MenuController.php`, `templates/menu/index.html.twig`, `tests/Functional/NativeAppTest.php`
- Modify: `templates/base.html.twig`, `templates/_nav.html.twig`, `docs/superpowers/decisions/2026-10-29-lot3a-decisions.md`

**Interfaces:**
- Produces: fonction Twig `is_native_app(): bool` (vrai si le `User-Agent` de la requête principale contient `Hotwire Native`) ; route `app_menu` (`GET /menu`, `ROLE_VIEWER` via `access_control`).

- [ ] **Step 1: Write the failing tests** in `tests/Functional/NativeAppTest.php` (constante `NATIVE_UA = 'Mozilla/5.0 (iPhone) Hotwire Native iOS; Turbo Native iOS; bridge-components: [barcode-scanner]'`, envoyée par `$client->request('GET', '/', server: ['HTTP_USER_AGENT' => self::NATIVE_UA])`) :
  - `testBrowserKeepsWebNavigation` : UA navigateur → `nav.sidebar`, `nav.bottombar`, `.mobile-header`, `.topbar` présents (1 chacun).
  - `testNativeHidesWebNavigation` : UA natif → ces 4 sélecteurs absents (0) ; `<title>` = `Tableau de bord · Corsaires · Matériel` ; `main.content` présent.
  - `testMenuListsGroupedLinksAndLogout` : Stock, `GET /menu` → 200, `h1` = `Menu`, liens `Scanner` (`/scan`), `Opérations`, `Prêts en cours`, `Déconnexion` (`/logout`) ; pas de lien `Utilisateurs`.
  - `testMenuShowsAdminLinksForAdmin` : Admin → lien `Utilisateurs` présent.

- [ ] **Step 2: Run tests to verify they fail**

Run: `make test c="--filter=NativeAppTest"`
Expected: FAIL (fonction inconnue / 404 sur `/menu`).

- [ ] **Step 3: Implement**
  - `NativeAppExtension` : `AbstractExtension`, dépend de `RequestStack`, lit `getMainRequest()?->headers->get('User-Agent')`, `str_contains(…, 'Hotwire Native')`.
  - `base.html.twig` : dans le bloc `shell`, `_nav.html.twig` et `.topbar` rendus seulement si `not is_native_app()`.
  - Liens groupés : extraire de `_nav.html.twig` la définition des groupes et le calcul de `visible` pour que `/menu` rende exactement la même liste que le panneau « Plus » sans dupliquer le tableau `groups` (mécanique Twig au choix du builder). `/menu` ajoute `Déconnexion`.
  - `MenuController` : `#[Route('/menu', name: 'app_menu', methods: ['GET'])]`, rend `menu/index.html.twig`.
  - Décision R54 : détection native par User-Agent ; navigation web masquée en natif ; page `/menu` pour l'onglet natif « Menu ».

- [ ] **Step 4: Run tests**

Run: `make test c="--filter='NativeAppTest|LayoutTest|ScanTest'"`
Expected: PASS.

- [ ] **Step 5: QA and commit**

Run: `make qa` → vert.
```bash
git add src/Twig/NativeAppExtension.php src/Controller/MenuController.php templates tests/Functional/NativeAppTest.php docs/superpowers/decisions/2026-10-29-lot3a-decisions.md
git commit -m "feat(native): hide web navigation in the native app and add a menu page"
```

---

### Task 4: Path configuration Hotwire Native

**Files:**
- Create: `src/HotwireNative/PathConfiguration.php`, `src/Controller/HotwireNativeController.php`, `tests/Functional/HotwireNativePathConfigurationTest.php`
- Modify: `config/packages/security.yaml:40-42`, `docs/superpowers/decisions/2026-10-29-lot3a-decisions.md`

**Interfaces:**
- Produces: `App\HotwireNative\PathConfiguration` avec `ios(): array` et `android(): array` (forme `array{settings: array<string, mixed>, rules: list<array{patterns: list<string>, properties: array<string, mixed>}>}`) ; routes `hotwire_native_path_configuration_ios` (`GET /hotwire-native/v1/ios-path-configuration.json`) et `hotwire_native_path_configuration_android` (`GET /hotwire-native/v1/android-path-configuration.json`). Consommé par le plan 3b.

Contenu iOS (règles appliquées dans l'ordre, la dernière qui correspond l'emporte, convention Hotwire Native) :

```json
{
  "settings": {},
  "rules": [
    { "patterns": [".*"], "properties": { "context": "default", "pull_to_refresh_enabled": true } },
    { "patterns": ["^/stock/entree/", "^/stock/sortie$", "^/stock/transfert$", "^/stock/variantes/\\d+/(pret|retour|don)$", "^/ventes/new$"],
      "properties": { "context": "modal", "pull_to_refresh_enabled": false } },
    { "patterns": ["^/login$"], "properties": { "context": "modal", "pull_to_refresh_enabled": false } }
  ]
}
```

Android : mêmes règles, avec en plus `"uri": "hotwire://fragment/web"` dans la règle par défaut et `"uri": "hotwire://fragment/web/modal/sheet"` dans les règles modales.

- [ ] **Step 1: Write the failing tests** in `tests/Functional/HotwireNativePathConfigurationTest.php` :
  - `testIosConfigurationIsPublicJson` : sans login, `GET /hotwire-native/v1/ios-path-configuration.json` → 200, `Content-Type` `application/json`, `json_decode` OK, `rules[0].patterns` = `['.*']`.
  - `testMovementFormsOpenAsModal` : pour `/stock/entree/achat`, `/stock/sortie`, `/stock/transfert`, `/stock/variantes/12/pret`, `/stock/variantes/12/retour`, `/stock/variantes/12/don`, `/ventes/new`, `/login` : la dernière règle dont un pattern correspond (`preg_match('#'.$p.'#', $path)`) a `context` = `modal`.
  - `testPagesStayDefault` : `/scan`, `/stock/variantes/12`, `/stock`, `/menu` → `context` = `default`.
  - `testAndroidConfigurationAddsFragmentUris` : sans login, Android → 200 ; règle par défaut `uri` = `hotwire://fragment/web`, règles modales `uri` = `hotwire://fragment/web/modal/sheet` ; mêmes `patterns` qu'iOS.
  - `testPatternsMatchRealRoutes` : chaque route `stock_entry_new`, `stock_out_new`, `stock_transfer_new`, `stock_loan_new`, `stock_loan_return`, `stock_gift_new`, `sale_new`, `app_login` générée via le routeur (`id` = 12, `type` = `achat`) tombe sur une règle modale (garde-fou si une URL change).

- [ ] **Step 2: Run tests to verify they fail**

Run: `make test c="--filter=HotwireNativePathConfigurationTest"`
Expected: FAIL (404 / redirection login).

- [ ] **Step 3: Implement**
  - `PathConfiguration` : `final readonly`, patterns en constantes de classe, Android = iOS + `uri`.
  - `HotwireNativeController` : deux actions renvoyant `JsonResponse`, en-tête `Cache-Control: public, max-age=300`.
  - `security.yaml` : `- { path: ^/hotwire-native/, roles: PUBLIC_ACCESS }` avant `^/`.
  - Décision R55 : path configuration servie par Symfony, versionnée `v1`, formulaires de mouvement et login en modale.

- [ ] **Step 4: Run tests**

Run: `make test c="--filter=HotwireNativePathConfigurationTest"`
Expected: PASS.

- [ ] **Step 5: QA and commit**

Run: `make qa` → vert.
```bash
git add src/HotwireNative src/Controller/HotwireNativeController.php config/packages/security.yaml tests/Functional/HotwireNativePathConfigurationTest.php docs/superpowers/decisions/2026-10-29-lot3a-decisions.md
git commit -m "feat(native): serve Hotwire Native path configuration"
```

---

### Task 5: Scan caméra web et bridge component `barcode-scanner`

**Files:**
- Create: `assets/controllers/scanner_controller.js`, `assets/controllers/bridge/barcode_scanner_controller.js`
- Modify: `importmap.php`, `templates/scan/index.html.twig`, `assets/styles/app.css`, `tests/Functional/Stock/ScanTest.php`, `docs/superpowers/decisions/2026-10-29-lot3a-decisions.md`, `docs/superpowers/specs/2026-10-27-hockey-staff-domain-design.md` (§ 9, lot 3)

**Interfaces:**
- Consumes: `<form data-scan-form>`, `input#scan-code`, `<div data-scan-zone>` (Task 1).
- Produces: contrôleur Stimulus `scanner` (actions `start`, `stop` ; cibles `video`, `status`, `input`, `webButton`) ; contrôleur `bridge--barcode-scanner` (`BridgeComponent`, `static component = "barcode-scanner"` ; cibles `nativeButton`, `webButton`, `input` ; action `scan`).

- [ ] **Step 1: Install libraries**

Run: `make sf c="importmap:require @zxing/browser @hotwired/hotwire-native-bridge"`
Expected: `importmap.php` gagne les deux entrées (et `@zxing/library` en dépendance), versions exactes.

- [ ] **Step 2: Write the failing test** (ajout à `ScanTest`) :
  - `testScanPageWiresScannerControllers` : `GET /scan` → le formulaire porte `data-controller` contenant `scanner` et `bridge--barcode-scanner` ; un bouton `Scanner avec la caméra` (`data-scanner-target="webButton"`), un bouton `Scanner` natif `hidden` (`data-bridge--barcode-scanner-target="nativeButton"`), un `<video data-scanner-target="video" hidden playsinline muted>`, une zone `role="status"` (`data-scanner-target="status"`).

Run: `make test c="--filter=ScanTest"` → FAIL.

- [ ] **Step 3: Implement**
  - Template : balisage ci-dessus dans `data-scan-zone`.
  - `scanner_controller.js` : `start()` → si `navigator.mediaDevices?.getUserMedia` absent ou `!window.isSecureContext`, statut « Caméra indisponible ici : saisissez le code. » ; sinon `BrowserMultiFormatReader` (formats des Global Constraints, caméra `facingMode: 'environment'`), affiche la vidéo ; au premier résultat : arrêter le flux, remplir `input`, `form.requestSubmit()`. Erreur `NotAllowedError` → « Accès à la caméra refusé : saisissez le code. ». `stop()` et `disconnect()` arrêtent les contrôles zxing et toutes les pistes du flux.
  - `bridge/barcode_scanner_controller.js` : `import { BridgeComponent } from '@hotwired/hotwire-native-bridge'` ; `connect()` : `super.connect()` puis, si `this.enabled`, montrer `nativeButton` et masquer `webButton` ; `scan()` : `this.send('scan', {}, (message) => { … })` qui remplit `input` avec `message.data.code` puis `form.requestSubmit()` ; rien si `code` absent.
  - CSS : vidéo pleine largeur, ratio 4/3, coins arrondis, charte existante.
  - Décisions : R56 (zxing seul ou `BarcodeDetector` + repli zxing, et pourquoi), R57 (contrat bridge `barcode-scanner` / `scan` / `{code}` figé pour 3b).
  - Spec de domaine § 9 lot 3 : « **3a LIVRÉ** (recette manuelle à faire, voir les décisions) ; 3b coques natives à planifier ».

- [ ] **Step 4: Run tests**

Run: `make test c="--filter=ScanTest"` → PASS ; `make qa` → vert.

- [ ] **Step 5: Browser verification (chrome-devtools MCP)** sur `https://hockey-staff.localhost/scan` (page de login → demander à l'utilisateur de se connecter, ne pas le faire soi-même) :
  - Console sans erreur d'import (zxing, bridge) au chargement ; bouton natif masqué, bouton caméra visible.
  - Saisie manuelle d'un code connu → fiche variante ; code inconnu → message.
  - Clic « Scanner avec la caméra » avec permission refusée → message de refus, champ utilisable.
  - Clic caméra puis navigation vers une autre page → aucune piste vidéo active (`evaluate_script`).
  - Simuler le natif (attributs `data-bridge-platform` / `data-bridge-components="barcode-scanner"` sur `<html>` avant connexion du contrôleur, d'après la doc de `@hotwired/hotwire-native-bridge`) → bouton natif visible, bouton caméra masqué. Noter le résultat dans le rapport de tâche.

- [ ] **Step 6: Commit**

```bash
git add importmap.php assets templates/scan tests/Functional/Stock/ScanTest.php docs/superpowers
git commit -m "feat(scan): add web camera scanner and barcode-scanner bridge component"
```
