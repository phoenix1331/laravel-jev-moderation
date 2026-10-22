<?php

declare(strict_types=1);

namespace Phoenix1331\JevModeration\Data;

use InvalidArgumentException;

/**
 * One piece of content and the question to classify it with. Content is a
 * map of field name to value so the classifier sees named fields, not one
 * concatenated string.
 */
final readonly class ModerationRequest
{
    /** Jev accepts at most 255 options per Choice question. */
    public const int MAX_CHOICES = 255;

    /**
     * @param  string  $policy  policy name, for logs and assertions
     * @param  string  $instructions  the question asked about the content
     * @param  array<string, string>  $choices  choice name to description ('' when the name says it all)
     * @param  array<string, string>  $content  field name to value
     * @param  string|null  $context  surrounding material, such as the parent thread
     * @param  string|null  $safeChoice  the choice that means nothing is wrong; not sent to the classifier
     */
    public function __construct(
        public string $policy,
        public string $instructions,
        public array $choices,
        public array $content,
        public ?string $context = null,
        public ?string $safeChoice = null,
    ) {
        if (count($choices) < 2 || count($choices) > self::MAX_CHOICES) {
            throw new InvalidArgumentException(sprintf(
                'Policy [%s] needs between 2 and %d choices, %d given.',
                $policy,
                self::MAX_CHOICES,
                count($choices),
            ));
        }

        if ($safeChoice !== null && ! array_key_exists($safeChoice, $choices)) {
            throw new InvalidArgumentException(sprintf('Policy [%s] safe choice [%s] is not one of its choices.', $policy, $safeChoice));
        }

        if ($content === []) {
            throw new InvalidArgumentException(sprintf('Nothing to moderate for policy [%s]: content has no fields.', $policy));
        }
    }
}
