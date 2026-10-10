@extends('layouts.mcu')
@section('title', 'Perusahaan')
@section('content')
<div class="grid gap-6 xl:grid-cols-[380px_1fr]">
    <section class="h-fit min-w-0 rounded-2xl border border-slate-100 bg-white p-6 shadow-md shadow-slate-200/50">
        <h2 class="text-lg font-bold text-slate-800">Tambah Perusahaan</h2><p class="mb-5 text-xs text-slate-400">Daftarkan perusahaan klien MCU baru.</p>
        <form action="{{ route('perusahaan.store') }}" method="POST" enctype="multipart/form-data" class="grid gap-3">
            @csrf
            <input name="nama_perusahaan" value="{{ old('nama_perusahaan') }}" placeholder="Nama perusahaan" required class="rounded-xl border border-slate-200 p-3">
            <input name="telp" value="{{ old('telp') }}" placeholder="Telepon" class="rounded-xl border border-slate-200 p-3">
            <input name="email" value="{{ old('email') }}" type="email" placeholder="Email" class="rounded-xl border border-slate-200 p-3">
            <textarea name="alamat" placeholder="Alamat" class="rounded-xl border border-slate-200 p-3">{{ old('alamat') }}</textarea>
            <div class="grid gap-3 rounded-xl border border-dashed border-slate-300 bg-slate-50 p-4">
                <div class="flex items-center gap-3">@include('mcu.company-icon')<div><p class="text-sm font-semibold text-slate-700">Logo perusahaan (opsional)</p><p class="mt-1 text-xs text-slate-500">Tanpa logo, ikon perusahaan ini digunakan.</p></div></div>
                <label class="grid min-w-0 gap-2 text-sm text-slate-600">Upload logo<input type="file" name="logo" accept="image/jpeg,image/png,image/webp" aria-describedby="logo-help" class="w-full min-w-0 text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-blue-50 file:px-3 file:py-2 file:font-semibold file:text-[#00008B]"></label>
                <p id="logo-help" class="text-xs text-slate-500">JPG, PNG, atau WebP. Maksimal 2 MB.</p>
            </div>
            <button class="rounded-xl bg-[#00008B] p-3 font-semibold text-white shadow-lg shadow-[#00008B]/20">Simpan Perusahaan</button>
        </form>
    </section>
    <section class="min-w-0 overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-md shadow-slate-200/50">
        <div class="border-b border-slate-100 p-6"><h2 class="text-lg font-bold text-slate-800">Daftar Perusahaan</h2><p class="text-xs text-slate-400">Perusahaan beserta jumlah kunjungan dan peserta.</p></div>
        <div class="overflow-x-auto"><table class="w-full text-left text-sm text-slate-600">
            <thead class="bg-slate-50 text-xs uppercase text-slate-400"><tr><th class="px-6 py-4">Perusahaan</th><th class="px-6 py-4">Kontak</th><th class="px-6 py-4">Kunjungan</th><th class="px-6 py-4">Peserta</th><th class="px-6 py-4">Logo</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($perusahaans as $perusahaan)
                    <tr class="hover:bg-slate-50/50">
                        <td class="px-6 py-4"><div class="flex items-center gap-3">
                            @if($perusahaan->logo_path)<img src="{{ route('perusahaan.logo', $perusahaan) }}" alt="Logo {{ $perusahaan->nama_perusahaan }}" loading="lazy" class="h-12 w-12 shrink-0 rounded-xl border border-slate-100 bg-white object-contain p-1">@else @include('mcu.company-icon') @endif
                            <div class="min-w-0"><strong class="break-words text-slate-800">{{ $perusahaan->nama_perusahaan }}</strong><small class="block max-w-xs text-slate-400">{{ $perusahaan->alamat }}</small></div>
                        </div></td>
                        <td class="px-6 py-4">{{ $perusahaan->telp ?? '—' }}<small class="block">{{ $perusahaan->email }}</small></td>
                        <td class="px-6 py-4">{{ $perusahaan->rencana_kunjungans_count }}</td><td class="px-6 py-4">{{ $perusahaan->karyawans_count }}</td>
                        <td class="px-6 py-4"><form action="{{ route('perusahaan.logo.update', $perusahaan) }}" method="POST" enctype="multipart/form-data" class="grid min-w-48 gap-2">
                            @csrf @method('PUT')
                            <label class="grid gap-2 text-xs font-semibold">{{ $perusahaan->logo_path ? 'Ganti logo' : 'Upload logo' }}<input type="file" name="logo" required accept="image/jpeg,image/png,image/webp" aria-label="Logo {{ $perusahaan->nama_perusahaan }}" class="w-48 text-xs file:mr-2 file:rounded-lg file:border-0 file:bg-slate-100 file:px-2 file:py-2"></label>
                            <button type="submit" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl border border-blue-200 bg-blue-50 px-3 py-2 text-xs font-semibold text-[#00008B]"><svg aria-hidden="true" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M12 16V4m-4 4 4-4 4 4M4 16v4h16v-4"/></svg>Simpan logo</button>
                        </form></td>
                    </tr>
                @empty<tr><td colspan="5" class="px-6 py-12 text-center text-slate-400">Belum ada perusahaan.</td></tr>@endforelse
            </tbody>
        </table></div>
        <div class="border-t border-slate-100 p-5">{{ $perusahaans->links() }}</div>
    </section>
</div>
@endsection
