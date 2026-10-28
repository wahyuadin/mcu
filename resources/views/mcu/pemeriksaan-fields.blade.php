@if($tab === 'identitas')
    <section class="grid gap-5 rounded-2xl border border-slate-200 bg-white p-6">
        <div class="flex flex-wrap items-center justify-between gap-4"><h3 class="text-lg font-bold">Identitas episode MCU</h3>
            @if(auth()->user()->can('examine-mcu'))<details data-print-dropdown class="relative">
                <summary class="cursor-pointer list-none rounded-xl bg-emerald-700 px-5 py-3 text-sm font-semibold text-white">Cetak ▾</summary>
                <div class="absolute right-0 z-20 mt-2 grid w-48 gap-1 rounded-xl border border-slate-200 bg-white p-2 shadow-lg">
                    <button type="button" data-checkpoint-print data-print-url="{{ route('pemeriksaan.checkpoint', [$kunjungan, $peserta]) }}" class="rounded-lg px-4 py-3 text-left text-sm font-semibold text-slate-700 hover:bg-slate-100 disabled:opacity-50">Cetak PDF</button>
                    <button type="button" data-label-open aria-haspopup="dialog" aria-controls="examination-label-modal" class="rounded-lg px-4 py-3 text-left text-sm font-semibold text-slate-700 hover:bg-slate-100">Cetak label</button>
                </div>
            </details>@endif
        </div>
        <p data-checkpoint-print-status role="status" aria-live="polite" class="text-sm text-slate-500"></p>
        <div class="flex flex-col gap-6 lg:flex-row lg:items-start lg:gap-8">
            <div class="flex shrink-0 justify-center rounded-xl bg-slate-50 p-4 lg:w-44">
                @if($peserta->hasStoredPhoto())<img src="{{ route('pemeriksaan.attachment', [$kunjungan, $peserta, 'foto', 'v' => hash('sha256', $peserta->foto)]) }}" alt="Foto peserta {{ $peserta->nama_karyawan }}" class="h-44 w-36 rounded-xl object-cover">@else<span class="flex h-44 w-36 items-center justify-center rounded-xl bg-slate-200 text-sm text-slate-500">Foto belum tersedia</span>@endif
            </div>
            <dl class="grid min-w-0 flex-1 gap-5 text-sm sm:grid-cols-2">
                @foreach(['Nama' => $peserta->nama_karyawan, 'Tanggal lahir' => $peserta->tanggal_lahir?->format('d/m/Y'), 'Jenis kelamin' => $peserta->jenis_kelamin === 'P' ? 'Perempuan' : 'Laki-laki', 'NIK identitas' => $peserta->nik, 'Nomor MCU' => $peserta->nomor_mcu, 'Perusahaan' => $peserta->perusahaan->nama_perusahaan, 'Paket' => $peserta->paket->nama_paket, 'Tanggal MCU' => $peserta->tanggal_mcu?->format('d/m/Y'), 'NIK/NIP karyawan' => $peserta->nip, 'Departemen' => $peserta->bagian, 'Jabatan' => $peserta->jabatan, 'Telepon' => $peserta->telepon] as $label => $value)
                    <div class="min-w-0"><dt class="text-slate-500">{{ $label }}</dt><dd class="mt-2 break-words font-semibold">{{ $value ?: 'Belum diisi' }}</dd></div>
                @endforeach
            </dl>
            @if(auth()->user()->can('examine-mcu'))<figure class="flex shrink-0 flex-col items-center justify-center gap-3 rounded-xl border border-slate-200 bg-white p-4 lg:w-44"><a href="{{ $checkpoint['scanUrl'] }}" data-examination-scan-url title="{{ $checkpoint['scanUrl'] }}" aria-label="Buka pemeriksaan pasien"><img src="{{ $checkpoint['qrDataUri'] }}" alt="QR menuju pemeriksaan pasien" class="h-36 w-36"></a><figcaption class="text-center text-xs font-semibold text-slate-500">Scan untuk pemeriksaan</figcaption></figure>@endif
        </div>
        @if(!$readonly)<div class="flex flex-wrap gap-3 border-t border-slate-200 pt-5">
            <button type="button" data-photo-modal-open aria-haspopup="dialog" aria-controls="patient-photo-modal" class="rounded-xl bg-blue-900 px-5 py-3 text-sm font-semibold text-white">Edit identitas pasien</button>
        </div>@endif
    </section>
    @if(auth()->user()->can('examine-mcu')) @include('mcu.label-modal') @endif
    @if(!$readonly)
        <dialog id="patient-photo-modal" data-photo-modal @if($errors->has('foto_pasien') || (old('identity_modal') && $errors->any())) data-photo-modal-errors @endif aria-labelledby="patient-photo-modal-title" class="m-auto max-h-[90dvh] w-[calc(100%-2rem)] max-w-5xl overflow-y-auto rounded-2xl border-0 bg-slate-50 p-0 text-slate-900 shadow-xl">
            <div class="sticky top-0 z-10 flex items-center justify-between gap-4 border-b border-slate-200 bg-white p-5"><div><h2 id="patient-photo-modal-title" class="text-lg font-bold">Edit identitas pasien</h2><p class="mt-1 text-sm text-slate-500">{{ $peserta->nama_karyawan }}</p></div><button type="button" data-photo-modal-close autofocus aria-label="Tutup modal identitas" class="rounded-xl border border-slate-300 px-4 py-3 text-sm font-semibold">Tutup</button></div>
            <div class="grid gap-6 p-4 sm:p-6">
                @can('manage-mcu') @include('mcu.patient-identity-form') @endcan
                <div><h3 class="mb-4 text-lg font-bold">Foto pasien</h3>@include('mcu.patient-photo-form', ['hasPhoto' => $peserta->hasStoredPhoto(), 'photoModal' => true])</div>
            </div>
        </dialog>
    @endif
@elseif($tab === 'anamnesa')
    @if($peserta->anamnesa_submission && !$hrdReadonly)
        <section class="grid gap-3 rounded-2xl border border-blue-200 bg-blue-50 p-6"><h3 class="font-bold">Anamnesa dari peserta</h3><p class="text-sm">Dikirim: {{ \Carbon\Carbon::parse($peserta->anamnesa_submission['submitted_at'])->format('d/m/Y H:i') }} · {{ ($peserta->anamnesa_submission['reviewed_at'] ?? null) ? 'Sudah ditinjau petugas' : 'Menunggu pemeriksaan petugas' }}</p>
            @if($reviewSubmission)<input type="hidden" name="submission_id" value="{{ $peserta->anamnesa_submission['id'] }}"><p class="text-sm font-semibold">Jawaban peserta dimuat untuk ditinjau. Periksa dan koreksi sebelum menekan Simpan. Hasil pemeriksaan belum diubah.</p>
            @elseif(!$readonly && !($peserta->anamnesa_submission['reviewed_at'] ?? null))<a href="{{ route('pemeriksaan.edit', [$kunjungan, $peserta, 'tab' => 'anamnesa', 'review_submission' => 1]) }}" class="justify-self-start rounded-xl bg-blue-900 px-5 py-3 text-sm font-semibold text-white">Tinjau jawaban peserta</a>@endif
        </section>
    @endif
    @foreach($anamnesaGroups as $group => $items)
        @php($activeItems = array_filter($items, fn ($item, $key) => $exam->includes($peserta, $key), ARRAY_FILTER_USE_BOTH))
        @if($activeItems)<section class="grid gap-5 rounded-2xl border border-slate-200 bg-white p-6"><h3 class="text-lg font-bold">{{ $group }}</h3>
            @foreach($activeItems as $key => $item)
                @php($history = $exam->historyValue($key))
                @if($reviewSubmission) @php($history = $peserta->anamnesa_submission['answers'][$key] ?? ['answer' => null, 'note' => null]) @endif
                <div @if($item['type'] === 'choice') data-conditional-answer @endif class="grid gap-4 border-t border-slate-100 pt-4 sm:grid-cols-2">
                    @include('mcu.field', ['field' => 'anamnesa.'.$key.'.answer', 'label' => $item['label'], 'type' => $item['type'] === 'choice' ? 'select' : 'textarea', 'value' => $history['answer'], 'options' => ['Ya' => 'Ya', 'Tidak' => 'Tidak']])
                    @if($item['type'] === 'choice')<fieldset data-conditional-note @if(old('anamnesa.'.$key.'.answer', $history['answer']) !== 'Ya') hidden disabled @endif>
                        @include('mcu.field', ['field' => 'anamnesa.'.$key.'.note', 'label' => 'Keterangan / sejak kapan (opsional)', 'type' => 'textarea', 'value' => $history['note']])
                    </fieldset>@endif
                </div>
            @endforeach
        </section>@endif
    @endforeach
    @foreach($exam->anamnesisGroups($peserta) as $group)
        @include('mcu.dynamic-examination-group')
    @endforeach
@elseif($tab === 'fisik')
    @if($progress['fisik']['included'] && $exam->sectionHasDoctor($peserta, 'fisik'))
        <section class="rounded-2xl border border-slate-200 bg-white p-6">@include('mcu.field', ['field' => 'dokter_fisik_id', 'label' => 'Dokter pemeriksa fisik', 'type' => 'select', 'options' => $doctors->pluck('nama_dokter', 'id'), 'value' => $exam->dokter_fisik_id])</section>
    @endif
    @foreach($physicalGroups as $group => $items)
        @php($activeItems = array_filter($items, fn ($label, $key) => $exam->includes($peserta, $key), ARRAY_FILTER_USE_BOTH))
        @if($activeItems)<section class="grid gap-5 rounded-2xl border border-slate-200 bg-white p-6"><h3 class="text-lg font-bold">{{ ucwords(str_replace('_', ' ', $group)) }}</h3><div class="grid gap-5 sm:grid-cols-2">
            @foreach($activeItems as $key => $label)
                @if($key === 'bmi')<div class="rounded-xl bg-blue-50 p-4 text-sm"><strong>BMI & kategori (otomatis)</strong><p data-bmi class="mt-2">{{ $exam->bmi ?? '—' }} · {{ $exam->kategori_bmi ?? 'Belum dihitung' }}</p></div>
                @else
                    @foreach(\App\McuCatalog::physicalFields()[$key] ?? [$key] as $field)
                        @include('mcu.field', ['field' => $field, 'label' => $label.(str_contains($field, '_od_') ? ' — OD' : (str_contains($field, '_os_') ? ' — OS' : '')), 'type' => in_array($field, ['tinggi_badan', 'berat_badan', 'lingkar_pinggang', 'suhu_badan', 'nadi', 'pernapasan']) ? 'number' : 'text', 'value' => $exam->$field, 'step' => in_array($field, ['nadi', 'pernapasan']) ? '1' : 'any'])
                    @endforeach
                @endif
            @endforeach
        </div><p class="text-xs text-slate-500">Untuk temuan teks yang tidak diperiksa, tulis “Tidak diperiksa — alasan”. Tekanan darah: sistolik/diastolik, misalnya 138/88.</p></section>@endif
    @endforeach
    @foreach($exam->physicalMasterGroups($peserta) as $group)
        @include('mcu.dynamic-examination-group')
    @endforeach
    @if($exam->physicalMasterGroups($peserta) !== [] && !$exam->includes($peserta, 'bmi'))
        <section class="rounded-xl bg-blue-50 p-4 text-sm"><strong>BMI & kategori (otomatis)</strong><p data-bmi role="status" aria-live="polite" class="mt-2">{{ $exam->bmi !== null ? $exam->bmi.' · '.$exam->kategori_bmi : 'Belum dapat dihitung' }}</p></section>
    @endif
@elseif($tab === 'laboratorium')
    <p class="rounded-xl bg-blue-50 p-4 text-sm text-blue-900">Isi nilai rujukan dari laboratorium sumber. Penanda otomatis hanya untuk angka dengan rujukan seperti 3.8–10.6 atau &lt; 200. Untuk hasil kualitatif/rentang hasil, pilih penanda secara manual.</p>
    @foreach($laboratoryGroups as $group => $items)
        @php($activeItems = array_filter($items, fn ($item, $key) => $exam->includes($peserta, $key), ARRAY_FILTER_USE_BOTH))
        @if($activeItems)<section class="grid gap-4 rounded-2xl border border-slate-200 bg-white p-6"><h3 class="text-lg font-bold">{{ $group }}</h3>
            @foreach($activeItems as $key => $item)
                @php($row = ($exam->laboratorium ?? [])[$key] ?? [])
                <div class="grid gap-4 rounded-xl border border-slate-200 p-4"><h4 class="font-semibold">{{ $item['label'] }}</h4><div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    @include('mcu.field', ['field' => 'laboratorium.'.$key.'.result', 'label' => 'Hasil', 'type' => 'text', 'value' => $row['result'] ?? null])
                    @include('mcu.field', ['field' => 'laboratorium.'.$key.'.unit', 'label' => 'Satuan', 'type' => 'text', 'value' => $row['unit'] ?? $item['unit']])
                    @include('mcu.field', ['field' => 'laboratorium.'.$key.'.reference', 'label' => 'Nilai rujukan', 'type' => 'text', 'value' => $row['reference'] ?? null])
                    @include('mcu.field', ['field' => 'laboratorium.'.$key.'.flag', 'label' => 'Penanda', 'type' => 'select', 'value' => $row['flag_mode'] ?? $row['flag'] ?? 'auto', 'options' => ['auto' => 'Hitung otomatis', 'Normal' => 'Normal', 'Tinggi' => 'Tinggi', 'Rendah' => 'Rendah', 'Tidak Normal' => 'Tidak Normal']])
                </div>
                <p class="text-xs text-slate-500">Penanda tersimpan: <strong>{{ $row['flag'] ?? 'Belum ditentukan' }}</strong>. Jika memilih otomatis, penanda dihitung ulang saat disimpan.</p>
                <details @if(old('laboratorium.'.$key.'.skipped', $row['skipped'] ?? false)) open @endif><summary class="cursor-pointer text-sm text-slate-500">Pemeriksaan tidak dilakukan</summary><div class="mt-3 grid gap-3 sm:grid-cols-2">
                    @include('mcu.field', ['field' => 'laboratorium.'.$key.'.skipped', 'label' => 'Tidak dilakukan?', 'type' => 'select', 'value' => ($row['skipped'] ?? false) ? '1' : '0', 'options' => ['0' => 'Tidak — hasil diisi', '1' => 'Ya — wajib isi alasan']])
                    @include('mcu.field', ['field' => 'laboratorium.'.$key.'.reason', 'label' => 'Alasan tidak dilakukan', 'type' => 'text', 'value' => $row['reason'] ?? null])
                </div></details></div>
            @endforeach
        </section>@endif
    @endforeach
@elseif($tab === 'penunjang')
    @foreach($supportingItems as $key => $item)
        @if($exam->includes($peserta, $key))
            @php($row = ($exam->penunjang ?? [])[$key] ?? [])
            <section id="{{ $key }}" class="grid scroll-mt-6 gap-5 rounded-2xl border border-slate-200 bg-white p-6"><h3 class="text-lg font-bold">{{ $item['label'] }}</h3><div class="grid gap-5 sm:grid-cols-2">
                @include('mcu.field', ['field' => 'penunjang.'.$key.'.doctor_id', 'label' => 'Dokter pemeriksa', 'type' => 'select', 'value' => $row['doctor_id'] ?? null, 'options' => $doctors->pluck('nama_dokter', 'id')])
                @include('mcu.field', ['field' => 'penunjang.'.$key.'.flag', 'label' => 'Penanda', 'type' => 'select', 'value' => $row['flag'] ?? null, 'options' => ['Normal' => 'Normal', 'Tidak Normal' => 'Tidak Normal']])
                @foreach($item['fields'] as $field => $label)
                    @include('mcu.field', ['field' => 'penunjang.'.$key.'.'.$field, 'label' => $label, 'type' => 'textarea', 'value' => $row[$field] ?? null])
                @endforeach
            </div>
            @if(!$hrdReadonly)<label class="grid gap-2 text-sm font-semibold">Gambar hasil (opsional)<input type="file" name="images[{{ $key }}]" accept="image/jpeg,image/png,image/webp" class="rounded-xl border border-slate-300 p-3"><span class="text-xs font-normal text-slate-500">JPG, PNG, WebP · maksimal 10 MB. Unggahan baru menggantikan gambar aktif; riwayat revisi tetap dipertahankan.</span></label>@endif
            @if(isset($row['attachment']))<a target="_blank" rel="noopener" href="{{ route('pemeriksaan.attachment', [$kunjungan, $peserta, $key]) }}" class="justify-self-start text-sm font-semibold text-[#00008B] underline">Lihat gambar tersimpan ↗</a>@endif
            <details @if($row['skipped'] ?? false) open @endif><summary class="cursor-pointer text-sm text-slate-500">Pemeriksaan tidak dilakukan</summary><div class="mt-3 grid gap-4 sm:grid-cols-2">
                @include('mcu.field', ['field' => 'penunjang.'.$key.'.skipped', 'label' => 'Tidak dilakukan?', 'type' => 'select', 'value' => ($row['skipped'] ?? false) ? '1' : '0', 'options' => ['0' => 'Tidak — hasil diisi', '1' => 'Ya — wajib isi alasan']])
                @include('mcu.field', ['field' => 'penunjang.'.$key.'.reason', 'label' => 'Alasan tidak dilakukan', 'type' => 'text', 'value' => $row['reason'] ?? null])
            </div></details></section>
        @endif
    @endforeach
@elseif(isset($categoryGroups[$tab]))
    @include('mcu.dynamic-examination-group', ['group' => $categoryGroups[$tab]])
@elseif($tab === 'kesimpulan')
    <section class="grid gap-5 rounded-2xl border border-slate-200 bg-white p-6"><h3 class="text-lg font-bold">Kesimpulan MCU</h3><p class="text-sm text-slate-500">Diinput operator berdasarkan hasil dokter. Program tidak menetapkan diagnosis atau kelayakan kerja otomatis.</p>
        <div class="grid gap-5 sm:grid-cols-2">
            @include('mcu.field', ['field' => 'dokter_id', 'label' => 'Dokter penanggung jawab', 'type' => 'select', 'value' => $exam->dokter_id, 'options' => $doctors->pluck('nama_dokter', 'id')])
            @include('mcu.field', ['field' => 'status_kelaikan', 'label' => 'Status kelayakan', 'type' => 'select', 'value' => $exam->status_kelaikan, 'options' => ['Fit to Work' => 'Fit to Work', 'Fit with Note' => 'Fit With Note', 'Temporarily Unfit' => 'Temporarily Unfit', 'Unfit' => 'Unfit']])
        </div>
        @include('mcu.field', ['field' => 'catatan_dokter', 'label' => 'Kesimpulan medis', 'type' => 'textarea', 'value' => $exam->catatan_dokter, 'rows' => 5])
        @include('mcu.field', ['field' => 'anjuran_saran', 'label' => 'Saran dan tindak lanjut (satu saran per baris)', 'type' => 'textarea', 'value' => $exam->anjuran_saran, 'rows' => 7])
    </section>
@endif
@if(isset($progress[$tab]) && !$progress[$tab]['included'])
    <div class="rounded-2xl border border-dashed border-slate-300 bg-white p-8 text-center text-slate-500">Pemeriksaan ini tidak termasuk paket peserta.</div>
@endif
