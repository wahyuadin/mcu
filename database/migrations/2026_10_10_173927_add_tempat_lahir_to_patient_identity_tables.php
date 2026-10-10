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
        Schema::table('patient_identity_tables', function (Blueprint $table) {
            foreach (['pasiens', 'karyawans'] as $tableName) {
                Schema::table($tableName, function (Blueprint $table): void {
                    $table->string('tempat_lahir')->nullable();
                });
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('patient_identity_tables', function (Blueprint $table) {
            foreach (['karyawans', 'pasiens'] as $tableName) {
                Schema::table($tableName, function (Blueprint $table): void {
                    $table->dropColumn('tempat_lahir');
                });
            }
        });
    }
};
