# Context

**Jev Moderation for Laravel** (`phoenix1331/laravel-jev-moderation`) - Confidence-aware content moderation for Laravel: classify any Eloquent model with Jev and route each item to passed, flagged for human review, or blocked, using per-category thresholds.

This is a Composer **package**, not a Laravel app. There is no `app/`, no Docker and no database server; it is tested inside Orchestra Testbench on in-memory SQLite.

## Family

| Package | Relationship |
|---|---|
| `phoenix1331/filament-jev-moderation` | Downstream Filament review queue; requires this package |

## Architecture at a glance

| Path | Role |
|---|---|
| `src/JevModerationServiceProvider.php` | Auto-discovered provider |
| `tests/` | Pest + Testbench; `tests/Arch/` enforces strict types and no debug calls |

## Decisions

| Decision | Why |
|---|---|
| No Docker, Makefile or database server | Package development only: host PHP and Composer, Testbench on SQLite |
| `composer.lock` gitignored | Libraries resolve against the consumer's lock file; CI tests prefer-lowest and prefer-stable instead |
| No Dependabot | Its `chore(deps):` titles break the no-scope commit rule; dependencies are updated by hand |

## Dependencies and constraints

| Package | Constraint | Why |
|---|---|---|
| `php` | `^8.3` | |
| `illuminate/*` | `^12.0\|^13.0` | Current and previous Laravel major; `console`, `contracts`, `database`, `http`, `queue`, `support` only (no runtime deps beyond `illuminate/*`; Laravel Prompts arrives via `illuminate/console`) |
| `orchestra/testbench` (dev) | `^10.6\|^11.3` | Laravel 12 and 13 |
| `pestphp/pest` (dev) | `^4.1\|^5.3` | Pest 5 needs PHP 8.4 and Laravel 13, so v4 covers the lower CI cells |
| `larastan/larastan` (dev) | `^3.13` | `level: max`, no baseline |

## CI

| Workflow | Jobs |
|---|---|
| `.github/workflows/tests.yml` | Pint `--test` \| PHP 8.3/8.4/8.5 x Laravel 12/13 x prefer-lowest/prefer-stable \| coverage (pcov, `--min=90`) \| dist archive check |
| `.github/workflows/static-analysis.yml` | Larastan with GitHub annotations |
| `.github/workflows/release.yml` | On `v*` tags: release notes from the matching `CHANGELOG.md` section |

## Local setup

- PHP 8.4 in WSL with `pcov` (`sudo apt install php8.4-pcov`)
- `osv-scanner` on the PATH for the pre-commit hook
- `npm install` once to activate the Husky hooks
- `composer install`, then `composer check` before pushing
