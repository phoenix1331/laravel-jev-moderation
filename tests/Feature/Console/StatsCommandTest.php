<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\Relation;
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
        ->expectsOutputToContain('Moderators upheld (rejected)')
        ->expectsOutputToContain('Moderators overturned (approved)')
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
