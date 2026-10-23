<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/*
 * vendor:publish writes into Testbench's throwaway app; remove what it wrote.
 */
afterEach(function (): void {
    File::delete(config_path('jev-moderation.php'));
    File::delete(glob(database_path('migrations/*_create_moderation_decisions_table.php')) ?: []);
});

function publishedMigrations(): array
{
    return glob(database_path('migrations/*_create_moderation_decisions_table.php')) ?: [];
}

describe('interactive', function (): void {
    it('asks for mode, policy and migrations, then prints the .env keys', function (): void {
        $this->artisan('jev-moderation:install')
            ->expectsChoice('When should content be visible?', 'pre', [
                'post' => 'post: straight away, hidden once blocked or rejected',
                'pre' => 'pre: only after it passes or a moderator approves it',
            ])
            ->expectsChoice('Which policy should models use by default?', 'chat', ['community', 'chat', 'strict'])
            ->expectsConfirmation('Run the migrations now?', 'no')
            ->expectsOutputToContain('Published config/jev-moderation.php')
            ->expectsOutputToContain('JEV_MODERATION_MODE=pre')
            ->expectsOutputToContain('JEV_MODERATION_POLICY=chat')
            ->expectsOutputToContain('JEV_API_KEY=')
            ->expectsOutputToContain('jev-moderation:ping')
            ->assertSuccessful();

        expect(file_exists(config_path('jev-moderation.php')))->toBeTrue()
            ->and(publishedMigrations())->toHaveCount(1);
    });

    it('runs the migrations when asked', function (): void {
        Schema::dropIfExists('moderation_decisions');

        $this->artisan('jev-moderation:install', ['--mode' => 'post', '--policy' => 'community'])
            ->expectsConfirmation('Run the migrations now?', 'yes')
            ->assertSuccessful();

        expect(Schema::hasTable('moderation_decisions'))->toBeTrue();
    });
});

describe('non-interactive', function (): void {
    it('uses the defaults and leaves the database alone', function (): void {
        Schema::dropIfExists('moderation_decisions');

        $this->artisan('jev-moderation:install', ['--no-interaction' => true])
            ->expectsOutputToContain('JEV_MODERATION_MODE=post')
            ->expectsOutputToContain('JEV_MODERATION_POLICY=community')
            ->doesntExpectOutputToContain('Run the migrations')
            ->assertSuccessful();

        expect(Schema::hasTable('moderation_decisions'))->toBeFalse()
            ->and(publishedMigrations())->toHaveCount(1);
    });

    it('migrates with --migrate and takes choices from options', function (): void {
        Schema::dropIfExists('moderation_decisions');

        $this->artisan('jev-moderation:install', ['--no-interaction' => true, '--migrate' => true, '--mode' => 'pre', '--policy' => 'strict'])
            ->expectsOutputToContain('JEV_MODERATION_MODE=pre')
            ->expectsOutputToContain('JEV_MODERATION_POLICY=strict')
            ->assertSuccessful();

        expect(Schema::hasTable('moderation_decisions'))->toBeTrue();
    });
});

describe('republishing', function (): void {
    it('keeps an edited config unless forced', function (): void {
        $this->artisan('jev-moderation:install', ['--no-interaction' => true])->assertSuccessful();
        file_put_contents(config_path('jev-moderation.php'), "<?php return ['edited' => true];");

        $this->artisan('jev-moderation:install', ['--no-interaction' => true])->assertSuccessful();
        expect(file_get_contents(config_path('jev-moderation.php')))->toContain('edited');

        $this->artisan('jev-moderation:install', ['--no-interaction' => true, '--force' => true])->assertSuccessful();
        expect(file_get_contents(config_path('jev-moderation.php')))->not->toContain('edited');
    });
});

describe('invalid options', function (): void {
    it('rejects an unknown mode or policy and publishes nothing', function (array $options, string $message): void {
        $this->artisan('jev-moderation:install', ['--no-interaction' => true, ...$options])
            ->expectsOutputToContain($message)
            ->assertFailed();

        expect(file_exists(config_path('jev-moderation.php')))->toBeFalse();
    })->with([
        'mode' => [['--mode' => 'sometimes'], '--mode must be one of: post, pre. Got [sometimes].'],
        'policy' => [['--policy' => 'forum'], '--policy must be one of: community, chat, strict. Got [forum].'],
    ]);
});
