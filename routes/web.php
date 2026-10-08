<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\McuController;
use App\Http\Controllers\PaketController;
use App\Http\Controllers\PasienController;
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
        Route::get('/pasien', [PasienController::class, 'index'])->name('pasien.index');
        Route::get('/pasien/{pasien}', [PasienController::class, 'show'])->whereUuid('pasien')->name('pasien.show');
    });
    Route::middleware('can:manage-mcu')->group(function (): void {
        Route::get('/perusahaan', [McuController::class, 'perusahaans'])->name('perusahaan.index');
        Route::post('/perusahaan', [McuController::class, 'storePerusahaan'])->name('perusahaan.store');
        Route::get('/rencana-kunjungan', [McuController::class, 'kunjungans'])->name('rencana-kunjungan.index');
        Route::post('/rencana-kunjungan', [McuController::class, 'storeKunjungan'])->name('rencana-kunjungan.store');
        Route::get('/rencana-kunjungan/{kunjungan}', [McuController::class, 'show'])->name('rencana-kunjungan.show');
        Route::resource('paket', PaketController::class)->parameters(['paket' => 'paket']);
        Route::get('/pasien/create', [PasienController::class, 'create'])->name('pasien.create');
        Route::post('/pasien', [PasienController::class, 'store'])->name('pasien.store');
        Route::get('/pasien/{pasien}/edit', [PasienController::class, 'edit'])->name('pasien.edit');
        Route::put('/pasien/{pasien}', [PasienController::class, 'update'])->name('pasien.update');
        Route::delete('/pasien/{pasien}', [PasienController::class, 'destroy'])->name('pasien.destroy');
        Route::post('/rencana-kunjungan/{kunjungan}/peserta', [McuController::class, 'storePeserta'])->name('peserta.store');
        Route::get('/audit', [McuController::class, 'audits'])->name('audits.index');
    });
    Route::middleware('can:examine-mcu')->group(function (): void {
        Route::get('/rencana-kunjungan/{kunjungan}/peserta/{peserta}/pemeriksaan', [PemeriksaanController::class, 'edit'])->name('pemeriksaan.edit');
        Route::put('/rencana-kunjungan/{kunjungan}/peserta/{peserta}/pemeriksaan', [PemeriksaanController::class, 'update'])->name('pemeriksaan.update');
    });
});
