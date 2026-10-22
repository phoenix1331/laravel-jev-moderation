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
 * Classifies a pending decision and appends the result, or a failed
 * decision when the check gives up. Skips decisions that no longer describe
 * the model: superseded by a newer decision, or content that has changed.
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
        $model = self::moderated($pending);

        if ($model === null || ! method_exists($model, 'toModerationSubject') || ! ($subject = $model->toModerationSubject()) instanceof ModerationSubject) {
            return null;
        }

        if (! $this->isCurrent($pending) || $subject->hash !== $pending->content_hash) {
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

        $model->unsetRelation('latestModeration');

        $this->events->dispatch(match ($verdict->status) {
            ModerationStatus::Blocked => new ContentBlocked($model, $result),
            ModerationStatus::Flagged => new ContentFlagged($model, $result),
            default => new ContentPassed($model, $result),
        });

        return $result;
    }

    /**
     * The check gave up: append a failed decision, so the fail setting
     * decides visibility, and fire ModerationFailed. Does nothing when the
     * pending decision has been superseded or its model is gone.
     */
    public function failed(ModerationDecision $pending, string $reason, bool $retryable): ?ModerationDecision
    {
        $model = self::moderated($pending);

        if ($model === null || ! $this->isCurrent($pending)) {
            return null;
        }

        $failed = ModerationDecision::query()->create([
            ...$pending->only(['moderatable_type', 'moderatable_id', 'source', 'policy', 'policy_version', 'content_hash', 'content']),
            'status' => ModerationStatus::Failed,
            'meta' => ['reason' => $reason, 'retryable' => $retryable],
        ]);

        $model->unsetRelation('latestModeration');
        $this->events->dispatch(new ModerationFailed($model, $failed, $reason, $retryable));

        return $failed;
    }

    /**
     * The moderated model, hidden or not: checks must read content the
     * visibility scope would hide.
     */
    private static function moderated(ModerationDecision $pending): ?Model
    {
        return $pending->moderatable()
            ->createModelByType($pending->moderatable_type)
            ->newQueryWithoutScopes()
            ->find($pending->moderatable_id);
    }

    /**
     * Still pending, and still the model's latest decision.
     */
    private function isCurrent(ModerationDecision $pending): bool
    {
        $latest = ModerationDecision::query()
            ->where('moderatable_type', $pending->moderatable_type)
            ->where('moderatable_id', $pending->moderatable_id)
            ->max('id');

        return $pending->status === ModerationStatus::Pending && $latest === $pending->id;
    }
}
