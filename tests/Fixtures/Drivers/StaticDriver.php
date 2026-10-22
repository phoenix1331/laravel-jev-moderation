<?php

declare(strict_types=1);

namespace Phoenix1331\JevModeration\Tests\Fixtures\Drivers;

use Phoenix1331\JevModeration\Contracts\ModerationDriver;
use Phoenix1331\JevModeration\Data\Classification;
use Phoenix1331\JevModeration\Data\ModerationRequest;

/**
 * A custom driver that always answers with its first choice.
 */
final class StaticDriver implements ModerationDriver
{
    public function classify(ModerationRequest $request): Classification
    {
        $choice = array_key_first($request->choices);

        return new Classification(choice: $choice, confidence: 1.0, probabilities: [$choice => 1.0], driver: 'static');
    }

    public function models(): array
    {
        return ['static-1'];
    }
}
