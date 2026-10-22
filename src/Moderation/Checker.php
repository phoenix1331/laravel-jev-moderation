<?php

declare(strict_types=1);

namespace Phoenix1331\JevModeration\Moderation;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Phoenix1331\JevModeration\Contracts\ModerationDriver;
use Phoenix1331\JevModeration\Enums\ModerationStatus;
use Phoenix1331\JevModeration\Events\ContentBlocked;
use Phoenix1331\JevModeration\Events\ContentFlagged;
use Phoenix1331\JevModeration\Events\ContentPassed;
use Phoenix1331\JevModeration\Events\ModerationFailed;
use Phoenix1331\JevModeration\Exceptions\DriverException;
use Phoenix1331\JevModeration\Models\ModerationDecision;
use Phoenix1331\JevModeration\Policies\PolicyRegistry;
use Phoenix1331\JevModeration\Policies\ThresholdResolver;

/**
 * Classifies a pending decision and appends the result. Skips decisions
 * that no longer describe the model: superseded by a newer decision, or
 * content that has changed since.
 *
 * @internal
 */
final readonly class Checker
{
    public function __construct(
        private ModerationDriver $driver,
        private PolicyRegistry $policies,
        private ThresholdResolver $resolver,
        private Dispatcher $events,
    ) {}

    /**
     * @return ModerationDecision|null the result, or null when there was nothing to check
     *
     * @throws DriverException
     */
    public function check(ModerationDecision $pending): ?ModerationDecision
    {
        $model = $pending->moderatable;

        if (! $model instanceof Model || ! method_exists($model, 'toModerationSubject') || ! ($subject = $model->toModerationSubject()) instanceof ModerationSubject) {
            return null;
        }

        if ($pending->status !== ModerationStatus::Pending || $subject->hash !== $pending->content_hash || $this->latestId($pending) !== $pending->id) {
            return null;
        }

        $policy = $this->policies->get($pending->policy);
        $classification = $this->driver->classify($policy->request($subject->content, $subject->context));
        $verdict = $this->resolver->resolve($policy, $classification);

        $result = ModerationDecision::query()->create([
            'moderatable_type' => $pending->moderatable_type,
            'moderatable_id' => $pending->moderatable_id,
            'status' => $verdict->status,
            'source' => $pending->source,
            'policy' => $policy->name,
            'policy_version' => $policy->version,
            'model' => $classification->model,
            'category' => $verdict->category,
            'confidence' => $verdict->probability,
            'content_hash' => $pending->content_hash,
            'content' => $pending->content,
            'meta' => [
                'reason' => $verdict->reason->value,
                'choice' => $classification->choice,
                'jev_confidence' => $classification->confidence,
                'probabilities' => $classification->probabilities,
                'driver' => $classification->driver,
                'latency_ms' => $classification->latencyMs,
                'input_tokens' => $classification->inputTokens,
                'request_id' => $classification->requestId,
                'truncated' => $classification->truncated,
            ],
        ]);

        $this->events->dispatch(match ($verdict->status) {
            ModerationStatus::Blocked => new ContentBlocked($model, $result),
            ModerationStatus::Flagged => new ContentFlagged($model, $result),
            default => new ContentPassed($model, $result),
        });

        return $result;
    }

    /**
     * The check gave up: fire ModerationFailed for the model, if it still exists.
     */
    public function failed(ModerationDecision $pending, string $reason, bool $retryable): void
    {
        $model = $pending->moderatable;

        if ($model instanceof Model) {
            $this->events->dispatch(new ModerationFailed($model, $pending, $reason, $retryable));
        }
    }

    private function latestId(ModerationDecision $pending): mixed
    {
        return ModerationDecision::query()
            ->where('moderatable_type', $pending->moderatable_type)
            ->where('moderatable_id', $pending->moderatable_id)
            ->max('id');
    }
}
