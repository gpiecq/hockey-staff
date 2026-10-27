# hockey-staff — Instructions Claude Code

Voir aussi `AGENTS.md` (conventions Symfony génériques), valable en complément de ce fichier.

## Contexte du projet

Application web + mobile de gestion du stock de matériel de hockey sur glace d'un club (un seul club) :
catalogue fournisseur importé depuis Excel (Bauer d'abord), mouvements de stock, attribution joueurs/équipes
par saison, inventaires au scan code-barres, valeur HT du stock. Pas de comptabilité, pas de mode hors ligne.

Spec de domaine : `docs/superpowers/specs/2026-10-27-hockey-staff-domain-design.md`.
Plans par lot : `docs/superpowers/plans/`. Décisions (R1…) : `docs/superpowers/decisions/`.

## Stack technique

- **Symfony :** 8.1 — **PHP :** 8.5 — FrankenPHP (worker mode)
- **Base de données :** PostgreSQL 18 — `hockey_staff` (extension `unaccent` requise)
- **ORM :** Doctrine ORM 3.7 (soft-delete via filtre softdeleteable, index uniques partiels)
- **Async :** Messenger (conteneur worker)
- **Front :** AssetMapper + Turbo + Stimulus, ux-autocomplete — Excel : phpspreadsheet
- **Docker Compose :** oui — `make up` (service PHP : `php`)
- **URL locale :** https://hockey-staff.localhost (Traefik + certificat mkcert)

## Conventions spécifiques à ce projet

- Tout passe par le `Makefile` (`make help`), jamais `docker-compose` (v1) ni `symfony serve`.
- Interface et libellés en français ; code, noms de classes et messages de commit en anglais (Conventional Commits).
- TDD : test rouge d'abord, puis correctif. Tests fonctionnels `WebTestCase`, fixtures via `tests/Support/*`.
- Une tâche est terminée quand `make qa` est vert (php-cs-fixer + PHPStan niveau 9 + PHPUnit).
- Toute décision de conception prise en cours de lot est consignée dans `docs/superpowers/decisions/`.
- Branches : tout le développement reste sur `develop` jusqu'à la fin des développements ; pas de PR vers `main`
  d'ici là.
- Après modification de code : `graphify update .` (graphe local, jamais commité).

## Commandes fréquentes

```bash
make up                         # démarrer l'environnement
make sh                         # shell dans le conteneur PHP
make test c="--filter=MyTest"   # tests ciblés
make qa                         # cs-fix + stan + tests
make db-diff                    # générer une migration
make db-migrate                 # appliquer les migrations
make sf c="<commande>"          # bin/console
```

## Ce que Claude NE doit jamais faire sur ce projet

- Pousser directement sur `main`, ou forcer un push.
- Utiliser `doctrine:schema:update` ou du SQL manuel à la place d'une migration.
- Commiter `graphify-out/`, `var/recette/` ou des données du vrai catalogue fournisseur.
- Supprimer ou réinitialiser la base de dev (catalogue Bauer importé) sans accord explicite.

## Skills recommandées pour ce projet

- `/ced4it:symfony-entity` — entités Doctrine
- `/ced4it:database-migrations` — avant de créer une migration
- `/ced4it:symfony-messenger` — traitements async (import du catalogue)
- `/ced4it:workflow-git` — conventions de commits et PR
