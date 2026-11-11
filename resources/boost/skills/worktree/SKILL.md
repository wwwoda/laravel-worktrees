---
name: worktree
description: Work in a git worktree of a Laravel project that uses woda/laravel-worktrees (one Docker stack per worktree). Use when you start in a worktree, run tests or artisan in it, need queue workers, the scheduler or phpMyAdmin, open the app in a browser, find a broken setup, or create, stop or delete worktrees. Covers the worktree:* commands, which container runs what, and how to keep memory low.
---

# Working in a worktree

Each worktree of the project is a git checkout. With the `sail` or `shared` strategy it has its own Docker stack: an app container (PHP), its own database and Redis (or its own databases on a shared server), and optional services behind compose profiles. With `native`, PHP runs on the host and the container parts of this skill do not apply. Run every `worktree:*` command with `php artisan` in the worktree; without a name it acts on the checkout you are in.

## Start of a session

```bash
php artisan worktree:context      # URL, what runs, database state, test login, last errors
```

If the session-start hook is set up, this summary is already in your context. Read it before you change anything.

## Which command for what

| You want to | Run |
|---|---|
| Know the URL, the services, memory and database state | `php artisan worktree:status` |
| Run tests | `php artisan worktree:exec test --filter=UserTest` (in the app container) |
| Run artisan or Composer | `php artisan worktree:exec artisan migrate`, `php artisan worktree:exec composer require x/y` |
| Run a shell command in the container | `php artisan worktree:exec "php -i \| grep memory"` (one quoted word) |
| Use another service | `php artisan worktree:exec --service=<service> <command>`, for example `--service=mariadb mariadb -e 'select 1'` (the service names are in `worktree:status`) |
| Fix a broken setup | `php artisan worktree:doctor`, then `php artisan worktree:doctor --fix` |
| Start a stopped stack | `php artisan worktree:wake` (or `worktree:doctor --fix`) |
| Create another worktree | `php artisan worktree:create my-feature` (prints each step's time and the URL) |
| Remove a worktree | `php artisan worktree:delete my-feature` (offers to delete a merged branch) |

With the `sail` or `shared` strategy (a Docker stack per worktree, the usual case), do not run `php artisan test`, `composer` or `php artisan migrate` on the host: the host has the wrong database host, PHP version and environment. Use `worktree:exec`. With the `native` strategy (no containers; `worktree:status` shows no compose project), the host is the right place, and `worktree:exec` runs the command there too.

## Decision tree

1. **Something does not work** (500 error, missing assets, "Vite manifest not found", connection refused, old behaviour): run `php artisan worktree:doctor`. It names the problem; `--fix` repairs it in the right order (containers, Composer, node, build, caches, migrations, stale workers).
2. **The code needs queued jobs** (mails, notifications, imports): run `php artisan worktree:services` to see the project's profiles, then turn on the worker profile it lists, for example `php artisan worktree:services workers` (the names come from the project's compose file; the project's notes may name them). Turn them off when you are done: `php artisan worktree:services none`. Do not start Horizon unless the task is about Horizon.
3. **The scheduler must run**: `php artisan worktree:services` shows which profile holds it.
4. **You need to look at the database**: `php artisan worktree:exec --service=<database service> <client> ...` (the service is in `worktree:status`; the client is `mariadb`, `mysql` or `psql`). With `shared`, the database server is the shared stack's, not a service of the worktree. Or turn on the project's admin profile, when it has one (`php artisan worktree:services phpmyadmin --add`; `phpmyadmin` is an alias of `tools`), and turn it off again afterwards.
5. **You check a page in the browser**: the URL is in `worktree:context`. The test login is in the project's notes in that output (or in the project's skills). A fresh worktree starts with the test data of the project's seed command when the project has the database dump switched on.
6. **Workers run old code** after you changed code (`worktree:doctor` says `workers: started before the last code change`): `php artisan worktree:doctor --fix` restarts them.
7. **Tests**: `php artisan worktree:exec test --parallel` uses the host's process limit (`exec.test_processes`). Run the tests you touched first (`--filter`), the full suite before you push.

## Memory rules

- Optional services (workers, Horizon, scheduler, phpMyAdmin) run only when their compose profile is on. A new worktree gets the profiles of `services.default` (`WORKTREE_DEFAULT_PROFILES`); when that is not set, it keeps the main checkout's `COMPOSE_PROFILES`. Check `php artisan worktree:services` at the start, turn on only what the task needs, and turn it off again (`worktree:services none`).
- Where the host runs `worktree:idle-stop` from a timer (or Sablier is set up), an unused stack is stopped after about 30 minutes (`idle.minutes`). Nothing stops it without such a setup. A stopped stack is normal: `worktree:wake` starts it in seconds, with its data.
- Delete a worktree when its branch is merged: `php artisan worktree:delete <name>`. With `sail`, it stops the containers and removes their volumes (the worktree's database) and the images the worktree built for itself. With `shared`, it stops the app container and drops the worktree's databases, its database user and its Redis numbers on the shared server. With `native`, it drops the cloned database. It offers to delete the merged branch.
- Do not start a second stack of the same worktree by hand (`docker compose -p ...`), and never change `COMPOSE_PROJECT_NAME` in a worktree's `.env`: the package refuses to act on a worktree whose compose project another checkout uses.

## Files you must not commit

The package writes `storage/worktree-bootstrap.json`, `storage/worktree-bootstrap.lock`, `storage/worktree-bootstrap.clone`, `storage/app/worktree/*` and `storage/logs/worktree-setup.log` in each worktree, and `storage/app/worktrees/*` (templates, locks, build and database caches) in the main checkout. They are not part of the project. A new worktree has no other changes: if `git status` shows changed tracked files right after `worktree:create`, report it.
