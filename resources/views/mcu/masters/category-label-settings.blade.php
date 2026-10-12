<fieldset data-label-master data-label-timezone="{{ config('app.timezone') }}" class="grid gap-4 rounded-xl border border-slate-200 p-4">
    <legend class="px-2 text-sm font-semibold">Pengaturan label</legend>
    <script type="application/json" data-label-barcodes>{!! json_encode($labelBarcodes, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
    <label class="grid gap-2 text-sm font-semibold">Aktifkan cetak label<select name="label_enabled" class="min-h-12 rounded-xl border border-slate-300 bg-white px-3">@foreach(['0' => 'TIDAK', '1' => 'YA'] as $value => $label)<option value="{{ $value }}" @selected((string) old('label_enabled', $category->label_enabled ? '1' : '0') === (string) $value)>{{ $label }}</option>@endforeach</select></label>
    <label class="grid gap-2 text-sm font-semibold">Jenis barcode<select name="label_barcode_type" class="min-h-12 rounded-xl border border-slate-300 bg-white px-3">@foreach(['qr' => 'QR', 'code128' => 'Code 128'] as $value => $label)<option value="{{ $value }}" @selected(old('label_barcode_type', $category->label_barcode_type) === $value)>{{ $label }}</option>@endforeach</select></label>
    <div class="grid gap-3 sm:grid-cols-2">
        <label class="grid gap-2 text-sm font-semibold">Panjang label (mm)<input type="number" name="label_width_mm" min="40" max="300" step="1" required value="{{ old('label_width_mm', $category->label_width_mm) }}" class="min-h-12 min-w-0 rounded-xl border border-slate-300 px-4"></label>
        <label class="grid gap-2 text-sm font-semibold">Lebar label (mm)<input type="number" name="label_height_mm" min="30" max="200" step="1" required value="{{ old('label_height_mm', $category->label_height_mm) }}" class="min-h-12 min-w-0 rounded-xl border border-slate-300 px-4"></label>
    </div>
    @foreach(['label_enabled', 'label_barcode_type', 'label_width_mm', 'label_height_mm'] as $field) @error($field)<p class="text-sm text-red-700">{{ $message }}</p>@enderror @endforeach
    <label class="grid gap-2 text-sm font-semibold">Subkategori pratinjau<select data-label-sample-subcategory class="min-h-12 min-w-0 rounded-xl border border-slate-300 bg-white px-3">@forelse($category->subcategories as $subcategory)<option>{{ $subcategory->name }}</option>@empty<option>Subkategori contoh</option>@endforelse</select></label>
    <p class="text-xs text-slate-500">Subkategori mengikuti pengaturan kategori. Pengaturan cetak menggunakan master terkini, tanpa mengubah snapshot paket atau hasil lama.</p>
    @include('mcu.label-preview', ['labelPerson' => 'Budi Santoso - 001234'])
    <p class="text-xs text-slate-500">Data contoh — bukan data pasien. QR / Code 128 contoh berisi URL fiktif, tidak membuka pasien nyata.</p>
    <button type="button" data-label-sample-print class="justify-self-start rounded-xl border border-blue-200 bg-blue-50 px-4 py-3 text-sm font-semibold text-[#00008B] disabled:opacity-50">Cetak contoh</button>
</fieldset>
