<?php

declare(strict_types=1);

namespace Phoenix1331\JevModeration\Moderation;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Phoenix1331\JevModeration\Data\Policy;
use Phoenix1331\JevModeration\Enums\ModerationSource;
use Phoenix1331\JevModeration\Enums\ModerationStatus;
use Phoenix1331\JevModeration\Events\ContentReported;
use Phoenix1331\JevModeration\Events\ReviewResolved;
use Phoenix1331\JevModeration\Models\ModerationDecision;
use Phoenix1331\JevModeration\Policies\PolicyRegistry;

/**
 * Records people's decisions: user reports and moderator reviews. Each adds
 * a row; Jev's decisions are never changed. Works while automatic
 * moderation is disabled, because these are explicit calls.
 *
 * @internal
 */
final readonly class Reviewer
{
    /** A report never brings removed content back into view. */
    private const array REMOVED = [ModerationStatus::Blocked, ModerationStatus::Rejected];

    public function __construct(
        private PolicyRegistry $policies,
        private Dispatcher $events,
    ) {}

    public function report(ModerationSubject $subject, string $reason, ?Model $reporter): ModerationDecision
    {
        $current = $this->latestStatus($subject->model);
        $status = in_array($current, self::REMOVED, true) ? $current : ModerationStatus::Flagged;

        $policy = $this->policies->get($subject->policy);
        $decision = $this->record($subject, $policy, $status, ModerationSource::Report, $reporter, $reason);
        $this->events->dispatch(new ContentReported($subject->model, $decision));

        return $decision;
    }

    /**
     * @param  string|null  $category  the moderator's category, a non-safe choice of the record's policy
     *
     * @throws InvalidArgumentException when the category is not one of the policy's harmful choices; nothing is written
     */
    public function resolve(ModerationSubject $subject, ModerationStatus $status, ?Model $moderator, ?string $note, ?string $category = null): ModerationDecision
    {
        $policy = $this->policies->get($subject->policy);

        if ($category !== null) {
            $this->ensureHarmfulChoice($policy, $category);
        }

        $decision = $this->record($subject, $policy, $status, ModerationSource::Human, $moderator, $note, $category);
        $this->events->dispatch(new ReviewResolved($subject->model, $decision));

        return $decision;
    }

    private function record(ModerationSubject $subject, Policy $policy, ModerationStatus $status, ModerationSource $source, ?Model $by, ?string $note, ?string $category = null): ModerationDecision
    {
        $decision = ModerationDecision::query()->create([
            'moderatable_type' => $subject->model->getMorphClass(),
            'moderatable_id' => $subject->model->getKey(),
            'status' => $status,
            'source' => $source,
            'policy' => $policy->name,
            'policy_version' => $policy->version,
            'content_hash' => $subject->hash,
            'category' => $category,
            'reviewed_by_type' => $by?->getMorphClass(),
            'reviewed_by_id' => $by?->getKey(),
            'note' => $note,
        ]);

        $subject->model->unsetRelation('latestModeration');

        return $decision;
    }

    private function ensureHarmfulChoice(Policy $policy, string $category): void
    {
        $allowed = array_values(array_filter(
            array_keys($policy->choices),
            static fn (string $choice): bool => $choice !== $policy->safeChoice,
        ));

        if (in_array($category, $allowed, true)) {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            'Category [%s] is not a harmful choice of moderation policy [%s]. Allowed: %s.',
            $category,
            $policy->name,
            implode(', ', $allowed),
        ));
    }

    private function latestStatus(Model $model): ?ModerationStatus
    {
        return ModerationDecision::query()
            ->where('moderatable_type', $model->getMorphClass())
            ->where('moderatable_id', $model->getKey())
            ->latest('id')
            ->first()
            ?->status;
    }
}
