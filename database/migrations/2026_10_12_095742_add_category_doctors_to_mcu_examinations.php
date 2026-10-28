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
        Schema::table('mcu_examinations', function (Blueprint $table) {
            Schema::table('mcu_categories', function (Blueprint $table): void {
                $table->boolean('has_responsible_doctor')->default(true);
            });
            Schema::table('anamnesas', function (Blueprint $table): void {
                $table->json('category_doctors')->nullable();
            });
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mcu_examinations', function (Blueprint $table) {
            Schema::table('anamnesas', function (Blueprint $table): void {
                $table->dropColumn('category_doctors');
            });
            Schema::table('mcu_categories', function (Blueprint $table): void {
                $table->dropColumn('has_responsible_doctor');
            });
        });
    }
};
