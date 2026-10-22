<?php

declare(strict_types=1);

use Phoenix1331\JevModeration\Database\Factories\ModerationDecisionFactory;
use Phoenix1331\JevModeration\Enums\ModerationStatus;
use Phoenix1331\JevModeration\Exceptions\DecisionsAreAppendOnly;
use Phoenix1331\JevModeration\Models\ModerationDecision;

function flaggedDecision(): ModerationDecision
{
    return ModerationDecisionFactory::new()->flagged('spam', 0.72)->create();
}

function expectUnchanged(ModerationDecision $decision): void
{
    $fresh = ModerationDecision::query()->findOrFail($decision->id);

    expect($fresh->status)->toBe(ModerationStatus::Flagged)
        ->and($fresh->confidence)->toBe(0.72)
        ->and($fresh->updated_at?->equalTo($fresh->created_at))->toBeTrue();
}

it('refuses to save changes to an existing decision', function (): void {
    $decision = flaggedDecision();
    $decision->status = ModerationStatus::Passed;

    expect(fn () => $decision->save())->toThrow(DecisionsAreAppendOnly::class, 'cannot be updated');

    expectUnchanged($decision);
});

it('refuses model-level writes', function (string $method): void {
    $decision = flaggedDecision();

    // Within the same second touch() has nothing to write.
    $this->travel(1)->minute();

    expect(fn () => match ($method) {
        'update' => $decision->update(['status' => ModerationStatus::Passed]),
        'touch' => $decision->touch(),
        'increment' => $decision->increment('confidence'),
    })->toThrow(DecisionsAreAppendOnly::class, 'cannot be updated');

    expectUnchanged($decision);
})->with(['update', 'touch', 'increment']);

it('refuses to delete a decision', function (): void {
    $decision = flaggedDecision();

    expect(fn () => $decision->delete())->toThrow(DecisionsAreAppendOnly::class, 'cannot be deleted');

    expect(ModerationDecision::query()->count())->toBe(1);
});

it('refuses mass updates that skip model events', function (Closure $write): void {
    $decision = flaggedDecision();

    expect(fn () => $write())->toThrow(DecisionsAreAppendOnly::class, 'cannot be updated');

    expectUnchanged($decision);
})->with([
    'update' => [fn () => ModerationDecision::query()->update(['status' => 'passed'])],
    'upsert' => [fn () => ModerationDecision::query()->upsert([['id' => 1, 'status' => 'passed']], ['id'], ['status'])],
    'touch' => [fn () => ModerationDecision::query()->touch()],
    'increment' => [fn () => ModerationDecision::query()->increment('confidence')],
    'decrement' => [fn () => ModerationDecision::query()->decrement('confidence')],
    'incrementEach' => [fn () => ModerationDecision::query()->incrementEach(['confidence' => 1])],
    'decrementEach' => [fn () => ModerationDecision::query()->decrementEach(['confidence' => 1])],
]);

it('refuses mass deletes that skip model events', function (Closure $write): void {
    flaggedDecision();

    expect(fn () => $write())->toThrow(DecisionsAreAppendOnly::class, 'cannot be deleted');

    expect(ModerationDecision::query()->count())->toBe(1);
})->with([
    'delete' => [fn () => ModerationDecision::query()->where('status', 'flagged')->delete()],
    'forceDelete' => [fn () => ModerationDecision::query()->forceDelete()],
]);

it('still records new decisions and reads them back', function (): void {
    flaggedDecision();
    ModerationDecisionFactory::new()->approved()->create();

    expect(ModerationDecision::query()->where('status', 'approved')->count())->toBe(1)
        ->and(ModerationDecision::query()->count())->toBe(2);
});
