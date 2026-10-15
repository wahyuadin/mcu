@extends('layouts.mcu')
@section('title', $tab === 'ringkasan' ? 'Detail kunjungan MCU' : $tabs[$tab].' — Pemeriksaan MCU')
@section('content')
@php($examinationParameters = [$kunjungan, $peserta, ...$dashboardContext])
<div class="mx-auto grid max-w-7xl gap-6">
    <a href="{{ $hrdReadonly || $dashboardContext ? $dashboardReturnUrl : route('kunjungan-mcu.show', $kunjungan) }}" class="text-sm font-semibold text-slate-500">← {{ $hrdReadonly || $dashboardContext ? 'Peserta & laporan' : 'Daftar peserta '.$kunjungan->nomor_kunjungan }}</a>
    <div><h2 class="text-2xl font-bold text-slate-900">{{ $tab === 'ringkasan' ? 'Detail kunjungan MCU' : $tabs[$tab] }}</h2><p class="mt-2 text-sm text-slate-500">{{ $hrdReadonly ? 'Hanya baca · hasil final yang sudah dirilis untuk perusahaan Anda.' : ($tab === 'ringkasan' ? 'Lengkapi pemeriksaan sebelum review, kesimpulan dokter, dan finalisasi.' : 'Catat hasil berdasarkan sumber pemeriksaan. Kosong bukan berarti normal.') }}</p></div>
    <section class="flex flex-wrap items-center gap-5 rounded-2xl border border-slate-200 bg-white p-6">
        @if($peserta->foto && str_starts_with($peserta->foto, 'mcu/'.$peserta->id.'/'))
            <img src="{{ route('pemeriksaan.attachment', [$kunjungan, $peserta, 'foto', 'v' => hash('sha256', $peserta->foto)]) }}" alt="Foto {{ $peserta->nama_karyawan }}" class="h-20 w-16 rounded-xl object-cover">
        @else
            <span class="flex h-20 w-20 shrink-0 items-center justify-center rounded-2xl bg-blue-50 text-2xl font-bold text-blue-800">{{ mb_strtoupper(mb_substr($peserta->nama_karyawan, 0, 2)) }}</span>
        @endif
        <div class="min-w-0 flex-1">
            <h2 class="break-words text-2xl font-bold">{{ $peserta->nama_karyawan }}</h2>
            <p class="mt-2 text-sm text-slate-500">No. karyawan {{ $peserta->nip ?? '—' }} · {{ $peserta->perusahaan->nama_perusahaan }}</p>
            <p class="mt-1 text-sm text-slate-500">{{ $peserta->bagian ?? 'Departemen belum diisi' }} · {{ $peserta->jenis_kelamin === 'L' ? 'Laki-laki' : 'Perempuan' }} · {{ $peserta->usia ?? '—' }} tahun</p>
        </div>
        <div class="min-w-0"><strong class="break-all text-sm">{{ $peserta->nomor_mcu }}</strong><p class="mt-2 text-sm text-slate-500">{{ $peserta->tanggal_mcu?->translatedFormat('d F Y') ?? 'Tanggal MCU belum diisi' }} · {{ $peserta->paket->nama_paket }}</p></div>
        <span class="rounded-full px-5 py-2 text-sm font-semibold {{ $exam->status === 'final' ? 'bg-emerald-50 text-emerald-700' : 'bg-blue-50 text-blue-800' }}">{{ $exam->status === 'final' ? 'Final · terkunci' : ($readonly ? 'Hanya baca' : ($peserta->status_pemeriksaan === 'siap_finalisasi' ? 'Siap finalisasi' : 'Dalam pemeriksaan')) }}</span>
    </section>

    @if($tab !== 'ringkasan')
    <a href="{{ route('pemeriksaan.edit', $examinationParameters) }}" class="justify-self-start text-sm font-semibold text-emerald-700">← Ringkasan pemeriksaan</a>
    <nav aria-label="Bagian pemeriksaan" class="flex gap-2 overflow-x-auto rounded-2xl border border-slate-200 bg-white p-2">
        @foreach($tabs as $key => $label)
            <a href="{{ route('pemeriksaan.edit', [...$examinationParameters, 'tab' => $key]) }}" @if($tab === $key) aria-current="page" @endif class="shrink-0 rounded-xl px-4 py-3 text-sm font-semibold {{ $tab === $key ? 'bg-[#00008B] text-white' : 'text-slate-600 hover:bg-slate-100' }}">
                {{ $label }} @if(isset($progress[$key]) && $progress[$key]['included'])<span aria-label="{{ $progress[$key]['status'] }}">{{ $progress[$key]['status'] === 'Selesai' ? '✓' : ($progress[$key]['status'] === 'Draft' ? '●' : '○') }}</span>@endif
            </a>
        @endforeach
    </nav>
    <p class="text-xs text-slate-500">○ Belum diisi · ● Draft · ✓ Selesai. Kosong bukan berarti normal.@if(!$hrdReadonly) Pengisian dapat dilakukan dalam urutan apa pun.@endif</p>
    @endif

    @if($tab === 'ringkasan')
        @include('mcu.pemeriksaan-ringkasan')
    @elseif($tab === 'review')
        <section class="grid gap-5 rounded-2xl border border-slate-200 bg-white p-6">
            <div class="flex flex-wrap items-center justify-between gap-3"><h3 class="text-lg font-bold">Review kelengkapan</h3><a target="_blank" rel="noopener" href="{{ route('pemeriksaan.report', $examinationParameters) }}" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold">Pratinjau / cetak laporan ↗</a></div>
            @foreach($progress as $key => $section)
                @continue(!$section['included'])
                <div class="rounded-xl border border-slate-200 p-4">
                    <div class="flex items-center justify-between gap-3"><strong>{{ $section['label'] }}</strong><a href="{{ route('pemeriksaan.edit', [...$examinationParameters, 'tab' => $key]) }}" class="text-sm {{ $section['status'] === 'Selesai' ? 'text-emerald-700' : 'text-amber-700' }}">{{ $section['status'] }} →</a></div>
                    @if($section['missing'])<p class="mt-2 text-sm text-slate-500">Belum lengkap: {{ implode(', ', $section['missing']) }}</p>@endif
                </div>
            @endforeach
            <h3 class="font-bold">Temuan abnormal dan pengecualian</h3>
            @foreach($exam->dynamicGroupsForSection($peserta, 'laboratorium') as $group)
                @foreach($group['items'] as $item)
                    @php($evaluation = $exam->laboratoryEvaluation($item))
                    @if(in_array($evaluation['status'], ['Tidak normal', 'Tidak dapat dibandingkan'], true))<p class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">{{ $item['label'] }}: {{ $exam->dynamicReportValue($item) }} {{ $item['lab_config']['unit'] }} — {{ $evaluation['status'] }}{{ $evaluation['remark'] !== '' ? ' · '.$evaluation['remark'] : '' }}</p>@endif
                @endforeach
            @endforeach
            @forelse($exam->laboratorium ?? [] as $key => $row)
                @if($exam->includes($peserta, $key) && (($row['skipped'] ?? false) || in_array($row['flag'] ?? null, ['Tinggi', 'Rendah', 'Tidak Normal'], true)))
                    <p class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">{{ collect($laboratoryGroups)->collapse()[$key]['label'] ?? $key }}: {{ ($row['skipped'] ?? false) ? 'Tidak dilakukan — '.($row['reason'] ?? '') : ($row['result'] ?? '').' '.($row['unit'] ?? '').' — '.($row['flag'] ?? '') }}</p>
                @endif
            @empty
                @if($progress['laboratorium']['included'])<p class="text-sm text-slate-500">Belum ada hasil laboratorium.</p>@endif
            @endforelse
            @foreach($exam->penunjang ?? [] as $key => $row)
                @if($exam->includes($peserta, $key))<p class="text-sm"><strong>{{ $supportingItems[$key]['label'] ?? $key }}:</strong> {{ ($row['skipped'] ?? false) ? 'Tidak dilakukan — '.($row['reason'] ?? '') : ($row['kesimpulan'] ?? 'Belum diisi') }}</p>@endif
            @endforeach
            <div class="rounded-xl bg-slate-50 p-4"><strong>{{ $exam->status_kelaikan ?? 'Kelayakan belum ditentukan' }}</strong><p class="mt-2 whitespace-pre-line text-sm">{{ $exam->catatan_dokter }}</p></div>
        </section>
        @if($exam->status === 'final')
            <section class="grid gap-4 rounded-2xl border border-emerald-200 bg-emerald-50 p-6"><h3 class="font-bold text-emerald-900">Hasil final — hanya baca</h3><p class="text-sm">Difinalisasi {{ $exam->finalized_at?->format('d/m/Y H:i') ?? '(hasil lama)' }}.@if(!$hrdReadonly) Koreksi harus melalui revisi beralasan.@endif</p>
                @if(!$hrdReadonly && !$kunjungan->is_closing && !$peserta->is_closing)<form method="POST" action="{{ route('pemeriksaan.revise', [$kunjungan, $peserta]) }}" class="grid gap-3">@csrf
                    @include('mcu.field', ['field' => 'reason', 'label' => 'Alasan revisi (wajib)', 'type' => 'textarea', 'value' => null])
                    <button class="justify-self-start rounded-xl border border-emerald-700 px-5 py-3 font-semibold text-emerald-900">Buka revisi hasil</button>
                </form>@endif
            </section>
        @elseif(!$readonly)
            <form method="POST" action="{{ route('pemeriksaan.finalize', [$kunjungan, $peserta]) }}" class="grid gap-4 rounded-2xl border border-slate-200 bg-white p-6">@csrf
                <input type="hidden" name="lock_version" value="{{ $exam->lock_version ?? 0 }}">
                @if($finalizationErrors)<div role="status" class="rounded-xl bg-amber-50 p-4 text-sm text-amber-900"><strong>Finalisasi belum tersedia.</strong><ul class="mt-2 list-inside list-disc">@foreach($finalizationErrors as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
                <label class="flex items-start gap-3 text-sm"><input type="checkbox" name="confirmed" value="1" class="mt-1 h-4 w-4 accent-[#00008B]">Saya telah mencocokkan seluruh data dengan hasil sumber dan kesimpulan dokter.</label>
                <button @disabled(count($finalizationErrors) > 0) class="justify-self-start rounded-xl bg-[#00008B] px-6 py-3 font-semibold text-white disabled:cursor-not-allowed disabled:bg-slate-300">Finalisasi hasil MCU</button>
            </form>
        @endif
        @if($exam->revisions && !$hrdReadonly)
            <section class="grid gap-3 rounded-2xl bg-white p-6"><h3 class="font-bold">Riwayat revisi</h3>@foreach(array_reverse($exam->revisions) as $revision)<details class="rounded-xl border border-slate-200 p-4"><summary class="cursor-pointer text-sm font-semibold">{{ $revision['at'] }} · {{ $revision['operator'] }} — {{ $revision['reason'] }}</summary><pre class="mt-3 max-h-96 overflow-auto whitespace-pre-wrap break-all text-xs">{{ json_encode($revision['snapshot'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre></details>@endforeach</section>
        @endif
    @elseif($tab === 'identitas')
        @include('mcu.pemeriksaan-fields')
        @if(!$readonly)<a href="{{ route('pemeriksaan.edit', [$kunjungan, $peserta, 'tab' => 'anamnesa']) }}" class="justify-self-end rounded-xl bg-emerald-700 px-5 py-3 text-sm font-semibold text-white">Lanjut ke anamnesa →</a>@endif
    @else
        @if($hrdReadonly)<div class="grid gap-6">@else
        <form data-exam-form method="POST" enctype="multipart/form-data" action="{{ route('pemeriksaan.update', [$kunjungan, $peserta]) }}" class="grid gap-6">
            @csrf @method('PUT')
            <input type="hidden" name="section" value="{{ $tab }}">
            <input type="hidden" name="lock_version" value="{{ $exam->lock_version ?? 0 }}">
        @endif
            <fieldset @disabled($readonly) class="grid min-w-0 gap-6">
                @include('mcu.pemeriksaan-fields')
            </fieldset>
            @if(!$readonly)
                <div class="sticky bottom-3 z-10 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-lg">
                    <p data-save-status aria-live="polite" class="text-sm text-slate-500">{{ $exam->updated_at ? 'Terakhir tersimpan '.$exam->updated_at->format('d/m/Y H:i') : 'Belum ada hasil tersimpan' }}</p>
                    <div class="flex flex-wrap gap-2"><button name="status" value="draft" class="rounded-xl border border-[#00008B] px-4 py-3 text-sm font-semibold text-[#00008B]">Simpan draft</button>@if($tab !== 'identitas')<button name="status" value="selesai" class="rounded-xl bg-[#00008B] px-4 py-3 text-sm font-semibold text-white">Simpan & tandai selesai</button>@endif</div>
                </div>
            @else
                <p class="rounded-xl bg-slate-200 p-4 text-sm">{{ $hrdReadonly ? 'Hanya baca · hasil final yang sudah dirilis.' : 'Hasil dikunci.' }} @if(!$hrdReadonly && $exam->status === 'final' && !$kunjungan->is_closing)Buka tab Review untuk membuat revisi.@endif</p>
            @endif
        @if($hrdReadonly)</div>@else</form>@endif
    @endif
    @if($tab === 'kesimpulan' && !$readonly)
        @can('manage-mcu')<details class="rounded-xl border border-slate-200 bg-white p-4"><summary class="cursor-pointer text-sm font-semibold">Dokter belum terdaftar? Tambahkan dokter</summary><form method="POST" action="{{ route('dokter.store') }}" class="mt-4 grid gap-4 sm:grid-cols-3">@csrf
            @include('mcu.field', ['field' => 'nama_dokter', 'label' => 'Nama dokter', 'value' => null])
            @include('mcu.field', ['field' => 'no_str', 'label' => 'Nomor STR', 'value' => null])
            @include('mcu.field', ['field' => 'spesialisasi', 'label' => 'Spesialisasi', 'value' => 'Dokter MCU'])
            <button class="rounded-xl bg-[#00008B] px-4 py-3 text-sm font-semibold text-white">Tambah dokter</button>
        </form></details>@endcan
    @endif
</div>
@endsection
