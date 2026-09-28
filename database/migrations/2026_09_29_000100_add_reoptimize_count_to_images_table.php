<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('images', function (Blueprint $table) {
            // "Opnieuw optimaliseren" costs an OpenAI edit each time; capped per photo.
            $table->unsignedSmallInteger('reoptimize_count')->default(0)->after('attempts');
        });
    }

    public function down(): void
    {
        Schema::table('images', function (Blueprint $table) {
            $table->dropColumn('reoptimize_count');
        });
    }
};
