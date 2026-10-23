<?php

declare(strict_types=1);

namespace Phoenix1331\JevModeration\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Phoenix1331\JevModeration\Enums\ModerationSource;
use Phoenix1331\JevModeration\Enums\ModerationStatus;
use Phoenix1331\JevModeration\Models\ModerationDecision;

/**
 * Realistic decisions for tests, demos and the Filament review queue. Needs
 * no Faker, so apps can seed with it outside dev dependencies.
 *
 *     ModerationDecisionFactory::new()->for($post, 'moderatable')->flagged('spam', 0.72)->create();
 *     ModerationDecisionFactory::new()->forModel($post)->rejected($moderator, 'confirmed spam')->create();
 *
 * @extends Factory<ModerationDecision>
 */
final class ModerationDecisionFactory extends Factory
{
    /** @var class-string<ModerationDecision> */
    protected $model = ModerationDecision::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'moderatable_type' => 'App\\Models\\Post',
            'moderatable_id' => 1,
            'status' => ModerationStatus::Passed,
            'source' => ModerationSource::Jev,
            'policy' => 'community',
            'policy_version' => hash('sha256', 'community'),
            'model' => 'jev-1.14.0',
            'category' => 'fine',
            'confidence' => 0.98,
            'content_hash' => hash('sha256', 'Is a Seiko 5 still the go-to under 200?'),
            'content' => null,
            'reviewed_by_type' => null,
            'reviewed_by_id' => null,
            'note' => null,
            'meta' => ['driver' => 'jev', 'latency_ms' => 420, 'input_tokens' => 490],
        ];
    }

    /**
     * Point the decision at an existing model.
     */
    public function forModel(Model $model): self
    {
        return $this->state([
            'moderatable_type' => $model->getMorphClass(),
            'moderatable_id' => $model->getKey(),
        ]);
    }

    /**
     * Saved, waiting for Jev.
     */
    public function pending(): self
    {
        return $this->state([
            'status' => ModerationStatus::Pending,
            'model' => null,
            'category' => null,
            'confidence' => null,
            'meta' => null,
        ]);
    }

    /**
     * The check gave up.
     */
    public function failed(string $reason = 'Jev returned HTTP 529: no details', bool $retryable = true): self
    {
        return $this->pending()->state([
            'status' => ModerationStatus::Failed,
            'meta' => ['reason' => $reason, 'retryable' => $retryable],
        ]);
    }

    public function passed(string $category = 'fine', float $confidence = 0.98): self
    {
        return $this->classified(ModerationStatus::Passed, $category, $confidence);
    }

    public function flagged(string $category = 'harassment', float $confidence = 0.72): self
    {
        return $this->classified(ModerationStatus::Flagged, $category, $confidence);
    }

    public function blocked(string $category = 'spam', float $confidence = 0.97): self
    {
        return $this->classified(ModerationStatus::Blocked, $category, $confidence);
    }

    /**
     * Flagged by a user report rather than by Jev.
     */
    public function reported(string $reason = 'reported by user', ?Model $reporter = null): self
    {
        return $this->pending()
            ->reviewedBy($reporter)
            ->state([
                'status' => ModerationStatus::Flagged,
                'source' => ModerationSource::Report,
                'note' => $reason,
            ]);
    }

    /**
     * A moderator overrode the flag or block.
     */
    public function approved(?Model $moderator = null, ?string $note = 'false positive'): self
    {
        return $this->human(ModerationStatus::Approved, $moderator, $note);
    }

    /**
     * A moderator confirmed the flag or block, optionally reclassifying it
     * under a corrected category.
     */
    public function rejected(?Model $moderator = null, ?string $note = 'confirmed', ?string $category = null): self
    {
        return $this->human(ModerationStatus::Rejected, $moderator, $note)->state(['category' => $category]);
    }

    /**
     * Record who reviewed or reported. Without a model, an example user.
     */
    public function reviewedBy(?Model $reviewer): self
    {
        return $this->state([
            'reviewed_by_type' => $reviewer?->getMorphClass() ?? 'App\\Models\\User',
            'reviewed_by_id' => $reviewer?->getKey() ?? 1,
        ]);
    }

    /**
     * Keep a copy of the content, as when store_content is on.
     */
    public function withContent(string $content): self
    {
        return $this->state([
            'content' => $content,
            'content_hash' => hash('sha256', $content),
        ]);
    }

    private function classified(ModerationStatus $status, string $category, float $confidence): self
    {
        return $this->state([
            'status' => $status,
            'source' => ModerationSource::Jev,
            'category' => $category,
            'confidence' => $confidence,
        ]);
    }

    private function human(ModerationStatus $status, ?Model $moderator, ?string $note): self
    {
        return $this->pending()
            ->reviewedBy($moderator)
            ->state([
                'status' => $status,
                'source' => ModerationSource::Human,
                'note' => $note,
            ]);
    }
}
