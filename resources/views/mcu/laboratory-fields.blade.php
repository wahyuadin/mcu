<div class="overflow-x-auto"><table class="w-full min-w-[720px] text-left text-sm"><thead><tr class="border-b-2 border-slate-200">@foreach(['Pemeriksaan', 'Hasil', 'Unit', 'Nilai Normal', 'Keterangan'] as $heading)<th class="p-3">{{ $heading }}</th>@endforeach</tr></thead><tbody>
@foreach($items as $item)
    @php
        $config = $item['lab_config'];
        $savedAnswers = $exam->dynamic_answers ?? [];
        $hasDefault = !$readonly && !session()->hasOldInput() && !array_key_exists($item['id'], $savedAnswers) && filled($config['default_result'] ?? null);
        $answer = ($readonly || !$hasDefault) ? ($savedAnswers[$item['id']] ?? null) : $config['default_result'];
        if (!$readonly && session()->hasOldInput()) {
            $answer = old('dynamic_answers.'.$item['id']);
        }
        $evaluation = \App\LaboratoryResult::evaluate($config, $answer);
    @endphp
    <tr data-laboratory-row data-config="{{ json_encode($config) }}" class="border-b border-slate-100 align-top">
        <th class="p-3 font-semibold">{{ $item['label'] }}{{ $item['required'] ? ' *' : ' (opsional)' }}@if($item['help_text'])<p class="mt-2 font-normal text-slate-500">{{ $item['help_text'] }}</p>@endif</th>
        <td class="p-3"><input data-laboratory-answer name="dynamic_answers[{{ $item['id'] }}]" aria-label="Hasil {{ $item['label'] }}" type="{{ $config['result_type'] === 'number' ? 'number' : 'text' }}" @if($config['result_type'] === 'number') step="any" min="-999999999" max="999999999" @else maxlength="2000" @endif value="{{ $answer }}" class="min-h-12 w-36 rounded-xl border border-slate-300 px-3"><span data-laboratory-status class="mt-2 block text-xs text-slate-500" role="status">{{ $evaluation['status'] }}</span>@error('dynamic_answers.'.$item['id'])<p class="mt-2 text-sm text-red-700">{{ $message }}</p>@enderror</td>
        <td class="p-3">{{ $config['unit'] }}</td><td class="whitespace-pre-line p-3">{{ $config['reference'] }}</td><td data-laboratory-remark class="whitespace-pre-line p-3">{{ $evaluation['remark'] }}</td>
    </tr>
    @if($item['allow_attachment'] ?? false)<tr><td colspan="5" class="p-3">@include('mcu.dynamic-attachment-field', ['item' => $item])</td></tr>@endif
    @if($hasDefault)<tr class="border-b border-slate-100"><td colspan="5" class="px-3 pb-3 text-xs text-amber-800">Hasil default dari master — belum tersimpan. Cocokkan {{ $item['label'] }} dengan hasil pemeriksaan sebelum menyimpan.</td></tr>@endif
@endforeach
</tbody></table></div>
