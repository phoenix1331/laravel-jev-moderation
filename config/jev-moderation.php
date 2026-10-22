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

    /*
    |--------------------------------------------------------------------------
    | Drivers
    |--------------------------------------------------------------------------
    |
    | Bring your own key from typesafe.ai. "jev-latest" is an alias that moves
    | when TypeSafe ships a release; once thresholds are tuned, pin a version
    | such as "jev-1.14.0". Every decision records the version that answered.
    |
    */

    'drivers' => [
        'jev' => [
            'key' => env('JEV_API_KEY'),
            'model' => env('JEV_MODEL', 'jev-latest'),
            'base_url' => env('JEV_BASE_URL', 'https://api.typesafe.ai/v1'),
            'timeout' => (int) env('JEV_TIMEOUT', 5),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Context Budget
    |--------------------------------------------------------------------------
    |
    | Jev reads at most 32k tokens per question. Requests estimated above this
    | many tokens (3 bytes per token, on the safe side) are trimmed, context
    | first, then content, and the decision is marked as truncated.
    |
    */

    'max_context_tokens' => (int) env('JEV_MODERATION_MAX_CONTEXT_TOKENS', 24000),

];
