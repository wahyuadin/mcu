@extends('layouts.mcu')
@section('title', 'Edit Perusahaan')
@section('content')
<div class="mx-auto grid max-w-3xl gap-5">
    <a href="{{ route('perusahaan.index') }}" class="justify-self-start text-sm font-semibold text-slate-500">← Daftar perusahaan</a>
    <form action="{{ route('perusahaan.update', $perusahaan) }}" method="POST" class="grid gap-5 rounded-2xl border border-slate-200 bg-white p-6">
        @csrf @method('PUT')
        <div class="flex min-w-0 items-center gap-3">
            @if($perusahaan->logo_path)<img src="{{ route('perusahaan.logo', $perusahaan) }}" alt="Logo {{ $perusahaan->nama_perusahaan }}" class="h-16 w-16 shrink-0 rounded-xl border border-slate-100 bg-white object-contain p-2">@else @include('mcu.company-icon') @endif
            <h2 class="min-w-0 break-words text-xl font-bold">Edit perusahaan</h2>
        </div>
        @include('mcu.field', ['field' => 'nama_perusahaan', 'label' => 'Nama perusahaan (wajib)', 'value' => $perusahaan->nama_perusahaan])
        @include('mcu.field', ['field' => 'alamat', 'label' => 'Alamat', 'type' => 'textarea', 'value' => $perusahaan->alamat])
        @include('mcu.field', ['field' => 'email', 'label' => 'Email', 'type' => 'email', 'value' => $perusahaan->email])
        @include('mcu.field', ['field' => 'telp', 'label' => 'No. telp', 'type' => 'tel', 'value' => $perusahaan->telp])
        <div class="flex flex-wrap gap-3"><button type="submit" class="rounded-xl bg-[#00008B] px-5 py-3 font-semibold text-white">Simpan perubahan</button><a href="{{ route('perusahaan.index') }}" class="rounded-xl border border-slate-300 px-5 py-3 font-semibold text-slate-600">Batal</a></div>
    </form>
    <form action="{{ route('perusahaan.logo.update', $perusahaan) }}" method="POST" enctype="multipart/form-data" class="grid gap-3 rounded-2xl border border-slate-200 bg-white p-6">
        @csrf @method('PUT')
        <h3 class="font-bold">Logo perusahaan</h3>
        <label class="grid min-w-0 gap-2 text-sm font-semibold">{{ $perusahaan->logo_path ? 'Ganti logo' : 'Upload logo' }}<input type="file" name="logo" required accept="image/jpeg,image/png,image/webp" class="min-w-0 rounded-xl border border-slate-300 p-3"></label>
        <p class="text-xs text-slate-500">JPG, PNG, atau WebP · maksimal 2 MB. Logo disimpan terpisah dari perubahan data perusahaan.</p>
        @error('logo')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
        <button type="submit" class="justify-self-start rounded-xl border border-blue-200 bg-blue-50 px-4 py-3 text-sm font-semibold text-[#00008B]">Simpan logo</button>
    </form>
</div>
@endsection
