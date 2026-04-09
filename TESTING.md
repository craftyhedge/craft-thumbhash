# Testing

## Prerequisites

- PHP 8.2+ with `gd` extension (and optionally `imagick`)
- [Docker](https://www.docker.com/) (for the test database)
- [Composer](https://getcomposer.org/)
- Node.js 18+ and npm (for JS decoder tests)

## Setup

### 1. Install dependencies

```sh
composer install
npm install
```

### 2. Start the test database

```sh
docker run -d --name craft-test-db \
  -p 3306:3306 \
  -e MYSQL_ROOT_PASSWORD=secret \
  -e MYSQL_USER=craft \
  -e MYSQL_PASSWORD=secret \
  -e MYSQL_DATABASE=craft_test \
  mysql:8.0
```

If port 3306 is already in use, map to a different host port (e.g. `-p 3307:3306`) and update `DB_PORT` in `tests/.env`.

### 3. Configure environment

Copy the example env file and adjust if needed:

```sh
cp tests/.env.example tests/.env
```

The defaults match the Docker command above — no changes needed unless you customized ports or credentials.

### 4. Stop / restart the database

```sh
docker stop craft-test-db
docker start craft-test-db
```

To remove it entirely:

```sh
docker rm -f craft-test-db
```

## Running Tests

Run all suites (unit + integration):

```sh
vendor/bin/codecept run
```

Run JavaScript decoder tests:

```sh
npm run test:js
```

Run only the decoder spec:

```sh
npm run test:js -- tests/js/decoder.test.js
```

The decoder JS tests cover public API behavior, DOM boot behavior, MutationObserver behavior, and robust output validation (including PNG signature/dimensions and malformed-but-base64 payload rejection).

Run a single suite:

```sh
vendor/bin/codecept run unit
vendor/bin/codecept run integration
```

Run a specific test class:

```sh
vendor/bin/codecept run integration ThumbhashServiceTest
```

## JS Mutation Validation

To confirm decoder tests fail when key behaviors are intentionally broken:

```sh
npm run validate:js
```

This runs targeted mutations against `src/web/assets/dist/th-decoder.js` and verifies the test suite kills each mutation.


## Static Analysis

PHPStan is configured at level 7:

```sh
composer phpstan
```

## CI

GitHub Actions runs both PHPStan and tests automatically on pushes to `main`/`testing` and on pull requests. The workflow uses a MySQL 8.0 service container — no manual DB setup needed in CI.
