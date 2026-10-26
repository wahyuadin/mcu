<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('mcu_categories', fn (Blueprint $table) => $table->boolean('import_enabled')->default(false));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mcu_categories', fn (Blueprint $table) => $table->dropColumn('import_enabled'));
    }
};
