@extends('layouts.mcu')
@section('title', 'Master Data Dokter')
@section('content')
<div class="mx-auto grid max-w-6xl gap-6">
    <div class="flex flex-wrap items-center justify-between gap-4"><div><h2 class="text-2xl font-bold">Dokter</h2><p class="mt-2 text-sm text-slate-500">Kelola dokter pemeriksa dan penanggung jawab MCU.</p></div><a href="{{ route('dokter.create') }}" class="rounded-xl bg-[#00008B] px-5 py-3 text-sm font-semibold text-white">+ Tambah dokter</a></div>
    <section class="grid gap-5 rounded-2xl border border-slate-200 bg-white p-6">
        <form method="GET" class="flex flex-wrap gap-3"><input name="q" value="{{ request('q') }}" placeholder="Cari nama, STR, atau spesialisasi" aria-label="Cari dokter" class="min-h-12 min-w-0 flex-1 rounded-xl border border-slate-300 px-3"><select name="status" aria-label="Status dokter" class="min-h-12 rounded-xl border border-slate-300 px-3"><option value="">Semua status</option><option value="aktif" @selected(request('status') === 'aktif')>Aktif</option><option value="nonaktif" @selected(request('status') === 'nonaktif')>Nonaktif</option></select><button class="rounded-xl border border-slate-300 px-5 py-3 text-sm font-semibold">Cari</button></form>
        <div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead class="bg-slate-50 text-xs uppercase text-slate-500"><tr><th class="p-3">Nama dokter</th><th class="p-3">Nomor STR</th><th class="p-3">Spesialisasi</th><th class="p-3">Status</th><th class="p-3">Aksi</th></tr></thead><tbody class="divide-y divide-slate-100">
            @forelse($dokters as $dokter)<tr><td class="p-3 font-semibold">{{ $dokter->nama_dokter }}</td><td class="p-3">{{ $dokter->no_str ?? '—' }}</td><td class="p-3">{{ $dokter->spesialisasi }}</td><td class="p-3"><span class="rounded-full px-3 py-1 text-xs font-semibold {{ $dokter->is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500' }}">{{ $dokter->is_active ? 'Aktif' : 'Nonaktif' }}</span></td><td class="p-3"><a href="{{ route('dokter.edit', $dokter) }}" class="font-semibold text-[#00008B]">Ubah →</a></td></tr>
            @empty<tr><td colspan="5" class="p-8 text-center text-slate-500">Dokter tidak ditemukan.</td></tr>@endforelse
        </tbody></table></div>{{ $dokters->links() }}
    </section>
</div>
@endsection
