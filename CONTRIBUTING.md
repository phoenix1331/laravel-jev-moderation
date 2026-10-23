# Contributing to Jev Moderation for Laravel

Thanks for helping. Bug reports, fixes and new features are all welcome. Questions are best asked in [GitHub Discussions](https://github.com/phoenix1331/laravel-jev-moderation/discussions) rather than issues.

## Setup

Prerequisites: PHP 8.3+ with `mbstring`, `intl` and `pdo_sqlite`, Composer 2, Node and npm. `osv-scanner` is required by the pre-commit hook (`go install github.com/google/osv-scanner/cmd/osv-scanner@latest`).

On Ubuntu or WSL:

```bash
sudo apt install php8.4-cli php8.4-mbstring php8.4-intl php8.4-sqlite3 php8.4-xml php8.4-curl php8.4-pcov
```

Everything runs on the host: there is no Docker setup and no database server to start.

```bash
git clone https://github.com/phoenix1331/laravel-jev-moderation
cd laravel-jev-moderation
composer install
npm install   # activates the git hooks
```

## Running checks

| Command | What it does |
|---|---|
| `composer test` | The Pest suite. Never calls a real API |
| `composer test:coverage` | Tests with coverage; fails below 90% on `src/` (needs `pcov` or `xdebug`) |
| `composer lint` | Fixes code style with Pint |
| `composer lint:test` | Checks code style without changing files |
| `composer analyse` | Larastan at max level |
| `composer check` | Style check, static analysis and the coverage gate, in that order |

The tests use Pest with Orchestra Testbench and an in-memory SQLite database, so nothing else needs to be running. Every PHP file declares `strict_types`; Pint adds it if you forget. `Http::preventStrayRequests()` is on for every test, and Jev answers come from recorded fixtures in `tests/Fixtures/Responses/jev/`.

### Persistent databases

CI also runs the suite on PostgreSQL, MySQL and MariaDB. In-memory SQLite starts empty for every test, so a test that leaks tables or rows only fails there. Reproduce that locally with a SQLite file:

```bash
touch /tmp/jev-moderation.sqlite
DB_DATABASE=/tmp/jev-moderation.sqlite vendor/bin/pest
```

### Accuracy suite

`composer test:accuracy` sends the labelled samples in `tests/Accuracy/Corpus/` to live Jev with each policy preset and writes `docs/accuracy.md`. It needs a key, costs about 28,000 input tokens a run, and is never part of `composer check` or CI:

```bash
JEV_API_KEY=... composer test:accuracy
JEV_API_KEY=... JEV_MODEL=jev-1.14.0 composer test:accuracy   # measure a pinned version
```

Run it after changing a preset's wording or thresholds, or when TypeSafe ships a new Jev version, and commit the updated `docs/accuracy.md` with the change. The corpus is synthetic: reserved phone numbers, `example.test` addresses, no real people.

## Git hooks

`npm install` installs two Husky hooks:

- `commit-msg` - commit messages must be entirely lowercase and follow [Conventional Commits](https://www.conventionalcommits.org/) without a scope, e.g. `feat: add report command`. `chore(deps): ...` is rejected
- `pre-commit` - runs `pint --test` and an `osv-scanner` audit of `composer.lock`

## Changelog

Every user-facing change adds a line under `## [Unreleased]` in `CHANGELOG.md`, in the same pull request, using the [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) headings (`Added`, `Changed`, `Fixed`, `Removed`, `Deprecated`, `Security`). Internal refactors and test-only changes do not need an entry.

## Pull requests

- Keep each pull request to one change
- Never put a real API key, real user content or personal data in tests, fixtures or the corpus
- Include tests; coverage must stay at or above 90%
- `composer check` must pass
- Fill in the pull request checklist

Releases are covered in [RELEASING.md](RELEASING.md).
