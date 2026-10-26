@extends('layouts.mcu')
@section('title', $dokter->exists ? 'Ubah Dokter' : 'Tambah Dokter')
@section('content')
<form method="POST" action="{{ $dokter->exists ? route('dokter.update', $dokter) : route('dokter.store') }}" class="mx-auto grid max-w-3xl gap-6 rounded-2xl border border-slate-200 bg-white p-6">
    @csrf
    @if($dokter->exists) @method('PUT') @endif
    <a href="{{ route('dokter.index') }}" class="text-sm font-semibold text-slate-500">← Master Data Dokter</a>
    <h2 class="text-xl font-bold">{{ $dokter->exists ? 'Ubah data dokter' : 'Daftarkan dokter' }}</h2>
    @include('mcu.field', ['field' => 'nama_dokter', 'label' => 'Nama dokter (wajib)', 'value' => $dokter->nama_dokter])
    @include('mcu.field', ['field' => 'no_str', 'label' => 'Nomor STR', 'value' => $dokter->no_str])
    @include('mcu.field', ['field' => 'is_active', 'label' => 'Status', 'type' => 'select', 'value' => $dokter->is_active ? '1' : '0', 'options' => ['1' => 'Aktif', '0' => 'Nonaktif']])
    <p class="text-sm text-slate-500">Dokter nonaktif tidak dapat dipilih untuk hasil baru. Riwayat pemeriksaan sebelumnya tetap tersimpan.</p>
    <button class="justify-self-start rounded-xl bg-[#00008B] px-5 py-3 font-semibold text-white">Simpan dokter</button>
</form>
@endsection
