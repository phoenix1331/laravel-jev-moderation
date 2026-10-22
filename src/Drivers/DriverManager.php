<?php

declare(strict_types=1);

namespace Phoenix1331\JevModeration\Drivers;

use Illuminate\Support\Manager;
use InvalidArgumentException;
use Phoenix1331\JevModeration\Contracts\ModerationDriver;

/**
 * Resolves the configured driver. Custom drivers register with extend().
 *
 * @internal
 */
final class DriverManager extends Manager
{
    public function getDefaultDriver(): string
    {
        $driver = $this->config->get('jev-moderation.driver');

        if (! is_string($driver) || $driver === '') {
            throw new InvalidArgumentException('Set jev-moderation.driver (JEV_MODERATION_DRIVER) to a driver name.');
        }

        return $driver;
    }

    public function createFakeDriver(): FakeDriver
    {
        return new FakeDriver;
    }

    /**
     * @param  string|null  $driver
     */
    public function driver($driver = null): ModerationDriver
    {
        $resolved = parent::driver($driver);

        if (! $resolved instanceof ModerationDriver) {
            throw new InvalidArgumentException(sprintf(
                'Driver [%s] must implement %s, %s given.',
                $driver ?? $this->getDefaultDriver(),
                ModerationDriver::class,
                get_debug_type($resolved),
            ));
        }

        return $resolved;
    }
}
