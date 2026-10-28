@php($attachment = ($exam->dynamic_attachments ?? [])[$item['id']] ?? null)
@if(($item['allow_attachment'] ?? false) && $attachment)
    @php($attachmentUrl = route('pemeriksaan.attachment', [$peserta->rencana_kunjungan_id, $peserta, 'dynamic-'.$item['id'], 'version' => $historicalVersion ?? null, 'v' => hash('sha256', $attachment['path'])]))
    <section class="py-4"><h3>Lampiran {{ $item['label'] }}</h3>
        @if($attachment['mime'] === 'application/pdf')
            <p><a href="{{ $attachmentUrl }}" target="_blank" rel="noopener" class="text-blue-900 underline">Unduh lampiran PDF — {{ $item['label'] }}</a></p>
        @else
            <figure><img src="{{ $attachmentUrl }}" alt="Lampiran hasil {{ $item['label'] }}" class="max-h-[600px] max-w-full object-contain"></figure>
        @endif
    </section>
@endif
