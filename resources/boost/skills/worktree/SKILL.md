---
name: worktree
description: Work in a git worktree of a Laravel project that uses woda/laravel-worktrees (one Docker stack per worktree). Use when you start in a worktree, run tests or artisan in it, need queue workers, the scheduler or phpMyAdmin, open the app in a browser, find a broken setup, or create, stop or delete worktrees. Covers the worktree:* commands, which container runs what, and how to keep memory low.
---

# Working in a worktree

Each worktree of the project is a git checkout with its own Docker stack: an app container (PHP), its own database and Redis, and optional services behind compose profiles. Run every `worktree:*` command with `php artisan` in the worktree; without a name it acts on the checkout you are in.

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
| Use another service | `php artisan worktree:exec --service=mariadb mariadb -e 'select 1'` |
| Fix a broken setup | `php artisan worktree:doctor`, then `php artisan worktree:doctor --fix` |
| Start a stopped stack | `php artisan worktree:wake` (or `worktree:doctor --fix`) |
| Create another worktree | `php artisan worktree:create my-feature` (prints each step's time and the URL) |
| Remove a worktree | `php artisan worktree:delete my-feature` (offers to delete a merged branch) |

Do not run `php artisan test`, `composer` or `php artisan migrate` on the host: the host has the wrong database host, PHP version and environment. Use `worktree:exec`.

## Decision tree

1. **Something does not work** (500 error, missing assets, "Vite manifest not found", connection refused, old behaviour): run `php artisan worktree:doctor`. It names the problem; `--fix` repairs it in the right order (containers, Composer, node, build, caches, migrations, stale workers).
2. **The code needs queued jobs** (mails, notifications, imports): turn the workers on with `php artisan worktree:services workers` (the profile names are in `worktree:services`). Turn them off when you are done: `php artisan worktree:services none`. Do not start Horizon unless the task is about Horizon.
3. **The scheduler must run**: it is part of the `workers` profile in most projects; check `php artisan worktree:services`.
4. **You need to look at the database**: `php artisan worktree:exec --service=mariadb mariadb ...`, or turn on the `tools` profile (`php artisan worktree:services phpmyadmin --add`) and turn it off again afterwards.
5. **You check a page in the browser**: the URL is in `worktree:context`. The test login is in the project's notes in that output (or in the project's skills). A fresh worktree starts with the test data of the project's seed command when the project has the database dump switched on.
6. **Workers run old code** after you changed code (`worktree:doctor` says `workers: started before the last code change`): `php artisan worktree:doctor --fix` restarts them.
7. **Tests**: `php artisan worktree:exec test --parallel` uses the host's process limit (`exec.test_processes`). Run the tests you touched first (`--filter`), the full suite before you push.

## Memory rules

- Optional services (workers, Horizon, scheduler, phpMyAdmin) are off by default. Turn on only what the task needs, and turn it off again (`worktree:services none`).
- An idle stack is stopped after about 30 minutes (`worktree:idle-stop` on agent hosts, or Sablier on the first request). That is normal: `worktree:wake` starts it in seconds, with its data.
- Delete a worktree when its branch is merged: `php artisan worktree:delete <name>`. It stops the containers, removes the worktree's database volumes and its own image, and offers to delete the merged branch.
- Do not start a second stack of the same worktree by hand (`docker compose -p ...`), and never change `COMPOSE_PROJECT_NAME` in a worktree's `.env`: the package refuses to act on a worktree whose compose project another checkout uses.

## Files you must not commit

The package writes `storage/worktree-bootstrap.json`, `storage/worktree-bootstrap.lock`, `storage/app/worktree/*` and `storage/logs/worktree-setup.log`. They are not part of the project. A new worktree has no other changes: if `git status` shows changed tracked files right after `worktree:create`, report it.
