# Jev Moderation for Laravel

**Confidence-aware content moderation for Laravel.**

> **The Jev Moderation family:** **Jev Moderation** classifies and records (this package) · Jev Moderation for Filament, a review queue and calibration widget, is coming next

Add a trait to any Eloquent model. Every time it is saved, its content is classified by [Jev](https://typesafe.ai), TypeSafe's typed decision model, and the probability of each risky category decides whether it passes, waits for a human, or is blocked.

[![Tests](https://github.com/phoenix1331/laravel-jev-moderation/actions/workflows/tests.yml/badge.svg)](https://github.com/phoenix1331/laravel-jev-moderation/actions/workflows/tests.yml)
[![Static analysis](https://github.com/phoenix1331/laravel-jev-moderation/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/phoenix1331/laravel-jev-moderation/actions/workflows/static-analysis.yml)
[![Release](https://img.shields.io/github/v/release/phoenix1331/laravel-jev-moderation.svg)](https://packagist.org/packages/phoenix1331/laravel-jev-moderation)
[![Licence](https://img.shields.io/badge/licence-MIT-blue.svg)](https://github.com/phoenix1331/laravel-jev-moderation/blob/main/LICENSE)

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/phoenix1331/laravel-jev-moderation/main/art/demo-dark.png">
  <img alt="A demo app listing forum posts and chat messages, each with a passed, flagged, blocked, approved or rejected status, the deciding category and probability, and its history of decisions." src="https://raw.githubusercontent.com/phoenix1331/laravel-jev-moderation/main/art/demo-light.png">
</picture>

Full documentation: **[phoenix1331.github.io/laravel-jev-moderation](https://phoenix1331.github.io/laravel-jev-moderation)**

## Why

Most moderation APIs hand back a label. Jev answers a typed question with a probability for every choice, so a post that is 55% fine and 44% harassment shows up as borderline instead of quietly "fine". This package checks each risky category's probability against its own thresholds: a low bar to send something to a person, a high bar to remove it without one.

- **Model events, not middleware.** Content saved from jobs, APIs, Livewire and seeders is moderated too, not just HTTP requests.
- **An append-only history.** Every check, report and review adds a row, so Jev's decision is never overwritten by a moderator's. That gives you an audit trail and the data to compare the two.
- **Headless.** No views and no routes: use the model API, query scopes and events in your own app, or the Filament package.
- **Swappable.** Jev is behind a driver contract with a fake for tests, so your suite never calls the API.

## Installation

```bash
composer require phoenix1331/laravel-jev-moderation
php artisan jev-moderation:install
```

The install command publishes the config and the `moderation_decisions` migration, asks for the mode and default policy, and prints the `.env` keys to add. Get a key at [typesafe.ai](https://typesafe.ai), then check everything works end to end:

```bash
php artisan jev-moderation:ping
```

![The ping command listing models, classifying a sample as passed by jev-1.14.0, and warning that jev-latest is an alias](https://raw.githubusercontent.com/phoenix1331/laravel-jev-moderation/main/art/ping.gif)

## Usage

Mark a model with the fields to send and a policy:

```php
use Phoenix1331\JevModeration\Attributes\Moderate;
use Phoenix1331\JevModeration\Concerns\Moderatable;

#[Moderate(fields: ['title', 'body'], policy: 'community')]
class ForumPost extends Model
{
    use Moderatable;
}
```

Saving it records a pending decision and queues the check once the transaction commits, so run a queue worker. Set `JEV_MODERATION_SYNC=true` to check inline instead, for example before broadcasting a chat message. A save that leaves the moderated fields unchanged is not sent again.

```php
$post->moderationStatus();      // ModerationStatus, or null if never checked
$post->isFlagged();             // waiting for a human
$post->isBlocked();             // blocked, or rejected by a moderator

ForumPost::query()->get();               // visible content only
ForumPost::onlyFlagged()->get();         // the review queue
ForumPost::withoutModeration()->get();   // everything

$post->flagForReview('reported by user', $reporter);
$post->approve($moderator, 'false positive');
$post->reject($moderator, 'confirmed spam');
$post->reject($moderator, 'harassment, not spam', category: 'harassment');
```

Passing `category:` reclassifies: the content is removed and the moderator's category is recorded on their decision, checked against the model's policy so a typo cannot corrupt calibration data.

Check something without recording it:

```php
use Phoenix1331\JevModeration\Facades\JevModeration;

if (JevModeration::classify($message, 'chat')->blocked()) {
    return;
}
```

Listen for `ContentPassed`, `ContentFlagged`, `ContentBlocked`, `ContentReported`, `ReviewResolved` and `ModerationFailed`. Each carries the model and the decision, so sanctions such as muting an author live in your listeners.

> [!WARNING]
> Moderation runs on Eloquent's `created` and `updated` events. `saveQuietly()`, `Model::withoutEvents()`, raw inserts and seeders using `WithoutModelEvents` skip it, and Laravel's default `DatabaseSeeder` uses that trait. Remove it from seeders whose content should be checked, or run `php artisan jev-moderation:rescan` afterwards.

## Policies and thresholds

A policy is one question, a set of choices with descriptions, and a rule per risky category. Three presets ship, `community`, `chat` and `strict`, and you can edit them in the published config:

```php
'actions' => [
    'spam' => ['review_above' => 0.50, 'block_above' => 0.90],
    'harassment' => ['review_above' => 0.40, 'block_above' => 0.95],
    'personal_data' => ['review_above' => 0.40],
],
'uncertain_below' => 0.30,
```

Every category with a rule is checked, not just Jev's top choice, and the strongest action wins. A probability equal to a threshold counts as reaching it. `uncertain_below` flags answers where Jev was undecided.

The presets are checked against live Jev with a small labelled set ([results](https://github.com/phoenix1331/laravel-jev-moderation/blob/main/docs/accuracy.md)). Jev is decisive on clear-cut content, so most clear cases reach `block_above` and are removed without a person. Set `block_above` to `1.0`, or leave it out, for any category where a human must confirm every removal.

## Modes

| Latest status | `post` (default) | `pre` |
|---|---|---|
| never checked | visible | visible |
| pending, flagged | visible | hidden |
| passed, approved | visible | visible |
| blocked, rejected | hidden | hidden |
| failed (retries used up) | visible with `fail: open` (default), hidden with `fail: closed` | same |

## Commands

| Command | Does |
|---|---|
| `jev-moderation:install` | Publish config and migration, choose mode and policy, print the `.env` keys |
| `jev-moderation:ping` | Check the key and driver with one sample classification |
| `jev-moderation:stats` | Decisions per status and category, and how often moderators upheld Jev, overturned it, or caught what it missed |
| `jev-moderation:rescan {model}` | Re-check content after a threshold change; skips moderator decisions unless asked |
| `jev-moderation:add-column {table}` | Add an indexed, backfilled `moderation_status` column for fast queries on busy tables |

![The rescan command counting blocked chat messages on a dry run, then re-checking them after confirmation](https://raw.githubusercontent.com/phoenix1331/laravel-jev-moderation/main/art/rescan.gif)

More recordings, for `install` and `stats`, are on the [documentation site](https://phoenix1331.github.io/laravel-jev-moderation/#commands).

## Configuration

The main `.env` keys:

| Key | Default | |
|---|---|---|
| `JEV_API_KEY` | none | Your TypeSafe key |
| `JEV_MODEL` | `jev-latest` | An alias that moves when TypeSafe releases; pin a version such as `jev-1.14.0` once your thresholds are tuned |
| `JEV_MODERATION_MODE` | `post` | `post` or `pre` |
| `JEV_MODERATION_FAIL` | `open` | Visibility of content whose check gave up |
| `JEV_MODERATION_POLICY` | `community` | Used when `#[Moderate]` names no policy |
| `JEV_MODERATION_DRIVER` | `jev` | `fake` answers locally |
| `JEV_MODERATION_SYNC` | `false` | Check inline instead of on the queue |
| `JEV_MODERATION_STORE_CONTENT` | `false` | Keep a copy of the content on each decision |

Every option, with queue, retry and context settings, is in [`config/jev-moderation.php`](https://github.com/phoenix1331/laravel-jev-moderation/blob/main/config/jev-moderation.php) and on the [documentation site](https://phoenix1331.github.io/laravel-jev-moderation/#configuration).

## Testing your app

```php
use Phoenix1331\JevModeration\Facades\JevModeration;

JevModeration::fake()->classifyAs('spam', 1.0, ['spam' => 1.0]);

$post = ForumPost::query()->create(['title' => 'Cheap watches', 'body' => 'Buy now']);

expect($post->isBlocked())->toBeTrue();
JevModeration::fake()->assertClassifiedTimes(1);
```

## Data and privacy

The `#[Moderate]` fields and any context are sent to TypeSafe on each check; nothing else about the model, and nothing at all with the fake driver. Decisions keep a sha256 hash of the content, not the content itself, unless `store_content` is on. There is no built-in erasure yet. [SECURITY.md](https://github.com/phoenix1331/laravel-jev-moderation/blob/main/SECURITY.md) has the details, including how to delete a model's decisions.

## Compatibility

| | Supported |
|---|---|
| PHP | 8.3, 8.4, 8.5 |
| Laravel | 12, 13 |
| Databases | SQLite, PostgreSQL, MySQL, MariaDB |
| Jev | Tested with `jev-1.14.0` (what `jev-latest` points to as of October 2026) |

## Contributing

```bash
composer install
composer check   # Pint, Larastan at max level, Pest with a 90% coverage gate
```

See [CONTRIBUTING.md](https://github.com/phoenix1331/laravel-jev-moderation/blob/main/CONTRIBUTING.md), [CHANGELOG.md](https://github.com/phoenix1331/laravel-jev-moderation/blob/main/CHANGELOG.md) and [SECURITY.md](https://github.com/phoenix1331/laravel-jev-moderation/blob/main/SECURITY.md).

## Licence

MIT. See [LICENSE](https://github.com/phoenix1331/laravel-jev-moderation/blob/main/LICENSE).
