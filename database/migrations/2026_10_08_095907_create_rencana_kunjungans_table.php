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
        Schema::create('rencana_kunjungans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('perusahaan_id')->constrained('perusahaans')->cascadeOnDelete();
            $table->string('nomor_kunjungan')->unique();
            $table->string('periode_kunjungan');
            $table->date('tgl_mulai')->nullable();
            $table->date('tgl_selesai')->nullable();
            $table->unsignedInteger('kuota_peserta')->default(0);
            $table->boolean('is_closing')->default(false);
            $table->string('status')->default('ongoing'); // draft, ongoing, completed
            $table->text('catatan')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rencana_kunjungans');
    }
};
