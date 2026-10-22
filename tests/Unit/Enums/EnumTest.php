<?php

declare(strict_types=1);

use Phoenix1331\JevModeration\Enums\ModerationSource;
use Phoenix1331\JevModeration\Enums\ModerationStatus;

it('stores every status under a stable value', function (): void {
    expect(array_map(fn (ModerationStatus $status): string => $status->value, ModerationStatus::cases()))
        ->toBe(['pending', 'failed', 'passed', 'flagged', 'blocked', 'approved', 'rejected']);
});

it('stores every source under a stable value', function (): void {
    expect(array_map(fn (ModerationSource $source): string => $source->value, ModerationSource::cases()))
        ->toBe(['jev', 'human', 'report', 'rescan']);
});

it('marks only approved and rejected as human decisions', function (ModerationStatus $status, bool $human): void {
    expect($status->isHuman())->toBe($human);
})->with([
    [ModerationStatus::Pending, false],
    [ModerationStatus::Failed, false],
    [ModerationStatus::Passed, false],
    [ModerationStatus::Flagged, false],
    [ModerationStatus::Blocked, false],
    [ModerationStatus::Approved, true],
    [ModerationStatus::Rejected, true],
]);
