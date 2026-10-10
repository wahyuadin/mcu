<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::withoutForeignKeyConstraints(function (): void {
            Schema::table('pakets', function (Blueprint $table): void {
                $table->uuid('perusahaan_id')->nullable()->change();
                $table->uuid('rencana_kunjungan_id')->nullable()->change();
            });
        });
        Schema::create('paket_rencana_kunjungan', function (Blueprint $table): void {
            $table->foreignUuid('rencana_kunjungan_id')->constrained('rencana_kunjungans')->cascadeOnDelete();
            $table->foreignUuid('paket_id')->constrained('pakets')->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['rencana_kunjungan_id', 'paket_id']);
            $table->index('paket_id');
        });
        DB::table('pakets')->whereNotNull('rencana_kunjungan_id')->orderBy('id')->chunkById(100, function ($packages): void {
            foreach ($packages as $package) {
                DB::table('paket_rencana_kunjungan')->insertOrIgnore([
                    'paket_id' => $package->id,
                    'rencana_kunjungan_id' => $package->rencana_kunjungan_id,
                    'created_at' => $package->created_at,
                    'updated_at' => $package->updated_at,
                ]);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $bindings = DB::table('paket_rencana_kunjungan')->get()->groupBy('paket_id');
        foreach (DB::table('pakets')->get() as $package) {
            if (($bindings[$package->id] ?? collect())->count() !== 1) {
                throw new RuntimeException('Paket master tanpa kunjungan atau lintas kunjungan tidak dapat dikembalikan tanpa kehilangan relasi. Gunakan migrasi perbaikan maju.');
            }
        }
        foreach ($bindings as $packageId => $visits) {
            $visitId = $visits->first()->rencana_kunjungan_id;
            DB::table('pakets')->where('id', $packageId)->update([
                'rencana_kunjungan_id' => $visitId,
                'perusahaan_id' => DB::table('rencana_kunjungans')->where('id', $visitId)->value('perusahaan_id'),
            ]);
        }
        Schema::dropIfExists('paket_rencana_kunjungan');
        Schema::withoutForeignKeyConstraints(function (): void {
            Schema::table('pakets', function (Blueprint $table): void {
                $table->uuid('perusahaan_id')->nullable(false)->change();
                $table->uuid('rencana_kunjungan_id')->nullable(false)->change();
            });
        });
    }
};
