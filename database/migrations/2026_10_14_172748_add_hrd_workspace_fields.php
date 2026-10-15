<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignUuid('perusahaan_id')->nullable()->constrained('perusahaans')->restrictOnDelete();
        });
        Schema::table('perusahaans', function (Blueprint $table): void {
            $table->json('dashboard_layout')->nullable();
        });
        Schema::table('rencana_kunjungans', function (Blueprint $table): void {
            $table->unsignedInteger('jumlah_peserta_direncanakan')->nullable();
        });
        Schema::table('karyawans', function (Blueprint $table): void {
            $table->boolean('is_closing')->default(false);
            $table->timestamp('released_at')->nullable();
            $table->foreignUuid('released_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->index(['perusahaan_id', 'rencana_kunjungan_id', 'is_closing'], 'participants_hrd_index');
        });
        DB::table('karyawans')->whereNull('deleted_at')->where('status_pemeriksaan', 'selesai')->whereIn('rencana_kunjungan_id', DB::table('rencana_kunjungans')->whereNull('deleted_at')->where('is_closing', true)->select('id'))
            ->whereIn('id', DB::table('anamnesas')->whereNull('deleted_at')->where('status', 'final')->select('karyawan_id'))
            ->update(['is_closing' => true, 'released_at' => now()]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('karyawans', function (Blueprint $table): void {
            $table->dropIndex('participants_hrd_index');
            $table->dropForeign(['released_by']);
            $table->dropColumn(['is_closing', 'released_at', 'released_by']);
        });
        Schema::table('rencana_kunjungans', fn (Blueprint $table) => $table->dropColumn('jumlah_peserta_direncanakan'));
        Schema::table('perusahaans', fn (Blueprint $table) => $table->dropColumn('dashboard_layout'));
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['perusahaan_id']);
            $table->dropColumn('perusahaan_id');
        });
    }
};
