<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DokterController;
use App\Http\Controllers\LaporanRiwayatController;
use App\Http\Controllers\McuCategoryController;
use App\Http\Controllers\McuCategoryOrderController;
use App\Http\Controllers\McuController;
use App\Http\Controllers\McuItemController;
use App\Http\Controllers\McuItemOrderController;
use App\Http\Controllers\PaketController;
use App\Http\Controllers\PasienController;
use App\Http\Controllers\PatientImportController;
use App\Http\Controllers\PemeriksaanController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');
Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->middleware('throttle:5,1')->name('login.store');
});
Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
    Route::get('/dashboard', [McuController::class, 'index'])->name('dashboard');
    Route::middleware('can:view-patients')->group(function (): void {
        Route::get('/kunjungan-mcu', [McuController::class, 'kunjunganMcu'])->name('kunjungan-mcu.index');
        Route::get('/perusahaan/{perusahaan}/logo', [McuController::class, 'perusahaanLogo'])->name('perusahaan.logo');
        Route::get('/pasien', [PasienController::class, 'index'])->name('pasien.index');
        Route::get('/pasien/{pasien}', [PasienController::class, 'show'])->whereUuid('pasien')->name('pasien.show');
        Route::get('/kunjungan-mcu/{kunjungan}', [McuController::class, 'show'])->name('kunjungan-mcu.show');
        Route::get('/kunjungan-mcu/{kunjungan}/peserta/{peserta}/lampiran/{item}', [PemeriksaanController::class, 'attachment'])->name('pemeriksaan.attachment');
        Route::get('/kunjungan-mcu/{kunjungan}/peserta/{peserta}/laporan', [PemeriksaanController::class, 'report'])->name('pemeriksaan.report');
        Route::get('/laporan-riwayat', [LaporanRiwayatController::class, 'index'])->name('laporan-riwayat.index');
        Route::get('/kunjungan-mcu/{kunjungan}/peserta/{peserta}/laporan-riwayat', [LaporanRiwayatController::class, 'show'])->name('pemeriksaan.laporan-riwayat');
        Route::get('/kunjungan-mcu/{kunjungan}/peserta/{peserta}/laporan-riwayat/versi/{version}', [LaporanRiwayatController::class, 'showVersion'])->name('pemeriksaan.laporan-riwayat.version');
    });
    Route::middleware('can:manage-mcu')->group(function (): void {
        Route::get('/perusahaan', [McuController::class, 'perusahaans'])->name('perusahaan.index');
        Route::post('/perusahaan', [McuController::class, 'storePerusahaan'])->name('perusahaan.store');
        Route::put('/perusahaan/{perusahaan}/logo', [McuController::class, 'updatePerusahaanLogo'])->name('perusahaan.logo.update');
        Route::get('/rencana-kunjungan', [McuController::class, 'kunjungans'])->name('rencana-kunjungan.index');
        Route::post('/rencana-kunjungan', [McuController::class, 'storeKunjungan'])->name('rencana-kunjungan.store');
        Route::get('/rencana-kunjungan/{kunjungan}', [McuController::class, 'planningShow'])->name('rencana-kunjungan.show');
        Route::post('/rencana-kunjungan/{kunjungan}/paket', [McuController::class, 'bindPackage'])->name('rencana-kunjungan.paket.bind');
        Route::delete('/rencana-kunjungan/{kunjungan}/paket/{paket}', [McuController::class, 'unbindPackage'])->name('rencana-kunjungan.paket.unbind');
        Route::post('/kunjungan-mcu/{kunjungan}/status', [McuController::class, 'updateStatus'])->name('rencana-kunjungan.status');
        Route::post('/kunjungan-mcu/{kunjungan}/peserta/{peserta}/status', [McuController::class, 'participantStatus'])->name('peserta.status');
        Route::resource('dokter', DokterController::class)->only(['index', 'create', 'store', 'edit', 'update']);
        Route::get('/master-kategori-mcu/urutan', [McuCategoryOrderController::class, 'show'])->name('mcu-category-order.show');
        Route::put('/master-kategori-mcu/urutan', [McuCategoryOrderController::class, 'update'])->name('mcu-category-order.update');
        Route::resource('master-kategori-mcu', McuCategoryController::class)->names('mcu-category')->parameters(['master-kategori-mcu' => 'category'])->only(['index', 'create', 'store', 'edit', 'update', 'destroy']);
        Route::get('/master-kategori-mcu/{category}/urutan-item', [McuItemOrderController::class, 'show'])->name('mcu-item-order.show');
        Route::put('/master-kategori-mcu/{category}/urutan-item', [McuItemOrderController::class, 'update'])->name('mcu-item-order.update');
        Route::resource('master-item-mcu', McuItemController::class)->names('mcu-item')->parameters(['master-item-mcu' => 'item'])->only(['index', 'create', 'store', 'edit', 'update', 'destroy']);
        Route::resource('paket', PaketController::class)->parameters(['paket' => 'paket']);
        Route::get('/pasien/create', [PasienController::class, 'create'])->name('pasien.create');
        Route::get('/pasien/import/template', [PatientImportController::class, 'template'])->name('pasien.import.template');
        Route::post('/pasien/import', [PatientImportController::class, 'store'])->middleware('throttle:10,1')->name('pasien.import.store');
        Route::get('/pasien/import/{batch}', [PatientImportController::class, 'show'])->name('pasien.import.show');
        Route::patch('/pasien/import/{batch}', [PatientImportController::class, 'update'])->name('pasien.import.update');
        Route::post('/pasien/import/{batch}/confirm', [PatientImportController::class, 'confirm'])->name('pasien.import.confirm');
        Route::get('/pasien/import/{batch}/report', [PatientImportController::class, 'report'])->name('pasien.import.report');
        Route::post('/pasien', [PasienController::class, 'store'])->name('pasien.store');
        Route::get('/pasien/{pasien}/edit', [PasienController::class, 'edit'])->name('pasien.edit');
        Route::put('/pasien/{pasien}', [PasienController::class, 'update'])->name('pasien.update');
        Route::delete('/pasien/{pasien}', [PasienController::class, 'destroy'])->name('pasien.destroy');
        Route::post('/kunjungan-mcu/{kunjungan}/peserta', [McuController::class, 'storePeserta'])->name('peserta.store');
        Route::get('/audit', [McuController::class, 'audits'])->name('audits.index');
    });
    Route::middleware('can:examine-mcu')->group(function (): void {
        Route::post('/kunjungan-mcu/{kunjungan}/peserta/{peserta}/foto', [PemeriksaanController::class, 'savePhoto'])->name('pemeriksaan.photo');
        Route::get('/kunjungan-mcu/{kunjungan}/peserta/{peserta}/pemeriksaan', [PemeriksaanController::class, 'edit'])->name('pemeriksaan.edit');
        Route::get('/{kunjungan}/peserta/{peserta}/pemeriksaan', [PemeriksaanController::class, 'edit'])->whereUuid(['kunjungan', 'peserta'])->name('pemeriksaan.scan');
        Route::get('/kunjungan-mcu/{kunjungan}/peserta/{peserta}/checkpoint', [PemeriksaanController::class, 'checkpoint'])->name('pemeriksaan.checkpoint');
        Route::put('/kunjungan-mcu/{kunjungan}/peserta/{peserta}/pemeriksaan', [PemeriksaanController::class, 'update'])->name('pemeriksaan.update');
        Route::post('/kunjungan-mcu/{kunjungan}/peserta/{peserta}/finalisasi', [PemeriksaanController::class, 'finalize'])->name('pemeriksaan.finalize');
        Route::post('/kunjungan-mcu/{kunjungan}/peserta/{peserta}/revisi', [PemeriksaanController::class, 'revise'])->name('pemeriksaan.revise');
    });
});
