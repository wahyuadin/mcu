<section data-exam-category="{{ $group['id'] }}" class="grid gap-5 rounded-2xl border border-slate-200 bg-white p-6">
    <h3 class="text-lg font-bold">{{ $group['name'] }}</h3>
    @if(($group['has_responsible_doctor'] ?? true) && $exam->groupSection($group) !== 'fisik')
        @include('mcu.field', ['field' => 'category_doctors.'.$group['id'], 'label' => 'Dokter Pemeriksa (opsional)', 'type' => 'select', 'options' => $doctors->pluck('nama_dokter', 'id'), 'value' => ($exam->category_doctors ?? [])[$group['id']] ?? null])
    @endif
    @foreach(\App\LaboratoryResult::sections($group['items']) as $subsection)
        <div data-exam-subcategory="{{ $subsection['items'][0]['subcategory']['id'] ?? $group['id'] }}" tabindex="-1" class="grid min-w-0 scroll-mt-24 gap-5 rounded-xl p-3">
            @if($subsection['name'])<h4 class="border-b border-slate-200 pb-3 font-bold">{{ $subsection['name'] }}</h4>@endif
            @php
                $labItems = array_values(array_filter($subsection['items'], fn ($item) => $item['type'] === 'laboratory'));
            @endphp
            @if($labItems) @include('mcu.laboratory-fields', ['items' => $labItems]) @endif
            @foreach($subsection['items'] as $item)
                @if($item['type'] === 'laboratory') @continue @endif
                @php
                    $storedAnswers = $exam->dynamic_answers ?? [];
                    $answer = array_key_exists($item['id'], $storedAnswers) ? $storedAnswers[$item['id']] : ($item['default_answer'] ?? null);
                    $note = ($exam->dynamic_notes ?? [])[$item['id']] ?? null;
                    if (($reviewSubmission ?? false) && ($group['section'] ?? null) === 'anamnesa') {
                        $answer = $peserta->anamnesa_submission['answers'][$item['id']]['answer'] ?? $answer;
                        $note = $peserta->anamnesa_submission['answers'][$item['id']]['note'] ?? $note;
                        if ($item['type'] === 'radio' && is_string($answer)) {
                            $answer = mb_strtoupper($answer);
                        }
                    }
                    $answer = old('dynamic_answers.'.$item['id'], $answer);
                @endphp
                <div @if($item['type'] === 'radio') data-conditional-answer @endif @if(isset($item['physical_key'])) data-physical-key="{{ $item['physical_key'] }}" @endif class="grid gap-3">
                @if($item['type'] === 'label')
                    <div class="rounded-xl bg-blue-50 p-4"><h4 class="font-semibold">{{ $item['label'] }}</h4><p class="mt-2 whitespace-pre-line text-sm text-blue-900">{{ $item['help_text'] }}</p></div>
                @elseif($item['type'] === 'radio')
                    <fieldset class="grid gap-3 border-t border-slate-100 pt-4"><legend class="text-sm font-semibold">{{ $item['label'] }}{{ $item['required'] ? ' *' : ' (opsional)' }}</legend>
                        <div class="flex flex-wrap gap-3">@foreach(['YA', 'TIDAK'] as $choice)<label class="flex cursor-pointer items-center gap-3 rounded-xl border border-slate-300 px-5 py-3 text-sm font-semibold"><input type="radio" name="dynamic_answers[{{ $item['id'] }}]" value="{{ $choice }}" @checked($answer === $choice) class="h-4 w-4 accent-[#00008B]">{{ $choice }}</label>@endforeach</div>
                        @if($item['help_text'])<p class="text-xs text-slate-500">{{ $item['help_text'] }}</p>@endif
                        <p class="text-xs text-slate-500">Hasil laporan tersimpan: {{ $exam->dynamicReportValue($item) }}</p>
                        @error('dynamic_answers.'.$item['id'])<p class="text-sm text-red-700">{{ $message }}</p>@enderror
                    </fieldset>
                @else
                    @include('mcu.field', ['field' => 'dynamic_answers.'.$item['id'], 'label' => $item['label'].($item['required'] ? ' *' : ' (opsional)'), 'type' => $item['type'] === 'number' ? 'number' : 'textarea', 'step' => 'any', 'value' => $answer, 'placeholder' => match ($item['physical_key'] ?? '') { 'tinggi_badan' => 'Contoh: 170', 'berat_badan' => 'Contoh: 65', 'tekanan_darah' => 'Contoh: 120/80', default => $item['type'] === 'number' ? 'Masukkan angka' : 'Tuliskan '.$item['label'] }])
                    @if($item['help_text'])<p class="text-xs text-slate-500">{{ $item['help_text'] }}</p>@endif
                    @include('mcu.dynamic-attachment-field', ['item' => $item])
                @endif
                @if($item['type'] === 'radio')
                    <fieldset @if($item['type'] === 'radio') data-conditional-note @if($answer !== 'YA') hidden disabled @endif @endif>
                        @include('mcu.field', ['field' => 'dynamic_notes.'.$item['id'], 'label' => 'Keterangan / sejak kapan (opsional)', 'type' => 'textarea', 'value' => $note, 'placeholder' => 'Tambahkan rincian atau sejak kapan (opsional)'])
                    </fieldset>
                @endif
                </div>
            @endforeach
        </div>
    @endforeach
</section>
