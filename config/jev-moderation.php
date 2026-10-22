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
    | Queue
    |--------------------------------------------------------------------------
    |
    | Checks run on the queue after the saving transaction commits. Set sync
    | to true to classify inline instead (e.g. chat, before broadcasting): a
    | failure then leaves the decision pending and fires ModerationFailed
    | straight away, with no retries.
    |
    | Rate limits, overload and timeouts are retried up to tries times, with
    | exponential backoff from backoff seconds (or Jev's retry-after). Other
    | failures, such as a bad key, fail at once.
    |
    */

    'queue' => env('JEV_MODERATION_QUEUE'),

    'connection' => env('JEV_MODERATION_QUEUE_CONNECTION'),

    'sync' => (bool) env('JEV_MODERATION_SYNC', false),

    'tries' => (int) env('JEV_MODERATION_TRIES', 5),

    'backoff' => (int) env('JEV_MODERATION_BACKOFF', 10),

    /*
    |--------------------------------------------------------------------------
    | Store Content
    |--------------------------------------------------------------------------
    |
    | Moderated content is sent to TypeSafe either way. Off (the default),
    | decisions keep only a sha256 hash of it; on, they also keep a copy,
    | which reviewers can read but which is personal data you then hold twice.
    |
    */

    'store_content' => (bool) env('JEV_MODERATION_STORE_CONTENT', false),

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

    /*
    |--------------------------------------------------------------------------
    | Default Policy
    |--------------------------------------------------------------------------
    |
    | Used by models whose #[Moderate] attribute names no policy.
    |
    */

    'policy' => env('JEV_MODERATION_POLICY', 'community'),

    /*
    |--------------------------------------------------------------------------
    | Policies
    |--------------------------------------------------------------------------
    |
    | Each policy asks Jev one question with a fixed set of choices and gets
    | back a probability for every choice. Thresholds compare a category's
    | probability, and a probability equal to a threshold counts as above:
    |
    |   review_above  flag for a human (keep it low: a person checks it)
    |   block_above   block automatically (keep it high: no one checks it)
    |
    | Every category with an action is checked, not only Jev's top choice,
    | and the strongest action wins. uncertain_below flags any answer whose
    | confidence (how decisive Jev was) falls below it; null turns it off.
    |
    | Choice descriptions are sent to Jev and are the main accuracy lever.
    | The safe choice goes last: Jev leans slightly towards the first option.
    | Changing anything here changes the policy version stored on decisions;
    | jev-moderation:rescan re-checks content after a change.
    |
    | The presets are starting points. Measure them on your own content.
    |
    */

    'policies' => [

        'community' => [
            'question' => 'Classify this user-generated forum content in `content`. Use `context`, if present, only to understand it.',
            'choices' => [
                'spam' => 'Advertising, link farming, scams or repeated promotional content',
                'harassment' => 'Insults, threats, intimidation or targeted abuse of a person',
                'hate' => 'Attacks or slurs against people for a protected characteristic such as race, religion, gender, sexuality or disability',
                'personal_data' => "Someone's home address, phone number, email address, financial details or other private information",
                'off_topic' => 'Unrelated to the discussion it was posted in',
                'fine' => 'Acceptable content that breaks none of these rules, including disagreement and criticism of ideas',
            ],
            'safe_choice' => 'fine',
            'actions' => [
                'spam' => ['review_above' => 0.50, 'block_above' => 0.90],
                'harassment' => ['review_above' => 0.40, 'block_above' => 0.95],
                'hate' => ['review_above' => 0.30, 'block_above' => 0.90],
                'personal_data' => ['review_above' => 0.40],
                'off_topic' => ['review_above' => 0.80],
            ],
            'uncertain_below' => 0.30,
        ],

        'chat' => [
            'question' => 'Classify this chat message in `content`. Use `context`, if present, only to understand it.',
            'choices' => [
                'spam' => 'Advertising, scams, phishing links or repeated promotional messages',
                'harassment' => 'Insults, threats, intimidation or targeted abuse of a person',
                'hate' => 'Attacks or slurs against people for a protected characteristic such as race, religion, gender, sexuality or disability',
                'sexual' => 'Sexually explicit content or unwanted sexual remarks',
                'personal_data' => "Someone's home address, phone number, email address, financial details or other private information",
                'fine' => 'Acceptable message that breaks none of these rules, including casual language, friendly banter, teasing and trash talk between players',
            ],
            'safe_choice' => 'fine',
            'actions' => [
                'spam' => ['review_above' => 0.60, 'block_above' => 0.95],
                'harassment' => ['review_above' => 0.50, 'block_above' => 0.95],
                'hate' => ['review_above' => 0.40, 'block_above' => 0.95],
                'sexual' => ['review_above' => 0.50, 'block_above' => 0.95],
                'personal_data' => ['review_above' => 0.50],
            ],
            // Chat moves fast; flag only on thresholds so the queue stays usable.
            'uncertain_below' => null,
        ],

        'strict' => [
            'question' => 'Classify this content in `content`, posted on a platform for young people. Use `context`, if present, only to understand it.',
            'choices' => [
                'spam' => 'Advertising, scams, phishing links or repeated promotional content',
                'harassment' => 'Insults, threats, intimidation, bullying or targeted abuse of a person',
                'hate' => 'Attacks or slurs against people for a protected characteristic such as race, religion, gender, sexuality or disability',
                'sexual' => 'Sexual content of any kind, including innuendo',
                'self_harm' => 'Mentions of self-harm, suicide or eating disorders, including asking for help',
                'personal_data' => "Anyone's home address, phone number, email address, school, social media handle or other identifying details",
                'off_topic' => 'Unrelated to the discussion it was posted in',
                'fine' => "Acceptable content that breaks none of these rules, including disagreement, corrections and blunt feedback about someone's work",
            ],
            'safe_choice' => 'fine',
            'actions' => [
                'spam' => ['review_above' => 0.40, 'block_above' => 0.85],
                'harassment' => ['review_above' => 0.25, 'block_above' => 0.85],
                'hate' => ['review_above' => 0.20, 'block_above' => 0.85],
                'sexual' => ['review_above' => 0.20, 'block_above' => 0.85],
                // Never auto-block: a person in distress needs a human, not a removed post.
                'self_harm' => ['review_above' => 0.15],
                'personal_data' => ['review_above' => 0.25, 'block_above' => 0.90],
                'off_topic' => ['review_above' => 0.70],
            ],
            'uncertain_below' => 0.50,
        ],

    ],

];
