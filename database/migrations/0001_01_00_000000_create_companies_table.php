<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('status', 20)->default('active')->index();
            $table->timestamp('blocked_at')->nullable();
            $table->string('blocked_reason')->nullable();
            $table->string('logo_path')->nullable();

            // Denormalised counters for dashboards and the Super Admin overview.
            // They survive the 7-day cleanup of batches/images.
            $table->unsignedBigInteger('storage_bytes')->default(0);
            $table->unsignedInteger('batches_total')->default(0);
            $table->unsignedInteger('images_processed_total')->default(0);
            $table->unsignedInteger('images_failed_total')->default(0);

            // Prepared for later subscriptions / limits / credits (unused in v1).
            $table->string('plan', 50)->nullable();
            $table->unsignedInteger('monthly_image_limit')->nullable();
            $table->integer('credits_balance')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
