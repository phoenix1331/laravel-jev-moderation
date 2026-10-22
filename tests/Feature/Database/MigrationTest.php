<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Phoenix1331\JevModeration\JevModerationServiceProvider;

function decisionsMigration(): object
{
    $files = glob(__DIR__.'/../../../database/migrations/*_create_moderation_decisions_table.php');

    expect($files)->toHaveCount(1);

    return require $files[0];
}

it('creates the decisions table with every column', function (): void {
    expect(Schema::getColumnListing('moderation_decisions'))->toEqualCanonicalizing([
        'id', 'moderatable_type', 'moderatable_id', 'status', 'source', 'policy', 'policy_version',
        'model', 'category', 'confidence', 'content_hash', 'content', 'reviewed_by_type',
        'reviewed_by_id', 'note', 'meta', 'created_at', 'updated_at',
    ]);
});

it('indexes the morphs and the status queue', function (): void {
    $indexes = collect(Schema::getIndexes('moderation_decisions'))->pluck('columns', 'name');

    expect($indexes->get('moderation_decisions_moderatable_index'))->toBe(['moderatable_type', 'moderatable_id'])
        ->and($indexes->get('moderation_decisions_reviewed_by_index'))->toBe(['reviewed_by_type', 'reviewed_by_id'])
        ->and($indexes->get('moderation_decisions_status_created_at_index'))->toBe(['status', 'created_at']);
});

it('keeps every index name within the MySQL limit', function (): void {
    foreach (Schema::getIndexes('moderation_decisions') as $index) {
        expect(strlen($index['name']))->toBeLessThanOrEqual(64);
    }
});

it('rolls back cleanly and migrates again', function (): void {
    $migration = decisionsMigration();

    $migration->down();
    expect(Schema::hasTable('moderation_decisions'))->toBeFalse();

    $migration->up();
    expect(Schema::hasTable('moderation_decisions'))->toBeTrue();
});

it('publishes the migrations under their tag', function (): void {
    $paths = ServiceProvider::pathsToPublish(JevModerationServiceProvider::class, 'jev-moderation-migrations');

    expect($paths)->toHaveCount(1)
        ->and(array_key_first($paths))->toEndWith('database/migrations');
});
