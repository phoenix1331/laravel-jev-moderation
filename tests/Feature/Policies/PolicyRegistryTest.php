<?php

declare(strict_types=1);

use Phoenix1331\JevModeration\Policies\PolicyRegistry;

function policies(): PolicyRegistry
{
    return app(PolicyRegistry::class);
}

it('ships the community, chat and strict presets', function (): void {
    expect(policies()->names())->toBe(['community', 'chat', 'strict']);
});

it('defaults to the community policy', function (): void {
    expect(policies()->defaultName())->toBe('community')
        ->and(policies()->get()->name)->toBe('community');
});

it('parses every preset with descriptions and the safe choice last', function (string $name): void {
    $policy = policies()->get($name);

    expect(array_key_last($policy->choices))->toBe($policy->safeChoice)
        ->and(array_filter($policy->choices, fn (string $description): bool => $description === ''))->toBe([])
        ->and($policy->rules)->not->toBe([]);
})->with(['community', 'chat', 'strict']);

it('never auto-blocks self-harm in the strict preset', function (): void {
    expect(policies()->get('strict')->rules['self_harm']->blockAbove)->toBeNull();
});

it('keeps the chat queue to thresholds only', function (): void {
    expect(policies()->get('chat')->uncertainBelow)->toBeNull();
});

it('applies config changes at runtime', function (): void {
    $before = policies()->get('community')->version;

    config(['jev-moderation.policies.community.actions.spam.review_above' => 0.6]);

    expect(policies()->get('community')->version)->not->toBe($before)
        ->and(policies()->get('community')->rules['spam']->reviewAbove)->toBe(0.6);
});

it('uses a custom policy from config', function (): void {
    config(['jev-moderation.policies.reviews' => [
        'question' => 'Classify this product review.',
        'choices' => ['fake_review', 'fine'],
        'safe_choice' => 'fine',
        'actions' => ['fake_review' => ['review_above' => 0.5]],
    ]]);

    expect(policies()->get('reviews')->choices)->toBe(['fake_review' => '', 'fine' => '']);
});

it('throws on an unknown policy, listing the defined ones', function (): void {
    policies()->get('forum');
})->throws(InvalidArgumentException::class, 'No moderation policy named [forum]. Defined: community, chat, strict.');

it('says when no policies are defined', function (): void {
    config(['jev-moderation.policies' => null]);

    policies()->get('community');
})->throws(InvalidArgumentException::class, 'No moderation policy named [community]. Defined: none.');

it('throws when no default policy is set', function (mixed $name): void {
    config(['jev-moderation.policy' => $name]);

    policies()->get();
})->with([null, '', 7])->throws(InvalidArgumentException::class, 'Set jev-moderation.policy (JEV_MODERATION_POLICY) to a policy name.');
