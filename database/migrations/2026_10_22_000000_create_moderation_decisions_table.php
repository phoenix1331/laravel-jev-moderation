<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Append-only: every check, report and human review adds a row, so the
 * latest row per model is its current status and the rest is the audit
 * trail. Portable across SQLite, PostgreSQL, MySQL and MariaDB: schema
 * builder types only, and index names are explicit and short because
 * MySQL caps them at 64 characters.
 *
 * The morph key type follows Schema::morphUsingUuids() / morphUsingUlids()
 * when the app sets one, so UUID and ULID models work.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('moderation_decisions', function (Blueprint $table): void {
            $table->id();
            $table->morphs('moderatable', 'moderation_decisions_moderatable_index');

            $table->string('status', 16);
            $table->string('source', 16);
            $table->string('policy', 64);
            $table->string('policy_version', 64);
            $table->string('model', 64)->nullable();
            $table->string('category', 64)->nullable();
            $table->decimal('confidence', 5, 4)->nullable();

            $table->string('content_hash', 64);
            $table->text('content')->nullable();

            $table->nullableMorphs('reviewed_by', 'moderation_decisions_reviewed_by_index');
            $table->text('note')->nullable();
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at'], 'moderation_decisions_status_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('moderation_decisions');
    }
};
