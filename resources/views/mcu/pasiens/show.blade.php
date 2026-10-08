@extends('layouts.mcu')
@section('title', 'Detail Pasien')
@section('content')
<div class="mx-auto max-w-6xl space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <a href="{{ route('pasien.index') }}" class="inline-flex items-center gap-2 text-sm font-semibold text-slate-500 hover:text-[#00008B]"><svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>Kembali ke daftar pasien</a>
        @can('manage-mcu')<div class="flex gap-2"><a href="{{ route('pasien.edit', $pasien) }}" class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 shadow-sm hover:border-[#00008B] hover:text-[#00008B]"><svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7m-1.5-9.5a2.12 2.12 0 013 3L12 14l-4 1 1-4 7.5-7.5z"/></svg>Ubah</a><form action="{{ route('pasien.destroy', $pasien) }}" method="POST" onsubmit="return confirm('Hapus pasien ini?')">@csrf @method('DELETE')<button class="inline-flex items-center rounded-xl border border-red-100 bg-red-50 px-4 py-2.5 text-sm font-bold text-red-700 hover:bg-red-100">Hapus</button></form></div>@endcan
    </div>

    <section class="relative overflow-hidden rounded-3xl bg-[#00008B] p-6 text-white shadow-xl shadow-blue-950/10 sm:p-8">
        <div class="absolute -right-16 -top-20 h-64 w-64 rounded-full bg-white/10"></div>
        <div class="relative flex flex-col gap-6 sm:flex-row sm:items-center">
            <div class="flex h-20 w-20 shrink-0 items-center justify-center rounded-2xl border border-white/20 bg-white/15 text-2xl font-extrabold shadow-inner">{{ strtoupper(substr($pasien->nama_karyawan, 0, 2)) }}</div>
            <div class="min-w-0 flex-1"><div class="flex flex-wrap items-center gap-3"><h2 class="text-2xl font-bold sm:text-3xl">{{ $pasien->nama_karyawan }}</h2><span class="rounded-full bg-white/15 px-3 py-1 text-xs font-bold capitalize ring-1 ring-white/20">{{ $pasien->status_pemeriksaan === 'belum' ? 'Belum diperiksa' : $pasien->status_pemeriksaan }}</span></div><p class="mt-2 text-sm text-blue-100">{{ $pasien->perusahaan->nama_perusahaan }} · {{ $pasien->bagian ?: 'Departemen belum diisi' }}</p><div class="mt-4 flex flex-wrap gap-x-6 gap-y-2 text-xs text-blue-100"><span>NIK: <strong class="text-white">{{ $pasien->nik ?? '—' }}</strong></span><span>No. Karyawan: <strong class="text-white">{{ $pasien->nip ?? '—' }}</strong></span></div></div>
            @can('examine-mcu')<a href="{{ route('pemeriksaan.edit', [$pasien->rencanaKunjungan, $pasien]) }}" class="inline-flex shrink-0 items-center justify-center gap-2 rounded-xl bg-[#FF8C00] px-5 py-3 text-sm font-bold text-white shadow-lg shadow-orange-950/20 active:scale-[.97]"><svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>Buka Pemeriksaan</a>@endcan
        </div>
    </section>

    <div class="grid gap-6 lg:grid-cols-[1fr_360px]">
        <section class="overflow-hidden rounded-2xl border border-slate-200/70 bg-white shadow-sm">
            <div class="border-b border-slate-100 px-6 py-5"><h3 class="font-bold text-slate-900">Informasi pribadi</h3><p class="mt-1 text-sm text-slate-400">Identitas dan kontak pasien.</p></div>
            <dl class="grid sm:grid-cols-2">
                @foreach(['Nama lengkap' => $pasien->nama_karyawan, 'NIK' => $pasien->nik, 'Tanggal lahir' => $pasien->tanggal_lahir?->translatedFormat('d F Y'), 'Usia' => $pasien->usia ? $pasien->usia.' tahun' : null, 'Jenis kelamin' => $pasien->jenis_kelamin === 'P' ? 'Perempuan' : 'Laki-laki', 'Nomor telepon' => $pasien->telepon] as $label => $value)
                    <div class="border-b border-slate-100 px-6 py-4 odd:sm:border-r"><dt class="text-xs font-semibold uppercase tracking-wider text-slate-400">{{ $label }}</dt><dd class="mt-1.5 text-sm font-bold text-slate-800">{{ $value ?? '—' }}</dd></div>
                @endforeach
            </dl>
            <div class="border-b border-slate-100 px-6 py-5"><h3 class="font-bold text-slate-900">Informasi pekerjaan</h3></div>
            <dl class="grid sm:grid-cols-2">
                @foreach(['Perusahaan' => $pasien->perusahaan->nama_perusahaan, 'Nomor karyawan' => $pasien->nip, 'Departemen' => $pasien->bagian, 'Jabatan' => $pasien->jabatan] as $label => $value)
                    <div class="border-b border-slate-100 px-6 py-4 odd:sm:border-r"><dt class="text-xs font-semibold uppercase tracking-wider text-slate-400">{{ $label }}</dt><dd class="mt-1.5 text-sm font-bold text-slate-800">{{ $value ?? '—' }}</dd></div>
                @endforeach
            </dl>
        </section>

        <aside class="space-y-4">
            <section class="rounded-2xl border border-slate-200/70 bg-white p-6 shadow-sm"><p class="text-xs font-bold uppercase tracking-wider text-slate-400">Kunjungan MCU</p><h3 class="mt-2 text-lg font-bold text-slate-900">{{ $pasien->rencanaKunjungan->nomor_kunjungan }}</h3><p class="mt-1 text-sm text-slate-500">{{ $pasien->rencanaKunjungan->periode_kunjungan }}</p><div class="my-5 h-px bg-slate-100"></div><p class="text-xs font-bold uppercase tracking-wider text-slate-400">Paket pemeriksaan</p>@can('manage-mcu')<a href="{{ route('paket.show', $pasien->paket) }}" class="mt-2 flex items-center justify-between rounded-xl bg-blue-50 p-4 text-sm font-bold text-[#00008B]"><span>{{ $pasien->paket->nama_paket }}</span><span>→</span></a>@else<div class="mt-2 rounded-xl bg-blue-50 p-4 text-sm font-bold text-[#00008B]">{{ $pasien->paket->nama_paket }}</div>@endcan</section>
            <section class="rounded-2xl border border-slate-200/70 bg-white p-6 shadow-sm"><p class="text-xs font-bold uppercase tracking-wider text-slate-400">Status pemeriksaan</p><div class="mt-4 flex items-center gap-3"><span class="relative flex h-3 w-3"><span class="absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-30"></span><span class="relative inline-flex h-3 w-3 rounded-full {{ $pasien->status_pemeriksaan === 'selesai' ? 'bg-emerald-500' : 'bg-amber-500' }}"></span></span><p class="font-bold capitalize text-slate-800">{{ $pasien->status_pemeriksaan === 'belum' ? 'Belum diperiksa' : $pasien->status_pemeriksaan }}</p></div></section>
        </aside>
    </div>
</div>
@endsection
