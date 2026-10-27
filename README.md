# hockey-staff

Symfony 8.1 · PHP 8.5.12 · FrankenPHP 1.13.4 (worker mode) · PostgreSQL 18 · API Platform 5 · Mercure · Messenger/Scheduler.

## Démarrage

Prérequis : Traefik externe sur le réseau Docker `ced4it-network`.

```bash
make start        # build + up
make db-migrate
```

- App : https://hockey-staff.localhost
- API : https://hockey-staff.localhost/api
- Mailpit : http://localhost:8025

Logs : `LOGS_MANAGER_API_KEY` à définir dans `.env.local` pour l'envoi fluent-bit → logs-manager / Loki.

## Qualité

```bash
make qa           # php-cs-fixer + PHPStan (niveau 9) + PHPUnit
```

## Import du catalogue

### Premier compte admin

```bash
docker compose exec php bin/console app:user:create <email> <Prénom> <Nom> ROLE_ADMIN
```

Le mot de passe est demandé en saisie masquée. L'option `--password` est possible mais déconseillée : la valeur reste visible dans l'historique du shell.

Rôles disponibles : `ROLE_ADMIN`, `ROLE_MANAGER`, `ROLE_STOCK`, `ROLE_VIEWER`.

### Préparation

1. Créer une saison dans Catalogue › Saisons et la rendre courante.
2. Le fournisseur BAUER et son mapping de colonnes sont créés par une migration de données (rien à saisir).

### Lancer un import (Manager et plus)

1. Catalogue › Imports › Nouvel import : choisir le fournisseur et la saison, puis déposer un fichier `.xlsx` (20 Mo max).
2. L'analyse tourne en asynchrone : le service `worker` (`messenger:consume async`) doit être démarré (`docker compose ps`). Sinon l'import reste à « Analyse en cours… ».
3. Les fichiers déposés sont stockés dans `var/storage/imports` (volume partagé entre `php` et `worker`).
4. L'aperçu liste les nouveautés, modifications, prix, désactivations et anomalies (avec le n° de ligne Excel). Aucune ligne n'est ignorée sans être signalée.
5. Appliquer (transaction unique, tout ou rien) ou annuler.

Si un autre import du même fournisseur a été appliqué depuis l'analyse, l'application est refusée : relancer l'analyse.

Règles de mise à jour :

- Une variante absente du fichier est désactivée, jamais supprimée.
- Un prix absent du fichier n'efface pas le prix existant.

**Ne jamais commiter les catalogues fournisseurs.**

### Préparer un import avec une IA (Manager et plus)

Pour transformer les fichiers bruts du fournisseur en fichier d'import, une IA (Claude, Gemini, Copilot…) peut remplir le modèle de l'application. Tout est expliqué dans la page **Catalogue › Imports › Préparer un import** (`/imports/preparer`).

1. **Télécharger les 3 fichiers** depuis cette page : le modèle d'import du fournisseur (xlsx), les règles pour l'IA (`regles-ia.md`) et la table de correspondance (csv, facultative : déjà dans l'onglet « Correspondances » du modèle).
2. **Donner le tout à l'IA** : modèle, règles, base EAN et tarif club du fournisseur, en lui demandant de remplir le modèle en suivant exactement les règles.
3. **Vérifier son compte rendu** : il doit chiffrer les références, lignes, lignes sans prix, sans EAN, sans catégorie, sections inconnues et EAN en double. S'il manque ou semble incohérent, lui demander de recommencer.
4. **Importer le fichier** rendu par l'IA via « Nouvel import », contrôler l'aperçu (marques créées, « N produits sans catégorie »), puis appliquer.

L'import ne crée jamais de catégorie : une catégorie vide ou inconnue laisse le produit sans catégorie.

Après l'import, le tableau de bord affiche deux cards :

- **Produits sans tarif** : ouvre le catalogue filtré (`sansTarif`) ; le prix se saisit au niveau du produit (`/catalogue/{id}/tarif`).
- **Produits sans catégorie** : ouvre le catalogue filtré (`sansCategorie`) ; à la première catégorisation, le produit reçoit le mode de suivi par défaut de la catégorie.

### Environnement de dev

`frankenphp/conf.d/20-app.dev.ini` relève `memory_limit` à 512M (mode debug et profiler). En prod, les limites d'upload de `10-app.ini` (20M / 24M) nécessitent une reconstruction de l'image : `docker compose build`.
