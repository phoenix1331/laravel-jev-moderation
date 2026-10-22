<?php

declare(strict_types=1);

use Phoenix1331\JevModeration\Data\ModerationRequest;

/**
 * @param  array<string, string>  $choices
 * @param  array<string, string>  $content
 */
function moderationRequest(array $choices = ['spam' => 'Advertising', 'fine' => ''], array $content = ['body' => 'Hello']): ModerationRequest
{
    return new ModerationRequest('community', 'Classify the post in `content`.', $choices, $content, 'Thread: watches');
}

it('keeps named content fields, choices and context', function (): void {
    $request = moderationRequest(content: ['title' => 'Watches', 'body' => 'Seiko 5?']);

    expect($request->policy)->toBe('community')
        ->and($request->instructions)->toBe('Classify the post in `content`.')
        ->and($request->choices)->toBe(['spam' => 'Advertising', 'fine' => ''])
        ->and($request->content)->toBe(['title' => 'Watches', 'body' => 'Seiko 5?'])
        ->and($request->context)->toBe('Thread: watches');
});

it('defaults to no context and no safe choice', function (): void {
    $request = new ModerationRequest('chat', 'Classify', ['a' => '', 'b' => ''], ['message' => 'hi']);

    expect($request->context)->toBeNull()
        ->and($request->safeChoice)->toBeNull();
});

it('keeps a safe choice that is one of the choices', function (): void {
    $request = new ModerationRequest('chat', 'Classify', ['spam' => '', 'fine' => ''], ['message' => 'hi'], safeChoice: 'fine');

    expect($request->safeChoice)->toBe('fine');
});

it('rejects a safe choice that is not one of the choices', function (): void {
    new ModerationRequest('chat', 'Classify', ['spam' => '', 'fine' => ''], ['message' => 'hi'], safeChoice: 'ok');
})->throws(InvalidArgumentException::class, 'Policy [chat] safe choice [ok] is not one of its choices.');

it('needs at least two choices', function (): void {
    moderationRequest(choices: ['fine' => '']);
})->throws(InvalidArgumentException::class, 'Policy [community] needs between 2 and 255 choices, 1 given.');

it('accepts at most 255 choices', function (): void {
    $choices = [];

    foreach (range(1, 256) as $i) {
        $choices["choice_{$i}"] = '';
    }

    moderationRequest(choices: $choices);
})->throws(InvalidArgumentException::class, '256 given');

it('needs something to moderate', function (): void {
    moderationRequest(content: []);
})->throws(InvalidArgumentException::class, 'content has no fields');
