@extends('layouts.mcu')
@section('title', $item->exists ? 'Ubah Item MCU' : 'Tambah Item MCU')
@section('content')
<div class="mx-auto max-w-4xl">
    <form data-master-item-form data-form-builder method="POST" action="{{ $item->exists ? route('mcu-item.update', $item) : route('mcu-item.store') }}" class="grid gap-5">
        @csrf @if($item->exists) @method('PUT') @endif
        <div class="flex flex-wrap items-center justify-between gap-3"><a href="{{ route('mcu-item.index', ['category' => $item->mcu_category_id]) }}" class="text-sm font-semibold text-slate-500">← Master item</a><button type="button" data-builder-preview-toggle aria-pressed="false" class="rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm font-semibold text-[#00008B]">Pratinjau form</button></div>
        <section class="overflow-hidden rounded-2xl border border-slate-200 border-t-8 border-t-[#00008B] bg-white p-6 shadow-sm sm:p-8">
            <p class="text-xs font-bold uppercase tracking-wider text-blue-800">Pembuat form MCU</p><h2 class="mt-2 text-2xl font-bold">{{ $item->exists ? 'Ubah pertanyaan' : 'Susun pertanyaan pemeriksaan' }}</h2>
            <p class="mt-2 text-sm text-slate-500">Pilih kategori, susun pertanyaan, dan tentukan jenis jawaban seperti membuat Google Form.</p>
            <div class="mt-6">@include('mcu.field', ['field' => 'mcu_category_id', 'label' => 'Kategori (wajib)', 'type' => 'select', 'options' => $categories->pluck('name', 'id'), 'value' => $item->mcu_category_id])</div>
            <label class="mt-4 grid gap-2 text-sm font-semibold">Subkategori (opsional)<select name="mcu_subcategory_id" data-item-subcategory class="min-h-12 rounded-xl border border-slate-300 bg-white px-3"><option value="">Langsung di bawah kategori</option>@foreach($subcategories as $subcategory)<option value="{{ $subcategory->id }}" data-category="{{ $subcategory->mcu_category_id }}" @selected(old('mcu_subcategory_id', $item->mcu_subcategory_id) === $subcategory->id)>{{ $subcategory->category->name }} — {{ $subcategory->name }}</option>@endforeach</select></label>
            <a href="{{ route('mcu-subcategory.index') }}" class="mt-3 inline-block text-sm font-semibold text-[#00008B]">Kelola subkategori →</a>
            @if(in_array($item->input_type, ['existing', 'label'], true))<p class="mt-4 rounded-xl bg-blue-50 p-4 text-sm text-blue-900">Item lama disesuaikan menjadi isian teks pada editor ini. Anda dapat memilih radio atau angka. Perubahan berlaku setelah disimpan; paket dan hasil lama tidak berubah.</p>@endif
        </section>
        <div data-builder-preview hidden class="grid gap-4" aria-label="Pratinjau form pemeriksaan"></div>
        <div data-builder-cards class="grid gap-5">
            @php
                $labFields = [];
                foreach ($item->lab_config ?? [] as $key => $value) {
                    $labFields['lab_'.$key] = $key === 'normal_values' ? implode("\n", $value) : $value;
                }
                $questions = old('questions', [array_merge(['label' => $item->label, 'input_type' => $item->editorType(), 'allow_attachment' => $item->allow_attachment ?? false, 'yes_report' => $item->yes_report, 'no_report' => $item->no_report, 'help_text' => $item->help_text, 'is_required' => $item->is_required ?? true, 'is_active' => $item->is_active ?? true], $labFields)]);
            @endphp
            @foreach($questions as $index => $question)
                @include('mcu.masters.question-card', ['question' => $question, 'index' => $index, 'primary' => $item->exists && $index === 0])
            @endforeach
        </div>
        <div data-builder-toolbar class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-dashed border-blue-200 bg-blue-50/50 p-4">
            <button type="button" data-builder-add class="rounded-xl bg-white px-5 py-3 text-sm font-semibold text-[#00008B] shadow-sm">+ Tambah pertanyaan</button>
            <button type="button" data-item-order-open data-category-select="mcu_category_id" aria-haspopup="dialog" aria-controls="item-order-modal" class="rounded-xl border border-blue-200 bg-white px-4 py-3 text-sm font-semibold text-[#00008B]">Atur urutan</button>
        </div>
        <p data-builder-status role="status" aria-live="polite" class="text-sm text-slate-500">Pertanyaan baru ditambahkan di akhir kategori. Paket dan hasil tersimpan tidak diperbarui otomatis.</p>
        <div class="sticky bottom-3 flex justify-end rounded-2xl border border-slate-200 bg-white p-4 shadow-lg"><button type="submit" data-builder-save class="rounded-xl bg-[#00008B] px-6 py-3 text-sm font-bold text-white">Simpan item dan mapping</button></div>
        <template data-builder-template>@include('mcu.masters.question-card', ['question' => ['input_type' => 'radio', 'is_required' => true, 'is_active' => true], 'index' => 0, 'primary' => false])</template>
    </form>
    @include('mcu.masters.item-order-modal')
</div>
@endsection
