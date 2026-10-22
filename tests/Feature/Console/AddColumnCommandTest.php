<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Phoenix1331\JevModeration\Database\Factories\ModerationDecisionFactory;
use Phoenix1331\JevModeration\Enums\ModerationStatus;
use Phoenix1331\JevModeration\Exceptions\DriverException;
use Phoenix1331\JevModeration\Support\StatusColumn;
use Phoenix1331\JevModeration\Tests\Fixtures\Models\ChatMessage;
use Phoenix1331\JevModeration\Tests\Fixtures\Models\ForumPost;

beforeEach(function (): void {
    $this->migrations = sys_get_temp_dir().'/jev-moderation-'.bin2hex(random_bytes(4));
});

afterEach(function (): void {
    File::deleteDirectory($this->migrations);
    Relation::morphMap([], false);
});

function generatedMigration(string $directory): string
{
    $files = glob("{$directory}/*_add_moderation_status_to_forum_posts_table.php") ?: [];

    expect($files)->toHaveCount(1);

    return $files[0];
}

function statuses(): array
{
    return ForumPost::withoutModeration()->orderBy('id')->pluck(StatusColumn::NAME, 'title')->all();
}

describe('command', function (): void {
    it('writes a migration for the table', function (): void {
        $this->artisan('jev-moderation:add-column', ['table' => 'forum_posts', '--path' => $this->migrations])
            ->expectsOutputToContain('Migration created')
            ->expectsOutputToContain('Restart queue workers')
            ->assertSuccessful();

        $path = generatedMigration($this->migrations);

        expect(basename($path))->toMatch('/^\d{4}_\d{2}_\d{2}_\d{6}_add_moderation_status_to_forum_posts_table\.php$/')
            ->and(file_get_contents($path))->toContain("Schema::table('forum_posts'")
            ->toContain("StatusColumn::backfill('forum_posts')")
            ->toContain('declare(strict_types=1);')
            ->not->toContain('{{ table }}');
    });

    it('writes to database/migrations by default', function (): void {
        $this->app->useDatabasePath($this->migrations);

        $this->artisan('jev-moderation:add-column', ['table' => 'forum_posts'])->assertSuccessful();

        generatedMigration("{$this->migrations}/migrations");
    });

    it('resolves a relative path from the app root', function (): void {
        $this->app->setBasePath($this->migrations);

        $this->artisan('jev-moderation:add-column', ['table' => 'forum_posts', '--path' => 'database/extra/'])->assertSuccessful();

        generatedMigration("{$this->migrations}/database/extra");
    });

    it('refuses a second migration for the same table', function (): void {
        $this->artisan('jev-moderation:add-column', ['table' => 'forum_posts', '--path' => $this->migrations])->assertSuccessful();

        $this->artisan('jev-moderation:add-column', ['table' => 'forum_posts', '--path' => $this->migrations])
            ->expectsOutputToContain('already exists')
            ->assertFailed();
    });

    it('refuses a table name that is not a plain identifier', function (string $table): void {
        $this->artisan('jev-moderation:add-column', ['table' => $table, '--path' => $this->migrations])
            ->expectsOutputToContain('may only contain letters, numbers and underscores')
            ->assertFailed();

        expect(is_dir($this->migrations))->toBeFalse();
    })->with(['forum-posts', "posts'; drop table users; --", '1posts']);
});

describe('generated migration', function (): void {
    it('adds an indexed column filled from each row\'s latest decision', function (): void {
        Queue::fake();
        $checked = ForumPost::query()->create(['title' => 'checked', 'body' => 'Body']);
        ModerationDecisionFactory::new()->forModel($checked)->flagged()->create();
        $rejected = ForumPost::query()->create(['title' => 'rejected', 'body' => 'Body']);
        ModerationDecisionFactory::new()->forModel($rejected)->blocked()->create();
        ModerationDecisionFactory::new()->forModel($rejected)->rejected()->create();
        (new ForumPost(['title' => 'never checked', 'body' => 'Body']))->saveQuietly();
        // Same id as the first post, different model: must not leak across tables.
        ChatMessage::query()->create(['room' => 'general', 'message' => 'hi']);

        $this->artisan('jev-moderation:add-column', ['table' => 'forum_posts', '--path' => $this->migrations]);
        $migration = require generatedMigration($this->migrations);
        $migration->up();

        expect(Schema::hasColumn('forum_posts', StatusColumn::NAME))->toBeTrue()
            ->and(collect(Schema::getIndexes('forum_posts'))->pluck('columns')->all())->toContain([StatusColumn::NAME])
            ->and(statuses())->toBe(['checked' => 'flagged', 'rejected' => 'rejected', 'never checked' => null]);
    });

    it('backfills models stored under a morph map alias', function (): void {
        Relation::morphMap(['forum_post' => ForumPost::class]);
        Queue::fake();
        ForumPost::query()->create(['title' => 'aliased', 'body' => 'Body']);

        $this->artisan('jev-moderation:add-column', ['table' => 'forum_posts', '--path' => $this->migrations]);
        (require generatedMigration($this->migrations))->up();

        expect(statuses())->toBe(['aliased' => 'pending']);
    });

    it('skips decisions for classes that no longer exist', function (): void {
        ModerationDecisionFactory::new()->state(['moderatable_type' => 'App\\Models\\Gone'])->create();

        $this->artisan('jev-moderation:add-column', ['table' => 'forum_posts', '--path' => $this->migrations]);
        (require generatedMigration($this->migrations))->up();

        expect(Schema::hasColumn('forum_posts', StatusColumn::NAME))->toBeTrue();
    });

    it('rolls back cleanly', function (): void {
        $this->artisan('jev-moderation:add-column', ['table' => 'forum_posts', '--path' => $this->migrations]);
        $migration = require generatedMigration($this->migrations);

        $migration->up();
        expect(StatusColumn::exists(new ForumPost))->toBeTrue();

        $migration->down();
        expect(Schema::hasColumn('forum_posts', StatusColumn::NAME))->toBeFalse()
            ->and(StatusColumn::exists(new ForumPost))->toBeFalse();
    });
});

describe('sync', function (): void {
    beforeEach(fn () => withStatusColumn());

    it('copies every new decision onto the row', function (): void {
        moderationFake()->classifyAs('fine');
        $post = ForumPost::query()->create(['title' => 'post', 'body' => 'Body']);
        expect(statuses())->toBe(['post' => 'passed']);

        $post->flagForReview('reported');
        expect(statuses())->toBe(['post' => 'flagged']);

        $post->approve();
        expect(statuses())->toBe(['post' => 'approved']);

        $post->reject();
        expect(statuses())->toBe(['post' => 'rejected']);

        moderationFake()->failWith(DriverException::permanent('bad key'));
        $post->moderateNow();
        expect(statuses())->toBe(['post' => 'failed']);
    });

    it('records pending while the check is queued', function (): void {
        Queue::fake();

        ForumPost::query()->create(['title' => 'queued', 'body' => 'Body']);

        expect(statuses())->toBe(['queued' => 'pending']);
    });

    it('leaves the host row\'s updated_at alone', function (): void {
        Queue::fake();
        $this->travelTo(now()->subDay());
        $post = ForumPost::query()->create(['title' => 'post', 'body' => 'Body']);
        $this->travelBack();

        $post->approve();

        expect(ForumPost::withoutModeration()->find($post->id)?->updated_at?->isYesterday())->toBeTrue();
    });

    it('ignores tables without the column', function (): void {
        ChatMessage::query()->create(['room' => 'general', 'message' => 'hi']);

        expect(Schema::hasColumn('chat_messages', StatusColumn::NAME))->toBeFalse()
            ->and(ChatMessage::query()->count())->toBe(1);
    });
});

describe('query shape', function (): void {
    it('joins decisions without the column', function (): void {
        $sql = ForumPost::query()->toSql();

        expect($sql)->toContain('exists')->not->toContain(StatusColumn::NAME);
    });

    it('reads the column when it exists', function (): void {
        withStatusColumn();

        // Quotes differ by grammar (MySQL uses backticks), so compare without them.
        $sql = str_replace(['"', '`'], '', ForumPost::query()->toSql());
        $filtered = str_replace(['"', '`'], '', ForumPost::whereModerationStatus(ModerationStatus::Flagged)->toSql());

        expect($sql)->toContain('forum_posts.moderation_status is null')
            ->not->toContain('exists')
            ->and($filtered)->toContain('forum_posts.moderation_status in')
            ->not->toContain('exists');
    });

    it('filters by status through the column', function (): void {
        withStatusColumn();
        Queue::fake();
        $flagged = ForumPost::query()->create(['title' => 'flagged', 'body' => 'Body']);
        ModerationDecisionFactory::new()->forModel($flagged)->flagged()->create();
        ForumPost::query()->create(['title' => 'pending', 'body' => 'Body']);

        expect(ForumPost::onlyFlagged()->pluck('title')->all())->toBe(['flagged']);
    });
});
