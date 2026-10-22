<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Phoenix1331\JevModeration\Data\ModerationRequest;
use Phoenix1331\JevModeration\Database\Factories\ModerationDecisionFactory;
use Phoenix1331\JevModeration\Enums\ModerationSource;
use Phoenix1331\JevModeration\Enums\ModerationStatus;
use Phoenix1331\JevModeration\Events\ContentBlocked;
use Phoenix1331\JevModeration\Events\ContentFlagged;
use Phoenix1331\JevModeration\Events\ContentPassed;
use Phoenix1331\JevModeration\Events\ContentPending;
use Phoenix1331\JevModeration\Events\ModerationFailed;
use Phoenix1331\JevModeration\Exceptions\DriverException;
use Phoenix1331\JevModeration\Jobs\ModerateContent;
use Phoenix1331\JevModeration\Models\ModerationDecision;
use Phoenix1331\JevModeration\Moderation\Checker;
use Phoenix1331\JevModeration\Policies\PolicyRegistry;
use Phoenix1331\JevModeration\Tests\Fixtures\Models\ForumPost;
use Phoenix1331\JevModeration\Tests\Fixtures\Models\Post;

const MODERATION_EVENTS = [ContentPending::class, ContentPassed::class, ContentFlagged::class, ContentBlocked::class, ModerationFailed::class];

/**
 * Answer every check like a recorded Jev response.
 */
function answerLike(string $fixture): void
{
    $answer = jevFixture($fixture)['answers']['moderation'];

    moderationFake()->classifyAs($answer['choice'], (float) $answer['confidence'], array_map(floatval(...), $answer['probabilities']));
}

function savePost(string $body = 'Is a Seiko 5 still the go-to?'): ForumPost
{
    return ForumPost::query()->create(['title' => 'Best budget watch?', 'body' => $body]);
}

/**
 * @return list<ModerationStatus>
 */
function history(ForumPost $post): array
{
    return $post->moderationDecisions()->pluck('status')->all();
}

function job(int $decisionId, int $attempts = 1): ModerateContent
{
    $job = (new ModerateContent($decisionId))->withFakeQueueInteractions();
    $job->job->attempts = $attempts;

    return $job;
}

describe('results', function (): void {
    it('appends the result to the pending decision', function (string $fixture, ModerationStatus $status, string $category, float $probability): void {
        answerLike($fixture);

        $post = savePost();
        $result = $post->latestModeration()->first();

        expect(history($post))->toBe([ModerationStatus::Pending, $status])
            ->and($result?->status)->toBe($status)
            ->and($result?->source)->toBe(ModerationSource::Jev)
            ->and($result?->category)->toBe($category)
            ->and($result?->confidence)->toBe($probability)
            ->and($result?->model)->toBe('fake')
            ->and($result?->policy)->toBe('community')
            ->and($result?->policy_version)->toBe(app(PolicyRegistry::class)->get('community')->version)
            ->and($result?->content_hash)->toBe($post->moderationDecisions()->first()?->content_hash);
    })->with([
        'passed' => ['systemone-fine', ModerationStatus::Passed, 'fine', 1.0],
        'flagged' => ['systemone-borderline', ModerationStatus::Flagged, 'harassment', 0.44],
        'blocked' => ['systemone-spam', ModerationStatus::Blocked, 'spam', 1.0],
    ]);

    it('keeps what Jev answered in meta, without the content', function (): void {
        answerLike('systemone-borderline');

        $meta = savePost()->latestModeration()->first()?->meta;

        // JSON storage turns 0.0 into 0, so compare loosely.
        expect($meta)->toEqual([
            'reason' => 'review_threshold',
            'choice' => 'fine',
            'jev_confidence' => 0.46,
            'probabilities' => ['spam' => 0.0, 'harassment' => 0.44, 'hate' => 0.0, 'personal_data' => 0.0, 'off_topic' => 0.01, 'fine' => 0.55],
            'driver' => 'fake',
            'latency_ms' => 0,
            'input_tokens' => null,
            'request_id' => null,
            'truncated' => false,
        ]);
    });

    it('asks the policy question about the model content', function (): void {
        savePost();

        moderationFake()->assertClassified(fn (ModerationRequest $request): bool => $request->policy === 'community'
            && $request->content === ['title' => 'Best budget watch?', 'body' => 'Is a Seiko 5 still the go-to?']
            && $request->safeChoice === 'fine'
            && $request->instructions === app(PolicyRegistry::class)->get('community')->question);
    });

    it('carries a stored copy of the content onto the result', function (): void {
        config(['jev-moderation.store_content' => true]);

        expect(savePost()->latestModeration()->first()?->content)->toBe('{"title":"Best budget watch?","body":"Is a Seiko 5 still the go-to?"}');
    });
});

describe('events', function (): void {
    it('fires pending, then the outcome, with the model and decision', function (string $fixture, string $outcome): void {
        Event::fake(MODERATION_EVENTS);
        answerLike($fixture);

        $post = savePost();

        Event::assertDispatched(ContentPending::class, fn (ContentPending $e): bool => $e->model->is($post) && $e->decision->status === ModerationStatus::Pending);
        Event::assertDispatched($outcome, fn (ContentPassed|ContentFlagged|ContentBlocked $e): bool => $e->model->is($post) && $e->decision->is($post->latestModeration()->first()));
        Event::assertNotDispatched(ModerationFailed::class);
    })->with([
        'passed' => ['systemone-fine', ContentPassed::class],
        'flagged' => ['systemone-borderline', ContentFlagged::class],
        'blocked' => ['systemone-spam', ContentBlocked::class],
    ]);

    it('serialises events for queued listeners', function (): void {
        answerLike('systemone-borderline');
        $post = savePost();
        $decision = $post->latestModeration()->first();
        expect($decision)->not->toBeNull();

        $event = unserialize(serialize(new ContentFlagged($post, $decision)));

        expect($event)->toBeInstanceOf(ContentFlagged::class)
            ->and($event->model->is($post))->toBeTrue()
            ->and($event->decision->is($decision))->toBeTrue();
    });
});

describe('transactions', function (): void {
    it('classifies nothing when the saving transaction rolls back', function (): void {
        try {
            DB::transaction(function (): void {
                savePost();

                throw new RuntimeException('rolled back');
            });
        } catch (RuntimeException) {
            // expected
        }

        expect(ModerationDecision::query()->count())->toBe(0);
        moderationFake()->assertNothingClassified();
    });

    it('waits for the commit before classifying', function (): void {
        DB::transaction(function (): void {
            savePost();

            moderationFake()->assertNothingClassified();
        });

        moderationFake()->assertClassifiedTimes(1);
    });
});

describe('queue', function (): void {
    it('queues the decision id on the configured connection and queue', function (): void {
        Queue::fake();
        config(['jev-moderation.queue' => 'moderation', 'jev-moderation.connection' => 'redis']);

        $pending = savePost()->latestModeration()->first();

        Queue::assertPushed(ModerateContent::class, fn (ModerateContent $job): bool => $job->decisionId === $pending?->id
            && $job->queue === 'moderation'
            && $job->connection === 'redis'
            && $job->afterCommit === true);
    });

    it('uses the default queue and connection when none is set', function (): void {
        Queue::fake();

        savePost();

        Queue::assertPushed(ModerateContent::class, fn (ModerateContent $job): bool => $job->queue === null && $job->connection === null);
    });

    it('classifies inline in sync mode', function (): void {
        Queue::fake();
        config(['jev-moderation.sync' => true]);
        answerLike('systemone-spam');

        $post = savePost();

        Queue::assertNothingPushed();
        expect(history($post))->toBe([ModerationStatus::Pending, ModerationStatus::Blocked]);
    });

    it('leaves the decision pending and reports the failure inline, without retrying', function (): void {
        Event::fake(MODERATION_EVENTS);
        config(['jev-moderation.sync' => true]);
        moderationFake()->failWith(DriverException::retryable('Jev returned HTTP 529: no details', 529));

        $post = savePost();

        expect(history($post))->toBe([ModerationStatus::Pending]);
        moderationFake()->assertClassifiedTimes(1);
        Event::assertDispatched(ModerationFailed::class, fn (ModerationFailed $e): bool => $e->model->is($post)
            && $e->reason === 'Jev returned HTTP 529: no details'
            && $e->retryable);
    });
});

describe('retries', function (): void {
    beforeEach(function (): void {
        Queue::fake();
        Event::fake(MODERATION_EVENTS);
        $this->pending = savePost()->latestModeration()->first();
    });

    it('releases a retryable failure after Jev\'s retry-after', function (): void {
        moderationFake()->failWith(DriverException::retryable('busy', 429, retryAfter: 7));

        $job = job($this->pending->id);
        $job->handle(app(Checker::class));

        $job->assertReleased(delay: 7);
        Event::assertNotDispatched(ModerationFailed::class);
    });

    it('backs off exponentially without retry-after', function (int $attempt, int $delay): void {
        moderationFake()->failWith();

        $job = job($this->pending->id, $attempt);
        $job->handle(app(Checker::class));

        $job->assertReleased(delay: $delay);
    })->with([[1, 10], [2, 20], [4, 80]]);

    it('gives up on the last attempt, leaving the decision pending', function (): void {
        moderationFake()->failWith();

        $job = job($this->pending->id, 5);
        $job->handle(app(Checker::class));

        $job->assertFailed();
        expect(ModerationDecision::query()->latest('id')->first()?->status)->toBe(ModerationStatus::Pending);
        Event::assertDispatched(ModerationFailed::class, fn (ModerationFailed $e): bool => $e->retryable && $e->decision->is($this->pending));
    });

    it('fails a permanent error at once', function (): void {
        moderationFake()->failWith(DriverException::permanent('Jev returned HTTP 401: bad key. Check JEV_API_KEY.', 401));

        $job = job($this->pending->id);
        $job->handle(app(Checker::class));

        $job->assertFailed();
        $job->assertNotReleased();
        Event::assertDispatched(ModerationFailed::class, fn (ModerationFailed $e): bool => ! $e->retryable && str_contains($e->reason, 'Check JEV_API_KEY'));
    });

    it('reads tries and backoff from config, ignoring nonsense', function (): void {
        config(['jev-moderation.tries' => 2, 'jev-moderation.backoff' => 3]);
        expect(job($this->pending->id)->tries())->toBe(2)
            ->and(job($this->pending->id)->backoff())->toBe([3, 6]);

        config(['jev-moderation.tries' => 'lots', 'jev-moderation.backoff' => -1]);
        expect(job($this->pending->id)->tries())->toBe(5)
            ->and(job($this->pending->id)->backoff())->toBe([10, 20, 40, 80, 160]);
    });

    it('reports unexpected failures once', function (): void {
        $job = new ModerateContent($this->pending->id);

        $job->failed(new RuntimeException('Model threw'));
        $job->failed(DriverException::permanent('already reported by handle()'));

        Event::assertDispatchedTimes(ModerationFailed::class, 1);
        Event::assertDispatched(ModerationFailed::class, fn (ModerationFailed $e): bool => $e->reason === 'Model threw' && ! $e->retryable);
    });

    it('has a reason even without an exception', function (): void {
        (new ModerateContent($this->pending->id))->failed(null);

        Event::assertDispatched(ModerationFailed::class, fn (ModerationFailed $e): bool => $e->reason === 'The moderation job failed.');
    });
});

describe('skipped checks', function (): void {
    it('does nothing for a decision that no longer exists', function (): void {
        Event::fake(MODERATION_EVENTS);

        job(999)->handle(app(Checker::class));
        (new ModerateContent(999))->failed(new RuntimeException('gone'));

        moderationFake()->assertNothingClassified();
        Event::assertNothingDispatched();
    });

    it('skips a pending decision a newer save has superseded', function (): void {
        Queue::fake();
        $post = savePost();
        $first = $post->latestModeration()->first();
        $post->update(['body' => 'Edited before the first check ran']);

        job($first->id)->handle(app(Checker::class));

        moderationFake()->assertNothingClassified();
    });

    it('skips when the content changed without a save event', function (): void {
        Queue::fake();
        $post = savePost();
        $post->body = 'Quietly edited';
        $post->saveQuietly();

        job($post->latestModeration()->first()->id)->handle(app(Checker::class));

        moderationFake()->assertNothingClassified();
    });

    it('skips a decision that is no longer pending', function (): void {
        $post = savePost();

        job($post->latestModeration()->first()->id)->handle(app(Checker::class));

        moderationFake()->assertClassifiedTimes(1);
    });

    it('skips decisions for deleted or unmoderatable models', function (): void {
        Event::fake(MODERATION_EVENTS);
        $missing = ModerationDecisionFactory::new()->pending()->state(['moderatable_type' => ForumPost::class, 'moderatable_id' => 404])->create();
        $plain = ModerationDecisionFactory::new()->pending()->forModel(Post::query()->create(['title' => 'x', 'body' => 'y']))->create();

        job($missing->id)->handle(app(Checker::class));
        job($plain->id)->handle(app(Checker::class));
        app(Checker::class)->failed($missing, 'gone', false);

        moderationFake()->assertNothingClassified();
        Event::assertNothingDispatched();
    });
});

describe('edits', function (): void {
    it('supersedes a flag when flagged content is edited', function (): void {
        answerLike('systemone-borderline');
        $post = savePost('Anyone still recommending the Seiko 5 has no idea what they are talking about.');

        answerLike('systemone-fine');
        $post->update(['body' => 'Sorry, that was rude. The Seiko 5 is a fine first watch.']);

        expect(history($post))->toBe([ModerationStatus::Pending, ModerationStatus::Flagged, ModerationStatus::Pending, ModerationStatus::Passed]);
    });

    it('re-checks approved content once it is edited', function (): void {
        Queue::fake();
        $post = savePost();
        ModerationDecisionFactory::new()->forModel($post)->approved()->state(['content_hash' => $post->latestModeration()->first()->content_hash])->create();

        $post->update(['body' => 'Now with a link to my shop']);

        expect(history($post))->toBe([ModerationStatus::Pending, ModerationStatus::Approved, ModerationStatus::Pending]);
        // One for the create, one for the edit.
        Queue::assertPushed(ModerateContent::class, 2);
    });
});
