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

## Points mineurs reportés (à trier en revue finale du lot)

- Task 1 — `app:user:create` : pas de validation du format email ni de passage en minuscules (unicité sensible à la
  casse), pas de longueur minimale du mot de passe ; saisie masquée et mot de passe vide non testés.
- Task 1 — déconnexion par lien GET sans jeton CSRF.
- Task 1 — `access_control` `^/login` couvre aussi `/login-xxx` (préférer `^/login$`).
- Task 1 — commentaires générés laissés dans la migration ; `UserFactory` code en dur le coût bcrypt 4.
