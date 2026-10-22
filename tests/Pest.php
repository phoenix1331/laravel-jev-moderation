<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Phoenix1331\JevModeration\Data\Classification;
use Phoenix1331\JevModeration\Drivers\DriverManager;
use Phoenix1331\JevModeration\Drivers\FakeDriver;
use Phoenix1331\JevModeration\Support\StatusColumn;
use Phoenix1331\JevModeration\Tests\TestCase;

pest()->extend(TestCase::class)->in('Unit', 'Feature', 'Accuracy');

/**
 * A recorded response from tests/Fixtures/Responses/jev.
 *
 * @return array<string, mixed>
 */
function jevFixture(string $name): array
{
    $decoded = json_decode((string) file_get_contents(__DIR__."/Fixtures/Responses/jev/{$name}.json"), true, flags: JSON_THROW_ON_ERROR);

    expect($decoded)->toBeArray();

    return $decoded;
}

/**
 * The classification JevDriver builds from a recorded systemone answer.
 */
function jevClassification(string $fixture): Classification
{
    $answer = jevFixture($fixture)['answers']['moderation'];

    return new Classification(
        choice: $answer['choice'],
        confidence: (float) $answer['confidence'],
        probabilities: array_map(floatval(...), $answer['probabilities']),
        driver: 'jev',
        model: 'jev-1.14.0',
    );
}

/**
 * The shared FakeDriver the package classifies with in tests.
 */
function moderationFake(): FakeDriver
{
    $fake = app(DriverManager::class)->driver('fake');

    expect($fake)->toBeInstanceOf(FakeDriver::class);

    return $fake;
}

/**
 * Add the optional moderation_status column, as the generated migration does.
 */
function withStatusColumn(string $table = 'forum_posts'): void
{
    Schema::table($table, function (Blueprint $blueprint): void {
        $blueprint->string(StatusColumn::NAME, 16)->nullable()->index();
    });

    StatusColumn::forget($table);
}
