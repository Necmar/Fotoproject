<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** A batch started without a name gets one from the AI analysis (e.g. "BMW 320i Touring"). */
    public function up(): void
    {
        Schema::table('batches', function (Blueprint $table) {
            $table->boolean('auto_named')->default(false)->after('filename_base');
        });
    }

    public function down(): void
    {
        Schema::table('batches', function (Blueprint $table) {
            $table->dropColumn('auto_named');
        });
    }
};
