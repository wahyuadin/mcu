@extends('layouts.mcu')
@section('title', $paket->exists ? 'Ubah Paket MCU' : 'Buat Paket MCU')
@section('content')
<form data-package-builder action="{{ $paket->exists ? route('paket.update', $paket) : route('paket.store') }}" method="POST" class="mx-auto space-y-6">
    @csrf
    @if($paket->exists) @method('PUT') @endif

    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-center gap-4">
            <a href="{{ $paket->exists ? route('paket.show', $paket) : route('paket.index') }}" class="flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-500 shadow-sm hover:border-[#00008B] hover:text-[#00008B]" aria-label="Kembali"><svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg></a>
            <div>
                <p class="text-xs font-bold uppercase tracking-wider text-[#00008B]">Konfigurasi paket</p>
                <h2 class="mt-1 text-2xl font-bold text-slate-900">{{ $paket->exists ? 'Ubah paket pemeriksaan' : 'Paket pemeriksaan baru' }}</h2>
            </div>
        </div>
        <button class="inline-flex items-center justify-center gap-2 rounded-xl bg-[#00008B] px-6 py-3 text-sm font-bold text-white shadow-lg shadow-blue-900/15 active:scale-[.97]"><svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>Simpan Paket</button>
    </div>

    <div class="grid gap-6 lg:grid-cols-[340px_minmax(0,1fr)]">
        <aside class="space-y-5 self-start rounded-2xl border border-slate-200/70 bg-white p-6 shadow-sm lg:sticky lg:top-6">
            <div>
                <h3 class="font-bold text-slate-900">Informasi paket master</h3>
                <p class="mt-1 text-sm leading-5 text-slate-400">Konfigurasi sekali, gunakan di beberapa rencana kunjungan dan perusahaan.</p>
            </div>
            @if(!$paket->exists && old('rencana_kunjungan_id', request('rencana_kunjungan_id')))<input type="hidden" name="rencana_kunjungan_id" value="{{ old('rencana_kunjungan_id', request('rencana_kunjungan_id')) }}">@endif
            <label class="grid gap-2 text-sm font-semibold text-slate-700">Nama paket<input name="nama_paket" required value="{{ old('nama_paket', $paket->nama_paket) }}" placeholder="Contoh: Executive Full" class="h-12 w-full rounded-xl border border-slate-200 px-4 text-sm outline-none focus:border-[#00008B] focus:ring-4 focus:ring-blue-100"></label>
            <label class="grid gap-2 text-sm font-semibold text-slate-700">Deskripsi<textarea name="deskripsi" rows="4" placeholder="Keterangan singkat paket..." class="w-full resize-none rounded-xl border border-slate-200 px-4 py-3 text-sm outline-none focus:border-[#00008B] focus:ring-4 focus:ring-blue-100">{{ old('deskripsi', $paket->deskripsi) }}</textarea></label>
            <div class="rounded-xl bg-blue-50 p-4 text-sm leading-6 text-blue-800"><strong class="block">Tips konfigurasi</strong>Centang cakupan paket. Pilih paket ini di detail Rencana Kunjungan tanpa mapping ulang.</div>
        </aside>

        <section class="overflow-hidden rounded-2xl border border-slate-200/70 bg-white shadow-sm">
            <div class="grid gap-3 border-b border-slate-100 px-6 py-5">
                <h3 class="font-bold text-slate-900">Cakupan pemeriksaan</h3>
                <p class="text-sm text-slate-500">Pilih item yang sudah dipetakan di master. Anamnesa dan BMI tetap existing. Mapping disalin ke paket saat disimpan.</p><a href="{{ route('mcu-category.index') }}" class="text-sm font-semibold text-[#00008B]">Kelola kategori & mapping →</a><button type="button" data-package-full class="justify-self-start rounded-lg border border-blue-200 px-3 py-2 text-sm font-semibold text-[#00008B]">Pilih semua pemeriksaan</button>
                <div class="grid gap-2 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
                    <label for="package-search" class="grid min-w-0 gap-2 text-sm font-semibold text-slate-700">Cari pemeriksaan<input id="package-search" data-package-search type="search" autocomplete="off" enterkeyhint="search" placeholder="Nama item, kategori, atau subkategori…" aria-describedby="package-search-help package-search-status" class="min-h-12 w-full rounded-xl border border-slate-200 px-4 text-base outline-none focus:border-[#00008B] focus:ring-4 focus:ring-blue-100"></label>
                    <button type="button" data-package-search-clear hidden class="min-h-12 rounded-xl border border-slate-200 px-4 text-sm font-semibold text-slate-600">Hapus pencarian</button>
                </div>
                <p id="package-search-help" class="text-xs leading-5 text-slate-500">Hasil berubah langsung saat mengetik. Pilihan yang tidak terlihat tetap tersimpan dalam form.</p>
                <p id="package-search-status" data-package-search-status role="status" aria-live="polite" class="text-xs font-semibold text-blue-800"></p>
                <p data-package-search-empty hidden class="rounded-xl bg-amber-50 p-4 text-sm text-amber-800">Tidak ada pemeriksaan yang cocok. Coba kata kunci lain atau hapus pencarian.</p>
            </div>
            <div class="grid gap-4 p-5 xl:grid-cols-2">
                @php
                $selectedMasters = old('master_items', session()->hasOldInput() ? [] : collect($paket->dynamic_schema ?? [])->pluck('items')->flatten(1)->pluck('id')->all());
                @endphp
                @if($categories->pluck('items')->flatten(1)->isEmpty())
                <p class="rounded-xl bg-slate-50 p-4 text-sm text-slate-500 xl:col-span-2">Belum ada item master aktif. Tambahkan kategori dan item di master untuk memilih pemeriksaan lainnya. Anamnesa dan pengukuran/BMI bawaan tetap tersedia di bawah.</p>
                @endif
                @foreach($categories as $category)
                @if($category->items->isNotEmpty())
                <fieldset data-package-group class="min-w-0 rounded-2xl border border-slate-200 p-5">
                    <legend class="px-2 text-sm font-bold">{{ $category->name }}</legend>
                    <button type="button" data-package-toggle class="mb-3 text-xs font-semibold text-[#00008B]">Pilih / lepas semua dalam kelompok</button>
                    <div class="grid gap-3">
                        @foreach($category->items->groupBy(fn ($item) => $item->mcu_subcategory_id ?? '')->sortBy(fn ($items) => $items->first()->subcategory?->sort_order ?? -1) as $groupedItems)
                        <fieldset data-package-group class="min-w-0 grid gap-2 rounded-xl border border-slate-100 p-3">
                            @if($groupedItems->first()->subcategory)<legend class="px-2 text-sm font-semibold">{{ $groupedItems->first()->subcategory->name }}</legend><button type="button" data-package-toggle class="justify-self-start text-xs font-semibold text-[#00008B]">Pilih / lepas semua subkategori</button>@endif
                            @foreach($groupedItems as $item)
                            <label data-package-search-item data-package-search-text="{{ $category->name }} {{ $item->subcategory?->name }} {{ $item->label }} {{ $item->inputLabel() }} {{ $item->lab_config['unit'] ?? '' }} {{ $item->lab_config['reference'] ?? '' }}" class="flex cursor-pointer items-start gap-3 rounded-xl p-2 hover:bg-blue-50">
                                <input type="checkbox" name="master_items[]" value="{{ $item->id }}" @checked(in_array($item->id, $selectedMasters, true) || (!session()->hasOldInput() && $item->input_type === 'existing' && $paket->isItemActive($item->legacy_key))) class="mt-1 h-4 w-4 shrink-0 accent-[#00008B]">
                                <span class="min-w-0 text-sm"><strong class="block break-words font-semibold">{{ $item->label }}</strong><span class="mt-1 block text-xs text-slate-500">{{ $item->inputLabel() }}</span>
                                    @if($item->input_type === 'radio')<span class="mt-1 block break-words text-xs text-slate-500">YA → {{ $item->yes_report }} · TIDAK → {{ $item->no_report }}</span>@endif
                                    @if($item->lab_config)<span class="mt-1 block break-words text-xs text-slate-500">Unit: {{ $item->lab_config['unit'] ?: '—' }} · Nilai normal: {{ $item->lab_config['reference'] }}</span>@endif
                                </span>
                            </label>
                            @endforeach
                        </fieldset>
                        @endforeach
                    </div>
                </fieldset>
                @endif
                @endforeach
            </div>
            <div class="border-t border-slate-100 px-6 py-4">
                <h4 class="font-bold">Anamnesa & pengukuran existing</h4>
                <p class="mt-1 text-xs text-slate-500">Perhitungan BMI tetap otomatis menyertakan tinggi dan berat badan. Item lama hanya ditampilkan jika sudah dipilih dalam paket yang sedang diedit; tidak ditawarkan kembali pada paket baru.</p>
            </div>
            <div class="grid gap-4 p-5 xl:grid-cols-2">
                @foreach($catalog as $group => $items)
                @php
                $items = array_filter($items, fn ($label, $key) => in_array($group, ['anamnesa_riwayat', 'antropometri_vital'], true) || ($paket->exists && $paket->isItemActive($key) && !$categories->pluck('items')->flatten(1)->contains(fn ($item) => $item->legacy_key === $key && $item->input_type === 'existing')), ARRAY_FILTER_USE_BOTH);
                @endphp
                @if($items === []) @continue @endif
                @php $selectedItems = old('items', array_keys(array_filter($paket->active_items ?? []))); @endphp
                <fieldset data-package-group class="rounded-2xl border border-slate-200 p-5">
                    <legend class="px-2 text-sm font-bold text-slate-800">{{ ['anamnesa_riwayat' => 'Anamnesa Pasien', 'antropometri_vital' => 'Hasil Pemeriksaan Fisik'][$group] ?? ucwords(str_replace('_', ' ', $group)) }}</legend>
                    <button type="button" data-package-toggle class="mb-3 text-xs font-semibold text-[#00008B]">Pilih / lepas semua dalam kelompok</button>
                    <div class="grid gap-2.5">
                        @foreach($items as $key => $label)
                        <label data-package-search-item data-package-search-text="{{ ['anamnesa_riwayat' => 'Anamnesa Pasien', 'antropometri_vital' => 'Hasil Pemeriksaan Fisik'][$group] ?? ucwords(str_replace('_', ' ', $group)) }} {{ $label }}" class="group flex cursor-pointer items-start gap-3 rounded-xl p-2.5 transition-colors hover:bg-blue-50">
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
