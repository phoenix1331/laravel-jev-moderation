# Security Policy

## Supported Versions

| Version | Supported |
|---------|-----------|
| 0.x     | Latest minor only |

While Jev Moderation for Laravel is pre-1.0, fixes are released on the latest minor version only.

## Reporting a Vulnerability

Please do not open a public GitHub issue for security vulnerabilities.

Report security issues by emailing **darren@phoenix1331.co.uk**. Include:

- A description of the vulnerability and its potential impact
- Steps to reproduce or a proof-of-concept
- The package version affected, plus your Laravel and PHP versions

You will receive an acknowledgement within 48 hours. If the issue is confirmed, a fix will be released as soon as possible and you will be credited in the changelog unless you prefer to remain anonymous.

## Scope

Jev Moderation for Laravel sends user-generated content to TypeSafe's Jev API for classification and records a decision for every check. This section sets out what goes where, what is kept, and the defaults that matter.

### What is sent to TypeSafe

| Sent | When |
|---|---|
| The `#[Moderate]` fields of the model (or whatever `moderatableContent()` returns), as field name to value | A moderated model is created, or one of its moderated fields changes; `moderateNow()`; `jev-moderation:rescan`; `JevModeration::classify()` |
| `moderationContext()`, if the model overrides it (e.g. the parent thread or previous chat messages) | With the content above |
| The policy's question and choice descriptions, and the model name (`JEV_MODEL`) | Every check |
| One fixed sample sentence | `jev-moderation:ping` |

Requests go through Laravel's HTTP client to `JEV_BASE_URL` (default `https://api.typesafe.ai/v1`), authenticated with `JEV_API_KEY` in the `Authorization` header. Nothing else about the model is sent: not its other columns, not its author, not the app's users. With the `fake` driver nothing leaves your application.

TypeSafe states that Jev is not trained on customer requests or responses; its data processing agreement and zero data retention terms are at [docs.typesafe.ai/legal](https://docs.typesafe.ai/legal). Check them against your own obligations before sending personal data.

### What is stored

Each check, report and review adds a row to `moderation_decisions`:

| Column | Holds |
|---|---|
| `moderatable_type`, `moderatable_id` | Which model the decision is about |
| `status`, `source`, `policy`, `policy_version`, `model`, `category`, `confidence` | The outcome, what produced it, the policy and Jev version, and the deciding category's probability |
| `content_hash` | sha256 of the moderated content |
| `content` | A copy of the content **only when `store_content` is on** (`JEV_MODERATION_STORE_CONTENT`, off by default) |
| `reviewed_by_type`, `reviewed_by_id` | The moderator on a review, or the reporter on a user report |
| `note` | Free text from `flagForReview()`, `approve()` and `reject()` |
| `meta` | Jev's choice, confidence and probabilities, latency, input tokens, request id; on failure, the error reason |

The package never puts content in `meta`. Failure reasons come from Jev's error messages (validation errors keep only the field path and message, never the input Jev echoes back), or, for an unexpected exception thrown by your own code during a check, that exception's message. Tests check every column, the queued job, the events and Jev failure reasons for content, and fail if any of them carry it with `store_content` off.

### Potential concerns

**Content goes to a third party** - Choose `#[Moderate]` fields deliberately: everything listed is sent on every change. Context is sent too, so a `moderationContext()` that includes other people's messages sends their words as well; keep it short.

**Notes and reporters are personal data** - `note` is whatever your moderators and reporting users type, and `reviewed_by` identifies them. Treat both as personal data in your retention policy.

**The content hash is not anonymous** - `content_hash` is an unsalted sha256, so anyone with the table can confirm whether a decision matches a piece of text they already have. It cannot be reversed, but short or predictable content can be guessed.

**No built-in erasure** - Decisions are append-only: updating or deleting one through Eloquent throws `DecisionsAreAppendOnly`, and deleting a moderated model leaves its decisions in place. To honour an erasure request, delete the rows directly, which bypasses the guard on purpose:

```php
DB::table('moderation_decisions')
    ->where('moderatable_type', $post->getMorphClass())
    ->where('moderatable_id', $post->getKey())
    ->delete();
```

**Content saved without model events is never checked** - Moderation runs on Eloquent's `created` and `updated` events. `saveQuietly()`, `Model::withoutEvents()`, seeders using `WithoutModelEvents` (Laravel's default `DatabaseSeeder` does), raw `DB::table()` inserts and imports bypass it. Content with no decision is visible in both modes, so in `pre` mode such content is shown unchecked. Run `jev-moderation:rescan` after bulk imports.

**Fail open is the default** - When a check gives up (rate limits, outages, a bad key), `fail: open` keeps the content visible so an outage never hides anything. Set `JEV_MODERATION_FAIL=closed` where unchecked content must never be shown.

**Automatic blocks have no human in the loop** - Content at or above a category's `block_above` is hidden without review. Jev is decisive on clear-cut content (probabilities of 0.97 to 1.00 are common), so most clear cases are blocked outright. Set `block_above` to `1.0`, or remove it, for categories where a person must confirm every removal.

**API key** - Read from `JEV_API_KEY` in `config/jev-moderation.php` only. It is never logged, stored, included in exceptions or events, or printed (`jev-moderation:ping` shows "set" or "missing").

**Events and listeners** - Moderation events carry the model and the decision. Queued listeners serialise them as identifiers, not attributes. What your listeners do with the content is up to you.

**No logging** - The package writes nothing to your logs; an architecture test forbids it.

**Dependency chain** - Runtime dependencies are `illuminate/*` components only. Keep `composer.lock` up to date and monitor it with `composer audit` or `osv-scanner`.

## Disclosure Policy

Once a fix is released, the vulnerability will be documented in `CHANGELOG.md` under the relevant version. We follow coordinated disclosure and will not publish details until a fix is available.
