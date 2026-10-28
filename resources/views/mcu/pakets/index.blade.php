@extends('layouts.mcu')
@section('title', 'Paket MCU')
@section('content')
<div class="space-y-6">
    <section class="flex flex-col gap-5 rounded-3xl border border-slate-200/70 bg-white p-6 shadow-sm sm:flex-row sm:items-center sm:justify-between sm:p-8">
        <div><p class="text-xs font-bold uppercase tracking-[0.18em] text-[#00008B]">Master layanan</p><h2 class="mt-1 text-2xl font-bold tracking-tight text-slate-900">Paket Pemeriksaan MCU</h2><p class="mt-2 text-sm leading-6 text-slate-500">Konfigurasi paket sekali, lalu gunakan di beberapa rencana kunjungan dan perusahaan.</p></div>
        <a href="{{ route('paket.create') }}" class="inline-flex items-center justify-center gap-2 rounded-xl bg-[#00008B] px-5 py-3 text-sm font-bold text-white shadow-lg shadow-blue-900/15 transition-transform duration-150 active:scale-[.97]"><svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>Buat Paket</a>
    </section>

    <section class="grid gap-4 sm:grid-cols-3">
        @foreach([['Total paket', $stats['total'], 'bg-blue-50 text-[#00008B]'], ['Total item aktif', $stats['items'], 'bg-violet-50 text-violet-700'], ['Peserta terdaftar', $stats['participants'], 'bg-emerald-50 text-emerald-700']] as [$label, $value, $tone])
            <article class="rounded-2xl border border-slate-200/70 bg-white p-5 shadow-sm"><div class="flex items-center justify-between"><p class="text-xs font-bold uppercase tracking-wider text-slate-400">{{ $label }}</p><span class="h-2 w-2 rounded-full {{ $tone }}"></span></div><p class="mt-3 text-3xl font-extrabold text-slate-900">{{ number_format($value) }}</p></article>
        @endforeach
    </section>

    <section data-bulk-delete class="overflow-hidden rounded-2xl border border-slate-200/70 bg-white shadow-sm">
        <form action="{{ route('paket.index') }}" class="flex flex-col gap-3 border-b border-slate-100 p-5 sm:flex-row sm:items-center sm:justify-between">
            <div><h3 class="font-bold text-slate-800">Semua paket</h3><p class="text-xs text-slate-400">{{ $pakets->total() }} paket ditemukan</p></div>
            <label class="relative w-full sm:max-w-sm"><svg class="pointer-events-none absolute left-3.5 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m21 21-4.35-4.35m1.35-5.65a7 7 0 11-14 0 7 7 0 0114 0z"/></svg><input name="q" value="{{ $search }}" placeholder="Cari paket..." class="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 pl-11 pr-4 text-sm outline-none transition focus:border-[#00008B] focus:bg-white focus:ring-4 focus:ring-blue-100"></label>
        </form>
        @include('mcu.master-bulk-delete', ['bulkRoute' => 'paket.bulk-destroy', 'bulkFilters' => ['q', 'page']])
        <div class="overflow-x-auto"><table class="w-full min-w-[700px] text-left"><thead><tr class="border-b border-slate-100 bg-slate-50/70 text-[11px] font-bold uppercase tracking-wider text-slate-400">@include('mcu.master-select-all')<th class="px-6 py-4">Nama paket</th><th class="px-6 py-4">Digunakan pada</th><th class="px-6 py-4">Cakupan</th><th class="px-6 py-4">Peserta</th><th class="px-6 py-4 text-right">Aksi</th></tr></thead><tbody class="divide-y divide-slate-100">
            @forelse($pakets as $paket)
                <tr class="hover:bg-blue-50/30">
                    @include('mcu.master-select-row', ['selectionId' => $paket->id, 'selectionName' => $paket->nama_paket])
                    <td class="px-6 py-4"><a href="{{ route('paket.show', $paket) }}" class="font-bold text-slate-800">{{ $paket->nama_paket }}</a><p class="mt-1 max-w-60 truncate text-xs text-slate-400">{{ $paket->deskripsi ?: 'Tanpa deskripsi' }}</p></td>
                    <td class="px-6 py-4 text-sm text-slate-600">{{ $paket->rencanaKunjungans->count() }} rencana kunjungan<p class="mt-1 text-xs text-slate-400">{{ $paket->rencanaKunjungans->pluck('perusahaan.nama_perusahaan')->unique()->filter()->join(', ') ?: 'Belum digunakan' }}</p></td>
                    <td class="px-6 py-4 text-sm font-bold">{{ $paket->itemCount() }} item</td>
                    <td class="px-6 py-4 text-sm font-bold">{{ $paket->karyawans_count }}</td>
                    <td class="px-6 py-4 text-right">@include('mcu.master-actions', ['name' => $paket->nama_paket, 'detailUrl' => route('paket.show', $paket), 'editUrl' => route('paket.edit', $paket), 'deleteUrl' => route('paket.destroy', $paket)])</td>
                </tr>
            @empty<tr><td colspan="6" class="px-6 py-16 text-center"><p class="font-bold text-slate-700">Belum ada paket pemeriksaan</p><p class="mt-1 text-sm text-slate-400">Buat paket pertama untuk memulai.</p></td></tr>@endforelse
        </tbody></table></div>
        @if($pakets->hasPages())<div class="border-t border-slate-100 px-6 py-4">{{ $pakets->links() }}</div>@endif
    </section>
</div>
@endsection
