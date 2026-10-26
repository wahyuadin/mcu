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
        Schema::table('rencana_kunjungans', function (Blueprint $table): void {
            $table->text('anamnesa_token')->nullable();
            $table->string('anamnesa_token_hash', 64)->nullable()->unique();
        });
        Schema::table('karyawans', function (Blueprint $table): void {
            $table->longText('anamnesa_submission')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('karyawans', function (Blueprint $table): void {
            $table->dropColumn('anamnesa_submission');
        });
        Schema::table('rencana_kunjungans', function (Blueprint $table): void {
            $table->dropUnique(['anamnesa_token_hash']);
            $table->dropColumn(['anamnesa_token', 'anamnesa_token_hash']);
        });
    }
};
