<?php

declare(strict_types=1);

namespace Phoenix1331\JevModeration\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Phoenix1331\JevModeration\Attributes\Moderate;
use Phoenix1331\JevModeration\Concerns\Moderatable;

/**
 * The README example: moderated by attribute alone.
 *
 * @property int $id
 * @property string $title
 * @property string|null $body
 * @property int|null $views
 * @property int|null $user_id
 */
#[Moderate(fields: ['title', 'body'], policy: 'community')]
final class ForumPost extends Model
{
    use Moderatable;

    protected $guarded = [];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
