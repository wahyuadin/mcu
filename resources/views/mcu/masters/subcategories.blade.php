@extends('layouts.mcu')
@section('title', 'Master Subkategori MCU')
@section('content')
<div class="mx-auto grid max-w-6xl gap-6">
    <div class="flex flex-wrap items-center justify-between gap-4"><div><h2 class="text-2xl font-bold">Subkategori pemeriksaan</h2><p class="mt-2 text-sm text-slate-500">Kelompok seperti Hematologi dan Hitung Jenis Leukosit di bawah header kategori.</p></div><div class="flex flex-wrap gap-3"><button type="button" data-item-order-open aria-haspopup="dialog" aria-controls="item-order-modal" class="rounded-xl border border-slate-300 bg-white px-5 py-3 text-sm font-semibold text-[#00008B]">Atur urutan subkategori</button><a href="{{ route('mcu-subcategory.create') }}" class="rounded-xl bg-[#00008B] px-5 py-3 text-sm font-bold text-white">+ Tambah subkategori</a></div></div>
    <section data-bulk-delete class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <form method="GET" action="{{ route('mcu-subcategory.index') }}" class="flex flex-wrap items-end gap-3 border-b border-slate-100 p-6">
            <label class="grid min-w-0 flex-1 gap-2 text-sm font-semibold sm:max-w-sm">Cari subkategori<input type="search" name="q" value="{{ request('q') }}" maxlength="255" placeholder="Nama subkategori..." class="min-h-12 min-w-0 rounded-xl border border-slate-300 bg-white px-4"></label>
            <label class="grid min-w-0 flex-1 gap-2 text-sm font-semibold sm:max-w-sm">Kategori<select name="category" class="min-h-12 min-w-0 rounded-xl border border-slate-300 bg-white px-3"><option value="">Semua kategori</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected(request('category') === $category->id)>{{ $category->name }}</option>@endforeach</select></label>
            <button type="submit" class="inline-flex min-h-12 items-center gap-2 rounded-xl bg-[#00008B] px-5 py-3 text-sm font-semibold text-white"><svg aria-hidden="true" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="10" cy="10" r="6" stroke-width="2"/><path d="m15 15 6 6" stroke-width="2" stroke-linecap="round"/></svg>Cari</button>
            @if(request()->filled('q') || request()->filled('category'))<a href="{{ route('mcu-subcategory.index') }}" class="rounded-xl border border-slate-300 px-5 py-3 text-sm font-semibold text-slate-600">Reset</a>@endif
        </form>
        @include('mcu.master-bulk-delete', ['bulkRoute' => 'mcu-subcategory.bulk-destroy', 'bulkFilters' => ['q', 'category', 'page']])
        <div class="overflow-x-auto">
            <table aria-label="Daftar subkategori MCU" class="w-full min-w-[700px] text-left text-sm">
                <thead class="border-b border-slate-100 bg-slate-50 text-xs uppercase tracking-wider text-slate-500"><tr>@include('mcu.master-select-all')<th scope="col" class="px-6 py-4">Subkategori</th><th scope="col" class="px-6 py-4">Kategori</th><th scope="col" class="px-6 py-4">Jumlah item</th><th scope="col" class="px-6 py-4">Urutan</th><th scope="col" class="px-6 py-4">Status</th><th scope="col" class="px-6 py-4 text-right">Aksi</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($subcategories as $subcategory)
                        <tr class="hover:bg-blue-50/30">
                            @include('mcu.master-select-row', ['selectionId' => $subcategory->id, 'selectionName' => $subcategory->name])
                            <th scope="row" class="max-w-80 break-words px-6 py-4 font-semibold text-slate-800">{{ $subcategory->name }}</th>
                            <td class="max-w-56 break-words px-6 py-4 text-slate-600">{{ $subcategory->category->name }}</td>
                            <td class="px-6 py-4 text-slate-600">{{ $subcategory->items_count }} item</td>
                            <td class="px-6 py-4 text-slate-600">{{ $subcategory->sort_order }}</td>
                            <td class="px-6 py-4"><span class="whitespace-nowrap rounded-full px-3 py-1 text-xs font-semibold {{ $subcategory->is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500' }}">{{ $subcategory->is_active ? 'Aktif' : 'Nonaktif' }}</span></td>
                            <td class="px-6 py-4">@include('mcu.master-actions', ['compact' => true, 'name' => $subcategory->name, 'editUrl' => route('mcu-subcategory.edit', $subcategory), 'deleteUrl' => route('mcu-subcategory.destroy', $subcategory)])</td>
                        </tr>
                    @empty<tr><td colspan="7" class="px-6 py-12 text-center text-slate-500">{{ request()->filled('q') || request()->filled('category') ? 'Tidak ada subkategori yang cocok dengan filter.' : 'Belum ada subkategori.' }}</td></tr>@endforelse
                </tbody>
            </table>
        </div>
        @if($subcategories->hasPages())<div class="border-t border-slate-100 px-6 py-4">{{ $subcategories->links() }}</div>@endif
    </section>
</div>
@include('mcu.masters.item-order-modal', ['orderingSubcategories' => true])
@endsection
