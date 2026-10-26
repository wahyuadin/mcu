@extends('layouts.mcu')
@section('title', 'Master Kategori MCU')
@section('content')
<div class="mx-auto grid max-w-6xl gap-6">
    <div class="flex flex-wrap items-center justify-between gap-4"><div><h2 class="text-2xl font-bold">Kategori form pemeriksaan</h2><p class="mt-2 text-sm text-slate-500">Kelola item dan mapping hasil. Kategori Anamnesis tetap tersedia; item di dalamnya dapat ditambah, diubah, dan dinonaktifkan.</p></div><div class="flex flex-wrap gap-3"><button type="button" data-item-order-open data-order-category="all" aria-haspopup="dialog" aria-controls="item-order-modal" class="rounded-xl border border-slate-300 bg-white px-5 py-3 text-sm font-semibold text-[#00008B]">Atur urutan kategori</button><a href="{{ route('mcu-category.create') }}" class="rounded-xl bg-[#00008B] px-5 py-3 text-sm font-bold text-white">+ Tambah kategori</a></div></div>
    <section data-bulk-delete class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <form method="GET" action="{{ route('mcu-category.index') }}" class="flex flex-wrap items-end gap-3 border-b border-slate-100 p-6">
            <label class="grid min-w-0 flex-1 gap-2 text-sm font-semibold sm:max-w-sm">Cari kategori<input type="search" name="q" value="{{ $search }}" maxlength="255" placeholder="Nama kategori..." class="min-h-12 rounded-xl border border-slate-300 bg-white px-4"></label>
            <button type="submit" class="inline-flex min-h-12 items-center gap-2 rounded-xl bg-[#00008B] px-5 py-3 text-sm font-semibold text-white"><svg aria-hidden="true" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="10" cy="10" r="6" stroke-width="2"/><path d="m15 15 6 6" stroke-width="2" stroke-linecap="round"/></svg>Cari</button>
            @if($search !== '')<a href="{{ route('mcu-category.index') }}" class="rounded-xl border border-slate-300 px-5 py-3 text-sm font-semibold text-slate-600">Reset</a>@endif
        </form>
        @include('mcu.master-bulk-delete', ['bulkRoute' => 'mcu-category.bulk-destroy', 'bulkFilters' => ['q', 'page']])
        <div class="overflow-x-auto">
            <table aria-label="Daftar kategori MCU" class="w-full min-w-[700px] text-left text-sm">
                <thead class="border-b border-slate-100 bg-slate-50 text-xs uppercase tracking-wider text-slate-500"><tr>@include('mcu.master-select-all')<th scope="col" class="px-6 py-4">Nama kategori</th><th scope="col" class="px-6 py-4">Jumlah item</th><th scope="col" class="px-6 py-4">Urutan</th><th scope="col" class="px-6 py-4">Status</th><th scope="col" class="px-6 py-4 text-right">Aksi</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($categories as $category)
                        <tr class="hover:bg-blue-50/30">
                            @if($category->isProtected())<td class="px-6 py-4"><span class="text-xs text-slate-500">Bawaan</span></td>@else @include('mcu.master-select-row', ['selectionId' => $category->id, 'selectionName' => $category->name]) @endif
                            <th scope="row" class="max-w-80 break-words px-6 py-4 font-semibold text-slate-800">{{ $category->name }}</th>
                            <td class="px-6 py-4 text-slate-600">{{ $category->items_count }} item</td>
                            <td class="px-6 py-4 text-slate-600">{{ $category->sort_order }}</td>
                            <td class="px-6 py-4"><span class="rounded-full px-3 py-1 text-xs font-semibold {{ $category->is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500' }}">{{ $category->is_active ? 'Aktif' : 'Nonaktif' }}</span></td>
                            <td class="px-6 py-4">@include('mcu.master-actions', ['compact' => true, 'name' => $category->name, 'manageUrl' => route('mcu-item.index', ['category' => $category->id]), 'editUrl' => route('mcu-category.edit', $category), 'deleteUrl' => $category->isProtected() ? null : route('mcu-category.destroy', $category)])</td>
                        </tr>
                    @empty<tr><td colspan="6" class="px-6 py-12 text-center text-slate-500">{{ $search !== '' ? 'Tidak ada kategori yang cocok dengan pencarian.' : 'Belum ada kategori. Buat kategori pertama untuk mulai memetakan form.' }}</td></tr>@endforelse
                </tbody>
            </table>
        </div>
        @if($categories->hasPages())<div class="border-t border-slate-100 px-6 py-4">{{ $categories->links() }}</div>@endif
    </section>
</div>
@include('mcu.masters.item-order-modal', ['orderingCategories' => true])
@endsection
