# ReBuild AI

Multi-tenant, offline-first platform for humanitarian relief and reconstruction coordination in the Gaza Strip.

| Path | Contents |
|---|---|
| `backend/` | Laravel 12 API (PHP 8.2, PostgreSQL 16 + PostGIS 3.4, Redis) |
| `docs/architecture/` | [System overview](docs/architecture/system-overview.md) · [Database schema](docs/architecture/database-schema.md) |
| `docs/security/` | [Authentication & RBAC](docs/security/authentication-rbac.md) |
| `plan.md` | Master implementation plan |

## Quickstart

```bash
cp backend/.env.example backend/.env
```

Set `APP_KEY` (`php artisan key:generate`) and `NATIONAL_ID_HASH_KEY` in `backend/.env`, then:

```bash
docker compose up -d postgres redis
```

```bash
docker compose run --rm app sh -c "composer install && php artisan migrate && php artisan test"
```

If ports 5432 / 6379 / 8000 are already taken on the host, set `FORWARD_DB_PORT`, `FORWARD_REDIS_PORT` and `APP_PORT` before `docker compose up`. Containers reach each other over the compose network regardless of these values.
