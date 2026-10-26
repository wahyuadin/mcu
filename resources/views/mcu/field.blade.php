@php
    $parts = explode('.', $field);
    $inputName = array_shift($parts).implode('', array_map(fn ($part) => '['.$part.']', $parts));
    $inputValue = old($field, $value ?? null);
    $inputType = $type ?? 'text';
    $inputPlaceholder = $placeholder ?? match ($field) {
        'tinggi_badan' => 'Contoh: 170', 'berat_badan' => 'Contoh: 65', 'tekanan_darah' => 'Contoh: 120/80',
        'nadi' => 'Contoh: 80', 'pernapasan' => 'Contoh: 20', 'suhu_badan' => 'Contoh: 36.5', 'lingkar_pinggang' => 'Contoh: 80',
        default => str_ends_with($field, '.note') ? 'Tambahkan rincian atau sejak kapan (opsional)' : ($inputType === 'number' ? 'Masukkan angka' : 'Tuliskan '.$label),
    };
@endphp
<label class="grid content-start gap-2 text-sm font-semibold text-slate-700">
    {{ $label }}{{ ($required ?? false) ? ' *' : '' }}
    @if($inputType === 'select')
        <select name="{{ $inputName }}" class="min-h-12 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 font-normal focus:border-[#00008B] focus:outline-none focus:ring-2 focus:ring-blue-100">
            <option value="">{{ $selectPlaceholder ?? 'Belum diisi' }}</option>
            @foreach($options as $optionValue => $optionLabel)
                <option value="{{ $optionValue }}" @selected((string) $inputValue === (string) $optionValue)>{{ $optionLabel }}</option>
            @endforeach
        </select>
    @elseif($inputType === 'textarea')
        <textarea name="{{ $inputName }}" placeholder="{{ $inputPlaceholder }}" rows="{{ $rows ?? 3 }}" class="min-h-24 w-full rounded-xl border border-slate-300 px-3 py-2 font-normal focus:border-[#00008B] focus:outline-none focus:ring-2 focus:ring-blue-100">{{ $inputValue }}</textarea>
    @else
        <input name="{{ $inputName }}" type="{{ $inputType }}" value="{{ $inputValue }}" placeholder="{{ $inputPlaceholder }}" @required($required ?? false) @if($inputType === 'number') step="{{ $step ?? 'any' }}" @endif class="min-h-12 w-full rounded-xl border border-slate-300 px-3 py-2 font-normal focus:border-[#00008B] focus:outline-none focus:ring-2 focus:ring-blue-100">
    @endif
    @error($field)<span class="text-xs font-normal text-red-700">{{ $message }}</span>@enderror
</label>
