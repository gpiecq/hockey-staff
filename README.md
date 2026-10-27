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
