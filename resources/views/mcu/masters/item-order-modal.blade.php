<dialog id="item-order-modal" data-item-order-modal aria-labelledby="item-order-title" class="m-auto max-h-[90dvh] w-[calc(100%-2rem)] max-w-3xl overflow-y-auto rounded-2xl border-0 bg-slate-50 p-0 text-slate-900 shadow-xl">
    <div class="sticky top-0 z-10 flex items-center justify-between gap-4 border-b border-slate-200 bg-white p-5"><div><h2 id="item-order-title" class="text-lg font-bold">{{ ($orderingCategories ?? false) ? 'Atur urutan kategori' : (($orderingSubcategories ?? false) ? 'Atur urutan subkategori' : 'Atur urutan item') }}</h2><p class="mt-1 text-sm text-slate-500">Seret kartu atau gunakan tombol naik / turun. Simpan semua urutan sekaligus.</p></div><button type="button" data-item-order-close autofocus aria-label="Tutup pengaturan urutan" class="rounded-xl border border-slate-300 px-4 py-3 text-sm font-semibold">Tutup</button></div>
    <div class="grid gap-4 p-5">
        @if($orderingCategories ?? false)
            <select data-item-order-category hidden aria-label="Seluruh kategori"><option value="all" data-url="{{ route('mcu-category-order.show') }}">Seluruh kategori MCU</option></select>
        @else
            <label class="grid gap-2 text-sm font-semibold">Kategori<select data-item-order-category data-order-noun="{{ ($orderingSubcategories ?? false) ? 'subkategori' : 'item' }}" class="min-h-12 min-w-0 rounded-xl border border-slate-300 bg-white px-3"><option value="">Pilih kategori</option>@foreach($categories as $category)<option value="{{ $category->id }}" data-url="{{ route(($orderingSubcategories ?? false) ? 'mcu-subcategory-order.show' : 'mcu-item-order.show', $category) }}">{{ $category->name }}</option>@endforeach</select></label>
        @endif
        @if(!($orderingCategories ?? false) && !($orderingSubcategories ?? false))
            <label class="grid gap-2 text-sm font-semibold">Subkategori<select data-item-order-subcategory disabled class="min-h-12 min-w-0 rounded-xl border border-slate-300 bg-white px-3"><option value="">Semua subkategori</option></select></label>
        @endif
        <p data-item-order-status role="status" aria-live="polite" class="text-sm text-slate-600">Pilih kategori untuk menampilkan kartu item.</p>
        <ol data-item-order-board aria-label="{{ ($orderingCategories ?? false) ? 'Urutan kategori dari atas ke bawah' : (($orderingSubcategories ?? false) ? 'Urutan subkategori dari atas ke bawah' : 'Urutan item dari atas ke bawah') }}" class="grid gap-3"></ol>
        <p class="text-xs text-slate-500">Urutan mencakup {{ ($orderingCategories ?? false) ? 'kategori' : (($orderingSubcategories ?? false) ? 'subkategori' : 'item') }} aktif dan nonaktif. Perubahan hanya berlaku untuk konfigurasi paket berikutnya; snapshot paket dan hasil lama tidak berubah.</p>
    </div>
    <div class="sticky bottom-0 flex flex-wrap justify-end gap-3 border-t border-slate-200 bg-white p-5"><button type="button" data-item-order-close class="rounded-xl border border-slate-300 px-5 py-3 text-sm font-semibold">Batal</button><button type="button" data-item-order-save disabled class="rounded-xl bg-[#00008B] px-5 py-3 text-sm font-semibold text-white disabled:opacity-50">Simpan urutan</button></div>
    <input type="hidden" data-item-order-token value="{{ csrf_token() }}">
</dialog>
