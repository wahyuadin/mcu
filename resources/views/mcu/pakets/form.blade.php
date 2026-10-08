@extends('layouts.mcu')
@section('title', $paket->exists ? 'Ubah Paket MCU' : 'Buat Paket MCU')
@section('content')
<form action="{{ $paket->exists ? route('paket.update', $paket) : route('paket.store') }}" method="POST" class="mx-auto max-w-6xl space-y-6">
    @csrf
    @if($paket->exists) @method('PUT') @endif

    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-center gap-4">
            <a href="{{ $paket->exists ? route('paket.show', $paket) : route('paket.index') }}" class="flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-500 shadow-sm hover:border-[#00008B] hover:text-[#00008B]" aria-label="Kembali"><svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg></a>
            <div><p class="text-xs font-bold uppercase tracking-wider text-[#00008B]">Konfigurasi paket</p><h2 class="mt-1 text-2xl font-bold text-slate-900">{{ $paket->exists ? 'Ubah paket pemeriksaan' : 'Paket pemeriksaan baru' }}</h2></div>
        </div>
        <button class="inline-flex items-center justify-center gap-2 rounded-xl bg-[#00008B] px-6 py-3 text-sm font-bold text-white shadow-lg shadow-blue-900/15 active:scale-[.97]"><svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>Simpan Paket</button>
    </div>

    <div class="grid gap-6 lg:grid-cols-[340px_minmax(0,1fr)]">
        <aside class="space-y-5 self-start rounded-2xl border border-slate-200/70 bg-white p-6 shadow-sm lg:sticky lg:top-6">
            <div><h3 class="font-bold text-slate-900">Informasi paket</h3><p class="mt-1 text-sm leading-5 text-slate-400">Tentukan kunjungan dan nama yang mudah dikenali.</p></div>
            <label class="grid gap-2 text-sm font-semibold text-slate-700">Rencana kunjungan<select name="rencana_kunjungan_id" required class="h-12 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none focus:border-[#00008B] focus:ring-4 focus:ring-blue-100"><option value="">Pilih kunjungan</option>@foreach($kunjungans as $kunjungan)<option value="{{ $kunjungan->id }}" @selected(old('rencana_kunjungan_id', $paket->rencana_kunjungan_id ?? request('rencana_kunjungan_id')) === $kunjungan->id)>{{ $kunjungan->nomor_kunjungan }} — {{ $kunjungan->perusahaan->nama_perusahaan }}</option>@endforeach</select></label>
            <label class="grid gap-2 text-sm font-semibold text-slate-700">Nama paket<input name="nama_paket" required value="{{ old('nama_paket', $paket->nama_paket) }}" placeholder="Contoh: Executive Full" class="h-12 w-full rounded-xl border border-slate-200 px-4 text-sm outline-none focus:border-[#00008B] focus:ring-4 focus:ring-blue-100"></label>
            <label class="grid gap-2 text-sm font-semibold text-slate-700">Deskripsi<textarea name="deskripsi" rows="4" placeholder="Keterangan singkat paket..." class="w-full resize-none rounded-xl border border-slate-200 px-4 py-3 text-sm outline-none focus:border-[#00008B] focus:ring-4 focus:ring-blue-100">{{ old('deskripsi', $paket->deskripsi) }}</textarea></label>
            <div class="rounded-xl bg-blue-50 p-4 text-sm leading-6 text-blue-800"><strong class="block">Tips konfigurasi</strong>Centang hanya pemeriksaan yang menjadi bagian dari kontrak perusahaan.</div>
        </aside>

        <section class="overflow-hidden rounded-2xl border border-slate-200/70 bg-white shadow-sm">
            <div class="border-b border-slate-100 px-6 py-5"><h3 class="font-bold text-slate-900">Cakupan pemeriksaan</h3><p class="mt-1 text-sm text-slate-400">Pilih minimal satu item. Pilihan ini menentukan kolom yang muncul saat pemeriksaan.</p></div>
            <div class="grid gap-4 p-5 xl:grid-cols-2">
                @foreach($catalog as $group => $items)
                    @php $selectedItems = old('items', array_keys(array_filter($paket->active_items ?? []))); @endphp
                    <fieldset class="rounded-2xl border border-slate-200 p-5">
                        <legend class="px-2 text-sm font-bold text-slate-800">{{ ucwords(str_replace('_', ' ', $group)) }}</legend>
                        <div class="grid gap-2.5">
                            @foreach($items as $key => $label)
                                <label class="group flex cursor-pointer items-start gap-3 rounded-xl p-2.5 transition-colors hover:bg-blue-50">
                                    <input type="checkbox" name="items[]" value="{{ $key }}" @checked(in_array($key, $selectedItems, true)) class="mt-0.5 h-4 w-4 rounded border-slate-300 accent-[#00008B]">
                                    <span class="text-sm leading-5 text-slate-600 group-hover:text-slate-900">{{ $label }}</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                @endforeach
            </div>
        </section>
    </div>
</form>
@endsection
