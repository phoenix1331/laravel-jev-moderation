<?php

declare(strict_types=1);

namespace Phoenix1331\JevModeration;

use Illuminate\Support\ServiceProvider;

class JevModerationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/jev-moderation.php', 'jev-moderation');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/jev-moderation.php' => config_path('jev-moderation.php'),
        ], 'jev-moderation-config');
    }
}
