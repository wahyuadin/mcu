@extends('layouts.mcu')
@section('title', 'Foto pasien sebelum pemeriksaan')
@section('content')
<div data-examination-page data-readonly="0" class="mx-auto grid max-w-5xl gap-6">
    <a href="{{ route('kunjungan-mcu.show', $kunjungan) }}" class="justify-self-start text-sm font-semibold text-slate-500">← Peserta kunjungan</a>
    <section class="rounded-2xl border border-slate-200 bg-white p-6 sm:p-8"><p class="text-xs font-bold uppercase tracking-wider text-emerald-700">Langkah awal pemeriksaan</p><h2 class="mt-2 text-2xl font-bold">{{ $hasPhoto ? 'Ganti foto pasien' : 'Foto pasien terlebih dahulu' }}</h2><p class="mt-3 text-sm text-slate-500">{{ $peserta->nama_karyawan }} · {{ $peserta->nomor_mcu }}</p><p class="mt-2 text-sm text-slate-500">Foto wajib tersimpan sebelum formulir dapat dibuka. Pilih kamera realtime atau upload gambar.</p></section>
    @include('mcu.patient-photo-form')
</div>
@endsection
