<?php

declare(strict_types=1);

namespace Phoenix1331\JevModeration\Attributes;

use Attribute;
use InvalidArgumentException;

/**
 * Marks a Moderatable model's fields and policy:
 *
 *     #[Moderate(fields: ['title', 'body'], policy: 'community')]
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Moderate
{
    /**
     * @param  list<string>  $fields  attributes sent to the classifier, by name
     * @param  string|null  $policy  a policy from config; null uses jev-moderation.policy
     */
    public function __construct(
        public array $fields,
        public ?string $policy = null,
    ) {
        if ($fields === []) {
            throw new InvalidArgumentException('#[Moderate] needs at least one field.');
        }
    }
}
