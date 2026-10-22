<?php

declare(strict_types=1);

namespace Phoenix1331\JevModeration\Moderation;

use Illuminate\Database\Eloquent\Model;

/**
 * What a Moderatable model asks to have checked, read once per save.
 *
 * @internal
 */
final readonly class ModerationSubject
{
    /**
     * @param  array<string, string>  $content  field name to value; empty when there is nothing to check
     * @param  list<string>|null  $fields  the #[Moderate] fields, or null when moderatableContent() is overridden
     */
    public function __construct(
        public Model $model,
        public string $policy,
        public array $content,
        public ?string $context,
        public string $hash,
        public ?array $fields,
    ) {}

    /**
     * sha256 of the content with keys sorted, so reordering fields in the
     * attribute does not count as a change.
     *
     * @param  array<string, string>  $content
     */
    public static function hash(array $content): string
    {
        ksort($content);

        return hash('sha256', json_encode($content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
