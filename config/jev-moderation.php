<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | Turn Jev Moderation for Laravel off without removing it, e.g. in a test environment.
    |
    */

    'enabled' => (bool) env('JEV_MODERATION_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Driver
    |--------------------------------------------------------------------------
    |
    | Which classifier answers moderation checks: "jev" calls TypeSafe's Jev
    | API, "fake" answers locally for tests and demos. Custom drivers are
    | registered with the driver manager's extend() and named here.
    |
    */

    'driver' => env('JEV_MODERATION_DRIVER', 'jev'),

];
