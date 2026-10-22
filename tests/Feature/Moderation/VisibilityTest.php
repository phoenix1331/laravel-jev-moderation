<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Phoenix1331\JevModeration\Database\Factories\ModerationDecisionFactory;
use Phoenix1331\JevModeration\Enums\ModerationStatus;
use Phoenix1331\JevModeration\Events\ContentBlocked;
use Phoenix1331\JevModeration\Tests\Fixtures\Models\ForumPost;

/**
 * A post whose latest decision has this status; null means never checked.
 */
function postWithStatus(?ModerationStatus $status, string $title = 'A post'): ForumPost
{
    $post = new ForumPost(['title' => $title, 'body' => 'Body']);

    if ($status === null) {
        $post->saveQuietly();

        return $post;
    }

    Queue::fake();
    $post->save();

    if ($status !== ModerationStatus::Pending) {
        ModerationDecisionFactory::new()->forModel($post)->state(['status' => $status])->create();
    }

    return $post;
}

function visibleTitles(): array
{
    return ForumPost::query()->orderBy('id')->pluck('title')->all();
}

describe('visibility matrix', function (): void {
    it('shows or hides content by mode, fail setting and latest status', function (string $mode, string $fail, ?ModerationStatus $status, bool $visible): void {
        config(['jev-moderation.mode' => $mode, 'jev-moderation.fail' => $fail]);

        $post = postWithStatus($status);

        expect(ForumPost::query()->count())->toBe($visible ? 1 : 0)
            ->and(ForumPost::query()->find($post->id) !== null)->toBe($visible)
            ->and($post->isVisible())->toBe($visible);
    })->with([
        // post mode: visible straight away, hidden once blocked or rejected
        ['post', 'open', null, true],
        ['post', 'open', ModerationStatus::Pending, true],
        ['post', 'open', ModerationStatus::Failed, true],
        ['post', 'open', ModerationStatus::Passed, true],
        ['post', 'open', ModerationStatus::Flagged, true],
        ['post', 'open', ModerationStatus::Blocked, false],
        ['post', 'open', ModerationStatus::Approved, true],
        ['post', 'open', ModerationStatus::Rejected, false],
        ['post', 'closed', null, true],
        ['post', 'closed', ModerationStatus::Pending, true],
        ['post', 'closed', ModerationStatus::Failed, false],
        ['post', 'closed', ModerationStatus::Passed, true],
        ['post', 'closed', ModerationStatus::Flagged, true],
        ['post', 'closed', ModerationStatus::Blocked, false],
        ['post', 'closed', ModerationStatus::Approved, true],
        ['post', 'closed', ModerationStatus::Rejected, false],
        // pre mode: hidden until passed or approved
        ['pre', 'open', null, true],
        ['pre', 'open', ModerationStatus::Pending, false],
        ['pre', 'open', ModerationStatus::Failed, true],
        ['pre', 'open', ModerationStatus::Passed, true],
        ['pre', 'open', ModerationStatus::Flagged, false],
        ['pre', 'open', ModerationStatus::Blocked, false],
        ['pre', 'open', ModerationStatus::Approved, true],
        ['pre', 'open', ModerationStatus::Rejected, false],
        ['pre', 'closed', null, true],
        ['pre', 'closed', ModerationStatus::Pending, false],
        ['pre', 'closed', ModerationStatus::Failed, false],
        ['pre', 'closed', ModerationStatus::Passed, true],
        ['pre', 'closed', ModerationStatus::Flagged, false],
        ['pre', 'closed', ModerationStatus::Blocked, false],
        ['pre', 'closed', ModerationStatus::Approved, true],
        ['pre', 'closed', ModerationStatus::Rejected, false],
    ]);

    it('ships as post moderation that fails open', function (): void {
        $config = require __DIR__.'/../../../config/jev-moderation.php';

        expect($config['mode'])->toBe('post')
            ->and($config['fail'])->toBe('open');
    });
});

describe('latest decision wins', function (): void {
    it('shows flagged content once a moderator approves it', function (): void {
        config(['jev-moderation.mode' => 'pre']);
        $post = postWithStatus(ModerationStatus::Flagged);

        expect(visibleTitles())->toBe([]);

        ModerationDecisionFactory::new()->forModel($post)->approved()->create();

        expect(visibleTitles())->toBe(['A post']);
    });

    it('hides passed content once a moderator rejects it', function (): void {
        $post = postWithStatus(ModerationStatus::Passed);

        ModerationDecisionFactory::new()->forModel($post)->rejected()->create();

        expect(visibleTitles())->toBe([]);
    });

    it('reads mode on every query', function (): void {
        postWithStatus(ModerationStatus::Flagged);

        expect(visibleTitles())->toBe(['A post']);

        config(['jev-moderation.mode' => 'pre']);

        expect(visibleTitles())->toBe([]);
    });
});

describe('scopes', function (): void {
    beforeEach(function (): void {
        foreach ([null, ModerationStatus::Pending, ModerationStatus::Failed, ModerationStatus::Passed, ModerationStatus::Flagged, ModerationStatus::Blocked, ModerationStatus::Approved, ModerationStatus::Rejected] as $status) {
            postWithStatus($status, $status?->value ?? 'unchecked');
        }
    });

    it('hides blocked and rejected content by default', function (): void {
        expect(visibleTitles())->toBe(['unchecked', 'pending', 'failed', 'passed', 'flagged', 'approved']);
    });

    it('returns everything without moderation', function (): void {
        expect(ForumPost::withoutModeration()->count())->toBe(8);
    });

    it('lists the review queue', function (): void {
        config(['jev-moderation.mode' => 'pre']);

        expect(ForumPost::onlyFlagged()->pluck('title')->all())->toBe(['flagged']);
    });

    it('lists removed content, automatic or confirmed', function (): void {
        expect(ForumPost::onlyBlocked()->orderBy('id')->pluck('title')->all())->toBe(['blocked', 'rejected']);
    });

    it('filters by any statuses, visible or not', function (): void {
        expect(ForumPost::whereModerationStatus(ModerationStatus::Pending, ModerationStatus::Failed)->orderBy('id')->pluck('title')->all())
            ->toBe(['pending', 'failed']);
    });

    it('combines with other constraints', function (): void {
        expect(ForumPost::query()->where('title', 'like', 'p%')->orderBy('id')->pluck('title')->all())
            ->toBe(['pending', 'passed']);
    });
});

describe('status helpers', function (): void {
    it('reports the latest status', function (?ModerationStatus $status, bool $flagged, bool $blocked): void {
        $post = postWithStatus($status);

        expect($post->moderationStatus())->toBe($status)
            ->and($post->isFlagged())->toBe($flagged)
            ->and($post->isBlocked())->toBe($blocked);
    })->with([
        'never checked' => [null, false, false],
        'pending' => [ModerationStatus::Pending, false, false],
        'flagged' => [ModerationStatus::Flagged, true, false],
        'blocked' => [ModerationStatus::Blocked, false, true],
        'rejected' => [ModerationStatus::Rejected, false, true],
        'approved' => [ModerationStatus::Approved, false, false],
    ]);

    it('sees a new decision on the same instance after a save', function (): void {
        moderationFake()->classifyAs('spam', 1.0, ['spam' => 1.0]);
        $post = ForumPost::query()->create(['title' => 'Cheap watches', 'body' => 'Buy now']);

        expect($post->moderationStatus())->toBe(ModerationStatus::Blocked);

        moderationFake()->classifyAs('fine');
        $post->update(['body' => 'Sorry, here is a real question about watches']);

        expect($post->moderationStatus())->toBe(ModerationStatus::Passed);
    });
});

describe('hidden content', function (): void {
    it('is still checked in pre mode', function (): void {
        config(['jev-moderation.mode' => 'pre']);

        $post = ForumPost::query()->create(['title' => 'Best budget watch?', 'body' => 'Is a Seiko 5 still the go-to?']);

        moderationFake()->assertClassifiedTimes(1);
        expect($post->moderationStatus())->toBe(ModerationStatus::Passed)
            ->and(visibleTitles())->toBe(['Best budget watch?']);
    });

    it('still restores in queued event listeners', function (): void {
        moderationFake()->classifyAs('spam', 1.0, ['spam' => 1.0]);
        $post = ForumPost::query()->create(['title' => 'Cheap watches', 'body' => 'Buy now']);
        $decision = $post->latestModeration()->first();
        expect($decision)->not->toBeNull()
            ->and(ForumPost::query()->find($post->id))->toBeNull();

        $event = unserialize(serialize(new ContentBlocked($post, $decision)));

        expect($event->model->is($post))->toBeTrue();
    });
});

describe('configuration', function (): void {
    it('rejects an unknown mode or fail setting', function (string $key, string $env, string $allowed): void {
        config([$key => 'sometimes']);

        expect(fn () => ForumPost::query()->count())
            ->toThrow(InvalidArgumentException::class, "Set {$key} ({$env}) to one of: {$allowed}.");
    })->with([
        ['jev-moderation.mode', 'JEV_MODERATION_MODE', 'post, pre'],
        ['jev-moderation.fail', 'JEV_MODERATION_FAIL', 'open, closed'],
    ]);
});
