<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;
use Phoenix1331\JevModeration\JevModerationServiceProvider;

it('merges the package config', function (): void {
    expect(config('jev-moderation.enabled'))->toBeTrue();
});

it('publishes the config file under its tag', function (): void {
    $paths = ServiceProvider::pathsToPublish(JevModerationServiceProvider::class, 'jev-moderation-config');

    expect($paths)->toHaveCount(1)
        ->and(array_key_first($paths))->toEndWith('config/jev-moderation.php');
});
