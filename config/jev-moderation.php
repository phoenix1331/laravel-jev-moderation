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

];
