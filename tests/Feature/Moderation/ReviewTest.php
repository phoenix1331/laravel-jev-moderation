<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Phoenix1331\JevModeration\Database\Factories\ModerationDecisionFactory;
use Phoenix1331\JevModeration\Enums\ModerationSource;
use Phoenix1331\JevModeration\Enums\ModerationStatus;
use Phoenix1331\JevModeration\Events\ContentReported;
use Phoenix1331\JevModeration\Events\ReviewResolved;
use Phoenix1331\JevModeration\Jobs\ModerateContent;
use Phoenix1331\JevModeration\Models\ModerationDecision;
use Phoenix1331\JevModeration\Moderation\Checker;
use Phoenix1331\JevModeration\Policies\PolicyRegistry;
use Phoenix1331\JevModeration\Tests\Fixtures\Models\ChatMessage;
use Phoenix1331\JevModeration\Tests\Fixtures\Models\ForumPost;
use Phoenix1331\JevModeration\Tests\Fixtures\Models\User;

function reviewedPost(string $answer = 'fine', float $probability = 1.0): ForumPost
{
    moderationFake()->classifyAs($answer, 1.0, [$answer => $probability]);

    return ForumPost::query()->create(['title' => 'Re: Seiko 5', 'body' => 'You clearly have no idea.']);
}

/**
 * @return list<string>
 */
function trail(ForumPost $post): array
{
    return $post->moderationDecisions()->get()
        ->map(fn (ModerationDecision $decision): string => "{$decision->source->value}:{$decision->status->value}")
        ->all();
}

describe('reports', function (): void {
    it('flags the content with the reporter and reason, keeping Jev\'s decision', function (): void {
        Event::fake([ContentReported::class]);
        $post = reviewedPost();
        $reporter = User::query()->create(['name' => 'Reporter']);

        $report = $post->flagForReview('reported by user: rude', $reporter);

        expect(trail($post))->toBe(['jev:pending', 'jev:passed', 'report:flagged'])
            ->and($report->status)->toBe(ModerationStatus::Flagged)
            ->and($report->source)->toBe(ModerationSource::Report)
            ->and($report->note)->toBe('reported by user: rude')
            ->and($report->reviewedBy?->is($reporter))->toBeTrue()
            ->and($report->content_hash)->toBe($post->toModerationSubject()->hash)
            ->and($report->policy_version)->toBe(app(PolicyRegistry::class)->get('community')->version)
            ->and($report->category)->toBeNull()
            ->and($post->isFlagged())->toBeTrue();
        Event::assertDispatched(ContentReported::class, fn (ContentReported $e): bool => $e->model->is($post) && $e->decision->is($report));
    });

    it('accepts anonymous reports', function (): void {
        $report = reviewedPost()->flagForReview('anonymous report');

        expect($report->reviewed_by_type)->toBeNull()
            ->and($report->reviewed_by_id)->toBeNull();
    });

    it('records every report', function (): void {
        $post = reviewedPost();

        $post->flagForReview('first');
        $post->flagForReview('second');

        expect(trail($post))->toBe(['jev:pending', 'jev:passed', 'report:flagged', 'report:flagged']);
    });

    it('never brings removed content back into view', function (ModerationStatus $removed): void {
        $post = reviewedPost();
        ModerationDecisionFactory::new()->forModel($post)->state(['status' => $removed])->create();

        $report = $post->flagForReview('still bad');

        expect($report->status)->toBe($removed)
            ->and($report->source)->toBe(ModerationSource::Report)
            ->and($post->isVisible())->toBeFalse()
            ->and(ForumPost::query()->find($post->id))->toBeNull();
    })->with([ModerationStatus::Blocked, ModerationStatus::Rejected]);

    it('re-opens approved content', function (): void {
        $post = reviewedPost();
        $post->approve();

        $post->flagForReview('reported again');

        expect($post->moderationStatus())->toBe(ModerationStatus::Flagged);
    });

    it('takes precedence over a check still in the queue', function (): void {
        Queue::fake();
        $post = ForumPost::query()->create(['title' => 'Pending', 'body' => 'Still waiting for Jev']);
        $pending = $post->latestModeration()->first();

        $post->flagForReview('reported before the check ran');
        (new ModerateContent($pending->id))->withFakeQueueInteractions()->handle(app(Checker::class));

        moderationFake()->assertNothingClassified();
        expect($post->moderationStatus())->toBe(ModerationStatus::Flagged);
    });
});

describe('reviews', function (): void {
    it('approves blocked content, keeping Jev\'s decision', function (): void {
        Event::fake([ReviewResolved::class]);
        $post = reviewedPost('spam');
        $moderator = User::query()->create(['name' => 'Moderator']);

        $decision = $post->approve($moderator, 'false positive');

        expect(trail($post))->toBe(['jev:pending', 'jev:blocked', 'human:approved'])
            ->and($decision->reviewedBy?->is($moderator))->toBeTrue()
            ->and($decision->note)->toBe('false positive')
            ->and($post->isBlocked())->toBeFalse()
            ->and(ForumPost::query()->find($post->id)?->is($post))->toBeTrue()
            ->and(ModerationDecision::query()->where('status', 'blocked')->count())->toBe(1);
        Event::assertDispatched(ReviewResolved::class, fn (ReviewResolved $e): bool => $e->model->is($post) && $e->decision->status === ModerationStatus::Approved);
    });

    it('rejects flagged content', function (): void {
        Event::fake([ReviewResolved::class]);
        $post = reviewedPost('harassment', 0.6);
        $moderator = User::query()->create(['name' => 'Moderator']);

        $decision = $post->reject($moderator, 'confirmed harassment');

        expect(trail($post))->toBe(['jev:pending', 'jev:flagged', 'human:rejected'])
            ->and($decision->status)->toBe(ModerationStatus::Rejected)
            ->and($decision->note)->toBe('confirmed harassment')
            ->and($post->isBlocked())->toBeTrue()
            ->and(ForumPost::query()->find($post->id))->toBeNull();
        Event::assertDispatched(ReviewResolved::class, fn (ReviewResolved $e): bool => $e->decision->is($decision));
    });

    it('rejects without a category as before', function (): void {
        $decision = reviewedPost('harassment', 0.6)->reject();

        expect($decision->category)->toBeNull()
            ->and($decision->confidence)->toBeNull();
    });

    it('works on hidden content found through withoutModeration()', function (): void {
        $id = reviewedPost('spam')->id;

        ForumPost::withoutModeration()->findOrFail($id)->approve();

        expect(ForumPost::query()->find($id))->not->toBeNull();
    });

    it('records reviews while automatic moderation is off', function (): void {
        $post = reviewedPost();
        config(['jev-moderation.enabled' => false]);

        $post->reject(note: 'manual removal');

        expect($post->moderationStatus())->toBe(ModerationStatus::Rejected);
    });

    it('keeps an approval through saves that do not change the content', function (): void {
        $post = reviewedPost('spam');
        $post->approve();

        $post->update(['views' => 10]);
        $post->update(['body' => 'You clearly have no idea.']);

        expect($post->moderationStatus())->toBe(ModerationStatus::Approved);
    });
});

describe('reclassify', function (): void {
    it('rejects under the corrected category, keeping Jev\'s decision', function (): void {
        $post = reviewedPost('harassment', 0.6);
        $jev = $post->moderationDecisions()->where('status', ModerationStatus::Flagged)->firstOrFail();
        $moderator = User::query()->create(['name' => 'Moderator']);

        $decision = $post->reject($moderator, 'wrong label', 'spam');

        expect(trail($post))->toBe(['jev:pending', 'jev:flagged', 'human:rejected'])
            ->and($decision->status)->toBe(ModerationStatus::Rejected)
            ->and($decision->source)->toBe(ModerationSource::Human)
            ->and($decision->category)->toBe('spam')
            ->and($decision->confidence)->toBeNull()
            ->and($decision->reviewedBy?->is($moderator))->toBeTrue()
            ->and($decision->note)->toBe('wrong label')
            ->and($jev->fresh()?->toArray())->toBe($jev->toArray())
            ->and($jev->category)->toBe('harassment');
    });

    it('carries the category on ReviewResolved', function (): void {
        Event::fake([ReviewResolved::class]);
        $post = reviewedPost('harassment', 0.6);

        $post->reject(category: 'spam');

        Event::assertDispatched(ReviewResolved::class, fn (ReviewResolved $e): bool => $e->decision->category === 'spam');
    });

    it('throws and writes nothing for a category the policy cannot give', function (string $category): void {
        Event::fake([ReviewResolved::class]);
        $post = reviewedPost('harassment', 0.6);
        $before = trail($post);

        expect(fn () => $post->reject(category: $category))->toThrow(
            InvalidArgumentException::class,
            "Category [{$category}] is not a harmful choice of moderation policy [community]. Allowed: spam, harassment, hate, personal_data, off_topic.",
        );
        expect(trail($post))->toBe($before);
        Event::assertNotDispatched(ReviewResolved::class);
    })->with([
        'unknown' => 'typo',
        'the safe choice' => 'fine',
        'a chat category' => 'sexual',
    ]);

    it('checks the category against the record\'s own policy', function (): void {
        moderationFake()->classifyAs('fine', 1.0, ['fine' => 1.0]);
        $message = ChatMessage::query()->create(['room' => 'general', 'message' => 'hello']);

        expect($message->reject(category: 'sexual')->category)->toBe('sexual')
            ->and(fn () => $message->reject(category: 'off_topic'))->toThrow(InvalidArgumentException::class, 'policy [chat]');
    });

    it('reclassifies while automatic moderation is off', function (): void {
        $post = reviewedPost();
        config(['jev-moderation.enabled' => false]);

        $decision = $post->reject(note: 'manual removal', category: 'hate');

        expect($decision->category)->toBe('hate')
            ->and($post->moderationStatus())->toBe(ModerationStatus::Rejected);
    });
});

describe('moderateNow', function (): void {
    it('checks again even when the content has not changed', function (): void {
        $post = reviewedPost();
        moderationFake()->classifyAs('spam', 1.0, ['spam' => 1.0]);

        $pending = $post->moderateNow();

        expect($pending?->status)->toBe(ModerationStatus::Pending)
            ->and(trail($post))->toBe(['jev:pending', 'jev:passed', 'jev:pending', 'jev:blocked']);
        moderationFake()->assertClassifiedTimes(2);
    });

    it('queues the check like a save', function (): void {
        Queue::fake();
        $post = ForumPost::query()->create(['title' => 'Queued', 'body' => 'Body']);

        $pending = $post->moderateNow();

        Queue::assertPushed(ModerateContent::class, fn (ModerateContent $job): bool => $job->decisionId === $pending?->id);
        Queue::assertPushed(ModerateContent::class, 2);
    });

    it('does nothing while disabled or with nothing to read', function (): void {
        $blank = new ForumPost(['title' => ' ', 'body' => '']);
        $blank->saveQuietly();

        expect($blank->moderateNow())->toBeNull();

        config(['jev-moderation.enabled' => false]);

        expect(reviewedPost()->moderateNow())->toBeNull();
    });
});
