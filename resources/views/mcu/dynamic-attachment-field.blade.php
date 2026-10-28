@if($item['allow_attachment'] ?? false)
    @php($attachment = ($exam->dynamic_attachments ?? [])[$item['id']] ?? null)
    <div class="grid min-w-0 gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4">
        @if(!$readonly)
            <label class="grid min-w-0 gap-2 text-sm font-semibold">Lampiran {{ $item['label'] }} (opsional)<input type="file" name="dynamic_files[{{ $item['id'] }}]" accept="image/jpeg,image/png,image/webp,application/pdf" class="min-w-0 rounded-xl border border-slate-300 bg-white p-3"><span class="text-xs font-normal text-slate-500">JPG, PNG, WebP, PDF · maksimal 10 MB. Tidak wajib. File baru menggantikan lampiran aktif; riwayat revisi tetap dipertahankan.</span></label>
            @error('dynamic_files.'.$item['id'])<p role="alert" class="text-sm text-red-700">{{ $message }}</p>@enderror
        @endif
        @if($attachment)
            <a target="_blank" rel="noopener" href="{{ route('pemeriksaan.attachment', [$kunjungan, $peserta, 'dynamic-'.$item['id'], 'v' => hash('sha256', $attachment['path'])]) }}" class="justify-self-start text-sm font-semibold text-blue-900 underline">{{ $attachment['mime'] === 'application/pdf' ? 'Unduh PDF tersimpan' : 'Lihat gambar tersimpan' }} ↗</a>
        @elseif($readonly)<p class="text-sm text-slate-500">Tidak ada lampiran.</p>@endif
    </div>
@endif
