<?php

declare(strict_types=1);

namespace Phoenix1331\JevModeration\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\SerializesModels;
use Phoenix1331\JevModeration\Models\ModerationDecision;

/**
 * Jev's answer reached no threshold: the content stays up.
 */
final readonly class ContentPassed
{
    use SerializesModels;

    public function __construct(
        public Model $model,
        public ModerationDecision $decision,
    ) {}
}
