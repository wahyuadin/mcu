<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Laporan MCU — {{ $peserta->nomor_mcu }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="stylesheet" href="{{ asset('css/mcu-report.css') }}">
</head>
<body>
    <div class="print-controls mx-auto flex max-w-4xl flex-wrap items-center justify-between gap-3 p-6"><a href="{{ route('pemeriksaan.edit', [$peserta->rencana_kunjungan_id, $peserta, 'tab' => 'review']) }}" class="font-semibold text-[#00008B]">← Review hasil</a><button onclick="window.print()" class="rounded-xl bg-[#00008B] px-5 py-3 font-semibold text-white">Cetak / Simpan PDF</button><p class="w-full text-sm text-slate-500">Gunakan “Simpan sebagai PDF” di dialog cetak browser. Dokumen ini bersifat rahasia medis.</p></div>
    <main>
        <article class="report-page cover">
            @include('mcu.report-brand')
            <div class="cover-title"><span class="eyebrow">HASIL</span><h1>PEMERIKSAAN<br>KESEHATAN</h1><p>MEDICAL CHECK-UP</p></div>
            <dl class="cover-patient"><div><dt>Perusahaan</dt><dd>{{ $peserta->perusahaan->nama_perusahaan }}</dd></div><div><dt>Nama</dt><dd>{{ $peserta->nama_karyawan }}</dd></div><div><dt>NIK/NIP karyawan</dt><dd>{{ $peserta->nip ?? '—' }}</dd></div><div><dt>No. MCU</dt><dd>{{ $peserta->nomor_mcu }}</dd></div></dl>
            @if($exam->status !== 'final')<p class="draft-label">DRAFT — BELUM DIFINALISASI</p>@endif
            <p class="confidential"><strong>RAHASIA MEDIS</strong> · Dokumen ini bersifat rahasia dan hanya untuk kepentingan medis pasien.</p>
            @include('mcu.report-footer')
        </article>
        <article class="report-page"><table class="page-frame"><thead><tr><td>@include('mcu.report-brand')</td></tr></thead><tfoot><tr><td>@include('mcu.report-footer')</td></tr></tfoot><tbody><tr><td>
            <h1>Ringkasan MCU</h1>
            <div class="summary-top"><section><h3>INFORMASI PASIEN</h3><dl class="info-list">
                @foreach(['Nama' => $peserta->nama_karyawan, 'Tanggal Lahir' => $peserta->tanggal_lahir?->format('d/m/Y') ?? '—', 'Jenis Kelamin' => $peserta->jenis_kelamin === 'L' ? 'Laki-laki' : 'Perempuan', 'NIK/NIP karyawan' => $peserta->nip ?? '—', 'Perusahaan' => $peserta->perusahaan->nama_perusahaan, 'Departemen' => $peserta->bagian, 'Paket' => $peserta->paket->nama_paket, 'No. MCU' => $peserta->nomor_mcu, 'Tanggal MCU' => $peserta->tanggal_mcu?->format('d/m/Y') ?? '—'] as $label => $value)
                    <div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
                @endforeach
            </dl></section><section><h3>FOTO PASIEN</h3>@if($peserta->foto && str_starts_with($peserta->foto, 'mcu/'.$peserta->id.'/'))<img class="patient-photo" src="{{ route('pemeriksaan.attachment', [$peserta->rencana_kunjungan_id, $peserta, 'foto', 'v' => hash('sha256', $peserta->foto)]) }}" alt="Foto pasien">@else<div class="photo-placeholder">Foto tidak tersedia</div>@endif</section></div>
            <div class="health-status">Status kesehatan: <strong>{{ $exam->status_kelaikan ?? 'Belum ditentukan' }}</strong></div>
            <section class="summary-block"><h3>KESIMPULAN</h3><p>{{ $exam->catatan_dokter ?? 'Belum diisi' }}</p></section>
            <section class="summary-block"><h3>SARAN</h3><p>{{ $exam->anjuran_saran ?? 'Belum diisi' }}</p></section>
            <p class="doctor">Dokter penanggung jawab: {{ $exam->dokter?->nama_dokter ?? 'Belum diisi' }}</p>
        </td></tr></tbody></table></article>
        <article class="report-page"><table class="page-frame"><thead><tr><td>@include('mcu.report-brand')<h1>Laporan Medical Check Up</h1><p class="identity">{{ $peserta->nama_karyawan }} · {{ $peserta->nomor_mcu }} · {{ $peserta->bagian }}<br>Lahir: {{ $peserta->tanggal_lahir?->format('d/m/Y') ?? '—' }} · NIK/NIP karyawan: {{ $peserta->nip ?? '—' }}</p></td></tr></thead><tfoot><tr><td>@include('mcu.report-footer')</td></tr></tfoot><tbody><tr><td class="report-content">
        @if($exam->progress($peserta, false)['fisik']['included'])<h2>HASIL PEMERIKSAAN FISIK</h2>@endif
        <div class="physical-columns">
        @foreach(\App\McuCatalog::physical() as $group => $items)
            @if(array_filter($items, fn ($label, $key) => $exam->includes($peserta, $key), ARRAY_FILTER_USE_BOTH))
                <section class="py-4"><h2 class="report-heading mb-3 text-lg font-bold">{{ ucwords(str_replace('_', ' ', $group)) }}</h2><table class="w-full text-left text-sm"><tbody>@foreach($items as $key => $label)@if($exam->includes($peserta, $key))@foreach(\App\McuCatalog::physicalFields()[$key] ?? [$key] as $field)<tr class="border-b border-slate-200"><th class="w-1/2 p-2 font-medium">{{ $label }} {{ str_contains($field, '_od_') ? 'OD' : (str_contains($field, '_os_') ? 'OS' : '') }}</th><td class="p-2">{{ $exam->$field ?? 'Belum diisi' }}</td></tr>@endforeach @endif @endforeach</tbody></table></section>
            @endif
        @endforeach
        </div>
        @if($exam->progress($peserta, false)['fisik']['included'])
        <p class="doctor">Dokter pemeriksa fisik: {{ $exam->dokterFisik?->nama_dokter ?? '—' }}</p>
        @endif
        @if($exam->progress($peserta, false)['anamnesa']['included'])
        <section class="report-section py-6"><h2 class="report-heading mb-4 text-xl font-bold">Anamnesa pasien</h2><table class="w-full text-left text-sm"><tbody>@foreach($catalog['anamnesa_riwayat'] as $key => $label)@if($exam->includes($peserta, $key))@php($value = $exam->historyValue($key))<tr class="border-b border-slate-200"><th class="w-1/2 p-2 font-medium">{{ $label }}</th><td class="p-2">{{ $value['answer'] ?? 'Belum diisi' }} {{ $value['note'] ? '— '.$value['note'] : '' }}</td></tr>@endif @endforeach</tbody></table></section>
        @endif
        @if($exam->progress($peserta, false)['laboratorium']['included'])
        <section class="report-section py-6"><h2 class="report-heading mb-4 text-xl font-bold">Hasil laboratorium</h2>@foreach(\App\McuCatalog::laboratory() as $group => $items)@if(array_filter($items, fn ($item, $key) => $exam->includes($peserta, $key), ARRAY_FILTER_USE_BOTH))<h3 class="report-heading mb-3 mt-5 font-bold">{{ $group }}</h3><table class="w-full text-left text-sm"><thead><tr class="border-b-2 border-slate-700">@foreach(['Pemeriksaan', 'Hasil', 'Satuan', 'Nilai rujukan', 'Keterangan'] as $heading)<th class="p-2">{{ $heading }}</th>@endforeach</tr></thead><tbody>@foreach($items as $key => $item)@if($exam->includes($peserta, $key))@php($row = ($exam->laboratorium ?? [])[$key] ?? [])<tr class="border-b border-slate-200"><td class="p-2">{{ $item['label'] }}</td><td class="p-2">{{ ($row['skipped'] ?? false) ? 'Tidak dilakukan' : ($row['result'] ?? 'Belum diisi') }}</td><td class="p-2">{{ $row['unit'] ?? '' }}</td><td class="p-2">{{ $row['reference'] ?? '' }}</td><td class="p-2">{{ ($row['skipped'] ?? false) ? ($row['reason'] ?? '') : ($row['flag'] ?? '') }}</td></tr>@endif @endforeach</tbody></table>@endif @endforeach</section>
        @endif
        @foreach(\App\McuCatalog::supporting() as $key => $item)@if($exam->includes($peserta, $key))@php($row = ($exam->penunjang ?? [])[$key] ?? [])<section class="report-section grid gap-4 py-6"><h2 class="report-heading text-xl font-bold">{{ $item['label'] }}</h2>@if($row['skipped'] ?? false)<p>Tidak dilakukan — {{ $row['reason'] ?? '' }}</p>@else<dl class="grid gap-3">@foreach($item['fields'] as $field => $label)<div><dt class="font-semibold">{{ $label }}</dt><dd class="whitespace-pre-line">{{ $row[$field] ?? 'Belum diisi' }}</dd></div>@endforeach</dl>@if(isset($row['attachment']))<img src="{{ route('pemeriksaan.attachment', [$peserta->rencana_kunjungan_id, $peserta, $key]) }}" alt="Hasil {{ $item['label'] }}" class="max-h-[600px] max-w-full object-contain">@endif<p class="text-sm">Dokter pemeriksa: {{ $doctors[$row['doctor_id'] ?? ''] ?? 'Belum diisi' }}</p>@endif</section>@endif @endforeach
        @foreach($exam->categoryGroups($peserta) as $group)
            <section class="report-section py-6"><h2 class="report-heading mb-4 text-xl font-bold">{{ $group['name'] }}</h2>
                @foreach(\App\LaboratoryResult::sections($group['items']) as $subsection)
                    @if($subsection['name'])<h3 class="report-heading mb-3 mt-5 font-bold">{{ $subsection['name'] }}</h3>@endif
                    @php($labItems = array_values(array_filter($subsection['items'], fn ($item) => $item['type'] === 'laboratory')))
                    @if($labItems)<table class="w-full text-left text-sm"><thead><tr class="border-b-2 border-slate-700">@foreach(['Pemeriksaan', 'Hasil', 'Unit', 'Nilai Normal', 'Keterangan'] as $heading)<th class="p-2">{{ $heading }}</th>@endforeach</tr></thead><tbody>
                        @foreach($labItems as $item)@php($evaluation = $exam->laboratoryEvaluation($item))<tr class="border-b border-slate-200 {{ $evaluation['status'] === 'Tidak normal' ? 'abnormal' : '' }}"><th class="p-2 font-medium">{{ $item['label'] }}</th><td class="p-2">{{ $exam->dynamicReportValue($item) }}@if($evaluation['status'] === 'Tidak dapat dibandingkan')<span class="block text-xs">Tidak dapat dibandingkan</span>@endif</td><td class="p-2">{{ $item['lab_config']['unit'] }}</td><td class="whitespace-pre-line p-2">{{ $item['lab_config']['reference'] }}</td><td class="whitespace-pre-line p-2">{{ $evaluation['remark'] }}</td></tr>@endforeach
                    </tbody></table>@endif
                    <table class="w-full text-left text-sm"><tbody>@foreach($subsection['items'] as $item)@if($item['type'] !== 'laboratory')<tr class="border-b border-slate-200"><th class="w-1/2 p-2 font-medium">{{ $item['label'] }}</th><td class="whitespace-pre-line p-2">{{ $exam->dynamicReportValue($item) }}</td></tr>@endif @endforeach</tbody></table>
                @endforeach
            </section>
        @endforeach
        </td></tr></tbody></table></article>
    </main>
</body>
</html>
