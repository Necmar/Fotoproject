<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('batches', function (Blueprint $table) {
            // ULIDs keep batch URLs unpredictable; policies still enforce ownership.
            $table->ulid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name')->nullable();
            $table->string('filename_base', 80);
            $table->string('status', 30)->default('draft')->index();

            // Chosen batch settings (format, resolution, ratio, strength, background,
            // remove_people, watermark mode/position/opacity).
            $table->json('settings');

            $table->unsignedSmallInteger('images_count')->default(0);
            $table->unsignedSmallInteger('completed_count')->default(0);
            $table->unsignedSmallInteger('failed_count')->default(0);
            $table->unsignedBigInteger('storage_bytes')->default(0);

            $table->string('zip_path')->nullable();
            $table->timestamp('zip_generated_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();

            $table->index(['company_id', 'created_at']);
        });

        Schema::create('images', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('batch_id')->constrained()->cascadeOnDelete();
            // Denormalised for fast tenant checks without joining batches.
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');

            $table->string('original_filename');
            $table->string('original_path')->nullable();
            $table->string('original_mime', 60)->nullable();
            $table->unsignedBigInteger('original_size')->default(0);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();

            // Normalised internal copy (orientation fixed, HEIC converted, metadata stripped).
            $table->string('working_path')->nullable();
            $table->string('optimized_path')->nullable();
            $table->string('thumbnail_path')->nullable();
            $table->string('output_filename')->nullable();
            $table->unsignedBigInteger('output_size')->nullable();
            $table->unsignedInteger('output_width')->nullable();
            $table->unsignedInteger('output_height')->nullable();

            $table->string('status', 30)->default('uploaded')->index();
            $table->string('ai_status', 30)->nullable();
            $table->json('analysis')->nullable();
            $table->json('warnings')->nullable();
            // Per-image overrides used by "opnieuw optimaliseren".
            $table->json('settings_override')->nullable();
            $table->boolean('apply_watermark')->default(false);

            $table->string('perceptual_hash', 64)->nullable();
            $table->string('content_hash', 64)->nullable();
            $table->ulid('duplicate_of_id')->nullable();

            $table->string('error_code', 60)->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['batch_id', 'position']);
            $table->index(['company_id', 'created_at']);
        });

        Schema::create('image_processing_records', function (Blueprint $table) {
            $table->id();
            // Kept after images/batches are cleaned up so usage stats survive.
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('batch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('image_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20);
            $table->string('status', 20);
            $table->string('provider', 30)->nullable();
            $table->string('model', 80)->nullable();
            $table->json('settings')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->decimal('estimated_cost_usd', 10, 5)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedTinyInteger('attempt')->default(1);
            $table->string('error_code', 60)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('image_processing_records');
        Schema::dropIfExists('images');
        Schema::dropIfExists('batches');
    }
};
