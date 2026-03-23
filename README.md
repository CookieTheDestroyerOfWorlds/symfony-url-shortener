# Symfony URL Shortener

[![CI](../../actions/workflows/ci.yml/badge.svg)](../../actions/workflows/ci.yml)

A URL shortener REST API built with Symfony 8, PostgreSQL, and Redis.

## Stack

| Component  | Technology    |
|------------|---------------|
| Framework  | Symfony 8     |
| Language   | PHP 8.4       |
| Database   | PostgreSQL 16 |
| Cache      | Redis 7       |
| Web server | Nginx 1.25    |
| Tests      | PHPUnit 13    |

## Features

- Shorten any URL with an auto-generated or custom alias
- Optional expiration date (ISO 8601 format)
- Synchronous click count tracking on every redirect
- Redis cache-aside for redirect lookups with a 1-hour TTL; cache is warmed on creation and evicted on deactivation
- Fixed-window rate limiting on URL creation (20 requests per minute per IP)
- Health and readiness endpoints with live DB and cache probes
- JSON error responses for all `/api/*` routes and requests with `Accept: application/json`

## Architecture

```
Client
  │
  ▼
Nginx (port 8081)
  │
  ▼
PHP-FPM (Symfony 8)
  ├── POST   /api/urls             → UrlController → ShortUrlService → PostgreSQL + Redis
  ├── GET    /api/urls/{shortCode} → UrlController → ShortUrlRepository
  ├── DELETE /api/urls/{shortCode} → UrlController → ShortUrlService → Redis eviction
  ├── GET    /{shortCode}          → RedirectController → Redis (cache-aside) → PostgreSQL
  ├── GET    /health               → HealthController
  └── GET    /ready                → HealthController → PostgreSQL + Redis probes
```

## Quick start

```bash
# Clone and enter the project
git clone <repo-url> && cd symfony-url-shortener

# Start all services
docker compose -f docker-compose.yml up -d

# Run database migrations
docker compose -f docker-compose.yml exec app php bin/console doctrine:migrations:migrate --no-interaction
```

The API is available at `http://localhost:8081`.

## API Reference

### Create a short URL

```
POST /api/urls
Content-Type: application/json
```

**Request body:**

```json
{
  "url": "https://example.com/very/long/path",
  "customAlias": "my-link",
  "expiresAt": "2027-01-01"
}
```

| Field         | Required | Description                              |
|---------------|----------|------------------------------------------|
| `url`         | Yes      | The original URL (max 2048 characters)   |
| `customAlias` | No       | 3–16 chars, `[a-zA-Z0-9_-]`             |
| `expiresAt`   | No       | ISO 8601 date or datetime (must be future) |

**Responses:**

| Status | Reason |
|--------|--------|
| `201 Created` | URL shortened successfully |
| `400 Bad Request` | Request body is not valid JSON |
| `409 Conflict` | The requested alias is already in use |
| `422 Unprocessable Entity` | Validation failed (see `errors` object in body) |
| `429 Too Many Requests` | Rate limit exceeded |

**`201` response body:**

```json
{
  "id": 1,
  "shortCode": "my-link",
  "shortUrl": "http://localhost:8081/my-link",
  "originalUrl": "https://example.com/very/long/path",
  "createdAt": "2026-03-23T12:00:00+00:00",
  "expiresAt": "2027-01-01T00:00:00+00:00",
  "clickCount": 0,
  "isActive": true
}
```

**`429` response headers:**

| Header | Description |
|--------|-------------|
| `X-RateLimit-Limit` | Maximum requests allowed per window |
| `X-RateLimit-Remaining` | Requests remaining in the current window |
| `Retry-After` | Seconds until the window resets |

Successful `201` responses also include `X-RateLimit-Limit` and `X-RateLimit-Remaining`.

---

### Redirect to original URL

```
GET /{shortCode}
```

| Status | Reason |
|--------|--------|
| `301 Moved Permanently` | Redirects to the original URL; increments click count |
| `404 Not Found` | Short code does not exist or has been deactivated |
| `410 Gone` | Short URL has passed its expiration date |

---

### Get URL details

```
GET /api/urls/{shortCode}
```

Returns the same JSON structure as the create response, with the current `clickCount`.

| Status | Reason |
|--------|--------|
| `200 OK` | Details returned |
| `404 Not Found` | Short code does not exist |

---

### Deactivate a short URL

```
DELETE /api/urls/{shortCode}
```

Sets `isActive` to `false` and evicts the Redis cache entry. The record is retained in the database.

| Status | Reason |
|--------|--------|
| `204 No Content` | Deactivated successfully |
| `404 Not Found` | Short code does not exist |
| `409 Conflict` | Short URL is already inactive |

---

### Health check

```
GET /health
```

Always returns `200`. Confirms the application process is running.

```json
{ "status": "ok", "app": "symfony-url-shortener" }
```

### Readiness check

```
GET /ready
```

Probes the database (`SELECT 1`) and the Redis cache (write probe). Returns `200` when all checks pass, `503` if any fail.

```json
{
  "status": "ok",
  "checks": {
    "database": true,
    "cache": true
  }
}
```

## Running tests

```bash
# Create the test database (first time only)
docker compose -f docker-compose.yml exec app php bin/console doctrine:database:create --env=test

# Run all tests
docker compose -f docker-compose.yml exec app php bin/phpunit

# Unit tests only (no running services required)
docker compose -f docker-compose.yml exec app php bin/phpunit tests/Unit

# Functional tests only
docker compose -f docker-compose.yml exec app php bin/phpunit tests/Functional
```

The test suite comprises 28 tests (12 unit, 16 functional) with 71 assertions. Unit tests use PHPUnit mocks against `ShortCodeGeneratorInterface` and `ShortUrlCacheInterface`; functional tests boot the Symfony kernel against a dedicated `url_shortener_test` PostgreSQL database.

## Development commands

```bash
# Clear the Symfony cache
docker compose -f docker-compose.yml exec app php bin/console cache:clear

# Generate a migration after entity changes
docker compose -f docker-compose.yml exec app php bin/console doctrine:migrations:diff

# Apply pending migrations
docker compose -f docker-compose.yml exec app php bin/console doctrine:migrations:migrate --no-interaction

# List all registered routes
docker compose -f docker-compose.yml exec app php bin/console debug:router
```

## Environment variables

| Variable       | Description                                         |
|----------------|-----------------------------------------------------|
| `APP_ENV`      | Symfony environment (`dev`, `test`, `prod`)         |
| `APP_SECRET`   | Symfony application secret, used for signing        |
| `DATABASE_URL` | PostgreSQL connection URL (Doctrine DSN format)     |
| `REDIS_URL`    | Redis connection URL                                |

## Notable implementation details

- **Collision-safe short code generation** — `ShortCodeGenerator` checks uniqueness against the database before returning a code. `ShortUrlService` additionally catches `UniqueConstraintViolationException` from Doctrine (the authoritative guard against TOCTOU races under concurrent load), clears the unit of work, and retries up to three times before surfacing an error.
- **Cache-aside pattern** — On a redirect, the cached entry is served from Redis. On a cache miss the database is queried and the entry is written to Redis for subsequent requests. Cache entries are evicted when a URL is deactivated.
- **Interface-based service design** — `ShortCodeGeneratorInterface` and `ShortUrlCacheInterface` decouple the service layer from concrete implementations, allowing unit tests to run without any infrastructure dependencies.
- **Click tracking** — Click counts are incremented synchronously on every redirect via a direct `UPDATE` query. On cache hits no `SELECT` is issued; all redirect data comes from Redis and only one write hits the database. A message queue would be more appropriate at high volume but is not implemented here.
