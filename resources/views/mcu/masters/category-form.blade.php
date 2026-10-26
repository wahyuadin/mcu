@extends('layouts.mcu')
@section('title', $category->exists ? 'Ubah Kategori MCU' : 'Tambah Kategori MCU')
@section('content')
<form method="POST" action="{{ $category->exists ? route('mcu-category.update', $category) : route('mcu-category.store') }}" class="mx-auto grid max-w-3xl gap-5 rounded-2xl border border-slate-200 bg-white p-6">
    @csrf @if($category->exists) @method('PUT') @endif
    <a href="{{ route('mcu-category.index') }}" class="text-sm font-semibold text-slate-500">← Master kategori</a>
    <h2 class="text-xl font-bold">{{ $category->exists ? 'Ubah kategori' : 'Kategori baru' }}</h2>
    @if($category->isProtected())<p class="rounded-xl bg-blue-50 p-4 text-sm">Kategori bawaan dapat diedit, termasuk label, QR/barcode, status, dan import data. Kategori tetap tidak dapat dihapus; paket dan hasil lama tidak berubah.</p>@endif
    @include('mcu.field', ['field' => 'name', 'label' => 'Nama kategori (wajib)', 'value' => $category->name])
    @if(!$category->isProtected())<fieldset class="grid gap-3 rounded-xl border border-slate-200 p-4">
        <legend class="px-2 text-sm font-semibold">Dokter penanggung jawab</legend>
        <div class="flex flex-wrap gap-3">
            @foreach(['1' => 'YA', '0' => 'TIDAK'] as $choice => $label)
                <label class="flex cursor-pointer items-center gap-3 rounded-xl border border-slate-300 px-5 py-3 text-sm font-semibold"><input type="radio" name="has_responsible_doctor" value="{{ $choice }}" @checked((string) old('has_responsible_doctor', $category->has_responsible_doctor ? '1' : '0') === (string) $choice) class="h-4 w-4 accent-[#00008B]">{{ $label }}</label>
            @endforeach
        </div>
        <p class="text-xs text-slate-500">YA menampilkan pilihan Dokter Pemeriksa pada kategori pemeriksaan pasien. Pengaturan paket dan hasil lama tidak berubah.</p>
        @error('has_responsible_doctor')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
    </fieldset>
    @else <input type="hidden" name="has_responsible_doctor" value="0"> @endif
    @include('mcu.masters.category-label-settings')
    @include('mcu.field', ['field' => 'import_enabled', 'label' => 'Import Data', 'type' => 'select', 'value' => $category->import_enabled ? '1' : '0', 'options' => ['0' => 'Nonaktif', '1' => 'Aktif']])
    <p class="text-xs text-slate-500">Jika aktif, Dokter dan Master Admin dapat mengimpor hasil kategori ini dari kunjungan MCU. Petugas tidak memiliki akses.</p>
    <div class="grid gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4"><div><h3 class="text-sm font-semibold">Urutan tampil</h3><p class="mt-1 text-xs text-slate-500">Susun kartu kategori tanpa mengubah angka satu per satu. Kategori baru ditambahkan di akhir setelah disimpan.</p></div><button type="button" data-item-order-open data-order-category="all" aria-haspopup="dialog" aria-controls="item-order-modal" class="justify-self-start rounded-xl border border-blue-200 bg-white px-4 py-3 text-sm font-semibold text-[#00008B]">Atur urutan kategori</button></div>
    @include('mcu.field', ['field' => 'is_active', 'label' => 'Status', 'type' => 'select', 'value' => $category->is_active ? '1' : '0', 'options' => ['1' => 'Aktif', '0' => 'Nonaktif']])
    <p class="text-sm text-slate-500">Nonaktif menyembunyikan kategori dari pilihan paket baru, bukan menghapusnya dari paket dan hasil lama.</p>
    <button class="justify-self-start rounded-xl bg-[#00008B] px-5 py-3 font-semibold text-white">Simpan kategori</button>
</form>
@include('mcu.masters.item-order-modal', ['orderingCategories' => true])
@endsection
