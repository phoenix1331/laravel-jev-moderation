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

    public function createJevDriver(): JevDriver
    {
        $key = $this->config->get('jev-moderation.drivers.jev.key');

        return new JevDriver(
            key: is_string($key) && trim($key) !== '' ? trim($key) : null,
            model: $this->string('jev-moderation.drivers.jev.model', 'jev-latest'),
            baseUrl: $this->string('jev-moderation.drivers.jev.base_url', 'https://api.typesafe.ai/v1'),
            timeout: $this->positiveInteger('jev-moderation.drivers.jev.timeout', 5),
            maxContextTokens: $this->positiveInteger('jev-moderation.max_context_tokens', 24000),
        );
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

    private function string(string $key, string $default): string
    {
        $value = $this->config->get($key, $default);

        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException(sprintf('Set %s to a non-empty string, %s given.', $key, get_debug_type($value)));
        }

        return $value;
    }

    private function positiveInteger(string $key, int $default): int
    {
        $value = $this->config->get($key, $default);

        if (! is_int($value) || $value < 1) {
            throw new InvalidArgumentException(sprintf('Set %s to a positive whole number, %s given.', $key, is_int($value) ? $value : get_debug_type($value)));
        }

        return $value;
    }
}
