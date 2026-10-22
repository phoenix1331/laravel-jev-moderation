# Contributing to Jev Moderation for Laravel

Thanks for helping. Bug reports, fixes and new features are all welcome. Questions are best asked in [GitHub Discussions](https://github.com/phoenix1331/laravel-jev-moderation/discussions) rather than issues.

## Setup

Prerequisites: PHP 8.3+ with `mbstring`, `intl` and `pdo_sqlite`, Composer 2, Node and npm. `osv-scanner` is required by the pre-commit hook (`go install github.com/google/osv-scanner/cmd/osv-scanner@latest`).

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

The tests use Pest with Orchestra Testbench and an in-memory SQLite database, so nothing else needs to be running. Every PHP file declares `strict_types`; Pint adds it if you forget.

## Git hooks

`npm install` installs two Husky hooks:

- `commit-msg` - commit messages must be entirely lowercase and follow [Conventional Commits](https://www.conventionalcommits.org/) without a scope, e.g. `feat: add report command`. `chore(deps): ...` is rejected
- `pre-commit` - runs `pint --test` and an `osv-scanner` audit of `composer.lock`

## Changelog

Every user-facing change adds a line under `## [Unreleased]` in `CHANGELOG.md`, in the same pull request, using the [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) headings (`Added`, `Changed`, `Fixed`, `Removed`, `Deprecated`, `Security`). Internal refactors and test-only changes do not need an entry.

## Pull requests

- Keep each pull request to one change
- Include tests; coverage must stay at or above 90%
- `composer check` must pass
- Fill in the pull request checklist

Releases are covered in [RELEASING.md](RELEASING.md).
