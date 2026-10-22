<?php

declare(strict_types=1);

namespace Phoenix1331\JevModeration\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Phoenix1331\JevModeration\Exceptions\DriverException;
use Phoenix1331\JevModeration\Models\ModerationDecision;
use Phoenix1331\JevModeration\Moderation\Checker;
use Throwable;

/**
 * Classifies one pending decision. The payload is the decision id only, so
 * no content sits in the queue. Retryable driver errors are released with
 * exponential backoff (or Jev's retry-after); permanent ones fail at once.
 *
 * @internal
 */
final class ModerateContent implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(public readonly int $decisionId) {}

    /**
     * Attempts in all, read by the queue worker too.
     */
    public function tries(): int
    {
        return self::setting('jev-moderation.tries', 5);
    }

    /**
     * Seconds before the queue retries an unexpected exception.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return array_map($this->delayFor(...), range(1, $this->tries()));
    }

    public function handle(Checker $checker): void
    {
        $pending = ModerationDecision::query()->find($this->decisionId);

        if ($pending === null) {
            return;
        }

        try {
            $checker->check($pending);
        } catch (DriverException $e) {
            if ($e->retryable && $this->attempts() < $this->tries()) {
                $this->release($e->retryAfter ?? $this->delayFor($this->attempts()));

                return;
            }

            $checker->failed($pending, $e->getMessage(), $e->retryable);
            $this->fail($e);
        }
    }

    /**
     * Anything other than a driver error (a model that throws, a timeout)
     * still reports the failure.
     */
    public function failed(?Throwable $e): void
    {
        if ($e instanceof DriverException) {
            return;
        }

        $pending = ModerationDecision::query()->find($this->decisionId);

        if ($pending !== null) {
            app(Checker::class)->failed($pending, $e?->getMessage() ?? 'The moderation job failed.', false);
        }
    }

    /**
     * Exponential: backoff, then double each attempt.
     */
    private function delayFor(int $attempt): int
    {
        return self::setting('jev-moderation.backoff', 10) * 2 ** ($attempt - 1);
    }

    private static function setting(string $key, int $default): int
    {
        $value = app(Repository::class)->get($key, $default);

        return is_int($value) && $value > 0 ? $value : $default;
    }
}
