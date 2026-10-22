# Context

**Jev Moderation for Laravel** (`phoenix1331/laravel-jev-moderation`) is a headless Laravel package for confidence-aware content moderation. A `Moderatable` trait sends chosen model fields to Jev (TypeSafe AI's typed decision model), gets back a category and a confidence score, and records a decision of `passed`, `flagged` (waits for a human) or `blocked` (actioned automatically) using per-category thresholds.

This is a Composer **package**, not a Laravel app. There is no `app/`, no UI, no views, no routes, no Docker and no database server; it is tested inside Orchestra Testbench on in-memory SQLite.

## Family

| Package | Relationship |
|---|---|
| `phoenix1331/filament-jev-moderation` | Downstream Filament review queue and calibration widget; requires this package. Built after it |
| `laravel-audit-logging` (`../audit-logging` playground) | Source of the trait-registers-shared-observer pattern |
| `laravel-jev-hotdog`, `laravel-jev-triage-playground` (playgrounds) | Existing Jev clients; reference for the API shape spike |
| `dynamik-dev/modman`, `edulazaro/laratox` | Prior art; neither is Jev-native or confidence-calibrated. Positioning: "confidence-aware moderation for Laravel" |

## Architecture at a glance

Only the service provider and config exist so far. The rest is the target layout from the brief.

| Path | Role |
|---|---|
| `src/JevModerationServiceProvider.php` | Merges and publishes `config/jev-moderation.php` (tag `jev-moderation-config`); migrations (tag `jev-moderation-migrations`), commands |
| `config/jev-moderation.php` | Driver, mode (`pre`/`post`), fail (`open`/`closed`), queue, `sync`, `store_content`, `max_context_tokens`, policies |
| `database/migrations/` | `moderation_decisions`: polymorphic, append-only, schema builder only |
| `src/Concerns/Moderatable.php`, `src/Attributes/Moderate.php` | Trait (relations, scope, human API) and `#[Moderate(fields, policy)]` |
| `src/Observers/` | Shared observer on `created`/`updated`; skips when the content hash is unchanged |
| `src/Jobs/ModerateContent.php` | Queued after commit (or inline when `sync`); retries with backoff |
| `src/Contracts/`, `src/Drivers/` | `ModerationDriver` contract, `JevDriver` (Laravel HTTP client), `FakeDriver` |
| `src/Policies/` | Policy objects, threshold resolver, policy version hash |
| `src/Scopes/ModeratedScope.php` | Visibility by mode and fail setting; uses the optional `moderation_status` column when present |
| `src/Data/`, `src/Enums/`, `src/Events/` | `Classification`, `ModerationStatus`, `ModerationSource`; `ContentPending`, `ContentPassed`, `ContentFlagged`, `ContentBlocked`, `ContentReported`, `ReviewResolved`, `ModerationFailed` |
| `src/Facades/JevModeration.php` | `classify()` for ad-hoc checks, `fake()` |
| `src/Console/` | `jev-moderation:install`, `ping`, `add-column`, `rescan`, `stats` |
| `tests/` | Pest + Testbench; `tests/Arch/` enforces strict types, no debug calls; `tests/Fixtures/Responses/jev/` recorded Jev responses |
| `test-app/` | Fresh Laravel 13 app, symlinked path repository to `../`; gitignored and export-ignored |
| `art/` | VHS tapes (`art/tapes/*.tape`) and GIFs; export-ignored |
| `docs/` | GitHub Pages site; export-ignored |

## External integrations

| Service | Use |
|---|---|
| Jev (TypeSafe AI) | Classification of moderated content. Early access: endpoint, payload and response shapes are unverified until the API spike (`.claude/docs/jev-api-spike.md`). Never called in the default test suite |

## Decisions

| Decision | Why |
|---|---|
| Trait + shared observer, not middleware | Middleware only sees HTTP requests; content saved from jobs, APIs, Livewire or seeders would be missed |
| Per-category `review_above` and optional `block_above` | High threshold for irreversible actions, low threshold for "send to a human"; a confidence equal to a threshold counts as above |
| Polymorphic, append-only `moderation_decisions` table | No changes to the developer's tables to start; human decisions add rows rather than overwrite Jev's, giving an audit trail and Jev-vs-human data for calibration |
| Optional denormalised `moderation_status` column | Fast pre-moderation queries on hot tables without a `whereHas` |
| User reports use the same pipeline (`flagForReview()`) | One decision history and one review queue |
| Driver contract with `JevDriver` and `FakeDriver` | Jev is early access and may change; tests and consumers fake it |
| Policy version (hash of the policy array) on every decision | Threshold changes are traceable and `rescan` can target them |
| `store_content` off by default | Content goes to a third party; locally only a sha256 hash and the decision are kept |
| Headless | UI lives in `filament-jev-moderation` |
| Runtime dependencies limited to `illuminate/*` | Lean install; HTTP via Laravel's client so `Http::fake()` works |
| `test-app/` is a fresh Laravel 13 app in the repo, not a copied playground | Brief: manual testing in the same repo, symlinked so package edits show live; setup steps in `CONTRIBUTING.md` |
| VHS recordings via `composer record` | Every CLI example in the README is a real recording against `test-app/` |
| Out of scope for v1: UI, image/file moderation, user sanctions, multi-label classification | Events let the app apply sanctions |
| No Docker, Makefile or database server | Package development only: host PHP and Composer, Testbench on SQLite |
| `composer.lock` gitignored | Libraries resolve against the consumer's lock file; CI tests prefer-lowest and prefer-stable instead |
| No Dependabot | Its `chore(deps):` titles break the no-scope commit rule; dependencies are updated by hand |

## Dependencies and constraints

| Package | Constraint | Why |
|---|---|---|
| `php` | `^8.3` | Laravel 13 floor; Pest 4 and Testbench 11 both allow it |
| `illuminate/*` | `^12.0\|^13.0` | Current and previous Laravel major; `console`, `contracts`, `database`, `http`, `queue`, `support` only. Laravel Prompts arrives via `illuminate/console` (`^0.3`) |
| `orchestra/testbench` (dev) | `^10.6\|^11.3` | 10.x = Laravel 12, 11.x = Laravel 13 (11.3 needs `^13.32`) |
| `pestphp/pest` (dev) | `^4.1\|^5.3` | Pest 5 needs PHP 8.4 and Laravel 13, so v4 covers the lower CI cells |
| `pestphp/pest-plugin-laravel` (dev) | `^4.0\|^5.0` | Matches Pest |
| `larastan/larastan` (dev) | `^3.13` | `level: max`, no baseline |
| `laravel/pint` (dev) | `^1.32` | `laravel` preset plus `declare_strict_types` |

## CI

| Workflow | Jobs |
|---|---|
| `.github/workflows/tests.yml` | Pint `--test` \| PHP 8.3/8.4/8.5 x Laravel 12/13 x prefer-lowest/prefer-stable \| coverage (pcov, `--min=90`) \| dist archive check. A PostgreSQL/MySQL/MariaDB job arrives with the migration |
| `.github/workflows/static-analysis.yml` | Larastan with GitHub annotations |
| `.github/workflows/release.yml` | On `v*` tags: release notes from the matching `CHANGELOG.md` section |

## Local setup

- PHP 8.4 in WSL with `pcov` (`sudo apt install php8.4-pcov`) and `intl`
- `osv-scanner` on the PATH for the pre-commit hook
- `npm install` once to activate the Husky hooks
- `composer install`, then `composer check` before pushing
- **composer**: lint | lint:test | analyse | test | test:coverage | check
