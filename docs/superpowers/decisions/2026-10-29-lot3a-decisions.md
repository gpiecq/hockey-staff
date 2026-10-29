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
