@php($attachmentUrl = route('pemeriksaan.attachment', [$peserta->rencana_kunjungan_id, $peserta, $attachment['key'], 'version' => $historicalVersion ?? null, 'v' => hash('sha256', $attachment['path'])]))
<figure class="medical-attachment report-block">
    @if($attachment['mime'] === 'application/pdf')
        <figcaption>Lampiran {{ $attachment['label'] }}</figcaption>
        <p><a href="{{ $attachmentUrl }}" target="_blank" rel="noopener">Unduh lampiran PDF — {{ $attachment['label'] }}</a></p>
    @else
        <img src="{{ $attachmentUrl }}" alt="Lampiran hasil {{ $attachment['label'] }}">
    @endif
</figure>
