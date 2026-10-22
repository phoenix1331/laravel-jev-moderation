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

Built so far: service provider, config, migration, `ModerationDecision`, enums, factory. The rest is the target layout from the brief.

| Path | Role |
|---|---|
| `src/JevModerationServiceProvider.php` | Merges and publishes `config/jev-moderation.php` (tag `jev-moderation-config`); migrations (tag `jev-moderation-migrations`), commands |
| `config/jev-moderation.php` | Driver, mode (`pre`/`post`), fail (`open`/`closed`), queue, `sync`, `store_content`, `max_context_tokens`, policies |
| `database/migrations/` | `moderation_decisions`: polymorphic, append-only, schema builder only, explicit index names under 64 characters; morph key type follows `Schema::morphUsingUuids()`/`morphUsingUlids()` |
| `src/Models/ModerationDecision.php` | `@internal` Eloquent model: enum, float and array casts, `moderatable()` and `reviewedBy()` morphs |
| `src/Models/Builders/DecisionBuilder.php` | `@internal` builder returned by `newEloquentBuilder()`: `update`, `upsert`, `touch`, `increment`/`decrement`(`Each`), `delete`, `forceDelete` throw `Exceptions\DecisionsAreAppendOnly` |
| `database/factories/ModerationDecisionFactory.php` | No Faker: `forModel()`, `pending()`, `passed()`, `flagged()`, `blocked()`, `reported()`, `approved()`, `rejected()`, `reviewedBy()`, `withContent()` |
| `tests/Fixtures/Models/`, `tests/Fixtures/migrations/` | Host-app `Post` and `User` models and tables for tests; not shipped |
| `src/Concerns/Moderatable.php`, `src/Attributes/Moderate.php` | Trait (relations, scope, human API) and `#[Moderate(fields, policy)]` |
| `src/Observers/` | Shared observer on `created`/`updated`; skips when the content hash is unchanged |
| `src/Jobs/ModerateContent.php` | Queued after commit (or inline when `sync`); retries with backoff |
| `src/Contracts/`, `src/Drivers/` | `ModerationDriver` contract, `JevDriver` (Laravel HTTP client), `FakeDriver` |
| `src/Policies/` | Policy objects, threshold resolver, policy version hash |
| `src/Scopes/ModeratedScope.php` | Visibility by mode and fail setting; uses the optional `moderation_status` column when present |
| `src/Data/`, `src/Enums/`, `src/Events/` | `Classification`; `ModerationStatus` (built, `isHuman()`), `ModerationSource` (built: `jev`, `human`, `report`, `rescan`); `ContentPending`, `ContentPassed`, `ContentFlagged`, `ContentBlocked`, `ContentReported`, `ReviewResolved`, `ModerationFailed` |
| `src/Facades/JevModeration.php` | `classify()` for ad-hoc checks, `fake()` |
| `src/Console/` | `jev-moderation:install`, `ping`, `add-column`, `rescan`, `stats` |
| `tests/` | Pest + Testbench; `tests/Arch/` enforces strict types, no debug calls; `tests/Fixtures/Responses/jev/` recorded Jev responses (synthetic content) |
| `test-app/` | Fresh Laravel 13 app, symlinked path repository to `../`; gitignored and export-ignored |
| `art/` | VHS tapes (`art/tapes/*.tape`) and GIFs; export-ignored |
| `docs/` | GitHub Pages site; export-ignored |

## External integrations

| Service | Use |
|---|---|
| Jev (TypeSafe AI) `POST /v1/systemone`, `GET /v1/models` | One `choice` question per check: `criteria` map in, `choice`, `confidence` and full `probabilities` out. Bearer key; `x-typesafe-request-id` on every response. Detail and open decisions: `.claude/docs/jev-api-spike.md`. Never called in the default test suite |

## Decisions

| Decision | Why |
|---|---|
| Trait + shared observer, not middleware | Middleware only sees HTTP requests; content saved from jobs, APIs, Livewire or seeders would be missed |
| Per-category `review_above` and optional `block_above`, compared with `probabilities[category]` for every non-safe category | High threshold for irreversible actions, low threshold for "send to a human"; equal to a threshold counts as above. Jev's `confidence` measures distribution sharpness and shifts with the number of choices, so it only drives the policy's `uncertain_below` floor. A recorded answer with `fine` on top and `harassment` at 0.44 is why every category is checked |
| Answering Jev version in a `model` column | Aliases move without notice (`jev-latest` answered as `jev-1.14.0` while the docs said 1.13); calibration groups Jev-vs-human agreement by version |
| Content sent as named fields (`state: {content: {...}, context}`) | TypeSafe recommends named state; the hash covers the same structure that is sent |
| Policy `choices` are a name-to-description map, safe choice last | Descriptions are sent to the model; `jev-1.13` leans towards the first option |
| Driver errors are retryable (`429`, `529`, `5xx`, timeout) or permanent (everything else) | Retrying a bad key or malformed policy burns attempts |
| Polymorphic, append-only `moderation_decisions` table, enforced by a custom builder rather than model events | No changes to the developer's tables to start; human decisions add rows rather than overwrite Jev's, giving an audit trail and Jev-vs-human data for calibration. Model `save()`/`delete()` run through the builder too, so one guard covers mass writes that skip events. Raw `DB::table()` writes and `truncate()` are not guarded |
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
| `.github/workflows/tests.yml` | Pint `--test` \| PHP 8.3/8.4/8.5 x Laravel 12/13 x prefer-lowest/prefer-stable \| databases (PHP 8.4 x Laravel 12/13 x PostgreSQL 17, MySQL 8.4, MariaDB 11; env overrides the SQLite defaults in `phpunit.xml`) \| coverage (pcov, `--min=90`) \| dist archive check |
| `.github/workflows/static-analysis.yml` | Larastan with GitHub annotations |
| `.github/workflows/release.yml` | On `v*` tags: release notes from the matching `CHANGELOG.md` section |

## Local setup

- PHP 8.4 in WSL with `pcov` (`sudo apt install php8.4-pcov`) and `intl`
- `osv-scanner` on the PATH for the pre-commit hook
- `npm install` once to activate the Husky hooks
- `composer install`, then `composer check` before pushing
- **composer**: lint | lint:test | analyse | test | test:coverage | check
