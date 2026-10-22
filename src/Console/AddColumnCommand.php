<?php

declare(strict_types=1);

namespace Phoenix1331\JevModeration\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Carbon;

/**
 * Writes a migration adding an indexed moderation_status column to a
 * moderated table, backfilled from the latest decisions.
 *
 * @internal
 */
final class AddColumnCommand extends Command
{
    protected $signature = 'jev-moderation:add-column
        {table : The moderated model\'s table, e.g. forum_posts}
        {--path= : Where to write the migration (default database/migrations)}';

    protected $description = 'Generate a migration adding a moderation_status column for fast visibility queries';

    public function handle(Filesystem $files): int
    {
        $table = $this->argument('table');

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) !== 1) {
            $this->components->error('The table name may only contain letters, numbers and underscores.');

            return self::FAILURE;
        }

        $directory = $this->directory();
        $name = "add_moderation_status_to_{$table}_table";

        if ($files->glob("{$directory}/*_{$name}.php") !== []) {
            $this->components->error("A migration adding moderation_status to [{$table}] already exists in {$directory}.");

            return self::FAILURE;
        }

        $files->ensureDirectoryExists($directory);
        $path = $directory.'/'.Carbon::now()->format('Y_m_d_His')."_{$name}.php";
        $files->put($path, str_replace('{{ table }}', $table, self::STUB));

        $this->components->info("Migration created: {$path}");
        $this->components->bulletList([
            'Run php artisan migrate: existing rows are filled from their latest decision.',
            'Restart queue workers afterwards so they start keeping the column in sync.',
        ]);

        return self::SUCCESS;
    }

    private function directory(): string
    {
        $path = $this->option('path');

        if (! is_string($path) || $path === '') {
            return database_path('migrations');
        }

        return str_starts_with($path, '/') ? rtrim($path, '/') : base_path(rtrim($path, '/'));
    }

    private const string STUB = <<<'PHP'
        <?php

        declare(strict_types=1);

        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;
        use Phoenix1331\JevModeration\Support\StatusColumn;

        /*
         * Jev Moderation keeps this column in step with each row's latest
         * decision, so visibility queries on {{ table }} read it instead of
         * joining moderation_decisions.
         */
        return new class extends Migration
        {
            public function up(): void
            {
                Schema::table('{{ table }}', function (Blueprint $table): void {
                    $table->string(StatusColumn::NAME, 16)->nullable()->index();
                });

                StatusColumn::backfill('{{ table }}');
            }

            public function down(): void
            {
                Schema::table('{{ table }}', function (Blueprint $table): void {
                    $table->dropIndex([StatusColumn::NAME]);
                    $table->dropColumn(StatusColumn::NAME);
                });

                StatusColumn::forget('{{ table }}');
            }
        };

        PHP;
}
