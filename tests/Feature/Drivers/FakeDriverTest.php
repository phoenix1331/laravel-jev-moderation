<?php

declare(strict_types=1);

use Phoenix1331\JevModeration\Contracts\ModerationDriver;
use Phoenix1331\JevModeration\Data\Classification;
use Phoenix1331\JevModeration\Data\ModerationRequest;
use Phoenix1331\JevModeration\Drivers\DriverManager;
use Phoenix1331\JevModeration\Drivers\FakeDriver;
use Phoenix1331\JevModeration\Exceptions\DriverException;
use PHPUnit\Framework\ExpectationFailedException;

const COMMUNITY_CHOICES = ['spam' => 'Advertising', 'harassment' => 'Abuse', 'off_topic' => '', 'fine' => 'No rule broken'];

function fakeRequest(string $body = 'Is a Seiko 5 still the go-to?', string $policy = 'community', ?string $safeChoice = 'fine'): ModerationRequest
{
    return new ModerationRequest($policy, 'Classify `content`.', COMMUNITY_CHOICES, ['body' => $body], safeChoice: $safeChoice);
}

it('is the fake driver in the manager, shared between resolutions', function (): void {
    config(['jev-moderation.driver' => 'fake']);

    expect(app(ModerationDriver::class))->toBeInstanceOf(FakeDriver::class)
        ->and(app(ModerationDriver::class))->toBe(app(DriverManager::class)->driver('fake'))
        ->and(app(DriverManager::class)->driver('fake')->models())->toBe(['fake']);
});

it('passes everything by default with the safe choice', function (): void {
    $result = (new FakeDriver)->classify(fakeRequest());

    expect($result->choice)->toBe('fine')
        ->and($result->confidence)->toBe(1.0)
        ->and($result->probabilities)->toBe(['spam' => 0.0, 'harassment' => 0.0, 'off_topic' => 0.0, 'fine' => 1.0])
        ->and($result->driver)->toBe('fake')
        ->and($result->model)->toBe('fake');
});

it('falls back to the first choice without a safe choice', function (): void {
    expect((new FakeDriver)->classify(fakeRequest(safeChoice: null))->choice)->toBe('spam');
});

it('answers every request with a standing classification', function (): void {
    $fake = (new FakeDriver)->classifyAs('spam', 0.97);

    expect($fake->classify(fakeRequest())->choice)->toBe('spam')
        ->and($fake->classify(fakeRequest('again'))->choice)->toBe('spam');
});

it('spreads probabilities to match the confidence, as Jev computes it', function (): void {
    $result = (new FakeDriver)->classifyAs('harassment', 0.6)->classify(fakeRequest());

    // Four choices: top = 1/4 + 0.6 * 3/4 = 0.7, the other three share 0.3.
    expect($result->confidence)->toBe(0.6)
        ->and($result->probabilities)->toBe(['spam' => 0.1, 'harassment' => 0.7, 'off_topic' => 0.1, 'fine' => 0.1]);
});

it('uses given probabilities as they are', function (): void {
    $probabilities = ['spam' => 0.0, 'harassment' => 0.44, 'off_topic' => 0.01, 'fine' => 0.55];

    $result = (new FakeDriver)->classifyAs('fine', 0.46, $probabilities)->classify(fakeRequest());

    expect($result->probabilities)->toBe($probabilities)
        ->and($result->probabilityOf('harassment'))->toBe(0.44);
});

it('answers from a callback', function (): void {
    $fake = (new FakeDriver)->classifyUsing(fn (ModerationRequest $request): Classification => new Classification(
        choice: str_contains($request->content['body'], 'http') ? 'spam' : 'fine',
        confidence: 1.0,
        probabilities: [],
        driver: 'fake',
    ));

    expect($fake->classify(fakeRequest('Buy at http://example.test'))->choice)->toBe('spam')
        ->and($fake->classify(fakeRequest('Nice watch'))->choice)->toBe('fine');
});

it('uses pushed answers first, in order, then falls back', function (): void {
    $fake = (new FakeDriver)->classifyAs('off_topic')
        ->push('spam', 0.9)
        ->push('harassment', 0.5);

    expect($fake->classify(fakeRequest())->choice)->toBe('spam')
        ->and($fake->classify(fakeRequest())->choice)->toBe('harassment')
        ->and($fake->classify(fakeRequest())->choice)->toBe('off_topic');
});

it('fails every request with a retryable error by default', function (): void {
    $fake = (new FakeDriver)->failWith();

    try {
        $fake->classify(fakeRequest());
        $this->fail('Expected a DriverException.');
    } catch (DriverException $e) {
        expect($e->retryable)->toBeTrue()
            ->and($e->getMessage())->toBe('The fake driver was told to fail.');
    }

    expect(fn () => $fake->classify(fakeRequest()))->toThrow(DriverException::class);
});

it('fails with the given error', function (): void {
    $fake = (new FakeDriver)->failWith(DriverException::permanent('Jev did not accept the API key.', 401));

    expect(fn () => $fake->classify(fakeRequest()))->toThrow(DriverException::class, 'Jev did not accept the API key.');
});

it('fails once, then recovers', function (): void {
    $fake = (new FakeDriver)->pushFailure()->classifyAs('spam');

    expect(fn () => $fake->classify(fakeRequest()))->toThrow(DriverException::class)
        ->and($fake->classify(fakeRequest())->choice)->toBe('spam');
});

it('records failed requests too', function (): void {
    $fake = (new FakeDriver)->pushFailure();

    try {
        $fake->classify(fakeRequest());
    } catch (DriverException) {
        // expected
    }

    $fake->assertClassifiedTimes(1);
});

it('throws when told to answer a choice the policy does not have', function (): void {
    (new FakeDriver)->classifyAs('hate')->classify(fakeRequest());
})->throws(InvalidArgumentException::class, 'The fake was told to answer [hate], which is not a choice of policy [community]: spam, harassment, off_topic, fine.');

it('lists what it classified, in order', function (): void {
    $fake = new FakeDriver;
    $fake->classify(fakeRequest('first'));
    $fake->classify(fakeRequest('second'));

    expect(array_map(fn (ModerationRequest $request): string => $request->content['body'], $fake->classified()))
        ->toBe(['first', 'second']);
});

describe('assertions', function (): void {
    it('asserts something was classified', function (): void {
        $fake = new FakeDriver;

        expect(fn () => $fake->assertClassified())->toThrow(ExpectationFailedException::class, 'Nothing was classified.');

        $fake->classify(fakeRequest());
        $fake->assertClassified();
    });

    it('asserts a matching request was classified', function (): void {
        $fake = new FakeDriver;
        $fake->classify(fakeRequest(policy: 'chat'));

        $fake->assertClassified(fn (ModerationRequest $request): bool => $request->policy === 'chat');

        expect(fn () => $fake->assertClassified(fn (ModerationRequest $request): bool => $request->policy === 'strict'))
            ->toThrow(ExpectationFailedException::class, 'No classified request matched the callback.');
    });

    it('asserts a matching request was not classified', function (): void {
        $fake = new FakeDriver;
        $fake->classify(fakeRequest(policy: 'chat'));

        $fake->assertNotClassified(fn (ModerationRequest $request): bool => $request->policy === 'strict');

        expect(fn () => $fake->assertNotClassified(fn (ModerationRequest $request): bool => $request->policy === 'chat'))
            ->toThrow(ExpectationFailedException::class, 'A request matching the callback was classified unexpectedly.');
    });

    it('asserts how many requests were classified', function (): void {
        $fake = new FakeDriver;
        $fake->classify(fakeRequest());
        $fake->classify(fakeRequest());

        $fake->assertClassifiedTimes(2);

        expect(fn () => $fake->assertClassifiedTimes(1))
            ->toThrow(ExpectationFailedException::class, 'Expected 1 requests classified, classified 2.');
    });

    it('asserts nothing was classified', function (): void {
        $fake = new FakeDriver;

        $fake->assertNothingClassified();

        $fake->classify(fakeRequest());

        expect(fn () => $fake->assertNothingClassified())
            ->toThrow(ExpectationFailedException::class, 'Expected nothing classified, classified 1.');
    });
});
