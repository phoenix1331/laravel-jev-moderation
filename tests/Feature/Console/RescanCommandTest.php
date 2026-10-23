<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\PendingCommand;
use Phoenix1331\JevModeration\Database\Factories\ModerationDecisionFactory;
use Phoenix1331\JevModeration\Enums\ModerationSource;
use Phoenix1331\JevModeration\Enums\ModerationStatus;
use Phoenix1331\JevModeration\Jobs\ModerateContent;
use Phoenix1331\JevModeration\Models\ModerationDecision;
use Phoenix1331\JevModeration\Tests\Fixtures\Models\ForumPost;
use Phoenix1331\JevModeration\Tests\Fixtures\Models\Post;

afterEach(fn () => Relation::morphMap([], false));

/**
 * One post per latest status (plus one never checked), titled by status.
 */
function seedPosts(): void
{
    Queue::fake();

    foreach ([ModerationStatus::Passed, ModerationStatus::Flagged, ModerationStatus::Blocked, ModerationStatus::Failed, ModerationStatus::Approved, ModerationStatus::Rejected] as $status) {
        $post = ForumPost::query()->create(['title' => $status->value, 'body' => "Body for {$status->value}"]);
        ModerationDecisionFactory::new()->forModel($post)->state(['status' => $status, 'content_hash' => $post->toModerationSubject()->hash])->create();
    }

    (new ForumPost(['title' => 'unchecked', 'body' => 'Saved before install']))->saveQuietly();
}

/**
 * Titles of the posts that got a rescan decision.
 *
 * @return list<string>
 */
function rescanned(): array
{
    return ModerationDecision::query()
        ->where('source', ModerationSource::Rescan)
        ->where('status', ModerationStatus::Pending)
        ->orderBy('moderatable_id')
        ->pluck('moderatable_id')
        ->map(fn (int $id): ?string => ForumPost::withoutModeration()->find($id)?->title)
        ->all();
}

function rescan(array $options = []): PendingCommand
{
    return test()->artisan('jev-moderation:rescan', ['model' => ForumPost::class, '--no-interaction' => true, ...$options]);
}

describe('selection', function (): void {
    beforeEach(fn () => seedPosts());

    it('re-checks everything but human decisions by default, including content never checked', function (): void {
        rescan()->expectsOutputToContain('Queued 5 checks')->assertSuccessful();

        expect(rescanned())->toBe(['passed', 'flagged', 'blocked', 'failed', 'unchecked']);
    });

    it('re-checks content whose hash has not changed', function (): void {
        rescan(['--status' => 'passed'])->assertSuccessful();

        expect(rescanned())->toBe(['passed']);
        Queue::assertPushed(ModerateContent::class, 7);
    });

    it('filters by latest status', function (): void {
        rescan(['--status' => 'passed, failed'])->assertSuccessful();

        expect(rescanned())->toBe(['passed', 'failed']);
    });

    it('re-checks human decisions only when asked', function (): void {
        rescan(['--status' => 'approved,rejected'])->assertSuccessful();

        expect(rescanned())->toBe(['approved', 'rejected']);
    });

    it('filters by creation date', function (): void {
        ForumPost::withoutModeration()->where('title', 'flagged')->update(['created_at' => now()->subMonth()]);
        ForumPost::withoutModeration()->where('title', 'unchecked')->update(['created_at' => now()->subMonth()]);

        rescan(['--since' => now()->subWeek()->toDateString()])->assertSuccessful();

        expect(rescanned())->toBe(['passed', 'blocked', 'failed']);
    });

    it('reads in chunks', function (): void {
        rescan(['--chunk' => '2'])->expectsOutputToContain('Queued 5 checks')->assertSuccessful();

        expect(rescanned())->toHaveCount(5);
    });

    it('counts without checking on a dry run', function (): void {
        rescan(['--dry-run' => true])->expectsOutputToContain('5 '.ForumPost::class.' would be re-checked')->assertSuccessful();

        expect(rescanned())->toBe([]);
    });

});

it('accepts a morph alias', function (): void {
    Relation::morphMap(['forum_post' => ForumPost::class]);
    seedPosts();

    $this->artisan('jev-moderation:rescan', ['model' => 'forum_post', '--no-interaction' => true, '--status' => 'blocked'])->assertSuccessful();

    expect(ModerationDecision::query()->where('source', 'rescan')->pluck('moderatable_type')->all())->toBe(['forum_post']);
});

describe('checks', function (): void {
    it('appends rescan decisions and runs the check', function (): void {
        moderationFake()->classifyAs('fine');
        $post = ForumPost::query()->create(['title' => 'post', 'body' => 'Body']);
        moderationFake()->classifyAs('spam', 1.0, ['spam' => 1.0]);

        rescan()->assertSuccessful();

        expect($post->moderationDecisions()->get()->map(fn (ModerationDecision $d): string => "{$d->source->value}:{$d->status->value}")->all())
            ->toBe(['jev:pending', 'jev:passed', 'rescan:pending', 'rescan:blocked']);
    });

    it('reports models with nothing to read', function (): void {
        Queue::fake();
        ForumPost::query()->create(['title' => 'post', 'body' => 'Body']);
        (new ForumPost(['title' => ' ', 'body' => null]))->saveQuietly();

        rescan()->expectsOutputToContain('Queued 1 checks for '.ForumPost::class.' (1 had nothing to read)')->assertSuccessful();
    });

    it('says when there is nothing to re-check', function (): void {
        rescan()->expectsOutputToContain('Nothing to re-check')->assertSuccessful();
    });
});

describe('confirmation', function (): void {
    beforeEach(fn () => seedPosts());

    it('asks before billing an interactive run', function (string $answer, int $checked): void {
        $this->artisan('jev-moderation:rescan', ['model' => ForumPost::class, '--status' => 'passed'])
            ->expectsConfirmation('Re-check 1 '.ForumPost::class.'? Each check is a billed Jev call.', $answer)
            ->assertSuccessful();

        expect(rescanned())->toHaveCount($checked);
    })->with([['yes', 1], ['no', 0]]);
});

describe('invalid input', function (): void {
    it('rejects what it cannot use', function (array $arguments, string $message): void {
        $this->artisan('jev-moderation:rescan', ['--no-interaction' => true, ...$arguments])
            ->expectsOutputToContain($message)
            ->assertFailed();
    })->with([
        'missing class' => [['model' => 'App\\Models\\Gone'], '[App\\Models\\Gone] is not a model using the Moderatable trait.'],
        'not moderatable' => [['model' => Post::class], 'is not a model using the Moderatable trait.'],
        'unknown status' => [['model' => ForumPost::class, '--status' => 'passed,maybe'], 'Unknown status [maybe]. Use: pending, failed, passed, flagged, blocked, approved, rejected.'],
        'bad date' => [['model' => ForumPost::class, '--since' => 'last blue moon'], '--since [last blue moon] is not a date.'],
        'zero chunk' => [['model' => ForumPost::class, '--chunk' => '0'], '--chunk must be a positive whole number.'],
        'text chunk' => [['model' => ForumPost::class, '--chunk' => 'lots'], '--chunk must be a positive whole number.'],
    ]);
});
