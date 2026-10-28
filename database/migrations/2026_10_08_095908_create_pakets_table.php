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
        Schema::create('pakets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('perusahaan_id')->constrained('perusahaans')->cascadeOnDelete();
            $table->foreignUuid('rencana_kunjungan_id')->constrained('rencana_kunjungans')->cascadeOnDelete();
            $table->string('nama_paket');
            $table->text('deskripsi')->nullable();
            $table->json('active_items')->nullable(); // Config item anamnesa/fisik yang aktif (misal: {"tbc": false, ...})
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pakets');
    }
};
