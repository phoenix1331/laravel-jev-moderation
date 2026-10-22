<?php

declare(strict_types=1);

namespace Phoenix1331\JevModeration\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\SerializesModels;
use Phoenix1331\JevModeration\Models\ModerationDecision;

/**
 * The content waits for a human: a review threshold or the confidence floor was reached.
 */
final readonly class ContentFlagged
{
    use SerializesModels;

    public function __construct(
        public Model $model,
        public ModerationDecision $decision,
    ) {}
}
