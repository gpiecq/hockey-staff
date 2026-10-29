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
