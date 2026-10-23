<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Phoenix1331\JevModeration\Console\PingCommand;
use Phoenix1331\JevModeration\Models\ModerationDecision;

const PING_KEY = 'sk-ping-0123456789';

function useLiveJev(string $model = 'jev-latest', ?string $key = PING_KEY): void
{
    config([
        'jev-moderation.driver' => 'jev',
        'jev-moderation.drivers.jev.key' => $key,
        'jev-moderation.drivers.jev.model' => $model,
    ]);
}

/**
 * @param  array<string, mixed>|null  $answer
 */
function fakeJevApi(?array $answer = null, int $status = 200): void
{
    Http::fake([
        'api.typesafe.ai/v1/models' => Http::response(jevFixture('models'), 200, ['x-typesafe-request-id' => 'req_models']),
        'api.typesafe.ai/v1/systemone' => Http::response($answer ?? jevFixture('systemone-fine'), $status, ['x-typesafe-request-id' => 'req_ping']),
    ]);
}

it('classifies the sample and shows the answer', function (): void {
    useLiveJev('jev-1.14.0');
    fakeJevApi();

    $this->artisan('jev-moderation:ping')
        ->expectsOutputToContain('jev-1.14.0')
        ->expectsOutputToContain('set')
        ->expectsOutputToContain('jev-latest, jev-preview')
        ->expectsOutputToContain('Sample classified')
        ->expectsOutputToContain('passed (clear)')
        ->expectsOutputToContain('fine at 1.00')
        ->expectsOutputToContain('491')
        ->expectsOutputToContain('req_ping')
        ->doesntExpectOutputToContain('is an alias')
        ->doesntExpectOutputToContain(PING_KEY)
        ->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/systemone')
        && $request['state'] === ['content' => ['content' => PingCommand::SAMPLE]]);
    expect(ModerationDecision::query()->count())->toBe(0);
});

it('warns when the model is an alias, naming the version to pin', function (string $alias): void {
    useLiveJev($alias);
    fakeJevApi();

    $this->artisan('jev-moderation:ping')
        ->expectsOutputToContain("{$alias} is an alias")
        ->expectsOutputToContain('JEV_MODEL=jev-1.14.0')
        ->assertSuccessful();
})->with(['jev-latest', 'jev-preview']);

it('uses the policy given', function (): void {
    useLiveJev();
    fakeJevApi();

    $this->artisan('jev-moderation:ping', ['--policy' => 'chat'])
        ->expectsOutputToContain('chat')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/systemone')
        && array_key_exists('sexual', $request['questions']['moderation']['criteria']));
});

it('fails without a key, sending nothing', function (): void {
    useLiveJev(key: null);
    Http::fake();

    $this->artisan('jev-moderation:ping')
        ->expectsOutputToContain('missing')
        ->expectsOutputToContain('No Jev API key is set')
        ->assertFailed();

    Http::assertNothingSent();
});

it('fails on a rejected key with the request id', function (): void {
    useLiveJev();
    Http::fake(['api.typesafe.ai/*' => Http::response(jevFixture('error-401-invalid-key'), 401, ['x-typesafe-request-id' => 'req_denied'])]);

    $this->artisan('jev-moderation:ping')
        ->expectsOutputToContain('Check JEV_API_KEY')
        ->expectsOutputToContain('req_denied')
        ->doesntExpectOutputToContain(PING_KEY)
        ->assertFailed();
});

it('fails when the classification errors after the model list', function (): void {
    useLiveJev();
    fakeJevApi(jevFixture('error-400-unknown-model'), 400);

    $this->artisan('jev-moderation:ping')
        ->expectsOutputToContain('jev-latest, jev-preview')
        ->expectsOutputToContain('Unknown model')
        ->assertFailed();
});

it('fails when Jev cannot be reached', function (): void {
    useLiveJev();
    Http::fake(fn () => throw new ConnectionException('cURL error 6: Could not resolve host'));

    $this->artisan('jev-moderation:ping')
        ->expectsOutputToContain('Could not reach Jev')
        ->assertFailed();
});

it('fails on an unknown policy', function (): void {
    useLiveJev();
    fakeJevApi();

    $this->artisan('jev-moderation:ping', ['--policy' => 'forum'])
        ->expectsOutputToContain('No moderation policy named [forum]')
        ->assertFailed();
});

it('works with the fake driver, without Jev settings or warnings', function (): void {
    $this->artisan('jev-moderation:ping')
        ->expectsOutputToContain('fake')
        ->doesntExpectOutputToContain('API key')
        ->doesntExpectOutputToContain('is an alias')
        ->assertSuccessful();
});

it('shows dashes for details a driver does not report', function (): void {
    config(['jev-moderation.driver' => 'jev', 'jev-moderation.drivers.jev.key' => PING_KEY, 'jev-moderation.drivers.jev.model' => 'jev-latest']);
    $answer = jevFixture('systemone-fine');
    unset($answer['model'], $answer['usage']);
    Http::fake([
        'api.typesafe.ai/v1/models' => Http::response(['models' => []]),
        'api.typesafe.ai/v1/systemone' => Http::response($answer),
    ]);

    $this->artisan('jev-moderation:ping')
        ->expectsOutputToContain('a versioned id such as jev-1.14.0')
        ->assertSuccessful();
});
