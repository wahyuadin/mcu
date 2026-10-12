<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Label — {{ $peserta->nomor_mcu }}</title>
    <style>
        @include('mcu.label-styles')
        :root { --label-width: {{ $category['width'] }}mm; --label-height: {{ $category['height'] }}mm; }
        @page { size: {{ $category['width'] }}mm {{ $category['height'] }}mm; margin: 0; }
        body { margin: 0; }
    </style>
</head>
<body>
    @foreach($subcategories as $subcategory)
        <article class="mcu-label" data-barcode-type="{{ $category['type'] }}" data-label-layout="{{ $category['type'] === 'qr' && $category['width'] > $category['height'] ? 'horizontal' : 'vertical' }}">
            <strong>{{ $subcategory['name'] }} - {{ $category['name'] }}</strong>
            <strong>{{ $peserta->nama_karyawan }} - {{ $peserta->nip ?: 'NIP belum diisi' }}</strong>
            <span>{{ $printedAt }}</span>
            <img src="{{ $barcodes[$subcategory['id']][$category['type']] }}" alt="{{ $category['type'] === 'qr' ? 'QR' : 'Code 128' }} menuju {{ $subcategory['name'] }} pada {{ $category['name'] }}">
        </article>
    @endforeach
</body>
</html>
