<?php

declare(strict_types=1);

namespace Phoenix1331\JevModeration\Drivers;

use Closure;
use InvalidArgumentException;
use Phoenix1331\JevModeration\Contracts\ModerationDriver;
use Phoenix1331\JevModeration\Data\Classification;
use Phoenix1331\JevModeration\Data\ModerationRequest;
use Phoenix1331\JevModeration\Exceptions\DriverException;
use PHPUnit\Framework\Assert;

/**
 * Answers locally, for tests and demos. With nothing set up it passes
 * everything: the request's safe choice (or its first choice) at full
 * confidence.
 *
 *     $fake->classifyAs('spam', 0.97);
 *     $fake->push('harassment', 0.6)->pushFailure();   // one-off answers, used first, in order
 *     $fake->assertClassified(fn (ModerationRequest $request) => $request->policy === 'chat');
 */
final class FakeDriver implements ModerationDriver
{
    public const string NAME = 'fake';

    /** @var (Closure(ModerationRequest): Classification)|null */
    private ?Closure $answer = null;

    /** @var list<Closure(ModerationRequest): Classification> */
    private array $queue = [];

    /** @var list<ModerationRequest> */
    private array $classified = [];

    public function classify(ModerationRequest $request): Classification
    {
        $this->classified[] = $request;

        $answer = array_shift($this->queue) ?? $this->answer ?? $this->answerWith($request->safeChoice ?? array_keys($request->choices)[0], 1.0, []);

        return $answer($request);
    }

    public function models(): array
    {
        return [self::NAME];
    }

    /**
     * Answer every request with this choice. Without probabilities, the
     * choice gets the probability that gives this confidence and the rest
     * share what is left, following Jev's confidence formula.
     *
     * @param  array<string, float>  $probabilities
     */
    public function classifyAs(string $choice, float $confidence = 1.0, array $probabilities = []): self
    {
        $this->answer = $this->answerWith($choice, $confidence, $probabilities);

        return $this;
    }

    /**
     * Answer every request from a callback, e.g. by content.
     *
     * @param  Closure(ModerationRequest): Classification  $callback
     */
    public function classifyUsing(Closure $callback): self
    {
        $this->answer = $callback;

        return $this;
    }

    /**
     * Fail every request. Retryable by default, like a rate limit.
     */
    public function failWith(?DriverException $exception = null): self
    {
        $this->answer = self::failure($exception);

        return $this;
    }

    /**
     * Answer the next request with this choice, then fall back.
     *
     * @param  array<string, float>  $probabilities
     */
    public function push(string $choice, float $confidence = 1.0, array $probabilities = []): self
    {
        $this->queue[] = $this->answerWith($choice, $confidence, $probabilities);

        return $this;
    }

    /**
     * Fail the next request, then fall back.
     */
    public function pushFailure(?DriverException $exception = null): self
    {
        $this->queue[] = self::failure($exception);

        return $this;
    }

    /**
     * Every request classified so far, in order.
     *
     * @return list<ModerationRequest>
     */
    public function classified(): array
    {
        return $this->classified;
    }

    /**
     * @param  (Closure(ModerationRequest): bool)|null  $callback
     */
    public function assertClassified(?Closure $callback = null): void
    {
        Assert::assertNotEmpty(
            $this->matching($callback),
            $callback === null ? 'Nothing was classified.' : 'No classified request matched the callback.',
        );
    }

    /**
     * @param  Closure(ModerationRequest): bool  $callback
     */
    public function assertNotClassified(Closure $callback): void
    {
        Assert::assertEmpty($this->matching($callback), 'A request matching the callback was classified unexpectedly.');
    }

    public function assertClassifiedTimes(int $times): void
    {
        $actual = count($this->classified);

        Assert::assertSame($times, $actual, "Expected {$times} requests classified, classified {$actual}.");
    }

    public function assertNothingClassified(): void
    {
        $actual = count($this->classified);

        Assert::assertSame(0, $actual, "Expected nothing classified, classified {$actual}.");
    }

    /**
     * @param  (Closure(ModerationRequest): bool)|null  $callback
     * @return list<ModerationRequest>
     */
    private function matching(?Closure $callback): array
    {
        if ($callback === null) {
            return $this->classified;
        }

        return array_values(array_filter($this->classified, static fn (ModerationRequest $request): bool => $callback($request) === true));
    }

    /**
     * @param  array<string, float>  $probabilities
     * @return Closure(ModerationRequest): Classification
     */
    private function answerWith(string $choice, float $confidence, array $probabilities): Closure
    {
        return static function (ModerationRequest $request) use ($choice, $confidence, $probabilities): Classification {
            if (! array_key_exists($choice, $request->choices)) {
                throw new InvalidArgumentException(sprintf(
                    'The fake was told to answer [%s], which is not a choice of policy [%s]: %s.',
                    $choice,
                    $request->policy,
                    implode(', ', array_keys($request->choices)),
                ));
            }

            return new Classification(
                choice: $choice,
                confidence: $confidence,
                probabilities: $probabilities === [] ? self::spread($request->choices, $choice, $confidence) : $probabilities,
                driver: self::NAME,
                model: self::NAME,
            );
        };
    }

    /**
     * Probabilities with the given confidence: the chosen option gets
     * 1/n + confidence * (1 - 1/n), the others share the rest evenly.
     *
     * @param  array<string, string>  $choices
     * @return array<string, float>
     */
    private static function spread(array $choices, string $choice, float $confidence): array
    {
        $count = count($choices);
        $top = 1 / $count + $confidence * (1 - 1 / $count);
        $rest = (1 - $top) / ($count - 1);

        $probabilities = [];

        foreach (array_keys($choices) as $name) {
            $probabilities[$name] = round($name === $choice ? $top : $rest, 4);
        }

        return $probabilities;
    }

    /**
     * @return Closure(ModerationRequest): Classification
     */
    private static function failure(?DriverException $exception): Closure
    {
        $exception ??= DriverException::retryable('The fake driver was told to fail.');

        return static fn (): never => throw $exception;
    }
}
