# ReBuild AI

Multi-tenant, offline-first platform for humanitarian relief and reconstruction coordination in the Gaza Strip.

| Path | Contents |
|---|---|
| `backend/` | Laravel 12 API (PHP 8.2, PostgreSQL 16 + PostGIS 3.4, Redis, Horizon) |
| `docs/architecture/` | [System overview](docs/architecture/system-overview.md) · [Database schema](docs/architecture/database-schema.md) |
| `docs/security/` | [Authentication & RBAC](docs/security/authentication-rbac.md) |
| `docs/api/` | [API reference](docs/api/api-reference.md) · [Postman collection](docs/api/postman-collection.json) |
| `docs/testing/` | [Chaos & offline scenarios](docs/testing/chaos-and-offline.md) |
| `plan.md` | Master implementation plan |

## Quickstart

All PHP commands run in Docker: Horizon needs the `pcntl`/`posix` extensions, which are not available for PHP on Windows.

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

Run the API (http://localhost:8000) and the background workers:

```bash
docker compose --profile workers up -d api horizon scheduler
```

If ports 5432 / 6379 / 8000 are already taken on the host, set `FORWARD_DB_PORT`, `FORWARD_REDIS_PORT` and `APP_PORT` before `docker compose up`. Containers reach each other over the compose network regardless of these values.
