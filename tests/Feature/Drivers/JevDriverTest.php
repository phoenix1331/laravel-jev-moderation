<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Phoenix1331\JevModeration\Data\ModerationRequest;
use Phoenix1331\JevModeration\Drivers\DriverManager;
use Phoenix1331\JevModeration\Drivers\JevDriver;
use Phoenix1331\JevModeration\Exceptions\DriverException;

const JEV_URL = 'https://api.typesafe.ai/v1/systemone';

/**
 * @param  array<string, mixed>|string|null  $body
 * @param  array<string, string>  $headers
 */
function fakeJev(array|string|null $body, int $status = 200, array $headers = []): void
{
    Http::fake(['api.typesafe.ai/*' => Http::response($body, $status, ['x-typesafe-request-id' => 'req_test', ...$headers])]);
}

function jevDriver(int $maxContextTokens = 24000, ?string $key = 'test-key', string $baseUrl = 'https://api.typesafe.ai/v1'): JevDriver
{
    return new JevDriver($key, 'jev-latest', $baseUrl, 5, $maxContextTokens);
}

/**
 * @param  array<string, string>  $content
 * @param  array<string, string>|null  $choices
 */
function jevRequest(array $content = ['title' => 'Best budget watch?', 'body' => 'Is a Seiko 5 still the go-to?'], ?string $context = 'Thread: Watch recommendations under 200', ?array $choices = null, string $instructions = 'Classify the forum post in `content`.'): ModerationRequest
{
    return new ModerationRequest('community', $instructions, $choices ?? [
        'spam' => 'Advertising or link farming',
        'harassment' => 'Insults or targeted abuse',
        'hate' => 'Attacks on a protected characteristic',
        'personal_data' => 'Private details about someone',
        'off_topic' => '',
        'fine' => 'Acceptable content',
    ], $content, $context, 'fine');
}

function classifyFailure(?JevDriver $driver = null): DriverException
{
    try {
        ($driver ?? jevDriver())->classify(jevRequest());
    } catch (DriverException $e) {
        return $e;
    }

    throw new RuntimeException('Expected a DriverException.');
}

describe('request', function (): void {
    it('asks one Choice question about the named content and context', function (): void {
        fakeJev(jevFixture('systemone-fine'));

        jevDriver()->classify(jevRequest());

        Http::assertSent(function (Request $request): bool {
            expect($request->url())->toBe(JEV_URL)
                ->and($request->method())->toBe('POST')
                ->and($request->header('Authorization'))->toBe(['Bearer test-key'])
                ->and($request->data())->toBe([
                    'model' => 'jev-latest',
                    'state' => [
                        'content' => ['title' => 'Best budget watch?', 'body' => 'Is a Seiko 5 still the go-to?'],
                        'context' => 'Thread: Watch recommendations under 200',
                    ],
                    'questions' => [
                        'moderation' => [
                            'type' => 'choice',
                            'instructions' => 'Classify the forum post in `content`.',
                            'criteria' => [
                                'spam' => 'Advertising or link farming',
                                'harassment' => 'Insults or targeted abuse',
                                'hate' => 'Attacks on a protected characteristic',
                                'personal_data' => 'Private details about someone',
                                'off_topic' => null,
                                'fine' => 'Acceptable content',
                            ],
                        ],
                    ],
                ]);

            return true;
        });
    });

    it('leaves context out when there is none', function (): void {
        fakeJev(jevFixture('systemone-fine'));

        jevDriver()->classify(jevRequest(context: null));

        Http::assertSent(fn (Request $request): bool => ! array_key_exists('context', $request['state']));
    });

    it('accepts a base URL with a trailing slash', function (): void {
        fakeJev(jevFixture('systemone-fine'));

        jevDriver(baseUrl: 'https://api.typesafe.ai/v1/')->classify(jevRequest());

        Http::assertSent(fn (Request $request): bool => $request->url() === JEV_URL);
    });

    it('sends nothing without a key', function (): void {
        Http::fake();

        $e = classifyFailure(jevDriver(key: null));

        expect($e->retryable)->toBeFalse()
            ->and($e->getMessage())->toBe('No Jev API key is set. Add JEV_API_KEY to .env, or set JEV_MODERATION_DRIVER=fake.');

        Http::assertNothingSent();
    });
});

describe('answers', function (): void {
    it('maps a recorded answer to a classification', function (): void {
        fakeJev(jevFixture('systemone-fine'));

        $result = jevDriver()->classify(jevRequest());

        expect($result->choice)->toBe('fine')
            ->and($result->confidence)->toBe(1.0)
            ->and($result->probabilities)->toBe(['spam' => 0.0, 'harassment' => 0.0, 'hate' => 0.0, 'personal_data' => 0.0, 'off_topic' => 0.0, 'fine' => 1.0])
            ->and($result->driver)->toBe('jev')
            ->and($result->model)->toBe('jev-1.14.0')
            ->and($result->inputTokens)->toBe(491)
            ->and($result->requestId)->toBe('req_test')
            ->and($result->latencyMs)->toBeGreaterThanOrEqual(0)
            ->and($result->truncated)->toBeFalse();
    });

    it('maps a recorded spam answer', function (): void {
        fakeJev(jevFixture('systemone-spam'));

        $result = jevDriver()->classify(jevRequest(['body' => 'Replica watches 90% off']));

        expect($result->choice)->toBe('spam')
            ->and($result->probabilityOf('spam'))->toBe(1.0);
    });

    it('keeps the runner-up probability on a borderline answer', function (): void {
        fakeJev(jevFixture('systemone-borderline'));

        $result = jevDriver()->classify(jevRequest());

        expect($result->choice)->toBe('fine')
            ->and($result->confidence)->toBe(0.46)
            ->and($result->probabilityOf('fine'))->toBe(0.55)
            ->and($result->probabilityOf('harassment'))->toBe(0.44);
    });

    it('tolerates a missing request id, model and usage', function (): void {
        $body = jevFixture('systemone-fine');
        unset($body['model'], $body['usage']);
        Http::fake(['api.typesafe.ai/*' => Http::response($body)]);

        $result = jevDriver()->classify(jevRequest());

        expect($result->requestId)->toBeNull()
            ->and($result->model)->toBeNull()
            ->and($result->inputTokens)->toBeNull();
    });

    it('fails permanently on an answer it does not understand', function (Closure $mutate): void {
        $body = jevFixture('systemone-borderline');
        $answer = $body['answers']['moderation'];
        $body['answers'] = ['moderation' => $mutate($answer)];
        fakeJev($body);

        $e = classifyFailure();

        expect($e->retryable)->toBeFalse()
            ->and($e->getMessage())->toBe('Jev sent an answer this package does not understand.')
            ->and($e->status)->toBe(200)
            ->and($e->requestId)->toBe('req_test');
    })->with([
        'not an object' => fn (): string => 'fine',
        'another question type' => fn (array $answer): array => ['type' => 'noul', 'noul' => 0.5],
        'no choice' => fn (array $answer): array => array_diff_key($answer, ['choice' => true]),
        'a choice we did not offer' => fn (array $answer): array => [...$answer, 'choice' => 'gibberish'],
        'confidence as text' => fn (array $answer): array => [...$answer, 'confidence' => 'high'],
        'probabilities missing' => fn (array $answer): array => array_diff_key($answer, ['probabilities' => true]),
        'probabilities as a list' => fn (array $answer): array => [...$answer, 'probabilities' => [0.5, 0.5]],
        'probability as text' => fn (array $answer): array => [...$answer, 'probabilities' => ['fine' => 'most']],
    ]);

    it('fails permanently when the answer is under another key', function (): void {
        fakeJev(jevFixture('systemone-missing-answer'));

        expect(classifyFailure()->getMessage())->toBe('Jev sent an answer this package does not understand.');
    });
});

describe('errors', function (): void {
    it('maps each HTTP failure to a retryable or permanent error', function (int $status, ?string $fixture, bool $retryable, string $message): void {
        fakeJev($fixture === null ? null : jevFixture($fixture), $status);

        $e = classifyFailure();

        expect($e->retryable)->toBe($retryable)
            ->and($e->status)->toBe($status)
            ->and($e->requestId)->toBe('req_test')
            ->and($e->getMessage())->toBe($message);
    })->with([
        'unknown model' => [400, 'error-400-unknown-model', false, 'Jev returned HTTP 400: Unknown model: jev-does-not-exist'],
        'invalid key' => [401, 'error-401-invalid-key', false, 'Jev returned HTTP 401: Cannot authenticate with the server. Please check your API key and try again. Check JEV_API_KEY.'],
        'missing key' => [403, 'error-403-missing-key', false, 'Jev returned HTTP 403: Must supply an API key! Check your request and try again. Check JEV_API_KEY.'],
        'too large' => [413, null, false, 'Jev returned HTTP 413: Request Entity Too Large'],
        'validation' => [422, 'error-422-validation', false, 'Jev returned HTTP 422: body.questions.moderation.choice.criteria: Dictionary should have at least 1 item after validation, not 0'],
        'rate limited' => [429, null, true, 'Jev returned HTTP 429: Too Many Requests'],
        'overloaded' => [529, null, true, 'Jev returned HTTP 529: no details'],
        'server error' => [500, null, true, 'Jev returned HTTP 500: Internal Server Error'],
        'unavailable' => [503, null, true, 'Jev returned HTTP 503: Service Unavailable'],
    ]);

    it('reads a retry-after header in seconds', function (string $header, ?int $seconds): void {
        fakeJev(null, 429, ['retry-after' => $header]);

        expect(classifyFailure()->retryAfter)->toBe($seconds);
    })->with([
        'seconds' => ['7', 7],
        'an HTTP date' => ['Wed, 21 Oct 2026 07:28:00 GMT', null],
    ]);

    it('has no retry-after on a permanent failure', function (): void {
        fakeJev(jevFixture('error-401-invalid-key'), 401, ['retry-after' => '7']);

        expect(classifyFailure()->retryAfter)->toBeNull();
    });

    it('retries a timeout or unreachable host', function (): void {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 5001 milliseconds'));

        $e = classifyFailure();

        expect($e->retryable)->toBeTrue()
            ->and($e->status)->toBeNull()
            ->and($e->getMessage())->toBe('Could not reach Jev: cURL error 28: Operation timed out after 5001 milliseconds')
            ->and($e->getPrevious())->toBeInstanceOf(ConnectionException::class);
    });
});

/**
 * Overhead: "Classify" (8) + "spam" + "fine" (8) = 16 bytes. 10 tokens x 3 bytes = 30, so 14 bytes remain.
 *
 * @param  array<string, string>  $content
 */
function budgetRequest(array $content, ?string $context): ModerationRequest
{
    return jevRequest($content, $context, ['spam' => '', 'fine' => ''], 'Classify');
}

describe('context budget', function (): void {
    it('sends everything that fits untouched', function (): void {
        fakeJev(jevFixture('systemone-fine'));

        $result = jevDriver(10)->classify(budgetRequest(['body' => 'abcdefghij'], 'ctxx'));

        expect($result->truncated)->toBeFalse();
        Http::assertSent(fn (Request $request): bool => $request['state'] === ['content' => ['body' => 'abcdefghij'], 'context' => 'ctxx']);
    });

    it('trims context first', function (): void {
        fakeJev(jevFixture('systemone-fine'));

        $result = jevDriver(10)->classify(budgetRequest(['body' => 'abcdefghij'], 'context-xyz'));

        expect($result->truncated)->toBeTrue();
        Http::assertSent(fn (Request $request): bool => $request['state'] === ['content' => ['body' => 'abcdefghij'], 'context' => 'cont']);
    });

    it('drops context, then trims every content field by the same share', function (): void {
        fakeJev(jevFixture('systemone-fine'));

        $result = jevDriver(10)->classify(budgetRequest(['title' => str_repeat('t', 10), 'body' => str_repeat('b', 10)], 'ctx'));

        expect($result->truncated)->toBeTrue();
        // 23 bytes against 14: context goes (3), the other 6 come off 20 content bytes, so each keeps 70%.
        Http::assertSent(fn (Request $request): bool => $request['state'] === ['content' => ['title' => 'ttttttt', 'body' => 'bbbbbbb']]);
    });

    it('never splits a multibyte character', function (): void {
        fakeJev(jevFixture('systemone-fine'));

        jevDriver(10)->classify(budgetRequest(['body' => str_repeat('é', 10)], null));

        Http::assertSent(function (Request $request): bool {
            $body = $request['state']['content']['body'];

            return mb_check_encoding($body, 'UTF-8') && strlen($body) <= 14 && $body === str_repeat('é', 7);
        });
    });
});

describe('models', function (): void {
    it('lists the model names the key can use', function (): void {
        fakeJev(jevFixture('models'));

        expect(jevDriver()->models())->toBe(['jev-latest', 'jev-preview']);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && $request->url() === 'https://api.typesafe.ai/v1/models');
    });

    it('skips entries without a name', function (): void {
        fakeJev(['models' => [['name' => 'jev-latest'], ['description' => 'nameless'], 'jev-preview']]);

        expect(jevDriver()->models())->toBe(['jev-latest']);
    });

    it('fails on an unexpected listing', function (): void {
        fakeJev(['data' => []]);

        jevDriver()->models();
    })->throws(DriverException::class, 'Jev sent an answer this package does not understand.');

    it('fails on an HTTP error', function (): void {
        fakeJev(jevFixture('error-401-invalid-key'), 401);

        jevDriver()->models();
    })->throws(DriverException::class, 'Jev returned HTTP 401');
});

describe('configuration', function (): void {
    it('builds the jev driver from config', function (): void {
        config([
            'jev-moderation.drivers.jev.key' => '  live-key  ',
            'jev-moderation.drivers.jev.model' => 'jev-1.14.0',
            'jev-moderation.drivers.jev.base_url' => 'https://openrouter.ai/api/v1',
        ]);
        Http::fake(['openrouter.ai/*' => Http::response(jevFixture('systemone-fine'))]);

        app(DriverManager::class)->driver('jev')->classify(jevRequest());

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://openrouter.ai/api/v1/systemone'
            && $request->header('Authorization') === ['Bearer live-key']
            && $request['model'] === 'jev-1.14.0');
    });

    it('treats a blank key as missing', function (mixed $key): void {
        config(['jev-moderation.drivers.jev.key' => $key]);
        Http::fake();

        app(DriverManager::class)->driver('jev')->models();
    })->with(['', '   ', null, 42])->throws(DriverException::class, 'No Jev API key is set.');

    it('rejects an invalid model or base URL', function (string $key): void {
        config([$key => '']);

        app(DriverManager::class)->driver('jev');
    })->with(['jev-moderation.drivers.jev.model', 'jev-moderation.drivers.jev.base_url'])
        ->throws(InvalidArgumentException::class, 'to a non-empty string, string given.');

    it('rejects a timeout or budget that is not a positive whole number', function (string $key, mixed $value, string $given): void {
        config([$key => $value]);

        expect(fn () => app(DriverManager::class)->driver('jev'))
            ->toThrow(InvalidArgumentException::class, "Set {$key} to a positive whole number, {$given} given.");
    })->with([
        ['jev-moderation.drivers.jev.timeout', 0, '0'],
        ['jev-moderation.max_context_tokens', '24000', 'string'],
    ]);
});
