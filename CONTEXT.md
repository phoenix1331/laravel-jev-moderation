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

Built so far: service provider, config, migration, `ModerationDecision`, enums, factory, driver contract, manager, data objects, `FakeDriver`, `JevDriver`, policies, presets, threshold resolver. The rest is the target layout from the brief.

| Path | Role |
|---|---|
| `src/JevModerationServiceProvider.php` | Merges and publishes `config/jev-moderation.php` (tag `jev-moderation-config`); migrations (tag `jev-moderation-migrations`), commands |
| `config/jev-moderation.php` | Built: `enabled`, `driver`, `drivers.jev` (key, model, base URL, timeout), `max_context_tokens`, `policy`, `policies` (`community`, `chat`, `strict`). To come: mode (`pre`/`post`), fail (`open`/`closed`), queue, `sync`, `store_content` |
| `database/migrations/` | `moderation_decisions`: polymorphic, append-only, schema builder only, explicit index names under 64 characters; morph key type follows `Schema::morphUsingUuids()`/`morphUsingUlids()` |
| `src/Models/ModerationDecision.php` | `@internal` Eloquent model: enum, float and array casts, `moderatable()` and `reviewedBy()` morphs |
| `src/Models/Builders/DecisionBuilder.php` | `@internal` builder returned by `newEloquentBuilder()`: `update`, `upsert`, `touch`, `increment`/`decrement`(`Each`), `delete`, `forceDelete` throw `Exceptions\DecisionsAreAppendOnly` |
| `database/factories/ModerationDecisionFactory.php` | No Faker: `forModel()`, `pending()`, `passed()`, `flagged()`, `blocked()`, `reported()`, `approved()`, `rejected()`, `reviewedBy()`, `withContent()` |
| `tests/Fixtures/Models/`, `tests/Fixtures/migrations/` | Host-app `Post` and `User` models and tables for tests; not shipped |
| `src/Concerns/Moderatable.php`, `src/Attributes/Moderate.php` | Trait (relations, scope, human API) and `#[Moderate(fields, policy)]` |
| `src/Observers/` | Shared observer on `created`/`updated`; skips when the content hash is unchanged |
| `src/Jobs/ModerateContent.php` | Queued after commit (or inline when `sync`); retries with backoff |
| `src/Contracts/ModerationDriver.php` | Built: `classify(ModerationRequest): Classification`, `models(): list<string>`; both throw `DriverException` |
| `src/Drivers/DriverManager.php` | Built, `@internal`: Laravel `Manager` keyed by `jev-moderation.driver`, `extend()` for custom drivers, guards that each driver implements the contract. Singleton; `ModerationDriver` binds to its default driver. Typed config guards throw on an empty model or base URL and a non-positive timeout or budget |
| `src/Drivers/JevDriver.php` | Built, `@internal`: Laravel HTTP client, bearer key, no internal retries; `state: {content, context?}` with one `choice` question keyed `moderation` (empty descriptions sent as `null`); answer validated field by field, anything else is a permanent "does not understand"; errors by status (`429`/`529`/`5xx`/connection retryable with `retry-after`, the rest permanent, auth errors hint at `JEV_API_KEY`); context budget at 3 bytes per token, context trimmed first, then content fields by the same share, `mb_strcut` so UTF-8 survives; `models()` from `GET /models` |
| `src/Drivers/FakeDriver.php` | Built, public test API: no setup answers the request's `safeChoice` (else first choice) at confidence 1; `classifyAs()` spreads probabilities with Jev's formula unless given; `classifyUsing()`, `failWith()` (retryable by default); queued `push()`/`pushFailure()` used first; records every request, failed ones too; PHPUnit `Assert` assertions |
| `src/Data/ModerationRequest.php`, `src/Data/Classification.php` | Built: request is policy name, instructions, `choices` (name to description, 2 to 255), `content` (field to value), `context`, `safeChoice` (must be a choice; not sent to the classifier); answer is choice, confidence, probabilities (`probabilityOf()`), driver, model version, input tokens, request id, latency, `truncated`. Values outside 0 to 1 throw |
| `src/Exceptions/DriverException.php` | Built: `retryable()` / `permanent()`, with HTTP status, request id and `retryAfter` seconds |
| `src/Data/Policy.php`, `src/Data/CategoryRule.php` | Built: `Policy::fromConfig()` guards every value and names the policy in errors; choices as a map or a list of names; rules only for offered, non-safe choices; `version` is sha256 of question, choices (in order), safe choice, thresholds and floor, not the name; `request()` builds the `ModerationRequest`. Rule thresholds 0 to 1, block not below review |
| `src/Policies/PolicyRegistry.php` | Built, `@internal`: `get(?name)`, `names()`, `defaultName()` from `jev-moderation.policy`; parses on every call so runtime config changes apply |
| `src/Policies/ThresholdResolver.php` | Built, `@internal`: every rule checked against `probabilityOf(category)`; block beats flag, then higher probability; nothing over a threshold and `confidence < uncertain_below` flags (`LowConfidence`); else passes with the top choice |
| `src/Data/Verdict.php`, `src/Enums/VerdictReason.php` | Built: status, reason (`clear`, `review_threshold`, `block_threshold`, `low_confidence`), deciding category and its probability |
| `src/Scopes/ModeratedScope.php` | Visibility by mode and fail setting; uses the optional `moderation_status` column when present |
| `src/Enums/`, `src/Events/` | `ModerationStatus` (built, `isHuman()`), `ModerationSource` (built: `jev`, `human`, `report`, `rescan`); `ContentPending`, `ContentPassed`, `ContentFlagged`, `ContentBlocked`, `ContentReported`, `ReviewResolved`, `ModerationFailed` |
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
| Presets: `community` (forums, floor 0.30), `chat` (higher review thresholds, no floor so the queue stays usable), `strict` (young people: low thresholds, floor 0.50, `self_harm` flags but never auto-blocks) | Starting points, not measured yet; the light accuracy eval tunes them and records the numbers here |
| A confidence exactly at `uncertain_below` passes; a probability exactly at a threshold acts | Thresholds are "at or above" per the brief; the floor is "below" so the two never overlap at one value |
| Driver errors are retryable (`429`, `529`, `5xx`, timeout) or permanent (everything else); the driver never retries itself | Retrying a bad key or malformed policy burns attempts; retrying inside the driver would block a queue worker, so backoff belongs to the job |
| No tokeniser; 3 bytes per token budget | Runtime deps are `illuminate/*` only and Jev's tokeniser is unpublished; `400 max_tokens_exceeded` still fails permanently, so the estimate only has to be safe |
| Polymorphic, append-only `moderation_decisions` table, enforced by a custom builder rather than model events | No changes to the developer's tables to start; human decisions add rows rather than overwrite Jev's, giving an audit trail and Jev-vs-human data for calibration. Model `save()`/`delete()` run through the builder too, so one guard covers mass writes that skip events. Raw `DB::table()` writes and `truncate()` are not guarded |
| Optional denormalised `moderation_status` column | Fast pre-moderation queries on hot tables without a `whereHas` |
| User reports use the same pipeline (`flagForReview()`) | One decision history and one review queue |
| Driver contract with `JevDriver` and `FakeDriver` | Jev is early access and may change; tests and consumers fake it. `FakeDriver` lives in `src/Drivers/`, not `src/Testing/`, because it is also a configurable driver for demos; its assertions use PHPUnit, present wherever tests run |
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
