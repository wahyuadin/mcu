<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Checkpoint — {{ $peserta->nomor_mcu }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; color: #17233b; background: #edf1f5; font-family: Arial, sans-serif; }
        .controls { max-width: 900px; margin: 24px auto; padding: 0 20px; font-size: 14px; line-height: 1.6; }
        .controls a { color: #006b50; }
        .controls button { margin-left: 16px; border: 0; border-radius: 8px; padding: 12px 18px; background: #006b50; color: white; font-weight: bold; cursor: pointer; }
        .preview { overflow-x: auto; padding: 0 20px 24px; }
        .sheet { width: 210mm; min-height: 148mm; margin: 0 auto; background: white; box-shadow: 0 8px 32px #17233b18; }
        .checkpoint-card { width: 210mm; height: 148mm; padding: 7mm; break-inside: avoid; }
        .logo { display: block; height: 10mm; max-width: 75mm; object-fit: contain; object-position: left; }
        .identity { display: grid; grid-template-columns: 28mm minmax(0, 1fr) 40mm; gap: 4mm; align-items: start; margin-top: 4mm; }
        .photo { width: 28mm; height: 38mm; object-fit: cover; border-radius: 1mm; }
        .photo-placeholder { border: 0.25mm solid #cbd5e1; display: flex; align-items: center; justify-content: center; font-size: 8pt; }
        .name { margin: 0 0 2mm; font-size: 14pt; line-height: 1.2; overflow-wrap: anywhere; }
        .details { margin: 0; display: grid; grid-template-columns: 30mm minmax(0, 1fr); gap: 1mm 1mm; font-size: 10pt; line-height: 1.2; }
        .details dt { color: #5b6575; }
        .details dd { margin: 0; overflow-wrap: anywhere; }
        .qr { text-align: center; font-size: 9pt; }
        .qr img { display: block; width: 40mm; height: 40mm; }
        .package { margin: 4mm 0 3mm; padding-top: 3mm; border-top: 0.25mm solid #cbd5e1; font-size: 11pt; overflow-wrap: anywhere; }
        .checkpoints { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 3mm; }
        .checkpoint-box { min-height: 17mm; border: 0.25mm solid #8c98a8; border-radius: 1mm; padding: 2mm 3mm; font-size: 10pt; line-height: 1.25; }
        .checkpoint-box strong { display: block; margin-bottom: 1mm; font-size: 10pt; overflow-wrap: anywhere; }
        .checkpoint-box p { margin: 0.6mm 0 0; }
        .check-square { display: inline-block; width: 2mm; height: 2mm; margin-right: 1mm; border: 0.25mm solid #17233b; vertical-align: middle; }
        @page { size: A5 landscape; margin: 0; }
        @media print {
            body { background: white; color: black; }
            .controls { display: none; }
            .preview { overflow: visible; padding: 0; }
            .sheet { margin: 0; box-shadow: none; }
        }
    </style>
</head>
<body>
    <div class="controls"><a href="{{ $checkpoint['identityUrl'] }}">← Identitas pasien</a><button type="button" onclick="window.print()">Cetak / Simpan PDF</button><p>A5 landscape (210 × 148 mm) · skala 100% · margin tidak ada · nonaktifkan header/footer browser.</p></div>
    <main class="preview"><div class="sheet"><article class="checkpoint-card" aria-label="Kartu checkpoint pasien">
        <img class="logo" src="{{ asset('Template/assets/logo.png') }}" alt="Nayaka">
        <div class="identity">
            @if($peserta->hasStoredPhoto())<img class="photo" src="{{ route('pemeriksaan.attachment', [$kunjungan, $peserta, 'foto', 'v' => hash('sha256', $peserta->foto)]) }}" alt="Foto pasien">@else<div class="photo photo-placeholder">Foto pasien</div>@endif
            <div><h1 class="name">{{ $peserta->nama_karyawan }}</h1><dl class="details">
                @foreach(['No. MCU' => $peserta->nomor_mcu, 'NIK/NIP' => $peserta->nip, 'Perusahaan' => $peserta->perusahaan->nama_perusahaan, 'Departemen' => $peserta->bagian, 'Jabatan' => $peserta->jabatan, 'Tanggal MCU' => $peserta->tanggal_mcu?->format('d/m/Y')] as $label => $value)<dt>{{ $label }}</dt><dd>{{ $value ?: '—' }}</dd>@endforeach
            </dl></div>
            <div class="qr"><img src="{{ $checkpoint['qrDataUri'] }}" alt="QR menuju pemeriksaan pasien"><span>Scan data pasien</span></div>
        </div>
        <h2 class="package">PAKET: {{ $peserta->paket->nama_paket }}</h2>
        <div class="checkpoints">
            @forelse($checkpoint['items'] as $key => $label)<section class="checkpoint-box"><strong>{{ $label }}</strong><p><span class="check-square" aria-hidden="true"></span>Diperiksa</p><p>Paraf: __________________</p></section>@empty<p>Belum ada pemeriksaan aktif dalam paket ini.</p>@endforelse
        </div>
    </article></div></main>
</body>
</html>
