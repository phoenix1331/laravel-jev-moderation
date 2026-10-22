<?php

declare(strict_types=1);

namespace Phoenix1331\JevModeration\Moderation;

use Illuminate\Contracts\Config\Repository;
use Phoenix1331\JevModeration\Enums\ModerationSource;
use Phoenix1331\JevModeration\Enums\ModerationStatus;
use Phoenix1331\JevModeration\Models\ModerationDecision;
use Phoenix1331\JevModeration\Policies\PolicyRegistry;

/**
 * Decides whether a save needs a check and records it as pending. Shared by
 * every Moderatable model.
 *
 * @internal
 */
final readonly class Moderator
{
    public function __construct(
        private Repository $config,
        private PolicyRegistry $policies,
    ) {}

    /**
     * @return ModerationDecision|null the pending decision, or null when nothing needs checking
     */
    public function saved(ModerationSubject $subject, bool $created): ?ModerationDecision
    {
        if ($this->config->get('jev-moderation.enabled', true) === false) {
            return null;
        }

        // Fast path: an update that touched none of the #[Moderate] fields.
        if (! $created && $subject->fields !== null && ! $subject->model->wasChanged($subject->fields)) {
            return null;
        }

        // Nothing to read; an earlier decision, if any, still stands.
        if ($subject->content === []) {
            return null;
        }

        if ($this->latestHash($subject) === $subject->hash) {
            return null;
        }

        $policy = $this->policies->get($subject->policy);

        return ModerationDecision::query()->create([
            'moderatable_type' => $subject->model->getMorphClass(),
            'moderatable_id' => $subject->model->getKey(),
            'status' => ModerationStatus::Pending,
            'source' => ModerationSource::Jev,
            'policy' => $policy->name,
            'policy_version' => $policy->version,
            'content_hash' => $subject->hash,
            'content' => $this->config->get('jev-moderation.store_content') === true
                ? json_encode($subject->content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null,
        ]);
    }

    private function latestHash(ModerationSubject $subject): mixed
    {
        return ModerationDecision::query()
            ->where('moderatable_type', $subject->model->getMorphClass())
            ->where('moderatable_id', $subject->model->getKey())
            ->latest('id')
            ->value('content_hash');
    }
}
