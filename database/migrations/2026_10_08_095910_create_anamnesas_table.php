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
        Schema::create('anamnesas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('karyawan_id')->constrained('karyawans')->cascadeOnDelete();
            $table->foreignUuid('rencana_kunjungan_id')->constrained('rencana_kunjungans')->cascadeOnDelete();
            $table->foreignUuid('dokter_id')->nullable()->constrained('dokters')->nullOnDelete();

            $table->string('header_form')->nullable();
            $table->string('sub_header')->nullable();

            // Riwayat Anamnesa & Keluhan
            $table->text('keluhan_saat_ini')->nullable();
            $table->json('riwayat_penyakit_dahulu')->nullable(); // {"hipertensi": false, "diabetes_melitus": false, "tbc": false, "jantung": false, "asma": false, ...}
            $table->json('riwayat_penyakit_keluarga')->nullable();
            $table->json('riwayat_kebiasaan')->nullable(); // {"merokok": false, "alkohol": false, "olahraga": false}

            // [1-4] Tinggi Badan & Berat Badan / Antropometri
            $table->decimal('tinggi_badan', 5, 2)->nullable();
            $table->decimal('berat_badan', 5, 2)->nullable();
            $table->decimal('bmi', 5, 2)->nullable();
            $table->string('kategori_bmi')->nullable();

            // [5-9] Tanda-Tanda Vital
            $table->string('tekanan_darah')->nullable(); // e.g. "138/88"
            $table->integer('nadi')->nullable(); // bpm
            $table->decimal('lingkar_pinggang', 5, 2)->nullable(); // cm
            $table->integer('pernapasan')->nullable(); // x/menit
            $table->decimal('suhu_badan', 4, 2)->nullable(); // °C

            // [10-13] Visus Mata
            $table->string('visus_od_tanpa_kacamata')->nullable();
            $table->string('visus_os_tanpa_kacamata')->nullable();
            $table->string('visus_od_dengan_kacamata')->nullable();
            $table->string('visus_os_dengan_kacamata')->nullable();

            // [14] Buta Warna
            $table->string('ishihara_test')->nullable()->default('Normal');

            // [15-17] Keadaan Umum & Mata
            $table->string('kesan_umum')->nullable()->default('Baik, compos mentis');
            $table->string('kulit')->nullable()->default('Normal');
            $table->string('mata')->nullable()->default('Konjungtiva tidak anemis, sklera tidak ikterik');

            // [18-23] Kepala - THT, Mulut, Gigi, Leher
            $table->string('kesan_telinga')->nullable()->default('Normal');
            $table->string('kesan_hidung')->nullable()->default('Normal');
            $table->string('kesan_tenggorokan')->nullable()->default('Normal');
            $table->string('kesan_mulut')->nullable()->default('Normal');
            $table->string('gigi')->nullable()->default('Normal');
            $table->string('kesan_leher')->nullable()->default('Normal, tidak ada pembesaran KGB');

            // [24-28] Dada - Thorax & Auskultasi
            $table->string('kesan_thorax')->nullable()->default('Simetris, tidak ada retraksi');
            $table->string('ronchi')->nullable()->default('Negatif');
            $table->string('wheezing')->nullable()->default('Negatif');
            $table->string('gallop')->nullable()->default('Negatif');
            $table->string('murmur')->nullable()->default('Negatif');

            // [29-36] Abdomen & Urogenitalia
            $table->string('kesan_abdomen')->nullable()->default('Supel, bising usus normal');
            $table->string('hati_lien')->nullable()->default('Tidak teraba membesar');
            $table->string('benjolan_massa')->nullable()->default('Tidak ada');
            $table->string('nyeri_tekan')->nullable()->default('Tidak ada');
            $table->string('hernia')->nullable()->default('Tidak ada');
            $table->string('test_cva')->nullable()->default('Negatif');
            $table->string('rectal')->nullable()->default('Tidak diperiksa');
            $table->string('haemorrhoid')->nullable()->default('Tidak ada');

            // [37-39] Ekstremitas
            $table->string('ekstremitas_atas')->nullable()->default('Normal');
            $table->string('ekstremitas_bawah')->nullable()->default('Normal');
            $table->string('range_of_movement')->nullable()->default('Full ROM');

            // [40-44] Pemeriksaan Neurologis
            $table->string('refleks_fisiologis')->nullable()->default('Normal');
            $table->string('refleks_patologis')->nullable()->default('Negatif');
            $table->string('kekuatan_motorik')->nullable()->default('5/5/5/5');
            $table->string('kelainan_saraf_pusat')->nullable()->default('Tidak ada');
            $table->string('kelainan_saraf_tepi')->nullable()->default('Tidak ada');

            // Kesimpulan & Kelaikan
            $table->text('catatan_dokter')->nullable();
            $table->text('anjuran_saran')->nullable();
            $table->string('status_kelaikan')->nullable()->default('Fit to Work'); // Fit to Work, Fit with Note, Temporarily Unfit, Unfit
            $table->string('status')->default('draft'); // draft, final

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('anamnesas');
    }
};
