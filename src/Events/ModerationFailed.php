<?php

declare(strict_types=1);

namespace Phoenix1331\JevModeration\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\SerializesModels;
use Phoenix1331\JevModeration\Models\ModerationDecision;

/**
 * A check gave up: a failed decision was recorded, and the fail setting
 * decides whether the content is visible. The reason never includes the
 * API key or the content.
 */
final readonly class ModerationFailed
{
    use SerializesModels;

    public function __construct(
        public Model $model,
        public ModerationDecision $decision,
        public string $reason,
        public bool $retryable,
    ) {}
}
