<dialog id="examination-label-modal" data-label-modal aria-labelledby="examination-label-title" class="m-auto max-h-[90dvh] w-[calc(100%-2rem)] max-w-2xl overflow-y-auto rounded-2xl border-0 bg-white p-0 text-slate-900 shadow-xl">
    <div class="sticky top-0 z-10 flex items-center justify-between gap-4 border-b border-slate-200 bg-white p-5"><h2 id="examination-label-title" class="text-lg font-bold">Cetak label peserta</h2><button type="button" data-label-close autofocus aria-label="Tutup modal label" class="rounded-xl border border-slate-300 px-4 py-3 text-sm font-semibold">Tutup</button></div>
    <div data-label-picker data-label-url="{{ route('pemeriksaan.labels', [$kunjungan, $peserta]) }}" data-label-timezone="{{ config('app.timezone') }}" class="grid gap-5 p-5">
        <script type="application/json" data-label-categories>{!! json_encode($labelCategories, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
        <script type="application/json" data-label-barcodes>{!! json_encode($labelBarcodes, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
        <label class="grid gap-2 text-sm font-semibold">Kategori<select data-label-category class="min-h-12 min-w-0 rounded-xl border border-slate-300 bg-white px-3">@forelse($labelCategories as $category)<option value="{{ $category['id'] }}">{{ $category['name'] }}</option>@empty<option value="">Tidak ada kategori dalam paket</option>@endforelse</select></label>
        <fieldset class="grid gap-3"><legend class="mb-3 text-sm font-semibold">Subkategori</legend><div data-label-subcategories class="grid gap-2 sm:grid-cols-2"></div></fieldset>
        <p data-label-config class="text-sm text-slate-500"></p>
        <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Pratinjau</h3>
        @include('mcu.label-preview', ['labelPerson' => $peserta->nama_karyawan.' - '.($peserta->nip ?: 'NIP belum diisi')])
        <p class="text-xs text-slate-500">Scan membuka tab kategori dan langsung menuju subkategori pada label, tetap memerlukan login. Waktu diperbarui saat mencetak. Skala 100%, margin 0, nonaktifkan header/footer browser.</p>
        <div class="flex flex-wrap items-center justify-between gap-3"><span data-label-count role="status" class="text-sm text-slate-500">0 label dipilih</span><div class="flex gap-2"><button type="button" data-label-close class="rounded-xl border border-slate-300 px-5 py-3 text-sm font-semibold">Batal</button><button type="button" data-label-print disabled class="rounded-xl bg-[#00008B] px-5 py-3 text-sm font-semibold text-white disabled:opacity-50">Cetak</button></div></div>
    </div>
</dialog>
