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
        Schema::table('mcu_items', function (Blueprint $table): void {
            $table->string('default_answer')->nullable();
        });
        Schema::table('anamnesas', function (Blueprint $table): void {
            $table->json('dynamic_notes')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mcu_items', fn (Blueprint $table) => $table->dropColumn('default_answer'));
        Schema::table('anamnesas', fn (Blueprint $table) => $table->dropColumn('dynamic_notes'));
    }
};
