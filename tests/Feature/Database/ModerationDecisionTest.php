<?php

declare(strict_types=1);

use Phoenix1331\JevModeration\Database\Factories\ModerationDecisionFactory;
use Phoenix1331\JevModeration\Enums\ModerationSource;
use Phoenix1331\JevModeration\Enums\ModerationStatus;
use Phoenix1331\JevModeration\Models\ModerationDecision;
use Phoenix1331\JevModeration\Tests\Fixtures\Models\Post;
use Phoenix1331\JevModeration\Tests\Fixtures\Models\User;

function post(): Post
{
    return Post::query()->create(['title' => 'Best budget watch?', 'body' => 'Is a Seiko 5 still the go-to?']);
}

function user(string $name = 'Moderator'): User
{
    return User::query()->create(['name' => $name]);
}

it('casts stored values back to enums, floats and arrays', function (): void {
    $id = ModerationDecisionFactory::new()->forModel(post())->blocked('spam', 0.9731)->create()->id;

    $decision = ModerationDecision::query()->findOrFail($id);

    expect($decision->status)->toBe(ModerationStatus::Blocked)
        ->and($decision->source)->toBe(ModerationSource::Jev)
        ->and($decision->category)->toBe('spam')
        ->and($decision->confidence)->toBe(0.9731)
        ->and($decision->model)->toBe('jev-1.14.0')
        ->and($decision->meta)->toBe(['driver' => 'jev', 'latency_ms' => 420, 'input_tokens' => 490]);
});

it('resolves the moderated model and the reviewer', function (): void {
    $post = post();
    $moderator = user();

    $decision = ModerationDecisionFactory::new()->forModel($post)->rejected($moderator, 'confirmed spam')->create();

    expect($decision->moderatable)->toBeInstanceOf(Post::class)
        ->and($decision->moderatable?->getKey())->toBe($post->getKey())
        ->and($decision->reviewedBy)->toBeInstanceOf(User::class)
        ->and($decision->reviewedBy?->getKey())->toBe($moderator->getKey())
        ->and($decision->note)->toBe('confirmed spam');
});

it('builds each status through a factory state', function (ModerationDecisionFactory $factory, ModerationStatus $status, ModerationSource $source): void {
    $decision = $factory->forModel(post())->create();

    expect($decision->status)->toBe($status)
        ->and($decision->source)->toBe($source);
})->with([
    'pending' => [fn () => ModerationDecisionFactory::new()->pending(), ModerationStatus::Pending, ModerationSource::Jev],
    'passed' => [fn () => ModerationDecisionFactory::new()->passed(), ModerationStatus::Passed, ModerationSource::Jev],
    'flagged' => [fn () => ModerationDecisionFactory::new()->flagged(), ModerationStatus::Flagged, ModerationSource::Jev],
    'blocked' => [fn () => ModerationDecisionFactory::new()->blocked(), ModerationStatus::Blocked, ModerationSource::Jev],
    'reported' => [fn () => ModerationDecisionFactory::new()->reported(), ModerationStatus::Flagged, ModerationSource::Report],
    'approved' => [fn () => ModerationDecisionFactory::new()->approved(), ModerationStatus::Approved, ModerationSource::Human],
    'rejected' => [fn () => ModerationDecisionFactory::new()->rejected(), ModerationStatus::Rejected, ModerationSource::Human],
]);

it('leaves a pending decision without a classification', function (): void {
    $decision = ModerationDecisionFactory::new()->forModel(post())->pending()->create();

    expect($decision->category)->toBeNull()
        ->and($decision->confidence)->toBeNull()
        ->and($decision->model)->toBeNull()
        ->and($decision->meta)->toBeNull();
});

it('records the reporter and reason on a report', function (): void {
    $reporter = user('Reporter');

    $decision = ModerationDecisionFactory::new()->forModel(post())->reported('looks like spam', $reporter)->create();

    expect($decision->reviewedBy?->getKey())->toBe($reporter->getKey())
        ->and($decision->note)->toBe('looks like spam')
        ->and($decision->category)->toBeNull();
});

it('falls back to an example reviewer when none is given', function (): void {
    $decision = ModerationDecisionFactory::new()->forModel(post())->approved()->make();

    expect($decision->reviewed_by_type)->toBe('App\\Models\\User')
        ->and($decision->reviewed_by_id)->toBe(1)
        ->and($decision->note)->toBe('false positive');
});

it('stores content only when asked, hashed the same way', function (): void {
    $decision = ModerationDecisionFactory::new()->forModel(post())->withContent('Buy cheap watches')->create();

    expect($decision->content)->toBe('Buy cheap watches')
        ->and($decision->content_hash)->toBe(hash('sha256', 'Buy cheap watches'))
        ->and(ModerationDecisionFactory::new()->make()->content)->toBeNull();
});

it('points at an example model by default', function (): void {
    $decision = ModerationDecision::factory()->make();

    expect($decision->moderatable_type)->toBe('App\\Models\\Post')
        ->and($decision->status)->toBe(ModerationStatus::Passed);
});
