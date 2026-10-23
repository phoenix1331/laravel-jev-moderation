<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Phoenix1331\JevModeration\Enums\ModerationStatus;
use Phoenix1331\JevModeration\Tests\Fixtures\Models\ForumPost;

/*
 * Some setups return every column as a string: PDO::ATTR_STRINGIFY_FETCHES,
 * or emulated prepares (common behind PgBouncer). Comparisons against
 * database results must still work, or every check is silently skipped.
 */
beforeEach(function (): void {
    DB::connection()->getPdo()->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);
});

it('still classifies when the database returns ids as strings', function (): void {
    expect(DB::table('moderation_decisions')->max('id'))->toBeNull();

    moderationFake()->classifyAs('spam', 1.0, ['spam' => 1.0]);
    $post = ForumPost::query()->create(['title' => 'Cheap watches', 'body' => 'Buy now']);

    expect(DB::table('moderation_decisions')->max('id'))->toBeString();
    moderationFake()->assertClassifiedTimes(1);
    expect($post->moderationStatus())->toBe(ModerationStatus::Blocked);
});

it('still records a failure when the database returns ids as strings', function (): void {
    config(['jev-moderation.sync' => true]);
    moderationFake()->failWith();

    $post = ForumPost::query()->create(['title' => 'Post', 'body' => 'Body']);

    expect($post->moderationStatus())->toBe(ModerationStatus::Failed);
});
