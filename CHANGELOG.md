# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `reject($moderator, $note, category: 'harassment')` reclassifies content: it is removed and the moderator's category is recorded on their decision. The category must be one of the model's policy choices other than its safe choice, else `InvalidArgumentException` and nothing is written
- `ModerationDecisionFactory::rejected()` takes an optional category

## [0.1.0] - 2026-10-23

### Added

- `moderation_decisions` migration, published with the `jev-moderation-migrations` tag: polymorphic and append-only, with the answering Jev version, category, confidence, content hash and reviewer, portable across SQLite, PostgreSQL, MySQL and MariaDB
- `ModerationStatus` (`pending`, `failed`, `passed`, `flagged`, `blocked`, `approved`, `rejected`) and `ModerationSource` enums
- `Moderatable` trait and `#[Moderate(fields: [...], policy: ...)]` attribute: creating a model, or changing its moderated content, records a pending decision, from any save path (requests, jobs, Livewire, seeders); unchanged content and updates to other columns are skipped
- Queued `ModerateContent` job, dispatched after the saving transaction commits: classifies the pending decision and appends a `passed`, `flagged` or `blocked` result with the deciding category and its probability, the answering Jev version, and Jev's full answer in `meta`
- Retries for rate limits, overload and timeouts with exponential backoff or Jev's `retry-after`; permanent failures fail at once; a check that gives up records a `failed` decision
- Pre- and post-moderation (`mode`, `JEV_MODERATION_MODE`, default `post`) and fail open or closed for failed checks (`fail`, `JEV_MODERATION_FAIL`, default `open`), applied by a global scope; models never checked stay visible
- `flagForReview($reason, $reporter)` for user reports, `approve($moderator, $note)` and `reject($moderator, $note)` for moderators, and `moderateNow()` to check again; each adds a decision and never changes Jev's. Reports on blocked or rejected content are recorded without bringing it back into view
- `ContentReported` and `ReviewResolved` events
- `jev-moderation:install`: publishes config and the migration, asks for the mode, default policy and whether to migrate, and prints the `.env` keys; `--mode`, `--policy`, `--migrate` and `--force` options, and defaults (no migration) under `--no-interaction`
- `jev-moderation:ping`: shows the driver settings (never the key), lists the models the key can use, classifies one harmless sample and shows the outcome and answering Jev version, and warns when `JEV_MODEL` is an alias; exits 1 on any failure with the request id
- `jev-moderation:rescan {model}`: re-checks content, e.g. after a threshold change, with `--status`, `--since`, `--chunk` and `--dry-run`; skips content a moderator approved or rejected unless `--status` names it, includes content never checked, and asks before an interactive run because each check is billed
- `jev-moderation:stats`: decisions per status, automatic flags and blocks per category, and how often moderators upheld them, over `--days` (default 7), optionally for one `--model`
- `jev-moderation:add-column {table}` generates a migration adding an indexed `moderation_status` column, backfilled from each row's latest decision; when present it is kept in sync on every decision and the visibility scopes read it instead of joining decisions
- `withoutModeration()`, `onlyFlagged()`, `onlyBlocked()` and `whereModerationStatus()` query scopes, and `moderationStatus()`, `isFlagged()`, `isBlocked()` and `isVisible()` on the model
- `queue`, `connection`, `sync`, `tries` and `backoff` config options (`JEV_MODERATION_QUEUE`, `JEV_MODERATION_QUEUE_CONNECTION`, `JEV_MODERATION_SYNC`, `JEV_MODERATION_TRIES`, `JEV_MODERATION_BACKOFF`); `sync` classifies inline without retries
- `ContentPending`, `ContentPassed`, `ContentFlagged`, `ContentBlocked` and `ModerationFailed` events, each with the model and the decision, serialisable for queued listeners
- Overridable `moderatableContent()`, `moderationContext()`, `moderationPolicy()` and `moderationAuthor()` (defaults to a `user` relation), plus `moderationDecisions` and `latestModeration` relations
- `store_content` config option (`JEV_MODERATION_STORE_CONTENT`, default off): decisions keep only a sha256 hash of the content unless turned on
- `JevModeration` facade (auto-registered alias): `classify()` checks content against a policy without recording anything and returns a `CheckResult`; `fake()` swaps in the `FakeDriver` for every check; `policy()`, `policies()` and `extend()` for custom drivers
- `ModerationDriver` contract with `classify()` and `models()`, `ModerationRequest` and `Classification` data objects, and `DriverException` with a `retryable` flag; custom drivers register through `JevModeration::extend()`
- Moderation policies in config, with `community`, `chat` and `strict` presets: a question, described choices with the safe choice last, `review_above` and optional `block_above` per category, and an optional `uncertain_below` confidence floor; `policy` config option (`JEV_MODERATION_POLICY`, default `community`)
- Preset wording and thresholds checked against live Jev with a light accuracy suite (`composer test:accuracy`, results in `docs/accuracy.md`): banter in `chat` and blunt corrections in `strict` now read as fine, and `community` blocks hate at 0.90
- Threshold resolution that checks every risky category's probability, not just Jev's top choice: block beats flag, a probability equal to a threshold counts as above it, and low-confidence answers are flagged; returns a `Verdict` with a `VerdictReason`
- Policy versions: a sha256 of everything Jev sees and every threshold, for tracing decisions back to the policy that made them
- `JevDriver` (driver name `jev`): one Choice question per check through TypeSafe's `POST /v1/systemone`, the answering Jev version and request id on every classification, retryable failures (`429`, `529`, `5xx`, timeouts, with `retry-after`) separated from permanent ones, and trimming to the context budget, context first
- `drivers.jev` config (`JEV_API_KEY`, `JEV_MODEL`, `JEV_BASE_URL`, `JEV_TIMEOUT`) and `max_context_tokens` (`JEV_MODERATION_MAX_CONTEXT_TOKENS`, default 24000)
- `FakeDriver` (driver name `fake`) for tests and demos: passes everything by default, `classifyAs()`, `classifyUsing()`, `failWith()`, one-off `push()` and `pushFailure()` answers, and `assertClassified()`, `assertNotClassified()`, `assertClassifiedTimes()`, `assertNothingClassified()`
- `driver` config option (`JEV_MODERATION_DRIVER`, default `jev`)
- Decisions are append-only: saving, deleting, or mass updating or deleting an existing decision throws `DecisionsAreAppendOnly`
- `ModerationDecisionFactory` with `pending`, `failed`, `passed`, `flagged`, `blocked`, `reported`, `approved` and `rejected` states, for seeding demos and tests without Faker

[Unreleased]: https://github.com/phoenix1331/laravel-jev-moderation/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/phoenix1331/laravel-jev-moderation/releases/tag/v0.1.0
