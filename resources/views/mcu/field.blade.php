@php
    $parts = explode('.', $field);
    $inputName = array_shift($parts).implode('', array_map(fn ($part) => '['.$part.']', $parts));
    $inputValue = old($field, $value ?? null);
    $inputType = $type ?? 'text';
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
        <textarea name="{{ $inputName }}" rows="{{ $rows ?? 3 }}" class="min-h-24 w-full rounded-xl border border-slate-300 px-3 py-2 font-normal focus:border-[#00008B] focus:outline-none focus:ring-2 focus:ring-blue-100">{{ $inputValue }}</textarea>
    @else
        <input name="{{ $inputName }}" type="{{ $inputType }}" value="{{ $inputValue }}" @required($required ?? false) @isset($placeholder) placeholder="{{ $placeholder }}" @endisset @if($inputType === 'number') step="{{ $step ?? 'any' }}" @endif class="min-h-12 w-full rounded-xl border border-slate-300 px-3 py-2 font-normal focus:border-[#00008B] focus:outline-none focus:ring-2 focus:ring-blue-100">
    @endif
    @error($field)<span class="text-xs font-normal text-red-700">{{ $message }}</span>@enderror
</label>
