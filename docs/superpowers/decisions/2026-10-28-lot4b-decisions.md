# Lot 4b — Décisions d'exécution (stock en quantité avec état)

Spec : `docs/superpowers/specs/2026-10-28-stock-quantite-etat-design.md`
Suite de la numérotation de `2026-10-28-lot4-decisions.md` (dernière entrée : R36).

Décisions prises pendant l'exécution du lot 4b, là où la spec et le plan ne tranchaient pas. Chacune indique ce
qu'elle coûte si elle est fausse. À relire par l'utilisateur.

## Migration et verrous

- **R37 — Migration par étapes, opération par opération.** Les tâches 1 à 5 ajoutent le nouveau modèle (niveaux par
  état, `PlayerHolding`, prêt / retour / don / vente en quantité) sans supprimer l'ancien ; la suppression de `Item`,
  des codes `HS-` et des étiquettes de pièce n'arrive qu'à la tâche 6. Les commits intermédiaires ne sont donc pas
  cohérents d'usage avant la tâche 6 (ex. un équipement reçu via l'application sous forme de pièce ne pouvait pas être
  donné). Coût si faux : aucun pour l'utilisateur, la base de dev n'avait aucun stock ; seuls les commits
  intermédiaires ne sont pas déployables seuls.
- **R38 — Migrations gardées : elles s'arrêtent s'il reste des lignes.** La migration de `sale_line` (suppression de
  `item_id`) et celle de `item` s'interrompent si la base contient des ventes ou des pièces, plutôt que de les perdre
  silencieusement. Base de dev vérifiée à 0 avant écriture. Coût si faux : migration refusée, à traiter à la main.
  Réserve : la garde `true === fetchOne` échouerait « ouverte » si un pilote renvoyait `'t'` (sans effet avec
  `pdo_pgsql`).
- **R39 — Verrou du type de produit fondé sur le stock et les détentions, pas sur les mouvements.** Le type
  Équipement / Consommable est verrouillé tant que le produit a du stock ou que des joueurs en détiennent
  (`PlayerHolding`). Un produit sans stock mais avec un historique de mouvements peut être recatégorisé. Coût si
  faux : un type changé après coup n'affecte que l'affichage de l'historique.
- **R40 — La valeur d'anomalie `tracking_mode_locked` est conservée.** `ImportAnomalyCode::ProductKindLocked` garde
  la valeur stockée `tracking_mode_locked` pour ne pas toucher aux anomalies déjà enregistrées ; seul le message
  change (« type Équipement/Consommable conservé, le produit a du stock »). Coût si faux : nom de valeur historique
  trompeur dans la base.
- **R41 — Ordre de verrouillage unique, appliqué partout.** Niveaux triés par id de variante, id de lieu, état
  (valeur texte : `damaged` < `good` < `worn`), puis détentions par id de joueur, id de variante. S'applique à la
  vente, au prêt, au retour, au don, aux sorties, aux transferts et, par uniformité, aux entrées. Les lignes gardent
  leur ordre de saisie dans l'opération et les mouvements ; seul le verrouillage est trié. Les niveaux manquants sont
  créés après la prise de tous les verrous. Coût si faux : risque d'interblocage entre opérations concurrentes.
- **R42 — Premier prêt ou retour concurrent sur la même clé : erreur de contrainte unique (500).** Une ligne de niveau
  ou de détention qui n'existe pas encore ne peut pas être verrouillée avant insertion ; deux opérations simultanées
  qui la créent déclenchent l'index unique (500), comme pour les entrées. Accepté pour un seul club. Coût si faux :
  un 500 rare, sans corruption de données (l'index unique protège).

## Ventes et totaux

- **R43 — Doublon variante × état refusé à la vente.** Deux lignes de vente avec la même variante et le même état sont
  refusées avec « augmentez la quantité ». La vérification du recorder fait foi ; celle du formulaire n'est qu'une
  commodité. Coût si faux : aucun, la quantité couvre le besoin.
- **R44 — Total de ligne = quantité × prix unitaires figés (TTC et HT).** Le total HT n'est pas recalculé à partir du
  TTC : il est quantité × prix HT unitaire figé à la vente. Coût si faux : écart d'arrondi possible par rapport à un
  recalcul du total.

## Écrans

- **R45 — Ventilation par état sous la quantité, page Stock.** L'état est présenté sous la quantité (« Neuf 4 · Usé 2 »)
  plutôt qu'en colonnes ou lignes distinctes ; la colonne prêté affiche « dont N prêté(s) ». Coût si faux : retouche
  d'un gabarit.
- **R46 — Entrées, sorties et transferts gardent « État » sur les lignes de consommable.** Un formulaire multi-lignes
  ne connaît pas le type du produit au moment du rendu (la variante est choisie par ligne), donc le champ reste, avec
  l'aide « Ignoré pour un consommable. » ; le recorder force Neuf. Les écrans à variante unique (prêt, don, vente)
  masquent le champ. Coût si faux : petit suivi JavaScript pour masquer le champ par ligne.
- **R47 — Code retiré de l'étiquette PDF.** La bande de code des étiquettes de pièce disparaît avec les codes `HS-` ;
  le padding de l'étiquette est ajusté. Coût si faux : étiquette à redessiner.
- **R48 — Colonne « État » sur la page d'opération.** Chaque mouvement affiche son état, ce que la tâche 6 avait
  laissé de côté. Coût si faux : aucun.
- **R49 — Correctif `locationNames` de la page Stock.** La page Stock affichait un nom de lieu erroné ; corrigé
  à la tâche 7 (la construction par `array_map` / `array_combine` reste dense, à simplifier). Coût si faux : aucun (lecture seule).

## Points ouverts

- **Droits** : pas de tests de visibilité fondés sur les droits (seulement `STOCK_VIEW` ; `STOCK_MOVE` sans
  `SALE_MANAGE` ; détenteurs sans stock : seul « Retour » doit apparaître).
- **Transferts** : couverture manquante pour le stock insuffisant par état, le consommable Usé vers Neuf, et la
  fusion de lignes d'entrée en double. Pas de test non plus d'une vente multi-lignes dont la ligne 2 est en manque
  (la ligne 1 doit rester intacte), ni d'un `?etat` / `?variant` falsifié.
- **Couches** : `SalePreviewer` dépend de `Form\Sale\SaleLineMapper::parse` (qui fait un `em->find`) ; l'aperçu
  transforme une quantité invalide ou nulle en 1.
- **Garde de migration** : robustesse de `true === fetchOne` (voir R38).
- **Don** : les boutons radio Joueur / Équipe sont visuellement serrés.
- **`?variant=`** accepte des variantes inactives en pré-remplissage.
- **Divers** : `productHasStock` est appelé deux fois à l'édition d'un produit (2 COUNT) ; les radios d'état
  désactivées sont décoratives, le serveur s'appuie sur le message de stock insuffisant ; la recette manuelle du
  lot reste à faire (la tâche 7 a créé en base de dev 2 achats au « Local matériel », variante 2487 : 4 Neuf et 2 Usé,
  324,78 € HT).
