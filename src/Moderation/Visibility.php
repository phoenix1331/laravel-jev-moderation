<?php

declare(strict_types=1);

namespace Phoenix1331\JevModeration\Moderation;

use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use Phoenix1331\JevModeration\Enums\FailMode;
use Phoenix1331\JevModeration\Enums\ModerationMode;
use Phoenix1331\JevModeration\Enums\ModerationStatus;

/**
 * Which latest statuses leave content visible. Content with no decision at
 * all (saved before install, or with nothing to read) is always visible,
 * so switching to pre mode never hides existing content until a rescan.
 *
 * @internal
 */
final readonly class Visibility
{
    public function __construct(
        public ModerationMode $mode,
        public FailMode $fail,
    ) {}

    public static function fromConfig(Repository $config): self
    {
        return new self(
            self::read($config, 'jev-moderation.mode', ModerationMode::class, 'JEV_MODERATION_MODE'),
            self::read($config, 'jev-moderation.fail', FailMode::class, 'JEV_MODERATION_FAIL'),
        );
    }

    /**
     * @return list<ModerationStatus>
     */
    public function visibleStatuses(): array
    {
        $visible = match ($this->mode) {
            ModerationMode::Post => [ModerationStatus::Pending, ModerationStatus::Passed, ModerationStatus::Flagged, ModerationStatus::Approved],
            ModerationMode::Pre => [ModerationStatus::Passed, ModerationStatus::Approved],
        };

        return $this->fail === FailMode::Open ? [...$visible, ModerationStatus::Failed] : $visible;
    }

    public function allows(?ModerationStatus $status): bool
    {
        return $status === null || in_array($status, $this->visibleStatuses(), true);
    }

    /**
     * @template T of ModerationMode|FailMode
     *
     * @param  class-string<T>  $enum
     * @return T
     */
    private static function read(Repository $config, string $key, string $enum, string $env): ModerationMode|FailMode
    {
        $value = $config->get($key);
        $case = is_string($value) ? $enum::tryFrom($value) : null;

        if ($case === null) {
            throw new InvalidArgumentException(sprintf(
                'Set %s (%s) to one of: %s.',
                $key,
                $env,
                implode(', ', array_map(static fn (ModerationMode|FailMode $case): string => $case->value, $enum::cases())),
            ));
        }

        return $case;
    }
}
