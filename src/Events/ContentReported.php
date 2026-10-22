<?php

declare(strict_types=1);

namespace Phoenix1331\JevModeration\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\SerializesModels;
use Phoenix1331\JevModeration\Models\ModerationDecision;

/**
 * A user reported the content: it waits for a human, unless it was already removed.
 */
final readonly class ContentReported
{
    use SerializesModels;

    public function __construct(
        public Model $model,
        public ModerationDecision $decision,
    ) {}
}
