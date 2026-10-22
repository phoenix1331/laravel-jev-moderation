<?php

declare(strict_types=1);

use Phoenix1331\JevModeration\Contracts\ModerationDriver;
use Phoenix1331\JevModeration\Drivers\DriverManager;
use Phoenix1331\JevModeration\Tests\Fixtures\Drivers\StaticDriver;

function manager(): DriverManager
{
    return app(DriverManager::class)->extend('static', fn (): ModerationDriver => new StaticDriver);
}

it('defaults to the jev driver', function (): void {
    expect(config('jev-moderation.driver'))->toBe('jev');
});

it('resolves the configured driver', function (): void {
    config(['jev-moderation.driver' => 'static']);

    expect(manager()->driver())->toBeInstanceOf(StaticDriver::class);
});

it('resolves a driver by name', function (): void {
    expect(manager()->driver('static')->models())->toBe(['static-1']);
});

it('binds the contract to the configured driver', function (): void {
    config(['jev-moderation.driver' => 'static']);
    manager();

    expect(app(ModerationDriver::class))->toBeInstanceOf(StaticDriver::class);
});

it('shares one manager and caches each driver', function (): void {
    expect(app(DriverManager::class))->toBe(app(DriverManager::class))
        ->and(manager()->driver('static'))->toBe(manager()->driver('static'));
});

it('throws on an unknown driver', function (): void {
    manager()->driver('carrier-pigeon');
})->throws(InvalidArgumentException::class, 'Driver [carrier-pigeon] not supported.');

it('throws when no driver is configured', function (mixed $driver): void {
    config(['jev-moderation.driver' => $driver]);

    manager()->driver();
})->with([null, '', 42])->throws(InvalidArgumentException::class, 'Set jev-moderation.driver (JEV_MODERATION_DRIVER) to a driver name.');

it('throws when a custom driver does not implement the contract', function (): void {
    app(DriverManager::class)->extend('broken', fn (): stdClass => new stdClass);

    app(DriverManager::class)->driver('broken');
})->throws(InvalidArgumentException::class, 'Driver [broken] must implement Phoenix1331\JevModeration\Contracts\ModerationDriver, stdClass given.');
