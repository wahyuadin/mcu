<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>Laporan Medical Check Up — {{ $peserta->nomor_mcu }}</title>
    @vite(['resources/js/app.js'])
    <link rel="stylesheet" href="{{ asset('css/mcu-report.css') }}">
</head>
<body>
    <div class="print-controls">
        <a href="{{ route('pemeriksaan.edit', [$peserta->rencana_kunjungan_id, $peserta, 'tab' => 'review']) }}">← Review hasil</a>
        <button type="button" data-report-print disabled>Cetak / Simpan PDF</button>
        <p data-report-status>Menyiapkan halaman A4… Gunakan skala 100%, tanpa header/footer browser.</p>
        @isset($historicalVersion)<p>Laporan versi {{ $historicalVersion }} · {{ $revisionNote }}</p>@endisset
        <noscript>Aktifkan JavaScript untuk pembagian halaman otomatis sebelum mencetak.</noscript>
    </div>

    <main class="document" data-mcu-report aria-label="Laporan Medical Check Up Nayaka DKI">
        <article class="report-page cover" data-cover aria-label="Sampul laporan">
            <img class="cover-art" src="{{ asset('Template/assets/report-cover.png') }}" alt="Kop dan sampul laporan Nayaka Era Husada DKI Jakarta">
            <div class="cover-details">
                <dl class="cover-info">
                    @foreach(['Perusahaan' => $peserta->perusahaan->nama_perusahaan, 'Nama' => $peserta->nama_karyawan, 'NIK KTP' => $peserta->nik, 'NIP' => $peserta->nip] as $label => $value)
                        <div><dt>{{ $label }}<span>:</span></dt><dd>{{ $value ?? '—' }}</dd></div>
                    @endforeach
                </dl>
                <p class="cover-confidential"><strong>RAHASIA MEDIS</strong> · Dokumen ini bersifat rahasia dan hanya untuk kepentingan medis pasien</p>
            </div>
            @if($exam->status !== 'final')<div class="draft-label">DRAFT — BELUM DIFINALISASI</div>@endif
            <span class="page-number" data-page-number></span>
        </article>

        <article class="report-page summary-page" data-report-source aria-label="Informasi pasien dan kesimpulan">
            @include('mcu.report-brand')
            <div class="page-body summary-body" data-page-body>
                <section class="summary-top report-block">
                    <div>
                        <h3 class="section-label">INFORMASI PASIEN</h3>
                        <dl class="info-list">
                            @foreach(['Nama' => $peserta->nama_karyawan, 'Tanggal Lahir' => $peserta->tanggal_lahir?->format('d/m/Y'), 'Jenis Kelamin' => $peserta->jenis_kelamin === 'L' ? 'Laki-Laki' : 'Perempuan', 'NIK KTP' => $peserta->nik, 'NIP / No. Karyawan' => $peserta->nip, 'Perusahaan' => $peserta->perusahaan->nama_perusahaan, 'Department' => $peserta->bagian] as $label => $value)
                                <div><dt>{{ $label }}</dt><dd>{{ $value ?? '—' }}</dd></div>
                            @endforeach
                        </dl>
                        <h3 class="section-label mcu-info-label">INFORMASI MCU</h3>
                        <dl class="info-list"><div><dt>Paket</dt><dd>{{ $peserta->paket->nama_paket }}</dd></div><div><dt>No. MCU</dt><dd>{{ $peserta->nomor_mcu }}</dd></div></dl>
                    </div>
                    <div class="photo-section"><h3 class="section-label">FOTO PASIEN</h3>
                        @if($peserta->foto && str_starts_with($peserta->foto, 'mcu/'.$peserta->id.'/'))
                            <img class="patient-photo" src="{{ route('pemeriksaan.attachment', [$peserta->rencana_kunjungan_id, $peserta, 'foto', 'version' => $historicalVersion ?? null, 'v' => hash('sha256', $peserta->foto)]) }}" alt="Foto pasien">
                        @else<div class="photo-placeholder">Foto tidak<br>tersedia</div>@endif
                    </div>
                </section>
                <div class="health-status report-block">Status Kesehatan: <strong>{{ $exam->status_kelaikan ?? 'Belum ditentukan' }}</strong></div>
                <section class="summary-block report-block"><h3 class="section-label">KESIMPULAN</h3><p>{{ $exam->catatan_dokter ?? 'Belum diisi' }}</p></section>
                <section class="summary-block report-block"><h3 class="section-label">SARAN</h3><div class="recommendations">{{ $exam->anjuran_saran ?? 'Belum diisi' }}</div></section>
                <p class="annual-note report-block">Lakukan pemeriksaan kesehatan berkala setidaknya setiap 1 tahun sekali.</p>
                <p class="doctor report-block">Dokter Penanggung Jawab: <strong>{{ $doctors[$exam->dokter_id ?? ''] ?? 'Belum diisi' }}</strong></p>
            </div>
            @include('mcu.report-footer')
        </article>

        @foreach($sections as $section)
            <article class="report-page examination-page report-{{ $section['kind'] }}" data-report-source data-report-kind="{{ $section['kind'] }}" aria-label="{{ $section['title'] }}">
                @include('mcu.report-brand')
                @include('mcu.report-identity')
                <div class="page-body" data-page-body>
                    <h2 class="section-title" data-section-title>{{ $section['title'] }}</h2>
                    @if($section['kind'] === 'columns')
                        <div class="exam-columns" data-columns>
                            @for($column = 0; $column < 2; $column++)
                                <div class="exam-column" data-column="{{ $column }}">
                                    @foreach($section['groups'] as $group)
                                        @if($group['column'] === $column)
                                            @include('mcu.report-table', ['kind' => 'columns'])
                                        @endif
                                    @endforeach
                                </div>
                            @endfor
                        </div>
                    @else
                        @foreach($section['groups'] as $group)
                            @include('mcu.report-table', ['kind' => $section['kind']])
                        @endforeach
                    @endif
                    @foreach($section['attachments'] as $attachment)
                        @include('mcu.report-attachment')
                    @endforeach
                    @if(($section['show_doctor'] ?? false) || $section['doctor_id'])
                        <p class="doctor report-block">Dokter Pemeriksa: <strong>{{ $doctors[$section['doctor_id'] ?? ''] ?? 'Belum diisi' }}</strong></p>
                    @endif
                </div>
                @include('mcu.report-footer')
            </article>
        @endforeach
    </main>
</body>
</html>
