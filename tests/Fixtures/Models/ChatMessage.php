<?php

declare(strict_types=1);

namespace Phoenix1331\JevModeration\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Phoenix1331\JevModeration\Concerns\Moderatable;

/**
 * Every override, no attribute: content trimmed, context from the room,
 * policy and author chosen in code.
 *
 * @property int $id
 * @property string $room
 * @property string $message
 * @property int|null $sender_id
 */
final class ChatMessage extends Model
{
    use Moderatable;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    public function moderatableContent(): array
    {
        return ['message' => trim($this->message)];
    }

    public function moderationContext(): string
    {
        return "Room: {$this->room}";
    }

    public function moderationPolicy(): string
    {
        return 'chat';
    }

    public function moderationAuthor(): ?Model
    {
        return User::query()->find($this->sender_id);
    }
}
