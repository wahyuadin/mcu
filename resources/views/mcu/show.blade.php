@extends('layouts.mcu')
@section('title', $kunjungan->perusahaan->nama_perusahaan)
@section('content')
<p class="text-slate-500"><a href="{{ route('rencana-kunjungan.index') }}" class="font-semibold text-[#00008B]">Rencana Kunjungan</a> / {{ $kunjungan->nomor_kunjungan }} · {{ $kunjungan->periode_kunjungan }} · Kuota {{ $kunjungan->kuota_peserta }}</p>
<div class="grid gap-6 lg:grid-cols-2">
    <section class="rounded-2xl bg-white p-6 shadow-sm"><div class="flex items-center justify-between"><h2 class="text-lg font-bold">Paket kunjungan</h2>@can('manage-mcu')<a href="{{ route('paket.create', ['rencana_kunjungan_id' => $kunjungan->id]) }}" class="rounded-lg bg-[#00008B] px-4 py-2 text-sm font-semibold text-white">Buat paket</a>@endcan</div>
        <ul class="my-4 grid gap-2">@forelse($kunjungan->pakets as $paket)<li><a href="{{ route('paket.show', $paket) }}" class="block rounded-lg border border-slate-200 p-3 hover:border-[#00008B]"><strong>{{ $paket->nama_paket }}</strong><span class="block text-sm text-slate-500">{{ count(array_filter($paket->active_items ?? [])) }} item aktif · {{ $paket->isItemActive('tbc') ? 'Termasuk TBC' : 'Tanpa TBC' }}</span></a></li>@empty<li class="text-slate-500">Belum ada paket.</li>@endforelse</ul>
    </section>
    <section class="rounded-2xl bg-white p-6 shadow-sm"><div class="flex items-center justify-between"><h2 class="text-lg font-bold">Peserta terdaftar</h2>@can('manage-mcu')<a href="{{ route('pasien.create', ['rencana_kunjungan_id' => $kunjungan->id]) }}" class="rounded-lg bg-[#FF8C00] px-4 py-2 text-sm font-semibold text-white">Tambah pasien</a>@endcan</div>
        <div class="mt-5 grid gap-3">@forelse($pesertas as $peserta)<a class="rounded-lg border border-slate-200 p-3 hover:border-[#00008B]" href="{{ route('pasien.show', $peserta) }}"><strong>{{ $peserta->nama_karyawan }}</strong><span class="block text-sm text-slate-500">{{ $peserta->paket->nama_paket }} · {{ $peserta->status_pemeriksaan }}</span></a>@empty<p class="text-slate-500">Belum ada peserta.</p>@endforelse</div>
        <div class="mt-5">{{ $pesertas->links() }}</div>
    </section>
</div>
@endsection
