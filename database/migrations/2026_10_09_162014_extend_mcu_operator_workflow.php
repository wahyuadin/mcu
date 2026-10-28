<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('pasiens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('nama');
            $table->string('nik', 20)->nullable()->index();
            $table->string('jenis_kelamin', 1);
            $table->date('tanggal_lahir')->nullable();
            $table->string('telepon')->nullable();
            $table->string('foto')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::table('karyawans', function (Blueprint $table): void {
            $table->foreignUuid('pasien_id')->nullable()->constrained('pasiens')->restrictOnDelete();
            $table->string('nomor_mcu')->nullable()->unique();
            $table->date('tanggal_mcu')->nullable();
            $table->text('alasan_status')->nullable();
        });
        DB::table('karyawans')->orderBy('id')->chunk(200, function ($rows): void {
            foreach ($rows as $row) {
                $id = (string) Str::uuid();
                DB::table('pasiens')->insert([
                    'id' => $id, 'nama' => $row->nama_karyawan, 'nik' => $row->nik,
                    'jenis_kelamin' => $row->jenis_kelamin, 'tanggal_lahir' => $row->tanggal_lahir,
                    'telepon' => $row->telepon, 'foto' => $row->foto,
                    'created_at' => $row->created_at, 'updated_at' => $row->updated_at, 'deleted_at' => $row->deleted_at,
                ]);
                DB::table('karyawans')->where('id', $row->id)->update([
                    'pasien_id' => $id, 'nomor_mcu' => 'MCU-'.strtoupper(str_replace('-', '', $row->id)),
                ]);
            }
        });
        Schema::table('anamnesas', function (Blueprint $table): void {
            $table->json('anamnesa_data')->nullable();
            $table->json('laboratorium')->nullable();
            $table->json('penunjang')->nullable();
            $table->json('section_status')->nullable();
            $table->json('revisions')->nullable();
            $table->json('package_items')->nullable();
            $table->foreignUuid('dokter_fisik_id')->nullable()->constrained('dokters')->restrictOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->foreignUuid('finalized_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('lock_version')->default(0);
            foreach ([
                'ishihara_test', 'kesan_umum', 'kulit', 'mata', 'kesan_telinga', 'kesan_hidung', 'kesan_tenggorokan',
                'kesan_mulut', 'gigi', 'kesan_leher', 'kesan_thorax', 'ronchi', 'wheezing', 'gallop', 'murmur',
                'kesan_abdomen', 'hati_lien', 'benjolan_massa', 'nyeri_tekan', 'hernia', 'test_cva', 'rectal', 'haemorrhoid',
                'ekstremitas_atas', 'ekstremitas_bawah', 'range_of_movement', 'refleks_fisiologis', 'refleks_patologis',
                'kekuatan_motorik', 'kelainan_saraf_pusat', 'kelainan_saraf_tepi', 'status_kelaikan',
            ] as $column) {
                $table->string($column)->nullable()->default(null)->change();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('anamnesas', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('dokter_fisik_id');
            $table->dropConstrainedForeignId('finalized_by');
            $table->dropColumn(['anamnesa_data', 'laboratorium', 'penunjang', 'section_status', 'revisions', 'package_items', 'finalized_at', 'lock_version']);
        });
        Schema::table('karyawans', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('pasien_id');
            $table->dropUnique(['nomor_mcu']);
            $table->dropColumn(['nomor_mcu', 'tanggal_mcu', 'alasan_status']);
        });
        Schema::dropIfExists('pasiens');
    }
};
