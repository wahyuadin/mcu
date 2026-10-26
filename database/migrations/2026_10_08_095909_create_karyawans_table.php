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
        Schema::create('karyawans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('perusahaan_id')->constrained('perusahaans')->cascadeOnDelete();
            $table->foreignUuid('rencana_kunjungan_id')->constrained('rencana_kunjungans')->cascadeOnDelete();
            $table->foreignUuid('paket_id')->constrained('pakets')->cascadeOnDelete();
            $table->string('nik', 20)->nullable()->index();
            $table->string('nip', 50)->nullable()->index();
            $table->string('nama_karyawan');
            $table->enum('jenis_kelamin', ['L', 'P'])->default('L');
            $table->date('tanggal_lahir')->nullable();
            $table->string('bagian')->nullable(); // Departemen / Unit Kerja
            $table->string('jabatan')->nullable();
            $table->string('telepon')->nullable();
            $table->string('foto')->nullable();
            $table->string('status_pemeriksaan')->default('belum'); // belum, proses, selesai
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('karyawans');
    }
};
