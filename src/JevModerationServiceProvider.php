<?php

declare(strict_types=1);

namespace Phoenix1331\JevModeration;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Phoenix1331\JevModeration\Contracts\ModerationDriver;
use Phoenix1331\JevModeration\Drivers\DriverManager;
use Phoenix1331\JevModeration\Policies\PolicyRegistry;
use Phoenix1331\JevModeration\Policies\ThresholdResolver;

class JevModerationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/jev-moderation.php', 'jev-moderation');

        $this->app->singleton(DriverManager::class);
        $this->app->singleton(PolicyRegistry::class);
        $this->app->singleton(ThresholdResolver::class);
        $this->app->singleton(JevModerationManager::class);
        $this->app->bind(ModerationDriver::class, static fn (Application $app): ModerationDriver => $app->make(DriverManager::class)->driver());
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/jev-moderation.php' => config_path('jev-moderation.php'),
        ], 'jev-moderation-config');

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'jev-moderation-migrations');
    }
}
