<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Artisan;
use Phoenix1331\JevModeration\Database\Factories\ModerationDecisionFactory;
use Phoenix1331\JevModeration\Enums\ModerationSource;
use Phoenix1331\JevModeration\Tests\Fixtures\Models\ChatMessage;
use Phoenix1331\JevModeration\Tests\Fixtures\Models\ForumPost;

afterEach(fn () => Relation::morphMap([], false));

function seedStats(): void
{
    $post = ForumPost::class;

    ModerationDecisionFactory::new()->count(4)->passed()->state(['moderatable_type' => $post])->create();
    ModerationDecisionFactory::new()->count(2)->flagged('harassment', 0.6)->state(['moderatable_type' => $post])->create();
    ModerationDecisionFactory::new()->flagged('spam', 0.7)->state(['moderatable_type' => $post, 'source' => ModerationSource::Rescan])->create();
    ModerationDecisionFactory::new()->count(3)->blocked('spam', 1.0)->state(['moderatable_type' => $post])->create();
    ModerationDecisionFactory::new()->reported()->state(['moderatable_type' => $post])->create();
    ModerationDecisionFactory::new()->count(3)->rejected()->state(['moderatable_type' => $post])->create();
    ModerationDecisionFactory::new()->approved()->state(['moderatable_type' => $post])->create();
    ModerationDecisionFactory::new()->failed()->state(['moderatable_type' => ChatMessage::class])->create();
    // Outside the default seven days.
    ModerationDecisionFactory::new()->count(5)->blocked('hate', 1.0)->state(['moderatable_type' => $post, 'created_at' => now()->subDays(10)])->create();
}

it('counts decisions per status, automatic flags and blocks per category, and moderator agreement', function (): void {
    seedStats();

    $this->artisan('jev-moderation:stats')
        ->expectsOutputToContain('for all models')
        ->expectsTable(['Status', 'Decisions'], [
            ['pending', 0],
            ['failed', 1],
            ['passed', 4],
            ['flagged', 4],
            ['blocked', 3],
            ['approved', 1],
            ['rejected', 3],
        ])
        ->expectsTable(['Category', 'Flagged', 'Blocked'], [
            ['harassment', 2, 0],
            ['spam', 1, 3],
        ])
        ->expectsOutputToContain('Moderators upheld Jev (rejected)')
        ->expectsOutputToContain('Moderators overturned Jev (approved)')
        ->expectsOutputToContain('75%')
        ->assertSuccessful();
});

it('looks further back with --days', function (): void {
    seedStats();

    $this->artisan('jev-moderation:stats', ['--days' => '14'])
        ->expectsTable(['Category', 'Flagged', 'Blocked'], [
            ['harassment', 2, 0],
            ['hate', 0, 5],
            ['spam', 1, 3],
        ])
        ->assertSuccessful();
});

it('filters by model class or morph alias', function (): void {
    Relation::morphMap(['chat_message' => ChatMessage::class]);
    ModerationDecisionFactory::new()->failed()->state(['moderatable_type' => 'chat_message'])->create();
    ModerationDecisionFactory::new()->passed()->state(['moderatable_type' => ForumPost::class])->create();

    $this->artisan('jev-moderation:stats', ['--model' => ChatMessage::class])
        ->expectsOutputToContain('for chat_message')
        ->expectsTable(['Status', 'Decisions'], [
            ['pending', 0], ['failed', 1], ['passed', 0], ['flagged', 0], ['blocked', 0], ['approved', 0], ['rejected', 0],
        ])
        ->assertSuccessful();
});

it('leaves out the category table and agreement when there is nothing to show', function (): void {
    ModerationDecisionFactory::new()->passed()->create();

    $this->artisan('jev-moderation:stats')
        ->doesntExpectOutputToContain('Category')
        ->doesntExpectOutputToContain('Moderators upheld')
        ->doesntExpectOutputToContain('Missed by Jev')
        ->assertSuccessful();
});

it('says when there are no decisions', function (): void {
    $this->artisan('jev-moderation:stats', ['--model' => ForumPost::class])
        ->expectsOutputToContain('No moderation decisions in the last 7 days for '.ForumPost::class.'.')
        ->assertSuccessful();
});

it('rejects bad options', function (array $options, string $message): void {
    $this->artisan('jev-moderation:stats', $options)
        ->expectsOutputToContain($message)
        ->assertFailed();
})->with([
    'zero days' => [['--days' => '0'], '--days must be a positive whole number.'],
    'text days' => [['--days' => 'week'], '--days must be a positive whole number.'],
    'unknown model' => [['--model' => 'App\\Models\\Gone'], '[App\\Models\\Gone] is not a model.'],
]);

/**
 * The moderator agreement lines of a stats run, label to count.
 *
 * @param  array<string, string>  $options
 * @return array<string, string>
 */
function agreementLines(array $options = []): array
{
    Artisan::call('jev-moderation:stats', $options);
    preg_match_all('/^\s*((?:Moderators|Upheld|Missed|Reports|Not checked)[^.]*?)\s*\.+\s*(\S+)\s*$/m', Artisan::output(), $matches);

    return array_combine($matches[1], $matches[2]);
}

/**
 * Jev's result for one forum post, then the moderator's decisions on it.
 */
function reviewedRecord(int $id, ?string $jev, string ...$moderator): void
{
    $record = ModerationDecisionFactory::new()->state(['moderatable_type' => ForumPost::class, 'moderatable_id' => $id]);

    if ($jev !== null) {
        $record->pending()->create();
        $record->{$jev}()->create();
    }

    foreach ($moderator as $status) {
        $record->{$status}()->create();
    }
}

describe('moderator agreement', function (): void {
    it('pairs each moderator decision with Jev\'s latest earlier result', function (?string $jev, string $moderator, array $expected): void {
        reviewedRecord(1, $jev, $moderator);

        expect(agreementLines())->toBe($expected);
    })->with([
        'flagged, rejected' => ['flagged', 'rejected', ['Moderators upheld Jev (rejected)' => '1', 'Moderators overturned Jev (approved)' => '0', 'Upheld share' => '100%']],
        'blocked, rejected' => ['blocked', 'rejected', ['Moderators upheld Jev (rejected)' => '1', 'Moderators overturned Jev (approved)' => '0', 'Upheld share' => '100%']],
        'flagged, approved' => ['flagged', 'approved', ['Moderators upheld Jev (rejected)' => '0', 'Moderators overturned Jev (approved)' => '1', 'Upheld share' => '0%']],
        'blocked, approved' => ['blocked', 'approved', ['Moderators upheld Jev (rejected)' => '0', 'Moderators overturned Jev (approved)' => '1', 'Upheld share' => '0%']],
        'passed, rejected' => ['passed', 'rejected', ['Moderators upheld Jev (rejected)' => '0', 'Moderators overturned Jev (approved)' => '0', 'Missed by Jev (passed, then rejected)' => '1']],
        'passed, approved' => ['passed', 'approved', ['Moderators upheld Jev (rejected)' => '0', 'Moderators overturned Jev (approved)' => '0', 'Reports dismissed (passed, then approved)' => '1']],
        'never checked, rejected' => [null, 'rejected', ['Moderators upheld Jev (rejected)' => '0', 'Moderators overturned Jev (approved)' => '0', 'Not checked by Jev' => '1']],
        'never checked, approved' => [null, 'approved', ['Moderators upheld Jev (rejected)' => '0', 'Moderators overturned Jev (approved)' => '0', 'Not checked by Jev' => '1']],
    ]);

    it('ignores reports, failures and later results when finding Jev\'s decision', function (): void {
        $record = ModerationDecisionFactory::new()->state(['moderatable_type' => ForumPost::class, 'moderatable_id' => 1]);
        $record->passed()->create();
        $record->reported()->create();
        $record->failed()->create();
        $record->rejected()->create();
        $record->flagged()->create();

        expect(agreementLines())->toHaveKey('Missed by Jev (passed, then rejected)', '1')
            ->and(agreementLines())->toHaveKey('Moderators upheld Jev (rejected)', '0');
    });

    it('counts every decision of a moderator who changed their mind', function (): void {
        reviewedRecord(1, 'flagged', 'rejected', 'approved');

        expect(agreementLines())->toBe([
            'Moderators upheld Jev (rejected)' => '1',
            'Moderators overturned Jev (approved)' => '1',
            'Upheld share' => '50%',
        ]);
    });

    it('pairs with Jev\'s result from before the window', function (): void {
        ModerationDecisionFactory::new()->blocked()->state(['moderatable_type' => ForumPost::class, 'moderatable_id' => 1, 'created_at' => now()->subDays(30)])->create();
        ModerationDecisionFactory::new()->approved()->state(['moderatable_type' => ForumPost::class, 'moderatable_id' => 1, 'created_at' => now()->subDays(20)])->create();
        ModerationDecisionFactory::new()->rejected()->state(['moderatable_type' => ForumPost::class, 'moderatable_id' => 1])->create();

        expect(agreementLines())->toBe([
            'Moderators upheld Jev (rejected)' => '1',
            'Moderators overturned Jev (approved)' => '0',
            'Upheld share' => '100%',
        ]);
    });

    it('pairs within the same record only', function (): void {
        reviewedRecord(1, 'blocked');
        reviewedRecord(2, 'passed', 'rejected');
        ModerationDecisionFactory::new()->flagged()->state(['moderatable_type' => ChatMessage::class, 'moderatable_id' => 3])->create();
        ModerationDecisionFactory::new()->rejected()->state(['moderatable_type' => ForumPost::class, 'moderatable_id' => 3])->create();

        expect(agreementLines())->toBe([
            'Moderators upheld Jev (rejected)' => '0',
            'Moderators overturned Jev (approved)' => '0',
            'Missed by Jev (passed, then rejected)' => '1',
            'Not checked by Jev' => '1',
        ]);
    });

    it('respects --model', function (): void {
        reviewedRecord(1, 'flagged', 'rejected');
        ModerationDecisionFactory::new()->flagged()->state(['moderatable_type' => ChatMessage::class, 'moderatable_id' => 1])->create();
        ModerationDecisionFactory::new()->approved()->state(['moderatable_type' => ChatMessage::class, 'moderatable_id' => 1])->create();

        expect(agreementLines(['--model' => ChatMessage::class]))->toBe([
            'Moderators upheld Jev (rejected)' => '0',
            'Moderators overturned Jev (approved)' => '1',
            'Upheld share' => '0%',
        ]);
    });
});
