<?php

declare(strict_types=1);

namespace Phoenix1331\JevModeration\Policies;

use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use Phoenix1331\JevModeration\Data\Policy;

/**
 * The policies in config('jev-moderation.policies'). Parsed on every call,
 * which is cheap, so runtime config() changes (tests, tenants) apply.
 *
 * @internal
 */
final readonly class PolicyRegistry
{
    public function __construct(private Repository $config) {}

    public function get(?string $name = null): Policy
    {
        $name ??= $this->defaultName();
        $config = $this->config->get("jev-moderation.policies.{$name}");

        if ($config === null) {
            throw new InvalidArgumentException(sprintf(
                'No moderation policy named [%s]. Defined: %s.',
                $name,
                implode(', ', $this->names()) ?: 'none',
            ));
        }

        return Policy::fromConfig($name, $config);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        $policies = $this->config->get('jev-moderation.policies');

        return is_array($policies) ? array_values(array_filter(array_keys($policies), is_string(...))) : [];
    }

    public function defaultName(): string
    {
        $name = $this->config->get('jev-moderation.policy');

        if (! is_string($name) || $name === '') {
            throw new InvalidArgumentException('Set jev-moderation.policy (JEV_MODERATION_POLICY) to a policy name.');
        }

        return $name;
    }
}
