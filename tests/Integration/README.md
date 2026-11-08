# Integration tests

These tests run against real database servers. They are skipped unless the server's environment variables are set.

| Variable | Meaning |
|---|---|
| `LW_TEST_MARIADB_HOST`, `LW_TEST_MARIADB_PORT`, `LW_TEST_MARIADB_PASSWORD` | MariaDB server, root password |
| `LW_TEST_MARIADB_CLIENT`, `LW_TEST_MARIADB_DUMP` | client commands, default `mariadb` and `mariadb-dump` |
| `LW_TEST_PGSQL_HOST`, `LW_TEST_PGSQL_PORT`, `LW_TEST_PGSQL_PASSWORD` | PostgreSQL server, `postgres` password |

The tests need the database command line clients. `runner.Dockerfile` builds a runner with PHP 8.4 and the MariaDB and PostgreSQL clients. Locally, with throwaway containers:

```bash
docker build -t lw-test-runner:local -f tests/Integration/runner.Dockerfile tests/Integration
docker network create lw-test-net
docker run -d --name lw-test-mariadb --network lw-test-net -e MARIADB_ROOT_PASSWORD=lwtestroot mariadb:10.11
docker run -d --name lw-test-postgres --network lw-test-net -e POSTGRES_PASSWORD=lwtestroot postgres:17
docker run --rm --network lw-test-net -v "$PWD:/app" \
  -e LW_TEST_MARIADB_HOST=lw-test-mariadb -e LW_TEST_MARIADB_PASSWORD=lwtestroot \
  -e LW_TEST_PGSQL_HOST=lw-test-postgres -e LW_TEST_PGSQL_PASSWORD=lwtestroot \
  lw-test-runner:local php vendor/bin/pest --testsuite=Integration
docker rm -f lw-test-mariadb lw-test-postgres && docker network rm lw-test-net
```

CI runs the same runner image against service containers (`.github/workflows/tests.yml`, job `integration`).
