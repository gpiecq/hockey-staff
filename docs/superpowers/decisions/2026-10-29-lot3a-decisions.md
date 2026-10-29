# Lot 3a — Décisions d'exécution (scan + préparation Hotwire Native)

Spec : `docs/superpowers/specs/2026-10-29-lot3-scan-hotwire-native-design.md`
Suite de la numérotation de `2026-10-28-lot4b-decisions.md` (dernière entrée : R49).

Décisions prises pendant l'exécution du lot 3a, là où la spec et le plan ne tranchaient pas. Chacune indique ce
qu'elle coûte si elle est fausse. À relire par l'utilisateur.

## Page de scan

- **R50 — Le scan mène à la fiche variante ; la recherche catalogue mène toujours à la fiche produit.** `/scan`
  résout le code (EAN ou `HV-…`) et redirige vers `stock_variant_show`. La redirection de la recherche catalogue
  vers la fiche produit est inchangée. Coût si faux : un écran de plus à traverser pour un usage « catalogue ».
- **R51 — Imports quitte la barre du bas au profit de Scanner.** La barre mobile devient Tableau de bord, Scanner,
  Stock, Produits, Plus ; Imports reste accessible dans « Plus » et la barre latérale. Coût si faux : un tap de plus
  pour atteindre les imports sur mobile.
- **R52 — Produit supprimé = code inconnu, y compris pour `HV-…`.** `VariantRepository::findOneByCode` joint le
  produit (le filtre softdeleteable s'applique à la jointure), comme la résolution par EAN. Coût si faux : un code
  `HV-` d'un produit supprimé n'ouvre plus de fiche ; le seul appelant est `CodeResolver`.

## Fiche variante

- **R53 — La fiche variante est la page d'actions du scan, sans écran intermédiaire.** Elle propose Achat, Dotation,
  Prêter, Retour, Donner, Transfert, Sortie et Vendre selon les droits et le stock. Le paramètre `lieu` n'est
  transmis à Transfert et Sortie (où il préremplit le lieu source) que si un seul lieu actif contient du stock de la
  variante ; sinon l'utilisateur choisit. Achat et Dotation préremplissent la variante (`EntryController` utilise
  désormais `initialData`, comme les autres écrans). Coût si faux : un choix de lieu de plus quand le stock est
  réparti, ou un lieu mal deviné si le stock a bougé entre le scan et l'action.

## App native

- **R54 — Détection native par User-Agent, navigation web masquée, page `/menu`.** `is_native_app()` est vrai si le
  `User-Agent` de la requête principale contient `Hotwire Native`. Dans ce cas `base.html.twig` n'affiche ni la
  navigation (barre latérale, en-tête et barre du bas mobiles) ni la `.topbar` ; l'onglet natif « Menu » ouvre `/menu`,
  qui rend les mêmes liens groupés que le panneau « Plus » (gabarit partagé `_nav_links.html.twig`) plus Déconnexion.
  Coût si faux : un navigateur dont l'UA contiendrait cette chaîne perdrait la navigation ; l'UA est fixé par nos
  coques natives.

- **R55 — La path configuration Hotwire Native est servie par Symfony, versionnée `v1`.** Deux JSON publics
  (`/hotwire-native/v1/ios-path-configuration.json` et `android-…`, cache 5 min) que les coques natives (lot 3b)
  téléchargent au démarrage ; les formulaires de mouvement (entrée, sortie, transfert, prêt, retour, don, vente) et le
  login s'ouvrent en modale, le reste en pile par défaut. Un test compare les patterns aux routes réelles. Coût si
  faux : un changement d'URL non répercuté ici ferait ouvrir un formulaire en pleine page ; le test le détecte.

## Scan caméra et bridge

- **R56 — Scan web par zxing seul, sans `BarcodeDetector`.** `@zxing/browser` (`BrowserMultiFormatReader`, formats
  EAN-13, EAN-8, UPC-A, UPC-E, Code128, QR, caméra arrière) lit le flux ; l'API `BarcodeDetector` n'existe ni sur
  Safari ni sur Firefox et n'ajouterait qu'un second chemin de code à tester pour un gain de performance inutile ici
  (un scan par ouverture de page). Hors contexte sécurisé ou sans `getUserMedia`, la saisie manuelle reste l'unique
  voie, avec un message. Coût si faux : un décodage un peu plus lent sur Chrome Android que le détecteur natif.
- **R57 — Contrat bridge `barcode-scanner` figé pour le lot 3b.** Composant `barcode-scanner`, événement `scan`
  (données vides hors `metadata.url` ajoutée par la bibliothèque), réponse `{ code: string }` ; une annulation côté
  natif n'envoie aucune réponse et la page ne change pas. Le bouton « Scanner » natif remplace le bouton caméra web
  seulement si le composant est annoncé par la coque (`this.enabled`), la saisie manuelle restant disponible. Coût si
  faux : toute évolution du contrat impose de modifier en même temps le contrôleur Stimulus et les deux coques.
