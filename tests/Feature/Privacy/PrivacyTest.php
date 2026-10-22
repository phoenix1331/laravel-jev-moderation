<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Phoenix1331\JevModeration\Data\ModerationRequest;
use Phoenix1331\JevModeration\Drivers\DriverManager;
use Phoenix1331\JevModeration\Events\ContentBlocked;
use Phoenix1331\JevModeration\Events\ContentFlagged;
use Phoenix1331\JevModeration\Events\ContentPassed;
use Phoenix1331\JevModeration\Events\ContentPending;
use Phoenix1331\JevModeration\Events\ModerationFailed;
use Phoenix1331\JevModeration\Exceptions\DriverException;
use Phoenix1331\JevModeration\Jobs\ModerateContent;
use Phoenix1331\JevModeration\Models\ModerationDecision;
use Phoenix1331\JevModeration\Tests\Fixtures\Models\ChatMessage;
use Phoenix1331\JevModeration\Tests\Fixtures\Models\ForumPost;
use Phoenix1331\JevModeration\Tests\Fixtures\Models\User;

/*
 * The claims in SECURITY.md: with store_content off (the default), the
 * package keeps a hash, never the text; nothing it queues, fires or stores
 * carries the text; Jev receives only the #[Moderate] fields; the API key
 * never leaves the Authorization header.
 */

const SECRET_TITLE = 'Private title 7f3a';
const SECRET_BODY = 'My address is 14 Example Road, Testville';
const API_KEY = 'sk-test-0123456789abcdef';

function privatePost(?User $author = null): ForumPost
{
    return ForumPost::query()->create([
        'title' => SECRET_TITLE,
        'body' => SECRET_BODY,
        'views' => 987654,
        'user_id' => $author?->id,
    ]);
}

function expectNoContent(string $haystack): void
{
    expect($haystack)->not->toContain(SECRET_TITLE)
        ->not->toContain(SECRET_BODY)
        ->not->toContain('14 Example Road');
}

function useJev(): void
{
    config([
        'jev-moderation.driver' => 'jev',
        'jev-moderation.drivers.jev.key' => API_KEY,
    ]);
}

describe('a stored decision', function (): void {
    it('keeps a hash, never the content, in any column', function (string $fixture): void {
        $answer = jevFixture($fixture)['answers']['moderation'];
        moderationFake()->classifyAs($answer['choice'], (float) $answer['confidence'], array_map(floatval(...), $answer['probabilities']));

        privatePost();

        $rows = ModerationDecision::query()->get();

        expect($rows)->toHaveCount(2);

        foreach ($rows as $row) {
            expect($row->content)->toBeNull()
                ->and($row->content_hash)->toMatch('/^[0-9a-f]{64}$/');
            expectNoContent(json_encode($row->getAttributes(), JSON_THROW_ON_ERROR));
        }
    })->with(['systemone-fine', 'systemone-borderline', 'systemone-spam']);
});

describe('queue', function (): void {
    it('queues the decision id, not the content', function (): void {
        Queue::fake();

        privatePost();

        Queue::assertPushed(ModerateContent::class, function (ModerateContent $job): bool {
            expectNoContent(serialize($job));

            return true;
        });
    });
});

describe('events', function (): void {
    it('serialise without the content', function (string $fixture): void {
        Event::fake([ContentPending::class, ContentPassed::class, ContentFlagged::class, ContentBlocked::class]);
        $answer = jevFixture($fixture)['answers']['moderation'];
        moderationFake()->classifyAs($answer['choice'], (float) $answer['confidence'], array_map(floatval(...), $answer['probabilities']));

        privatePost();

        $fired = 0;

        foreach ([ContentPending::class, ContentPassed::class, ContentFlagged::class, ContentBlocked::class] as $class) {
            foreach (Event::dispatched($class) as [$event]) {
                expectNoContent(serialize($event));
                $fired++;
            }
        }

        expect($fired)->toBe(2);
    })->with(['systemone-fine', 'systemone-borderline', 'systemone-spam']);

    it('report a failure without the content or the key', function (): void {
        Event::fake([ModerationFailed::class]);
        useJev();
        Http::fake(['api.typesafe.ai/*' => Http::response([
            // Validation errors may echo the offending input back.
            'detail' => [['loc' => ['body', 'state'], 'msg' => 'Invalid state', 'type' => 'value_error', 'input' => ['content' => ['title' => SECRET_TITLE, 'body' => SECRET_BODY]]]],
        ], 422)]);

        privatePost();

        Event::assertDispatched(ModerationFailed::class, function (ModerationFailed $event): bool {
            expect($event->reason)->toBe('Jev returned HTTP 422: body.state: Invalid state')
                ->not->toContain(API_KEY);
            expectNoContent(serialize($event));

            return true;
        });
    });
});

describe('what Jev receives', function (): void {
    it('only the #[Moderate] fields, never other columns or the author', function (): void {
        useJev();
        Http::fake(['api.typesafe.ai/*' => Http::response(jevFixture('systemone-fine'))]);

        privatePost(User::query()->create(['name' => 'Alexandra Example']));

        Http::assertSent(function (Request $request): bool {
            expect($request['state'])->toBe(['content' => ['title' => SECRET_TITLE, 'body' => SECRET_BODY]])
                ->and($request->body())->not->toContain('987654')
                ->not->toContain('Alexandra Example')
                ->not->toContain(API_KEY)
                ->and($request->header('Authorization'))->toBe(['Bearer '.API_KEY]);

            return true;
        });
        Http::assertSentCount(1);
    });

    it('only the overridden content when moderatableContent() is customised', function (): void {
        ChatMessage::query()->create(['room' => 'general', 'message' => ' hi ', 'sender_id' => 42]);

        moderationFake()->assertClassified(fn (ModerationRequest $request): bool => $request->content === ['message' => 'hi']
            && $request->context === 'Room: general');
    });
});

describe('driver errors', function (): void {
    it('never put the key in an exception message', function (int $status, string $fixture): void {
        useJev();
        Http::fake(['api.typesafe.ai/*' => Http::response(jevFixture($fixture), $status)]);

        try {
            app(DriverManager::class)->driver('jev')->models();
            $this->fail('Expected a DriverException.');
        } catch (DriverException $e) {
            expect($e->getMessage())->not->toContain(API_KEY);
        }
    })->with([
        [401, 'error-401-invalid-key'],
        [403, 'error-403-missing-key'],
        [400, 'error-400-unknown-model'],
    ]);
});
