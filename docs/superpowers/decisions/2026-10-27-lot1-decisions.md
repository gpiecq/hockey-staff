# Lot 1 — Décisions d'exécution

Plan : `docs/superpowers/plans/2026-10-27-lot1-socle-catalogue.md`
Specs : `docs/superpowers/specs/2026-10-27-hockey-staff-domain-design.md`,
`docs/superpowers/specs/2026-10-27-charte-graphique-design.md`

Décisions prises par Claude pendant l'exécution, là où la spec et le plan ne tranchaient pas ou se
contredisaient. Chacune indique ce qu'elle coûte si elle est fausse. À relire par l'utilisateur.

## Avant exécution (vérification du plan)

| # | Décision | Pourquoi | Coût si faux |
|---|---|---|---|
| R1 | L'entité `User` n'a pas d'horodatage ni d'auteur (trait réservé aux entités du domaine). | La spec « horodatage + auteur sur toutes les entités » vise le catalogue et le stock ; `User` est créé avant le trait. | Une petite migration. |
| R2 | La migration de données Bauer (Task 8) remplit `created_at`/`updated_at` = `NOW()`, `created_by` NULL. | Une migration SQL contourne les extensions Doctrine. | Modifier la migration. |
| R3 | Les changements portant sur le produit (désignation, marque, catégorie) apparaissent dans l'aperçu d'import sur chaque variante du produit (`changedVariants`), l'application met à jour le produit. | Conserve la forme d'`ImportDiff` prévue par le plan. | Changer la forme du diff (Tasks 9 et 11). |
| R4 | Le statut `Pending` du plan correspond au `DRAFT` de la spec §5.1 ; noms du plan conservés. | Le plan détaille des statuts plus fins que la spec. | Renommer l'enum. |
| R5 | Travail directement sur `develop`, sans branche dédiée. | Flux de branches de l'utilisateur ; pas de push sans accord. | Aucun, historique linéaire. |

## Pendant l'exécution

| # | Tâche | Décision | Pourquoi | Coût si faux |
|---|---|---|---|---|
| R6 | 1 | Le test « `GET /favicon.ico` → 200 » est remplacé par : fichier présent et non vide + balises `<link>` présentes. | FrankenPHP sert le fichier statique sans passer par le noyau Symfony ; une route dédiée au test serait artificielle. | Aucun fonctionnel ; vérifié au navigateur en Task 12. |
| R7 | 4 | Le nom d'un fournisseur ou d'une marque est unique parmi les non supprimés (index unique partiel `WHERE deleted_at IS NULL`) ; recréer un nom supprimé est permis. | La validation d'unicité ne voit pas les lignes supprimées : une unicité globale finirait en erreur 500. | Une migration. |
| R8 | 4 | Unicité `(parent, nom)` des catégories aussi pour les racines (index partiel `WHERE parent_id IS NULL`) ; pas de suppression de catégorie en lot 1. | PostgreSQL considère les NULL distincts ; la contrainte globale ne prévoit le soft delete que sur fournisseur, marque, produit, variante. | Une migration et une action de suppression plus tard. |
| R9 | 4 | L'autocomplétion « Catégorie parente » ne propose que les niveaux 1-2 ; le cas `pat` du plan est testé avec une catégorie de niveau 2 ; le champ produit (Task 5) aura son propre champ. | Un parent de niveau 3 violerait la profondeur max 3 ; le plan supposait PATIN proposable. | Une classe de champ ou une option en plus. |

## Points mineurs reportés (à trier en revue finale du lot)

- Task 1 — `app:user:create` : pas de validation du format email ni de passage en minuscules (unicité sensible à la
  casse), pas de longueur minimale du mot de passe ; saisie masquée et mot de passe vide non testés.
- Task 1 — déconnexion par lien GET sans jeton CSRF.
- Task 1 — `access_control` `^/login` couvre aussi `/login-xxx` (préférer `^/login$`).
- Task 1 — commentaires générés laissés dans la migration ; `UserFactory` code en dur le coût bcrypt 4.
- Task 2 — formulaire utilisateur : pas de contrainte `Email` ni `Length(max: 180)` sur l'email (> 180 caractères
  → erreur base), pas de longueur max sur prénom/nom/mot de passe ; email non passé en minuscules (à faire avec une
  recherche insensible à la casse au login).
- Task 2 — `?page[]=x` → 400 ; pas de test `?page=0`/`-1` ni du changement de mot de passe en édition.
- Task 2 — détection « soi-même » par identité d'objet plutôt que par id ; `UserVoter` répète le contrôle `ROLE_ADMIN`.
- Task 3 — « rendre courante » : la remise à zéro en DQL contourne Gedmo (l'ancienne saison courante garde ses
  `updatedAt`/`updatedBy`) et laisse les entités en mémoire périmées ; deux appels concurrents → violation d'index (500).
- Task 3 — regex du libellé avec `$` (accepte un saut de ligne final, préférer `\z`) ; formulaire « nouvelle saison »
  pré-rempli avec deux dates identiques (invalide à l'ouverture) ; style en ligne sur le bouton « Rendre courante ».
- Task 3 — tests manquants : `CurrentSeasonProvider::get()`, doublon de libellé via le contrôleur, pagination,
  Responsable matériel refusé en édition.
- Task 4 — `findOrCreatePath` : pas de contrôle de longueur (150) après normalisation, un flush par niveau hors
  transaction (chemin partiel si échec, conflit d'unicité si imports concurrents) — à surveiller en Tasks 8/10.
- Task 4 — contrôleurs/formulaires/gabarits fournisseur, marque, catégorie quasi identiques ; entrées Tom Select
  inutiles (bootstrap4/5, .min.css) dans `importmap.php` ; liens Modifier/Supprimer à vérifier (cible tactile 44 px) ;
  habillage Tom Select non vérifié au navigateur (Task 12).
- Incident Task 4 — la base de dev a été remise à zéro par l'implementer (utilisateurs locaux perdus, à recréer via
  `app:user:create`). Les consignes suivantes interdisent de toucher aux données de dev.
