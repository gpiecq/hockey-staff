# Lot 3 — Scan et Hotwire Native

Date : 2026-10-29 · Statut : rédigé sans séance de questions (à la demande de l'utilisateur) ; les choix marqués
« choix de Claude » sont à vérifier en lecture.

Références : spec de domaine (`2026-10-27-hockey-staff-domain-design.md`, § 3, 8, 9), lot 8 (résolution d'un code),
lot 4b (stock par variante × lieu × état, fiche variante `/stock/variantes/{id}`).

## 1. Intention

Le staff a le matériel en main, au local ou au bord de la glace. Il sort son téléphone, scanne le code-barres
(EAN fournisseur ou étiquette `HV-` imprimée au lot 8) et arrive **sur la fiche de la variante**, d'où il lance
l'action : entrée, sortie, transfert, prêt, retour, don, vente. Plus de recherche au clavier pour retrouver un article.

Le scan se fait :
- dans l'**app native** (Hotwire Native iOS / Android) avec le scanner de l'OS, rapide et fiable ;
- dans un **navigateur** (secours) avec la caméra via une librairie JS ;
- à la **douchette USB / clavier** dans le champ de saisie (déjà possible dans la recherche depuis le lot 8).

## 2. Objectifs / non-objectifs / anti-objectifs

**Objectifs**
- Un point d'entrée unique « Scanner » visible partout (menu, barre du bas mobile).
- Code scanné → fiche variante, sinon page « code inconnu » qui dit quoi faire.
- Fiche variante = page d'actions : toutes les actions de stock y sont proposées, préremplies avec la variante
  (et le lieu quand c'est sans ambiguïté).
- Scan web de secours (caméra du navigateur) quand on n'est pas dans l'app native.
- Pages servies prêtes pour Hotwire Native : path configuration JSON, détection native, bridge component
  `barcode-scanner`.
- Coques natives iOS (Swift) et Android (Kotlin) dans deux dépôts séparés, qui affichent l'appli et fournissent
  le scanner natif.

**Non-objectifs**
- Inventaire au scan (sessions, scan en continu, écarts) : **lot 5**. Le lot 3 fait un scan → une fiche.
- Mode hors ligne.
- Création d'un produit / d'une variante depuis un code inconnu (on renvoie vers la recherche du catalogue).
- Publication sur l'App Store / le Play Store (installation en développement / interne seulement).
- Notifications push.

**Anti-objectifs** (échec même si « ça marche »)
- Scanner lent ou peu fiable au point que le staff revienne au papier (spec de domaine).
- Un code scanné qui ouvre une **autre** variante que celle de l'étiquette.
- Le scan web qui gêne l'usage au bureau (navigateur sans caméra, permission refusée) : la saisie manuelle doit
  toujours rester possible.
- Une app native qui demande de se reconnecter à chaque ouverture.

## 3. Ce qui existe déjà

- `App\Catalogue\CodeResolver::resolve(string): ?Variant` : `HV-<n>` ou EAN 12/13 chiffres (zéro de tête géré),
  recherche exacte, pas de vérification de la clé.
- Recherche du catalogue : un code résolu redirige vers la **fiche produit** (lot 8).
- Fiche variante `/stock/variantes/{id}` (`stock_variant_show`, droit `STOCK_VIEW`) avec actions contextuelles :
  Prêter, Retour, Donner, Sortie, Vendre.
- Préremplissage des formulaires de mouvement par `?variant=<id>&lieu=<id>` (`StockOperationScreen`).
- Remember-me d'un an (`security.yaml`).
- AssetMapper + Turbo 8 + Stimulus ; aucun code natif ni librairie de scan.

## 4. Décisions

### 4.1 Découpage (choix de Claude)

Le lot 3 se fait en deux plans :
- **3a — côté Symfony (ce dépôt)** : route de scan, page code inconnu, actions complètes sur la fiche variante,
  scan web, détection native, path configuration, bridge component côté JS. Vérifiable par `make qa` et au
  navigateur.
- **3b — coques natives** (`hockey-staff-ios`, `hockey-staff-android`) : plan séparé, après 3a, car autres dépôts,
  autres outils (Xcode, Gradle) et pas de `make qa`. Il s'appuie sur le contrat défini ici (§ 4.5, 4.6).

### 4.2 Point d'entrée « Scanner »

- Route `GET /scan` (`scan_index`, droit `STOCK_VIEW`) : page avec un bouton « Scanner » (caméra) et un champ
  « Code » (saisie manuelle / douchette), soumis en GET sur la même route avec `?code=…`.
- `GET /scan?code=<code>` : `CodeResolver` → **redirection vers la fiche variante** ; sinon la même page avec un
  message « Code inconnu : <code> », le code conservé dans le champ, et un lien vers la recherche du catalogue
  avec ce code.
- Une variante « plus au catalogue » se résout quand même (elle reste scannable, spec § 5.1) ; la fiche affiche déjà
  son badge. Le comportement pour une variante archivée ou un produit supprimé suit ce que renvoie déjà
  `CodeResolver` (filtre softdeleteable) ; le builder le constate par un test et le consigne.
- Entrée « Scanner » dans le menu, groupe Stock, en tête, et dans la barre du bas mobile (`main: true`).
- Choix de Claude : la recherche du catalogue garde sa redirection vers la fiche **produit** (usage catalogue) ;
  seul le scan mène à la fiche **variante** (usage stock).

### 4.3 Fiche variante = page d'actions

Les actions existantes restent. On ajoute, pour `STOCK_MOVE` :
- **Achat** et **Dotation** (entrées), toujours proposées, même sans stock (c'est le cas de la réception),
  préremplies `?variant=<id>` ;
- **Transfert**, quand il y a du stock, prérempli `?variant=<id>`, et `?lieu=<id>` si la variante n'est en stock
  que dans un seul lieu actif ;
- **Sortie** : même règle de lieu unique pour `?lieu=`.

Choix de Claude : pas de nouvel écran « que voulez-vous faire ? » ; la fiche variante l'est déjà. Les boutons
restent des liens simples.

### 4.4 Scan web de secours

- Contrôleur Stimulus sur la page `/scan` : ouvre la caméra arrière, décode, remplit le champ `code` et soumet le
  formulaire. Formats : EAN-13, EAN-8, UPC-A, UPC-E, Code128, QR (ceux imprimés au lot 8 et par les fournisseurs).
- Librairie : `@zxing/browser` via importmap (spec de domaine § 8). Le builder peut ajouter l'API native
  `BarcodeDetector` quand elle existe, avec repli zxing ; le choix est consigné en décision.
- Caméra indisponible (pas de `getUserMedia`, page non HTTPS, permission refusée) : message clair, le champ manuel
  reste utilisable. La page n'est jamais bloquée.
- Le flux vidéo est arrêté dès qu'un code est lu ou qu'on quitte la page (`disconnect`).

### 4.5 Détection native et path configuration

- Native = l'en-tête `User-Agent` contient `Hotwire Native` (convention des librairies Hotwire Native). Exposé en
  Twig et testable.
- En natif : la navigation web (menu latéral, barre du bas, barre du haut) est masquée, la coque native fournit la
  navigation ; le titre de page reste dans `<title>` (repris par la barre native).
  Choix de Claude : la coque native a des onglets « Scanner », « Stock », « Catalogue », « Accueil » (chargeant
  `/scan`, `/stock`, `/catalogue`, `/`) ; détail en 3b.
- Path configuration servie par Symfony, publique (lue avant connexion), JSON :
  - `GET /hotwire-native/v1/ios-path-configuration.json`
  - `GET /hotwire-native/v1/android-path-configuration.json`
  - Règles : par défaut `context: default`, `pull_to_refresh_enabled: true` ; formulaires de mouvement (entrée,
    sortie, transfert, prêt, retour, don, vente — chemins exacts relevés dans le code) en `context: modal` ;
    `/login` en `context: modal` sans pull-to-refresh.
  - Contenu commun aux deux plateformes sauf écart justifié ; versionné dans l'URL (`v1`).

### 4.6 Bridge component `barcode-scanner`

- Côté web : `@hotwired/hotwire-native-bridge` via importmap ; un `BridgeComponent` nommé `barcode-scanner` sur
  la page `/scan`. Quand la coque native déclare supporter ce composant, le bouton « Scanner » envoie le message
  `scan` au natif au lieu d'ouvrir la caméra web ; la réponse `{ "code": "<valeur>" }` remplit le champ et soumet.
  Sans support natif : scan web (§ 4.4).
- Contrat (à respecter par 3b) : composant `barcode-scanner`, événement `scan`, réponse `{ code: string }` ;
  annulation = pas de réponse.
- Côté natif (3b) : iOS `DataScannerViewController` (VisionKit) ; Android Google code scanner (ML Kit). Mêmes
  formats qu'au § 4.4.

### 4.7 Session native

- Le remember-me d'un an suffit côté serveur. Les coques gardent les cookies ; l'écran de connexion s'ouvre en
  modale (§ 4.5). Rien de plus côté Symfony.

## 5. Coques natives (plan 3b, résumé)

- `hockey-staff-ios` (Swift, Hotwire Native iOS) et `hockey-staff-android` (Kotlin, Hotwire Native Android),
  dépôts séparés à côté de ce dépôt.
- URL de base configurable. `https://hockey-staff.localhost` n'est pas joignable depuis un téléphone : le plan 3b
  tranche avec l'utilisateur (IP locale + certificat, tunnel, ou serveur de recette).
- Composant natif `barcode-scanner`, onglets, path configuration distante (§ 4.5) avec copie embarquée de secours.
- Installation : Xcode sur appareil de dev, APK de debug. Pas de store.

## 6. Tests et vérification (3a)

- Fonctionnels `WebTestCase` : `/scan` (affichage, droits), `?code=` EAN / EAN à zéro de tête / `HV-` → redirection
  vers la fiche variante, code inconnu → 200 + message + lien, code vide → page sans message ; actions de la fiche
  variante (entrées toujours visibles pour STOCK_MOVE, transfert / sortie avec `lieu` seulement si lieu unique,
  invisibles pour VIEWER) ; détection native (User-Agent) masque la navigation ; path configuration (JSON valide,
  public, règles attendues) ; entrée « Scanner » du menu.
- Le JS (Stimulus, bridge) n'a pas de tests unitaires dans le projet : vérification au navigateur
  (chrome-devtools MCP) — page `/scan`, caméra refusée / absente, saisie manuelle, soumission.
- Recette manuelle : scanner une étiquette EAN-13, UPC-A et `HV-` à la caméra d'un téléphone (HTTPS requis par
  `getUserMedia`).

## 7. Laissé au builder

- Nommage des classes et de l'extension Twig de détection native, structure du JSON au-delà des règles ci-dessus.
- Mise en page de `/scan` (charte graphique existante, mobile-first).
- `BarcodeDetector` + zxing ou zxing seul (§ 4.4), consigné dans `docs/superpowers/decisions/`.
