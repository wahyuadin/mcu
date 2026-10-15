@extends('layouts.mcu')
@section('title', 'Dashboard Hasil MCU')
@section('content')
<section class="mx-auto grid max-w-3xl gap-4 rounded-2xl border border-slate-200 bg-white p-6"><h2 class="text-xl font-bold">Belum ada perusahaan</h2><p class="text-sm text-slate-500">Daftarkan perusahaan dan rencana kunjungan terlebih dahulu untuk memulai dashboard HRD.</p><a href="{{ route('perusahaan.index') }}" class="min-h-11 justify-self-start rounded-xl bg-[#00008B] px-5 py-3 text-sm font-semibold text-white">Kelola perusahaan</a></section>
@endsection
