<?php

declare(strict_types=1);

namespace Phoenix1331\JevModeration\Tests;

use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as Orchestra;
use Phoenix1331\JevModeration\JevModerationServiceProvider;
use Phoenix1331\JevModeration\Support\StatusColumn;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        // Any HTTP request a test has not faked is a bug.
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        // Column detection is cached per process; each test rebuilds the database.
        StatusColumn::forget();

        parent::tearDown();
    }

    /**
     * Tests classify with FakeDriver unless they choose another driver.
     */
    protected function defineEnvironment($app): void
    {
        $app->make('config')->set('jev-moderation.driver', 'fake');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/migrations');
    }

    protected function getPackageProviders($app): array
    {
        return [
            JevModerationServiceProvider::class,
        ];
    }
}
