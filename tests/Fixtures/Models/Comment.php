<?php

declare(strict_types=1);

namespace Phoenix1331\JevModeration\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Phoenix1331\JevModeration\Attributes\Moderate;
use Phoenix1331\JevModeration\Concerns\Moderatable;

/**
 * No policy in the attribute, so the default applies; no user relation.
 *
 * @property int $id
 * @property mixed $body
 */
#[Moderate(fields: ['body'])]
final class Comment extends Model
{
    use Moderatable;

    protected $guarded = [];

    protected $table = 'posts';
}
