<?php

declare(strict_types=1);

use Phoenix1331\JevModeration\Data\CheckResult;
use Phoenix1331\JevModeration\Data\ModerationRequest;
use Phoenix1331\JevModeration\Drivers\DriverManager;
use Phoenix1331\JevModeration\Drivers\FakeDriver;
use Phoenix1331\JevModeration\Enums\ModerationStatus;
use Phoenix1331\JevModeration\Exceptions\DriverException;
use Phoenix1331\JevModeration\Facades\JevModeration;
use Phoenix1331\JevModeration\Models\ModerationDecision;
use Phoenix1331\JevModeration\Tests\Fixtures\Drivers\StaticDriver;
use Phoenix1331\JevModeration\Tests\Fixtures\Models\ForumPost;

describe('classify', function (): void {
    it('checks text against the default policy without recording anything', function (): void {
        JevModeration::fake();

        $result = JevModeration::classify('gg, rematch?');

        expect($result)->toBeInstanceOf(CheckResult::class)
            ->and($result->policy)->toBe('community')
            ->and($result->status())->toBe(ModerationStatus::Passed)
            ->and($result->passed())->toBeTrue()
            ->and($result->classification->driver)->toBe('fake')
            ->and(ModerationDecision::query()->count())->toBe(0);
        JevModeration::fake()->assertClassified(fn (ModerationRequest $request): bool => $request->content === ['content' => 'gg, rematch?'] && $request->context === null);
    });

    it('takes named fields, a policy and context', function (): void {
        JevModeration::fake()->classifyAs('harassment', 1.0, ['harassment' => 0.6, 'fine' => 0.4]);

        $result = JevModeration::classify(['message' => 'shut up'], 'chat', 'sam: gg');

        expect($result->policy)->toBe('chat')
            ->and($result->flagged())->toBeTrue()
            ->and($result->verdict->category)->toBe('harassment');
        JevModeration::fake()->assertClassified(fn (ModerationRequest $request): bool => $request->policy === 'chat'
            && $request->content === ['message' => 'shut up']
            && $request->context === 'sam: gg');
    });

    it('reports a block', function (): void {
        JevModeration::fake()->classifyAs('spam', 1.0, ['spam' => 1.0]);

        $result = JevModeration::classify('Replica watches 90% off');

        expect($result->blocked())->toBeTrue()
            ->and($result->passed())->toBeFalse()
            ->and($result->flagged())->toBeFalse();
    });

    it('lets the caller handle a classifier failure', function (): void {
        JevModeration::fake()->failWith(DriverException::retryable('busy', 429));

        JevModeration::classify('hello');
    })->throws(DriverException::class, 'busy');
});

describe('fake', function (): void {
    it('swaps the configured driver for every check and returns the same fake', function (): void {
        config(['jev-moderation.driver' => 'jev']);

        $fake = JevModeration::fake()->classifyAs('spam', 1.0, ['spam' => 1.0]);

        $post = ForumPost::query()->create(['title' => 'Cheap watches', 'body' => 'Buy now']);

        expect($fake)->toBeInstanceOf(FakeDriver::class)
            ->and(JevModeration::fake())->toBe($fake)
            ->and(config('jev-moderation.driver'))->toBe('fake')
            ->and($post->moderationStatus())->toBe(ModerationStatus::Blocked);
        JevModeration::fake()->assertClassifiedTimes(1);
    });

    it('refuses when extend() replaced the fake driver', function (): void {
        JevModeration::extend('fake', fn (): StaticDriver => new StaticDriver);
        app(DriverManager::class)->forgetDrivers();

        JevModeration::fake();
    })->throws(RuntimeException::class, 'JevModeration::fake() needs the built-in fake driver, but extend() replaced it.');
});

describe('policies and drivers', function (): void {
    it('reads policies from config', function (): void {
        expect(JevModeration::policies())->toBe(['community', 'chat', 'strict'])
            ->and(JevModeration::policy()->name)->toBe('community')
            ->and(JevModeration::policy('strict')->rules)->toHaveKey('self_harm');
    });

    it('classifies with a custom driver', function (): void {
        JevModeration::extend('static', fn (): StaticDriver => new StaticDriver);
        config(['jev-moderation.driver' => 'static']);

        expect(JevModeration::classify('anything')->classification->driver)->toBe('static');
    });
});
