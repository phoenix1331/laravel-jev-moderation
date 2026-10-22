<?php

declare(strict_types=1);

arch('no debugging calls are left behind')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'var_export', 'die'])
    ->not->toBeUsed();

arch('every file declares strict types')
    ->expect(['Phoenix1331\JevModeration', 'Phoenix1331\JevModeration\Tests'])
    ->toUseStrictTypes();

arch('php best practices')->preset()->php();

arch('no insecure functions')->preset()->security();
