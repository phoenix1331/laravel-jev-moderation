<?php

declare(strict_types=1);

namespace Phoenix1331\JevModeration\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Phoenix1331\JevModeration\Concerns\Moderatable;

/**
 * Uses the trait but forgot #[Moderate].
 *
 * @property int $id
 */
final class UnmarkedPost extends Model
{
    use Moderatable;

    protected $guarded = [];

    protected $table = 'posts';
}
