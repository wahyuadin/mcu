@extends('layouts.mcu')
@section('title', 'Daftar Pasien')
@section('content')
<div class="space-y-6">
    <section class="relative overflow-hidden rounded-3xl bg-[#00008B] px-6 py-7 text-white shadow-xl shadow-blue-950/10 sm:px-8">
        <div class="absolute -right-16 -top-20 h-64 w-64 rounded-full bg-white/10"></div>
        <div class="absolute -bottom-20 right-36 h-40 w-40 rounded-full border-[28px] border-white/5"></div>
        <div class="relative flex flex-col gap-6 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="mb-2 text-xs font-bold uppercase tracking-[0.2em] text-blue-200">Manajemen peserta MCU</p>
                <h2 class="text-2xl font-bold tracking-tight sm:text-3xl">Data Pasien</h2>
                <p class="mt-2 max-w-xl text-sm leading-6 text-blue-100">Cari data peserta, pantau progres pemeriksaan, dan buka rekam MCU dari satu tempat.</p>
            </div>
            @can('manage-mcu')
                <a href="{{ route('pasien.create') }}" class="inline-flex shrink-0 items-center justify-center gap-2 rounded-xl bg-white px-5 py-3 text-sm font-bold text-[#00008B] shadow-lg shadow-blue-950/20 transition-transform duration-150 active:scale-[.97]">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    Tambah Pasien
                </a>
            @endcan
        </div>
    </section>

    <section class="grid gap-4 sm:grid-cols-3">
        @foreach([
            ['label' => 'Total pasien', 'value' => $stats['total'], 'color' => 'text-[#00008B]', 'bg' => 'bg-blue-50'],
            ['label' => 'Dalam proses', 'value' => $stats['process'], 'color' => 'text-amber-700', 'bg' => 'bg-amber-50'],
            ['label' => 'Pemeriksaan selesai', 'value' => $stats['completed'], 'color' => 'text-emerald-700', 'bg' => 'bg-emerald-50'],
        ] as $stat)
            <article class="flex items-center gap-4 rounded-2xl border border-slate-200/70 bg-white p-5 shadow-sm">
                <div class="flex h-11 w-11 items-center justify-center rounded-xl {{ $stat['bg'] }} {{ $stat['color'] }}">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a4 4 0 00-4-4h-1M9 20H2v-2a4 4 0 014-4h3m6 6v-2a4 4 0 00-4-4H9a4 4 0 00-4 4v2m10-12a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                </div>
                <div><p class="text-xs font-semibold uppercase tracking-wider text-slate-400">{{ $stat['label'] }}</p><p class="mt-1 text-2xl font-extrabold text-slate-900">{{ number_format($stat['value']) }}</p></div>
            </article>
        @endforeach
    </section>

    <section class="overflow-hidden rounded-2xl border border-slate-200/70 bg-white shadow-sm">
        <form action="{{ route('pasien.index') }}" class="grid gap-3 border-b border-slate-100 p-4 sm:grid-cols-[minmax(260px,1fr)_220px_180px_auto] sm:p-5">
            <label class="relative">
                <svg class="pointer-events-none absolute left-3.5 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m21 21-4.35-4.35m1.35-5.65a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                <input name="q" value="{{ request('q') }}" placeholder="Cari nama, NIK, atau nomor karyawan..." class="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 pl-11 pr-4 text-sm outline-none transition focus:border-[#00008B] focus:bg-white focus:ring-4 focus:ring-blue-100">
            </label>
            <select name="perusahaan" class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none focus:border-[#00008B] focus:ring-4 focus:ring-blue-100"><option value="">Semua perusahaan</option>@foreach($perusahaans as $perusahaan)<option value="{{ $perusahaan->id }}" @selected(request('perusahaan') === $perusahaan->id)>{{ $perusahaan->nama_perusahaan }}</option>@endforeach</select>
            <select name="status" class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none focus:border-[#00008B] focus:ring-4 focus:ring-blue-100"><option value="">Semua status</option>@foreach(['belum' => 'Belum diperiksa', 'proses' => 'Dalam proses', 'selesai' => 'Selesai'] as $key => $label)<option value="{{ $key }}" @selected(request('status') === $key)>{{ $label }}</option>@endforeach</select>
            <button class="h-11 rounded-xl bg-slate-900 px-5 text-sm font-bold text-white transition-colors hover:bg-[#00008B] active:scale-[.97]">Terapkan</button>
        </form>

        <div class="overflow-x-auto">
            <table class="w-full min-w-[900px] text-left">
                <thead><tr class="border-b border-slate-100 bg-slate-50/70 text-[11px] font-bold uppercase tracking-wider text-slate-400"><th class="px-6 py-4">Pasien</th><th class="px-6 py-4">Perusahaan</th><th class="px-6 py-4">Kunjungan & paket</th><th class="px-6 py-4">Status</th><th class="px-6 py-4 text-right">Aksi</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($pasiens as $pasien)
                        @php
                            $statusStyle = match($pasien->status_pemeriksaan) {
                                'selesai' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/10',
                                'proses' => 'bg-amber-50 text-amber-700 ring-amber-600/10',
                                default => 'bg-slate-100 text-slate-600 ring-slate-500/10',
                            };
                        @endphp
                        <tr class="group transition-colors hover:bg-blue-50/30">
                            <td class="px-6 py-4"><div class="flex items-center gap-3"><div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-[#00008B] text-sm font-bold text-white">{{ strtoupper(substr($pasien->nama_karyawan, 0, 2)) }}</div><div><a href="{{ route('pasien.show', $pasien) }}" class="font-bold text-slate-800 group-hover:text-[#00008B]">{{ $pasien->nama_karyawan }}</a><p class="mt-0.5 text-xs text-slate-400">NIK {{ $pasien->nik ?? '—' }} · ID {{ $pasien->nip ?? '—' }}</p></div></div></td>
                            <td class="px-6 py-4"><p class="text-sm font-semibold text-slate-700">{{ $pasien->perusahaan->nama_perusahaan }}</p><p class="mt-0.5 text-xs text-slate-400">{{ $pasien->bagian ?: 'Departemen belum diisi' }}</p></td>
                            <td class="px-6 py-4"><p class="text-sm font-semibold text-slate-700">{{ $pasien->rencanaKunjungan->nomor_kunjungan }}</p><p class="mt-0.5 text-xs text-slate-400">{{ $pasien->paket->nama_paket }}</p></td>
                            <td class="px-6 py-4"><span class="inline-flex rounded-full px-2.5 py-1 text-xs font-bold capitalize ring-1 ring-inset {{ $statusStyle }}">{{ $pasien->status_pemeriksaan === 'belum' ? 'Belum diperiksa' : $pasien->status_pemeriksaan }}</span></td>
                            <td class="px-6 py-4 text-right"><a href="{{ route('pasien.show', $pasien) }}" class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-slate-200 text-slate-500 transition hover:border-[#00008B] hover:bg-[#00008B] hover:text-white" aria-label="Lihat {{ $pasien->nama_karyawan }}"><svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg></a></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-6 py-16 text-center"><div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-100 text-slate-400"><svg class="h-7 w-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a4 4 0 00-4-4h-1M15 8a4 4 0 11-8 0 4 4 0 018 0zM2 20v-2a4 4 0 014-4h5a4 4 0 014 4v2"/></svg></div><p class="mt-4 font-bold text-slate-700">Pasien tidak ditemukan</p><p class="mt-1 text-sm text-slate-400">Coba ubah kata kunci atau filter pencarian.</p></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($pasiens->hasPages())<div class="border-t border-slate-100 px-6 py-4">{{ $pasiens->links() }}</div>@endif
    </section>
</div>
@endsection
