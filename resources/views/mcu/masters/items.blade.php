@extends('layouts.mcu')
@section('title', 'Master Item MCU')
@section('content')
<div class="mx-auto grid max-w-6xl gap-6">
    <div class="flex flex-wrap items-center justify-between gap-4"><div><h2 class="text-2xl font-bold">Item dan mapping laporan</h2><p class="mt-2 text-sm text-slate-500">Susun pertanyaan seperti form. Pilih item tersebut saat membuat paket.</p></div><div class="flex flex-wrap gap-3"><button type="button" data-item-order-open data-category-select="category" aria-haspopup="dialog" aria-controls="item-order-modal" class="rounded-xl border border-slate-300 bg-white px-5 py-3 text-sm font-semibold text-[#00008B]">Atur urutan</button><a href="{{ route('mcu-item.create', ['category' => request('category')]) }}" class="rounded-xl bg-[#00008B] px-5 py-3 text-sm font-bold text-white">+ Tambah item</a></div></div>
    <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <form method="GET" class="flex flex-wrap items-end gap-3 border-b border-slate-100 p-6"><label class="grid min-w-0 flex-1 gap-2 text-sm font-semibold sm:max-w-sm">Kategori<select name="category" class="min-h-12 min-w-0 rounded-xl border border-slate-300 bg-white px-3"><option value="">Semua kategori</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected(request('category') === $category->id)>{{ $category->name }}</option>@endforeach</select></label><button class="rounded-xl border border-slate-300 px-5 py-3 text-sm font-semibold">Tampilkan</button></form>
        <div class="overflow-x-auto">
            <table aria-label="Daftar item MCU" class="w-full min-w-[1100px] text-left text-sm">
                <thead class="border-b border-slate-100 bg-slate-50 text-xs uppercase tracking-wider text-slate-500"><tr><th scope="col" class="px-5 py-4">Item</th><th scope="col" class="px-5 py-4">Kategori</th><th scope="col" class="px-5 py-4">Jenis input</th><th scope="col" class="px-5 py-4">Mapping laporan</th><th scope="col" class="px-5 py-4">Pengisian</th><th scope="col" class="px-5 py-4">Status</th><th scope="col" class="px-5 py-4 text-right">Aksi</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($items as $item)
                        <tr class="hover:bg-blue-50/30">
                            <th scope="row" class="max-w-64 px-5 py-4 align-top"><span class="block break-words font-semibold text-slate-800">{{ $item->label }}</span><span class="mt-1 block text-xs font-normal text-slate-500">Urutan {{ $item->sort_order }}</span></th>
                            <td class="max-w-56 break-words px-5 py-4 align-top text-slate-600">{{ $item->category->name }}</td>
                            <td class="max-w-48 px-5 py-4 align-top text-slate-600">{{ $item->inputLabel() }}</td>
                            <td class="w-72 max-w-80 px-5 py-4 align-top">
                                @if($item->input_type === 'radio')<dl class="grid gap-2"><div><dt class="text-xs font-semibold text-slate-500">YA → laporan</dt><dd class="break-words text-slate-700">{{ $item->yes_report }}</dd></div><div><dt class="text-xs font-semibold text-slate-500">TIDAK → laporan</dt><dd class="break-words text-slate-700">{{ $item->no_report }}</dd></div></dl>@else<span class="text-slate-400">—</span>@endif
                            </td>
                            <td class="px-5 py-4 align-top text-slate-600">{{ $item->is_required ? 'Wajib' : 'Opsional' }}</td>
                            <td class="px-5 py-4 align-top"><span class="whitespace-nowrap rounded-full px-3 py-1 text-xs font-semibold {{ $item->is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500' }}">{{ $item->is_active ? 'Aktif' : 'Nonaktif' }}</span></td>
                            <td class="px-5 py-4 text-right align-top">@include('mcu.master-actions', ['compact' => true, 'name' => $item->label, 'editUrl' => route('mcu-item.edit', $item), 'deleteUrl' => route('mcu-item.destroy', $item)])</td>
                        </tr>
                    @empty<tr><td colspan="7" class="px-6 py-12 text-center text-slate-500">Belum ada item di kategori ini.</td></tr>@endforelse
                </tbody>
            </table>
        </div>
        @if($items->hasPages())<div class="border-t border-slate-100 px-6 py-4">{{ $items->links() }}</div>@endif
    </section>
</div>
@include('mcu.masters.item-order-modal')
@endsection
