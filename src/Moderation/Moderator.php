<?php

declare(strict_types=1);

namespace Phoenix1331\JevModeration\Moderation;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Phoenix1331\JevModeration\Enums\ModerationSource;
use Phoenix1331\JevModeration\Enums\ModerationStatus;
use Phoenix1331\JevModeration\Events\ContentPending;
use Phoenix1331\JevModeration\Exceptions\DriverException;
use Phoenix1331\JevModeration\Jobs\ModerateContent;
use Phoenix1331\JevModeration\Models\ModerationDecision;
use Phoenix1331\JevModeration\Policies\PolicyRegistry;

/**
 * Decides whether a save needs a check, records it as pending and hands it
 * to the queue after commit, or to the Checker inline when sync is on.
 * Shared by every Moderatable model.
 *
 * @internal
 */
final readonly class Moderator
{
    public function __construct(
        private Repository $config,
        private PolicyRegistry $policies,
        private Checker $checker,
        private Dispatcher $events,
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

        $pending = ModerationDecision::query()->create([
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

        $subject->model->unsetRelation('latestModeration');
        $this->events->dispatch(new ContentPending($subject->model, $pending));

        if ($this->config->get('jev-moderation.sync') === true) {
            $this->checkNow($pending);

            return $pending;
        }

        $job = new ModerateContent($pending->id);
        $connection = $this->config->get('jev-moderation.connection');
        $queue = $this->config->get('jev-moderation.queue');

        dispatch($job->onConnection(is_string($connection) && $connection !== '' ? $connection : null)
            ->onQueue(is_string($queue) && $queue !== '' ? $queue : null)
            ->afterCommit());

        return $pending;
    }

    /**
     * Inline: no retries, so a request is never held up by backoff.
     */
    private function checkNow(ModerationDecision $pending): void
    {
        try {
            $this->checker->check($pending);
        } catch (DriverException $e) {
            $this->checker->failed($pending, $e->getMessage(), $e->retryable);
        }
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
