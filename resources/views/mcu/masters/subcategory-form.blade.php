@extends('layouts.mcu')
@section('title', $subcategory->exists ? 'Ubah Subkategori MCU' : 'Tambah Subkategori MCU')
@section('content')
<form method="POST" action="{{ $subcategory->exists ? route('mcu-subcategory.update', $subcategory) : route('mcu-subcategory.store') }}" class="mx-auto grid max-w-3xl gap-5 rounded-2xl border border-slate-200 bg-white p-6">
    @csrf @if($subcategory->exists) @method('PUT') @endif
    <a href="{{ route('mcu-subcategory.index') }}" class="text-sm font-semibold text-slate-500">← Master subkategori</a>
    <h2 class="text-xl font-bold">{{ $subcategory->exists ? 'Ubah subkategori' : 'Subkategori baru' }}</h2>
    @include('mcu.field', ['field' => 'mcu_category_id', 'label' => 'Kategori/header (wajib)', 'type' => 'select', 'options' => $categories->pluck('name', 'id'), 'value' => $subcategory->mcu_category_id])
    @include('mcu.field', ['field' => 'name', 'label' => 'Nama subkategori (wajib)', 'value' => $subcategory->name])
    @include('mcu.field', ['field' => 'is_active', 'label' => 'Status', 'type' => 'select', 'options' => ['1' => 'Aktif', '0' => 'Nonaktif'], 'value' => $subcategory->is_active ? '1' : '0'])
    <button type="button" data-item-order-open data-category-select="mcu_category_id" aria-haspopup="dialog" aria-controls="item-order-modal" class="justify-self-start rounded-xl border border-slate-300 px-4 py-3 text-sm font-semibold">Atur urutan subkategori</button>
    <p class="text-sm text-slate-500">Subkategori baru ditambahkan di akhir kategori. Perubahan tidak mengubah paket dan hasil lama.</p>
    <button class="justify-self-end rounded-xl bg-[#00008B] px-6 py-3 font-semibold text-white">Simpan subkategori</button>
</form>
@include('mcu.masters.item-order-modal', ['orderingSubcategories' => true])
@endsection
