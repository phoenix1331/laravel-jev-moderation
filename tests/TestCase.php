<?php

declare(strict_types=1);

namespace Phoenix1331\JevModeration\Tests;

use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as Orchestra;
use Phoenix1331\JevModeration\JevModerationServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        // Any HTTP request a test has not faked is a bug.
        Http::preventStrayRequests();
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
