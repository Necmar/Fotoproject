<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('default_output_format', 10)->default('jpg');
            $table->string('default_resolution', 10)->default('2000');
            $table->string('default_aspect_ratio', 10)->default('original');
            $table->string('default_strength', 20)->default('normal');
            $table->string('default_background', 30)->default('keep');
            $table->string('default_watermark_mode', 20)->default('none');
            $table->string('default_watermark_position', 20)->default('bottom_right');
            $table->unsignedTinyInteger('default_watermark_opacity')->default(70);
            $table->string('filename_prefix', 60)->default('foto');
            $table->timestamps();
        });

        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');
        Schema::dropIfExists('company_settings');
    }
};
