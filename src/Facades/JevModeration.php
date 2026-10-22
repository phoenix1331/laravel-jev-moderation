<?php

declare(strict_types=1);

namespace Phoenix1331\JevModeration\Facades;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Facade;
use Phoenix1331\JevModeration\Contracts\ModerationDriver;
use Phoenix1331\JevModeration\Data\CheckResult;
use Phoenix1331\JevModeration\Data\Policy;
use Phoenix1331\JevModeration\Drivers\DriverManager;
use Phoenix1331\JevModeration\Drivers\FakeDriver;
use Phoenix1331\JevModeration\JevModerationManager;
use RuntimeException;

/**
 * @method static CheckResult classify(string|array<string, string> $content, ?string $policy = null, ?string $context = null)
 * @method static Policy policy(?string $name = null)
 * @method static list<string> policies()
 * @method static void extend(string $driver, Closure(): ModerationDriver $factory)
 *
 * @see JevModerationManager
 */
final class JevModeration extends Facade
{
    /**
     * Swap the classifier for a FakeDriver, for every check (saves, queued
     * jobs, classify()), and return it to set answers and assert on:
     *
     *     JevModeration::fake()->classifyAs('spam', 0.97);
     *     // ...
     *     JevModeration::fake()->assertClassified();   // the same fake
     */
    public static function fake(): FakeDriver
    {
        $drivers = app(DriverManager::class);
        $config = app(Repository::class);

        if ($config->get('jev-moderation.driver') !== FakeDriver::NAME) {
            $config->set('jev-moderation.driver', FakeDriver::NAME);
            $drivers->forgetDrivers();
        }

        $fake = $drivers->driver(FakeDriver::NAME);

        if (! $fake instanceof FakeDriver) {
            throw new RuntimeException('JevModeration::fake() needs the built-in fake driver, but extend() replaced it.');
        }

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return JevModerationManager::class;
    }
}
