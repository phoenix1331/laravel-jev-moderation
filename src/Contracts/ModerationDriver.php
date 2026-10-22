<?php

declare(strict_types=1);

namespace Phoenix1331\JevModeration\Contracts;

use Phoenix1331\JevModeration\Data\Classification;
use Phoenix1331\JevModeration\Data\ModerationRequest;
use Phoenix1331\JevModeration\Exceptions\DriverException;

/**
 * Classifies content against one policy question. Implement it to swap Jev
 * for another classifier, and register it with the driver manager's extend().
 */
interface ModerationDriver
{
    /**
     * @throws DriverException when the classifier cannot answer; its retryable flag decides whether the job tries again
     */
    public function classify(ModerationRequest $request): Classification;

    /**
     * Model names the driver can use, for jev-moderation:ping.
     *
     * @return list<string>
     *
     * @throws DriverException
     */
    public function models(): array;
}
