<?php

declare(strict_types=1);

use Phoenix1331\JevModeration\Attributes\Moderate;
use Phoenix1331\JevModeration\Enums\ModerationSource;
use Phoenix1331\JevModeration\Enums\ModerationStatus;
use Phoenix1331\JevModeration\Models\ModerationDecision;
use Phoenix1331\JevModeration\Moderation\ModerationSubject;
use Phoenix1331\JevModeration\Policies\PolicyRegistry;
use Phoenix1331\JevModeration\Tests\Fixtures\Models\ChatMessage;
use Phoenix1331\JevModeration\Tests\Fixtures\Models\Comment;
use Phoenix1331\JevModeration\Tests\Fixtures\Models\ForumPost;
use Phoenix1331\JevModeration\Tests\Fixtures\Models\Post;
use Phoenix1331\JevModeration\Tests\Fixtures\Models\UnmarkedPost;
use Phoenix1331\JevModeration\Tests\Fixtures\Models\User;

function forumPost(array $attributes = []): ForumPost
{
    return ForumPost::query()->create([...['title' => 'Best budget watch?', 'body' => 'Is a Seiko 5 still the go-to?'], ...$attributes]);
}

describe('on create', function (): void {
    it('records a pending decision for the attribute fields', function (): void {
        $post = forumPost();

        $decision = $post->latestModeration;

        expect($decision)->toBeInstanceOf(ModerationDecision::class)
            ->and($decision?->status)->toBe(ModerationStatus::Pending)
            ->and($decision?->source)->toBe(ModerationSource::Jev)
            ->and($decision?->policy)->toBe('community')
            ->and($decision?->policy_version)->toBe(app(PolicyRegistry::class)->get('community')->version)
            ->and($decision?->content_hash)->toBe(ModerationSubject::hash(['title' => 'Best budget watch?', 'body' => 'Is a Seiko 5 still the go-to?']))
            ->and($decision?->content)->toBeNull()
            ->and($decision?->category)->toBeNull();
    });

    it('leaves models without the trait alone', function (): void {
        Post::query()->create(['title' => 'Untouched', 'body' => 'No trait here']);

        expect(ModerationDecision::query()->count())->toBe(0);
    });

    it('skips empty and null fields, and records nothing with nothing to read', function (): void {
        $post = forumPost(['body' => null]);

        expect($post->moderatableContent())->toBe(['title' => 'Best budget watch?']);

        $blank = forumPost(['title' => '   ', 'body' => '']);

        expect($blank->moderationDecisions()->count())->toBe(0);
    });

    it('uses the default policy when the attribute names none', function (): void {
        config(['jev-moderation.policy' => 'strict']);

        $comment = Comment::query()->create(['body' => 'Nice answer']);

        expect($comment->latestModeration?->policy)->toBe('strict');
    });

    it('fails loudly on a policy that does not exist', function (): void {
        config(['jev-moderation.policy' => 'forum']);

        Comment::query()->create(['body' => 'Nice answer']);
    })->throws(InvalidArgumentException::class, 'No moderation policy named [forum].');

    it('keeps a copy of the content only when store_content is on', function (): void {
        config(['jev-moderation.store_content' => true]);

        $post = forumPost(['body' => 'Zürich watches / straps']);

        expect($post->latestModeration?->content)->toBe('{"title":"Best budget watch?","body":"Zürich watches / straps"}');
    });

    it('does nothing while the package is disabled', function (): void {
        config(['jev-moderation.enabled' => false]);

        forumPost();

        expect(ModerationDecision::query()->count())->toBe(0);
    });
});

describe('on update', function (): void {
    it('records a new pending decision when moderated content changes', function (): void {
        $post = forumPost();

        $post->update(['body' => 'Actually, the Orient is better.']);

        expect($post->moderationDecisions()->count())->toBe(2)
            ->and($post->latestModeration()->first()?->content_hash)->toBe(ModerationSubject::hash(['title' => 'Best budget watch?', 'body' => 'Actually, the Orient is better.']));
    });

    it('supersedes a flag when flagged content is edited', function (): void {
        $post = forumPost();
        ModerationDecision::query()->create([
            ...$post->latestModeration?->only(['moderatable_type', 'moderatable_id', 'policy', 'policy_version', 'content_hash']) ?? [],
            'status' => ModerationStatus::Flagged,
            'source' => ModerationSource::Jev,
            'category' => 'harassment',
            'confidence' => 0.44,
        ]);

        $post->update(['body' => 'Sorry, that was rude. The Seiko is fine.']);

        expect($post->latestModeration()->first()?->status)->toBe(ModerationStatus::Pending)
            ->and($post->moderationDecisions()->pluck('status')->all())->toBe([ModerationStatus::Pending, ModerationStatus::Flagged, ModerationStatus::Pending]);
    });

    it('ignores updates that leave the moderated fields alone', function (): void {
        $post = forumPost();

        $post->update(['views' => 42]);
        $post->touch();

        expect($post->moderationDecisions()->count())->toBe(1);
    });

    it('ignores a change that produces the same content', function (): void {
        $message = ChatMessage::query()->create(['room' => 'general', 'message' => 'hello']);

        $message->update(['message' => '  hello  ']);

        expect($message->moderationDecisions()->count())->toBe(1);
    });

    it('re-checks content changed back to an earlier version', function (): void {
        $post = forumPost();

        $post->update(['body' => 'Edited']);
        $post->update(['body' => 'Is a Seiko 5 still the go-to?']);

        expect($post->moderationDecisions()->count())->toBe(3);
    });

    it('does not count field order as a change', function (): void {
        expect(ModerationSubject::hash(['title' => 'A', 'body' => 'B']))->toBe(ModerationSubject::hash(['body' => 'B', 'title' => 'A']));
    });

    it('skips saveQuietly, as with any model event', function (): void {
        $post = forumPost();

        $post->body = 'Quietly edited';
        $post->saveQuietly();

        expect($post->moderationDecisions()->count())->toBe(1);
    });
});

describe('overrides', function (): void {
    it('uses overridden content, context, policy and author', function (): void {
        $sender = User::query()->create(['name' => 'Sam']);

        $message = ChatMessage::query()->create(['room' => 'general', 'message' => ' gg, rematch? ', 'sender_id' => $sender->id]);
        $subject = $message->toModerationSubject();

        expect($subject->content)->toBe(['message' => 'gg, rematch?'])
            ->and($subject->context)->toBe('Room: general')
            ->and($subject->policy)->toBe('chat')
            ->and($subject->fields)->toBeNull()
            ->and($message->latestModeration?->policy)->toBe('chat')
            ->and($message->moderationAuthor()?->is($sender))->toBeTrue();
    });

    it('reads the attribute fields when content is not overridden', function (): void {
        expect(forumPost()->toModerationSubject()->fields)->toBe(['title', 'body']);
    });

    it('defaults to no context', function (): void {
        expect(forumPost()->moderationContext())->toBeNull();
    });

    it('finds the author through a user relation', function (): void {
        $author = User::query()->create(['name' => 'Alex']);

        expect(forumPost(['user_id' => $author->id])->moderationAuthor()?->is($author))->toBeTrue()
            ->and(forumPost()->moderationAuthor())->toBeNull()
            ->and(Comment::query()->create(['body' => 'Hi'])->moderationAuthor())->toBeNull();
    });
});

describe('content', function (): void {
    it('converts numbers and Stringables to text', function (): void {
        $comment = new Comment(['body' => 42]);

        expect($comment->moderatableContent())->toBe(['body' => '42']);

        $comment->body = str('Stringable body');

        expect($comment->moderatableContent())->toBe(['body' => 'Stringable body']);
    });

    it('refuses a field that is not text', function (): void {
        (new Comment(['body' => ['an', 'array']]))->moderatableContent();
    })->throws(InvalidArgumentException::class, 'Moderated field [body] on ['.Comment::class.'] must be text, array given. Override moderatableContent() to format it.');

    it('explains a missing #[Moderate] attribute', function (): void {
        UnmarkedPost::query()->create(['title' => 'Oops']);
    })->throws(LogicException::class, '['.UnmarkedPost::class.'] uses Moderatable but has no #[Moderate(fields: [...])] attribute. Add one, or override moderatableContent().');

    it('needs at least one field in the attribute', function (): void {
        new Moderate([]);
    })->throws(InvalidArgumentException::class, '#[Moderate] needs at least one field.');
});

describe('relations', function (): void {
    it('lists decisions oldest first and points latestModeration at the newest', function (): void {
        $post = forumPost();
        $post->update(['body' => 'Second']);
        $post->update(['body' => 'Third']);

        $ids = $post->moderationDecisions()->pluck('id')->all();

        expect($ids)->toBe(collect($ids)->sort()->values()->all())
            ->and($post->latestModeration()->first()?->id)->toBe(end($ids));
    });

    it('eager loads the latest decision per model', function (): void {
        forumPost()->update(['body' => 'Edited']);
        forumPost(['title' => 'Another']);

        $posts = ForumPost::query()->with('latestModeration')->get();

        expect($posts->map(fn (ForumPost $post): ?int => $post->latestModeration?->id)->all())
            ->toBe([2, 3]);
    });
});
