<?php

declare(strict_types=1);

namespace Phoenix1331\JevModeration\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $title
 * @property string $body
 */
final class Post extends Model
{
    protected $guarded = [];
}
