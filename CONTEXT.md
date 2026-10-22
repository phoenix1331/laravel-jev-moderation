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

Built so far: service provider, config, migration, `ModerationDecision`, enums, factory, driver contract, manager, data objects, `FakeDriver`, `JevDriver`, policies, presets, threshold resolver, accuracy suite, `Moderatable` trait and `#[Moderate]`, pending decisions, `ModerateContent` job, result decisions, events, visibility scope and modes, reports and reviews, facade and public API tests. The rest is the target layout from the brief.

| Path | Role |
|---|---|
| `src/JevModerationServiceProvider.php` | Merges and publishes `config/jev-moderation.php` (tag `jev-moderation-config`); migrations (tag `jev-moderation-migrations`), commands |
| `config/jev-moderation.php` | Built: `enabled`, `mode`, `fail`, `queue`, `connection`, `sync`, `tries`, `backoff`, `store_content`, `driver`, `drivers.jev` (key, model, base URL, timeout), `max_context_tokens`, `policy`, `policies` (`community`, `chat`, `strict`) |
| `database/migrations/` | `moderation_decisions`: polymorphic, append-only, schema builder only, explicit index names under 64 characters; morph key type follows `Schema::morphUsingUuids()`/`morphUsingUlids()` |
| `src/Models/ModerationDecision.php` | Public read API (handed out by the trait's relations): Eloquent model, enum, float and array casts, `moderatable()` and `reviewedBy()` morphs |
| `src/Models/Builders/DecisionBuilder.php` | `@internal` builder returned by `newEloquentBuilder()`: `update`, `upsert`, `touch`, `increment`/`decrement`(`Each`), `delete`, `forceDelete` throw `Exceptions\DecisionsAreAppendOnly` |
| `database/factories/ModerationDecisionFactory.php` | No Faker: `forModel()`, `pending()`, `passed()`, `flagged()`, `blocked()`, `reported()`, `approved()`, `rejected()`, `reviewedBy()`, `withContent()` |
| `tests/Fixtures/Models/`, `tests/Fixtures/migrations/` | Host-app models and tables for tests, not shipped: `Post`, `User`, `ForumPost` (README example), `ChatMessage` (every override), `Comment` (default policy), `UnmarkedPost` (no attribute). Analysed by Larastan, which skips unused traits |
| `src/Concerns/Moderatable.php`, `src/Attributes/Moderate.php` | Built: `#[Moderate(fields, policy)]` (read once per class by reflection); trait registers typed `created`/`updated` listeners that call `Moderator`; `moderatableContent()` (attribute fields, null/blank skipped, numbers and `Stringable` to text, anything else throws), `moderationContext()`, `moderationPolicy()`, `moderationAuthor()` (`user` relation), `moderationDecisions` (oldest first), `latestModeration` (`latestOfMany`), `moderationStatus()` (null if never checked), `isFlagged()`, `isBlocked()` (blocked or rejected), `isVisible()`, scopes `withoutModeration()`, `whereModerationStatus(...)`, `onlyFlagged()`, `onlyBlocked()`, human API `flagForReview()`, `approve()`, `reject()`, `moderateNow()`, `toModerationSubject()` |
| `src/Moderation/Reviewer.php` | Built, `@internal`: `report()` appends `Flagged` (source `report`), or the current status if blocked or rejected; `resolve()` appends `Approved`/`Rejected` (source `human`). Reporter or moderator in `reviewed_by`, reason in `note`, current hash and policy version. Fires `ContentReported` / `ReviewResolved`; works with `enabled` off |
| `src/Moderation/Moderator.php`, `src/Moderation/ModerationSubject.php` | Built, `@internal`: skips when disabled, when an update changed none of the attribute fields, when content is empty, or when the hash equals the latest decision's; otherwise writes a `Pending` decision (policy name and version, hash, content only with `store_content`), fires `ContentPending`, then dispatches `ModerateContent` `afterCommit()` (`submit()` skips the change checks, for `moderateNow()`) on the configured connection and queue, or with `sync` checks inline (a failure fires `ModerationFailed`, no retries). Hash: sha256 of the content map with keys sorted, context excluded |
| `src/Jobs/ModerateContent.php` | Built, `@internal`: payload is the decision id only; `tries()` and `backoff()` from config (exponential from `backoff`); retryable `DriverException` released with `retry-after` or backoff until the last attempt, then failed; permanent ones failed at once; `failed()` reports other exceptions once |
| `src/Moderation/Checker.php` | Built, `@internal`: skips decisions that are gone, not pending, superseded by a newer decision, for a deleted or non-Moderatable model, or whose hash no longer matches the content; else classifies with the current policy and appends the result row, then fires the outcome event. `failed()` appends a `Failed` row (meta: reason, retryable) and fires `ModerationFailed`, unless superseded. Loads the model with `newQueryWithoutScopes()` so hidden content is still checked |
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
| `src/Scopes/ModeratedScope.php` | Built, `@internal`: added by the trait; `whereDoesntHave(moderationDecisions) or whereHas(latestModeration, status in visible)`, visible set read from config per query. To come: use the optional `moderation_status` column when present |
| `src/Moderation/Visibility.php`, `src/Enums/ModerationMode.php`, `src/Enums/FailMode.php` | Built: post shows pending, passed, flagged, approved; pre shows passed, approved; `fail: open` adds failed; no decision is always visible. Unknown config values throw naming the env var |
| `src/Enums/`, `src/Events/` | `ModerationStatus` (built, `isHuman()`), `ModerationSource` (built: `jev`, `human`, `report`, `rescan`); events built: `ContentPending`, `ContentPassed`, `ContentFlagged`, `ContentBlocked` (model, decision), `ModerationFailed` (model, failed decision, reason, retryable); final readonly with `SerializesModels`. `ContentReported`, `ReviewResolved` (model, decision) |
| `src/Facades/JevModeration.php`, `src/JevModerationManager.php` | Built: facade (alias in `extra.laravel.aliases`) over the singleton manager: `classify(string\|array, ?policy, ?context): CheckResult` (records nothing, throws `DriverException`), `policy()`, `policies()`, `extend()`. Static `fake()` sets `jev-moderation.driver` to `fake`, forgets cached drivers once, returns the shared `FakeDriver` |
| `src/Data/CheckResult.php` | Built: policy name, `Verdict`, `Classification`; `status()`, `passed()`, `flagged()`, `blocked()` |
| `tests/Arch/PublicApiTest.php` | `@internal` exactly on `Jobs`, `Models\Builders`, `Moderation`, `Policies`, `Scopes`, `DriverManager`, `JevDriver`; facade `@method` names and parameters equal the manager's public methods; plain-data classes return no Eloquent types; interfaces only in `Contracts`; headless (no Filament, Livewire, views, routes); alias registered. Mutation-checked |
| `src/Console/` | `jev-moderation:install`, `ping`, `add-column`, `rescan`, `stats` |
| `tests/Accuracy/` | Live Jev suite, outside the default `phpunit.xml` suites: `Corpus/{preset}.php` (53 synthetic samples with `expected` and `acceptable` outcomes), `Support/` (`Corpus`, `Sample`, `AccuracyRunner` with retry on retryable errors, `AccuracyResult` scoring exact/acceptable/wrong/missed/wrongly blocked/over-flagged, `AccuracyReport` writing `docs/accuracy.md`). Harness and corpus validity tested in the default suite with `FakeDriver` (`tests/Unit/Accuracy/`) |
| `tests/` | Pest + Testbench; `tests/Arch/` enforces strict types, no debug calls; `tests/Fixtures/Responses/jev/` recorded Jev responses (synthetic content) |
| `test-app/` | Fresh Laravel 13 app, symlinked path repository to `../`; gitignored and export-ignored |
| `art/` | VHS tapes (`art/tapes/*.tape`) and GIFs; export-ignored |
| `docs/` | GitHub Pages site; `docs/accuracy.md` written by the accuracy suite; export-ignored |

## External integrations

| Service | Use |
|---|---|
| Jev (TypeSafe AI) `POST /v1/systemone`, `GET /v1/models` | One `choice` question per check: `criteria` map in, `choice`, `confidence` and full `probabilities` out. Bearer key; `x-typesafe-request-id` on every response. Detail and open decisions: `.claude/docs/jev-api-spike.md`. Never called in the default test suite |

## Decisions

| Decision | Why |
|---|---|
| Trait + model events, not middleware | Middleware only sees HTTP requests; content saved from jobs, APIs, Livewire or seeders would be missed. Typed `static fn (self $model)` listeners in `bootModeratable()` instead of an observer class: an observer receives a plain `Model` and would need an unreachable `method_exists()` guard. `saveQuietly()`/`withoutEvents()` skip moderation, as for any model event |
| `ModerationDecision` is public for reads | The brief's `moderationDecisions`/`latestModeration` relations hand it out and `filament-jev-moderation` queries it; append-only keeps writes inside the package |
| Content hash covers content only, keys sorted | Reordering `#[Moderate]` fields or changing context (thread title) is not a reason to re-check; `rescan` covers policy changes |
| A check appends a result row after the pending row | Append-only: the pending row is never updated, so history shows when a check started and what it returned |
| Result `category` is the deciding category and `confidence` its probability; Jev's top choice and own confidence go in `meta` | Thresholds read the category probability, so calibration (Jev vs human) compares like with like |
| The job carries the decision id, not the content, and rebuilds content from the model | No content in the queue; a hash mismatch means the content moved on and a newer decision (or none, after `saveQuietly()`) covers it |
| Retries counted by hand in `handle()` rather than by thrown exceptions | Permanent errors must fail on the first attempt while retryable ones honour `retry-after` |
| `sync` checks inline with no retries | Backoff would hold up the request; the `fail` setting covers pending content |
| Events carry the model and decision (brief) rather than scalars only (`package-patterns.md`) | Listeners for sanctions need the author and content; `SerializesModels` keeps queued listeners light |
| Tests default to `FakeDriver` (`TestCase::defineEnvironment()`) | Saves now classify; the shipped default stays `jev` |
| Privacy claims are tests (`tests/Feature/Privacy/PrivacyTest.php`): no content in any decision column, queued job, event or `ModerationFailed` reason (even when Jev echoes input in a `422`); only `#[Moderate]` fields reach Jev; the key only in the `Authorization` header | `SECURITY.md` will state these; a regression fails the suite. Mutation-checked with `store_content` on |
| The package never logs (arch rule bans `Log`, `LoggerInterface`, `logger()`, `info()`, `error_log()`) | Logs are the usual place content leaks; events let apps log what they choose |
| A check that gives up appends a `failed` decision (user's choice over the brief's "stays pending") | Mode governs ordinary pending content and `fail` only failed checks, so `pre` + `fail: open` never shows unchecked posts while they wait; failures are queryable and `rescan` can retry them |
| Models with no decision are visible in both modes | Switching to `pre` must not hide all existing content; `rescan` brings it under moderation |
| Local scopes (`scopeOnlyFlagged()` etc.) rather than builder macros | Larastan understands local scopes, so apps keep level max |
| `$decision->moderatable` applies the visibility scope (null for hidden content) | Eloquent applies global scopes to `morphTo`; package code loads models with `newQueryWithoutScopes()`, and the Filament package must too. `SerializesModels` restores without scopes, so queued listeners still get hidden models |
| A report on blocked or rejected content keeps that status | In `post` mode a `flagged` row would make removed content visible again; the report is still recorded with its reason |
| A report supersedes a check still queued | The pending row is no longer latest, so the check is skipped; a human sees it anyway |
| `reviewed_by` holds the reporter on reports and the moderator on reviews; `source` tells them apart | One morph pair, no schema change |
| Reports and reviews work while `enabled` is off | They are explicit calls; `enabled` only stops automatic checks |
| Public API = everything not `@internal`, enforced by `tests/Arch/PublicApiTest.php` | SemVer needs a deliberate boundary; the test fails when a class crosses it without the list being updated |
| `JevModeration::fake()` changes config rather than swapping the facade root | Saves, queued jobs and `classify()` all resolve the driver through `DriverManager`, so one switch covers every path |
| Emptied content records nothing | Nothing to classify; the earlier decision stands (a flagged post blanked by its author stays flagged) |
| Per-category `review_above` and optional `block_above`, compared with `probabilities[category]` for every non-safe category | High threshold for irreversible actions, low threshold for "send to a human"; equal to a threshold counts as above. Jev's `confidence` measures distribution sharpness and shifts with the number of choices, so it only drives the policy's `uncertain_below` floor. A recorded answer with `fine` on top and `harassment` at 0.44 is why every category is checked |
| Answering Jev version in a `model` column | Aliases move without notice (`jev-latest` answered as `jev-1.14.0` while the docs said 1.13); calibration groups Jev-vs-human agreement by version |
| Content sent as named fields (`state: {content: {...}, context}`) | TypeSafe recommends named state; the hash covers the same structure that is sent |
| Policy `choices` are a name-to-description map, safe choice last | Descriptions are sent to the model; `jev-1.13` leans towards the first option |
| Presets: `community` (forums, floor 0.30, hate blocks at 0.90), `chat` (higher review thresholds, no floor so the queue stays usable), `strict` (young people: low thresholds, floor 0.50, `self_harm` flags but never auto-blocks) | Starting points, checked with the light accuracy suite (below) |
| Light accuracy suite, run by hand (`JEV_API_KEY=... composer test:accuracy`), never in CI | Thresholds are app-specific, so no eval proves them for everyone; the suite catches presets that are plainly wrong and shows the effect of a new Jev version or a wording change. About 28k input tokens a run |
| Accuracy on 2026-10-22, `jev-1.14.0` via `jev-latest`: first run chat/community/strict 71/71/67% exact, 93/100/93% exact or acceptable, 0 missed harmful, 1 wrongly blocked (`strict` blunt correction), 1 over-flagged (`chat` banter). Fixed with `fine` descriptions (banter; corrections and blunt feedback) and `community` hate `block_above` 0.95 to 0.90. Second run 86/75/73% exact, 100% exact or acceptable everywhere, 0 missed, 0 wrongly blocked | Descriptions, not thresholds, fixed both errors (moving a threshold from 0.68 to 0.70 for one sample would be overfitting). Caveat: 53 samples, tuned on the same set, so a sanity check rather than a benchmark. Jev is decisive (clear cases at 0.97 to 1.00), so most clear-cut "flag" samples are auto-blocked; acceptable, but apps wanting a human on every removal should raise `block_above` to 1.0 |
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
- **composer**: lint | lint:test | analyse | test | test:coverage | test:accuracy | check
