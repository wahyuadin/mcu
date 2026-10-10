@extends('layouts.mcu')
@section('title', 'Detail Paket MCU')
@section('content')
<div class="mx-auto max-w-6xl space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4"><a href="{{ route('paket.index') }}" class="inline-flex items-center gap-2 text-sm font-semibold text-slate-500 hover:text-[#00008B]"><svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>Kembali ke daftar paket</a><div class="flex gap-2"><a href="{{ route('paket.edit', $paket) }}" class="rounded-xl bg-[#00008B] px-4 py-2.5 text-sm font-bold text-white shadow-sm">Ubah Paket</a><form action="{{ route('paket.destroy', $paket) }}" method="POST" onsubmit="return confirm('Hapus paket ini?')">@csrf @method('DELETE')<button class="rounded-xl border border-red-100 bg-red-50 px-4 py-2.5 text-sm font-bold text-red-700">Hapus</button></form></div></div>

    <section class="overflow-hidden rounded-3xl border border-slate-200/70 bg-white shadow-sm">
        <div class="bg-[#00008B] px-6 py-8 text-white sm:px-8"><p class="text-xs font-bold uppercase tracking-wider text-blue-200">Paket master pemeriksaan</p><h2 class="mt-2 text-2xl font-bold sm:text-3xl">{{ $paket->nama_paket }}</h2><p class="mt-2 text-sm text-blue-100">{{ $paket->deskripsi ?: 'Tidak ada deskripsi untuk paket ini.' }}</p></div>
        <div class="grid gap-4 p-6 sm:grid-cols-2"><div><p class="text-xs text-slate-500">Digunakan pada</p><p class="mt-2 font-bold">{{ $paket->rencanaKunjungans->count() }} rencana kunjungan</p></div><div><p class="text-xs text-slate-500">Peserta lintas kunjungan</p><p class="mt-2 font-bold">{{ $pesertas->total() }} orang</p></div></div>
        <div class="grid gap-3 border-t border-slate-100 p-6">@forelse($paket->rencanaKunjungans as $visit)<a href="{{ route('rencana-kunjungan.show', $visit) }}" class="rounded-xl border border-slate-200 p-3 text-sm font-semibold">{{ $visit->nomor_kunjungan }} — {{ $visit->perusahaan->nama_perusahaan }}</a>@empty<p class="text-sm text-slate-500">Belum digunakan. Tambahkan paket ini melalui detail Rencana Kunjungan.</p>@endforelse</div>
    </section>

    @foreach($paket->dynamic_schema ?? [] as $group)
        <section class="grid gap-4 rounded-2xl border border-slate-200 bg-white p-6"><h3 class="font-bold">{{ $group['name'] }}</h3>
            @foreach($group['items'] as $item)<div class="rounded-xl bg-slate-50 p-4"><strong class="text-sm">{{ $item['label'] }}</strong><p class="mt-1 text-xs text-slate-500">{{ \App\Models\McuItem::inputTypes()[$item['type']] ?? $item['type'] }} · {{ $item['required'] ? 'Wajib' : 'Opsional' }}</p>@if($item['type'] === 'radio')<p class="mt-2 break-words text-sm">YA → {{ $item['yes_report'] }}<br>TIDAK → {{ $item['no_report'] }}</p>@endif</div>@endforeach
        </section>
    @endforeach
    <div class="grid gap-6 lg:grid-cols-[1fr_340px]">
        <section class="rounded-2xl border border-slate-200/70 bg-white p-6 shadow-sm">
            <div class="mb-5"><h3 class="font-bold text-slate-900">Cakupan pemeriksaan</h3><p class="mt-1 text-sm text-slate-500">{{ $paket->itemCount() }} item aktif, termasuk item dinamis di atas.</p></div>
            <div class="grid gap-4 sm:grid-cols-2">
                @foreach($catalog as $group => $items)
                    @php($active = collect($items)->filter(fn ($label, $key) => $paket->isItemActive($key)))
                    @if($active->isNotEmpty())<div class="rounded-2xl border border-slate-200 p-4"><h4 class="text-xs font-bold uppercase tracking-wider text-[#00008B]">{{ ucwords(str_replace('_', ' ', $group)) }}</h4><ul class="mt-3 grid gap-2">@foreach($active as $label)<li class="text-sm text-slate-600">{{ $label }}</li>@endforeach</ul></div>@endif
                @endforeach
            </div>
        </section>
        <aside class="self-start rounded-2xl border border-slate-200/70 bg-white shadow-sm"><div class="border-b border-slate-100 px-5 py-4"><h3 class="font-bold text-slate-900">Peserta paket</h3><p class="text-xs text-slate-400">{{ $pesertas->total() }} peserta terdaftar</p></div><div class="divide-y divide-slate-100">@forelse($pesertas as $peserta)<a href="{{ route('pasien.show', $peserta) }}" class="flex items-center gap-3 px-5 py-4 hover:bg-blue-50/50"><span class="flex h-9 w-9 items-center justify-center rounded-xl bg-blue-50 text-xs font-bold text-[#00008B]">{{ strtoupper(substr($peserta->nama_karyawan, 0, 2)) }}</span><span class="min-w-0 flex-1"><strong class="block truncate text-sm text-slate-800">{{ $peserta->nama_karyawan }}</strong><small class="text-slate-400">{{ $peserta->nip ?? 'Tanpa nomor karyawan' }}</small></span><span class="text-slate-300">→</span></a>@empty<div class="px-5 py-10 text-center text-sm text-slate-400">Belum ada peserta.</div>@endforelse</div>@if($pesertas->hasPages())<div class="border-t border-slate-100 p-4">{{ $pesertas->links() }}</div>@endif</aside>
    </div>
</div>
@endsection
