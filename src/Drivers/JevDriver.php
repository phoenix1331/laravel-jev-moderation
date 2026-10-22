<?php

declare(strict_types=1);

namespace Phoenix1331\JevModeration\Drivers;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Phoenix1331\JevModeration\Contracts\ModerationDriver;
use Phoenix1331\JevModeration\Data\Classification;
use Phoenix1331\JevModeration\Data\ModerationRequest;
use Phoenix1331\JevModeration\Exceptions\DriverException;

/**
 * Classifies with TypeSafe's Jev: one Choice question per request through
 * POST /systemone. Never retries itself; DriverException::$retryable tells
 * the queued job whether to try again. Shapes and error codes:
 * docs.typesafe.ai/api/errors.
 *
 * @internal
 */
final readonly class JevDriver implements ModerationDriver
{
    public const string NAME = 'jev';

    /** The question key; ours, never seen by the model. */
    public const string QUESTION = 'moderation';

    public const string REQUEST_ID_HEADER = 'x-typesafe-request-id';

    /** Conservative estimate for English; Jev's tokeniser is unpublished. */
    public const int BYTES_PER_TOKEN = 3;

    /** Rate limited or overloaded; 5xx and connection failures are retried too. */
    private const array RETRYABLE = [429, 529];

    public function __construct(
        private ?string $key,
        private string $model,
        private string $baseUrl,
        private int $timeout,
        private int $maxContextTokens,
    ) {}

    public function classify(ModerationRequest $request): Classification
    {
        [$state, $truncated] = $this->state($request);
        $started = hrtime(true);

        $response = $this->send(fn (PendingRequest $http): Response => $http->post('systemone', [
            'model' => $this->model,
            'state' => $state,
            'questions' => [
                self::QUESTION => [
                    'type' => 'choice',
                    'instructions' => $request->instructions,
                    // A choice without a description is read by its name alone.
                    'criteria' => array_map(static fn (string $description): ?string => $description === '' ? null : $description, $request->choices),
                ],
            ],
        ]));

        return $this->classification($request, $response, (int) round((hrtime(true) - $started) / 1_000_000), $truncated);
    }

    public function models(): array
    {
        $response = $this->send(fn (PendingRequest $http): Response => $http->get('models'));
        $models = $response->json('models');

        if (! is_array($models)) {
            throw self::unexpected($response);
        }

        $names = [];

        foreach ($models as $model) {
            if (is_array($model) && is_string($model['name'] ?? null)) {
                $names[] = $model['name'];
            }
        }

        return $names;
    }

    /**
     * @param  Closure(PendingRequest): Response  $call
     */
    private function send(Closure $call): Response
    {
        if ($this->key === null) {
            throw DriverException::permanent('No Jev API key is set. Add JEV_API_KEY to .env, or set JEV_MODERATION_DRIVER=fake.');
        }

        $http = Http::baseUrl(rtrim($this->baseUrl, '/').'/')
            ->withToken($this->key)
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout);

        try {
            $response = $call($http);
        } catch (ConnectionException $e) {
            throw DriverException::retryable('Could not reach Jev: '.$e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            throw self::failure($response);
        }

        return $response;
    }

    private function classification(ModerationRequest $request, Response $response, int $latencyMs, bool $truncated): Classification
    {
        $answer = $response->json('answers.'.self::QUESTION);

        if (! is_array($answer) || ($answer['type'] ?? null) !== 'choice') {
            throw self::unexpected($response);
        }

        $choice = $answer['choice'] ?? null;
        $confidence = $answer['confidence'] ?? null;
        $probabilities = self::probabilities($answer['probabilities'] ?? null);

        if (! is_string($choice) || ! array_key_exists($choice, $request->choices) || ! self::isNumber($confidence) || $probabilities === null) {
            throw self::unexpected($response);
        }

        $model = $response->json('model');
        $inputTokens = $response->json('usage.input_tokens');

        return new Classification(
            choice: $choice,
            confidence: (float) $confidence,
            probabilities: $probabilities,
            driver: self::NAME,
            model: is_string($model) ? $model : null,
            inputTokens: is_int($inputTokens) ? $inputTokens : null,
            requestId: self::requestId($response),
            latencyMs: $latencyMs,
            truncated: $truncated,
        );
    }

    /**
     * @return array<string, float>|null null when the shape is wrong
     */
    private static function probabilities(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $probabilities = [];

        foreach ($raw as $name => $probability) {
            if (! is_string($name) || ! self::isNumber($probability)) {
                return null;
            }

            // Jev rounds, so certain answers arrive as integers 0 and 1.
            $probabilities[$name] = (float) $probability;
        }

        return $probabilities;
    }

    /**
     * The state sent to Jev, trimmed to the context budget: context first,
     * then every content field by the same proportion.
     *
     * @return array{0: array{content: array<string, string>, context?: string}, 1: bool}
     */
    private function state(ModerationRequest $request): array
    {
        $content = $request->content;
        $context = $request->context ?? '';

        $overhead = strlen($request->instructions);

        foreach ($request->choices as $name => $description) {
            $overhead += strlen($name) + strlen($description);
        }

        $budget = max(0, $this->maxContextTokens * self::BYTES_PER_TOKEN - $overhead);
        $contentBytes = array_sum(array_map(strlen(...), $content));
        $over = strlen($context) + $contentBytes - $budget;
        $truncated = $over > 0;

        if ($truncated) {
            $keep = max(0, strlen($context) - $over);
            $over -= strlen($context) - $keep;
            $context = mb_strcut($context, 0, $keep);
        }

        if ($over > 0) {
            $ratio = max(0, $contentBytes - $over) / $contentBytes;
            $content = array_map(static fn (string $value): string => mb_strcut($value, 0, (int) floor(strlen($value) * $ratio)), $content);
        }

        $state = ['content' => $content];

        if ($context !== '') {
            $state['context'] = $context;
        }

        return [$state, $truncated];
    }

    private static function failure(Response $response): DriverException
    {
        $status = $response->status();
        $message = sprintf('Jev returned HTTP %d: %s', $status, self::detail($response));

        if ($status === 401 || $status === 403) {
            $message .= ' Check JEV_API_KEY.';
        }

        if (in_array($status, self::RETRYABLE, true) || $status >= 500) {
            $retryAfter = $response->header('retry-after');

            return DriverException::retryable(
                $message,
                $status,
                self::requestId($response),
                retryAfter: ctype_digit($retryAfter) ? (int) $retryAfter : null,
            );
        }

        return DriverException::permanent($message, $status, self::requestId($response));
    }

    /**
     * The reason Jev gave. Only the 422 body is documented; auth and usage
     * errors were seen with {detail: {message}}. Anything else: no details.
     */
    private static function detail(Response $response): string
    {
        $detail = $response->json('detail');

        if (is_array($detail) && is_string($detail['message'] ?? null)) {
            return $detail['message'];
        }

        $first = is_array($detail) ? ($detail[0] ?? null) : null;

        if (is_array($first) && is_string($first['msg'] ?? null)) {
            $location = is_array($first['loc'] ?? null) ? implode('.', array_filter($first['loc'], is_scalar(...))) : '';

            return $location === '' ? $first['msg'] : "{$location}: {$first['msg']}";
        }

        return $response->reason() !== '' ? $response->reason() : 'no details';
    }

    private static function unexpected(Response $response): DriverException
    {
        return DriverException::permanent('Jev sent an answer this package does not understand.', $response->status(), self::requestId($response));
    }

    private static function requestId(Response $response): ?string
    {
        $id = $response->header(self::REQUEST_ID_HEADER);

        return $id === '' ? null : $id;
    }

    /**
     * @phpstan-assert-if-true int|float $value
     */
    private static function isNumber(mixed $value): bool
    {
        return is_int($value) || is_float($value);
    }
}
