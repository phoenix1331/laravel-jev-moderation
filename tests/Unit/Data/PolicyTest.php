<?php

declare(strict_types=1);

use Phoenix1331\JevModeration\Data\CategoryRule;
use Phoenix1331\JevModeration\Data\Policy;

/**
 * @return array<string, mixed>
 */
function policyConfig(array $overrides = []): array
{
    return array_replace([
        'question' => 'Classify the post in `content`.',
        'choices' => ['spam' => 'Advertising', 'harassment' => 'Abuse', 'fine' => 'No rule broken'],
        'safe_choice' => 'fine',
        'actions' => [
            'spam' => ['review_above' => 0.5, 'block_above' => 0.9],
            'harassment' => ['review_above' => 0.4],
        ],
        'uncertain_below' => 0.3,
    ], $overrides);
}

it('builds a policy from config', function (): void {
    $policy = Policy::fromConfig('community', policyConfig());

    expect($policy->name)->toBe('community')
        ->and($policy->question)->toBe('Classify the post in `content`.')
        ->and($policy->choices)->toBe(['spam' => 'Advertising', 'harassment' => 'Abuse', 'fine' => 'No rule broken'])
        ->and($policy->safeChoice)->toBe('fine')
        ->and($policy->rules)->toEqual([
            'spam' => new CategoryRule('spam', 0.5, 0.9),
            'harassment' => new CategoryRule('harassment', 0.4),
        ])
        ->and($policy->uncertainBelow)->toBe(0.3);
});

it('accepts choices as a list of names', function (): void {
    $policy = Policy::fromConfig('chat', policyConfig(['choices' => ['spam', 'harassment', 'fine']]));

    expect($policy->choices)->toBe(['spam' => '', 'harassment' => '', 'fine' => '']);
});

it('accepts whole-number thresholds, no actions and no confidence floor', function (): void {
    $policy = Policy::fromConfig('lenient', policyConfig([
        'actions' => ['spam' => ['review_above' => 1]],
        'uncertain_below' => null,
    ]));

    expect($policy->rules['spam']->reviewAbove)->toBe(1.0)
        ->and($policy->uncertainBelow)->toBeNull()
        ->and(Policy::fromConfig('silent', array_diff_key(policyConfig(), ['actions' => true, 'uncertain_below' => true]))->rules)->toBe([]);
});

it('builds the request to classify content under it', function (): void {
    $request = Policy::fromConfig('community', policyConfig())->request(['body' => 'Hello'], 'Thread: watches');

    expect($request->policy)->toBe('community')
        ->and($request->instructions)->toBe('Classify the post in `content`.')
        ->and($request->choices)->toBe(['spam' => 'Advertising', 'harassment' => 'Abuse', 'fine' => 'No rule broken'])
        ->and($request->content)->toBe(['body' => 'Hello'])
        ->and($request->context)->toBe('Thread: watches')
        ->and($request->safeChoice)->toBe('fine');
});

describe('version', function (): void {
    it('is a stable sha256 of the policy', function (): void {
        $version = Policy::fromConfig('community', policyConfig())->version;

        expect($version)->toMatch('/^[0-9a-f]{64}$/')
            ->and(Policy::fromConfig('community', policyConfig())->version)->toBe($version)
            ->and(Policy::fromConfig('renamed', policyConfig())->version)->toBe($version);
    });

    it('treats 0.5 and 0.50 as the same threshold', function (): void {
        expect(Policy::fromConfig('a', policyConfig(['uncertain_below' => 0.30]))->version)
            ->toBe(Policy::fromConfig('b', policyConfig(['uncertain_below' => 0.3]))->version);
    });

    it('changes when anything Jev sees or the thresholds change', function (array $overrides): void {
        expect(Policy::fromConfig('community', policyConfig($overrides))->version)
            ->not->toBe(Policy::fromConfig('community', policyConfig())->version);
    })->with([
        'question' => [['question' => 'Classify the comment.']],
        'a description' => [['choices' => ['spam' => 'Adverts', 'harassment' => 'Abuse', 'fine' => 'No rule broken']]],
        'choice order' => [['choices' => ['harassment' => 'Abuse', 'spam' => 'Advertising', 'fine' => 'No rule broken']]],
        'review threshold' => [['actions' => ['spam' => ['review_above' => 0.6, 'block_above' => 0.9], 'harassment' => ['review_above' => 0.4]]]],
        'block threshold' => [['actions' => ['spam' => ['review_above' => 0.5], 'harassment' => ['review_above' => 0.4]]]],
        'confidence floor' => [['uncertain_below' => null]],
    ]);
});

describe('invalid config', function (): void {
    it('names the policy and the problem', function (mixed $config, string $message): void {
        expect(fn () => Policy::fromConfig('community', $config))
            ->toThrow(InvalidArgumentException::class, "Moderation policy [community] {$message}.");
    })->with([
        'not an array' => ['community', 'must be an array, string given'],
        'no question' => [policyConfig(['question' => null]), 'needs a question'],
        'blank question' => [policyConfig(['question' => '  ']), 'needs a question'],
        'no safe choice' => [policyConfig(['safe_choice' => null]), 'needs a safe_choice'],
        'safe choice not offered' => [policyConfig(['safe_choice' => 'ok']), 'safe choice [ok] is not one of its choices'],
        'no choices' => [policyConfig(['choices' => null]), 'needs choices'],
        'one choice' => [policyConfig(['choices' => ['fine'], 'actions' => []]), 'needs between 2 and 255 choices, 1 given'],
        'non-string description' => [policyConfig(['choices' => ['spam' => 1, 'fine' => '']]), 'choices must be a list of names or a map of name to description'],
        'blank choice name' => [policyConfig(['choices' => ['', 'fine']]), 'choices must be a list of names or a map of name to description'],
        'actions not an array' => [policyConfig(['actions' => 'spam']), 'actions must be an array'],
        'actions as a list' => [policyConfig(['actions' => [['review_above' => 0.5]]]), 'actions must be keyed by category name'],
        'action without review_above' => [policyConfig(['actions' => ['spam' => ['block_above' => 0.9]]]), 'action for [spam] needs a numeric review_above'],
        'action not an array' => [policyConfig(['actions' => ['spam' => 0.5]]), 'action for [spam] needs a numeric review_above'],
        'block_above as text' => [policyConfig(['actions' => ['spam' => ['review_above' => 0.5, 'block_above' => 'high']]]), 'action for [spam] has a block_above that is not a number'],
        'action for an unknown category' => [policyConfig(['actions' => ['hate' => ['review_above' => 0.5]]]), 'has a rule for [hate], which is not one of its choices'],
        'action for the safe choice' => [policyConfig(['actions' => ['fine' => ['review_above' => 0.5]]]), 'has a rule for its safe choice [fine]'],
        'uncertain_below as text' => [policyConfig(['uncertain_below' => 'low']), 'uncertain_below must be a number or null'],
        'uncertain_below out of range' => [policyConfig(['uncertain_below' => 1.5]), 'uncertain_below must be between 0 and 1, 1.5 given'],
    ]);

    it('rejects a threshold outside 0 to 1 from the rule', function (): void {
        Policy::fromConfig('community', policyConfig(['actions' => ['spam' => ['review_above' => 2]]]));
    })->throws(InvalidArgumentException::class, 'The review_above threshold for [spam] must be between 0 and 1, 2 given.');

    it('rejects a rule filed under another category', function (): void {
        new Policy('community', 'Classify', ['spam' => '', 'fine' => ''], 'fine', ['spam' => new CategoryRule('hate', 0.5)]);
    })->throws(InvalidArgumentException::class, 'Moderation policy [community] rule under [spam] is for [hate].');
});
