<?php

declare(strict_types=1);

namespace Phoenix1331\JevModeration\Tests\Accuracy\Support;

use Phoenix1331\JevModeration\Enums\ModerationStatus;

/**
 * One labelled post from tests/Accuracy/Corpus.
 */
final readonly class Sample
{
    /**
     * @param  list<ModerationStatus>  $acceptable  outcomes that are not ideal but not wrong
     * @param  array<string, string>  $content
     */
    public function __construct(
        public string $id,
        public string $label,
        public ModerationStatus $expected,
        public array $acceptable,
        public array $content,
        public ?string $context = null,
        public ?string $note = null,
    ) {}
}
