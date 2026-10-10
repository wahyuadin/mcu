<form data-patient-photo method="POST" enctype="multipart/form-data" action="{{ route('pemeriksaan.photo', [$kunjungan, $peserta]) }}" class="grid gap-6">@csrf
    @error('foto_pasien')<p role="alert" class="rounded-xl bg-red-50 p-4 text-sm text-red-700">{{ $message }} Pilih ulang foto sebelum menyimpan.</p>@enderror
    <div class="grid items-start gap-6 lg:grid-cols-2">
        <section class="grid gap-5 rounded-2xl border border-slate-200 bg-white p-6">
            <h3 class="text-lg font-bold">Ambil foto dengan kamera</h3>
            <div data-camera-panel hidden class="grid gap-3"><video data-camera autoplay playsinline muted class="aspect-[4/3] w-full rounded-xl bg-slate-900 object-contain"></video><div class="flex flex-wrap gap-3"><button data-camera-capture type="button" disabled class="rounded-xl bg-emerald-700 px-4 py-3 text-sm font-semibold text-white disabled:opacity-50">Ambil foto</button><button data-camera-switch type="button" class="rounded-xl border border-slate-300 px-4 py-3 text-sm font-semibold">Ganti kamera</button><button data-camera-stop type="button" class="rounded-xl border border-slate-300 px-4 py-3 text-sm font-semibold">Tutup kamera</button></div></div>
            <button data-camera-start type="button" class="rounded-xl border border-emerald-700 px-5 py-3 text-sm font-bold text-emerald-700">Buka kamera realtime</button>
            <p class="text-xs text-slate-500">Izinkan akses kamera. Kamera memerlukan HTTPS atau localhost; jika tidak tersedia, gunakan upload.</p>
            <h3 class="border-t border-slate-200 pt-5 text-lg font-bold">Upload foto</h3>
            <div data-photo-drop tabindex="0" role="button" aria-label="Pilih, seret, atau paste foto pasien" class="grid cursor-pointer gap-3 rounded-xl border-2 border-dashed border-slate-300 bg-slate-50 p-7 text-center focus:outline-none focus:ring-2 focus:ring-emerald-700"><strong class="text-sm">Seret foto ke sini atau pilih gambar</strong><span class="text-xs text-slate-500">Bisa juga paste gambar: Ctrl+V / ⌘+V</span><span class="text-xs text-slate-500">JPG, PNG, WebP · maksimum 5 MB · maksimum 20 megapiksel</span></div>
            <input data-photo-file type="file" name="foto_pasien" accept="image/jpeg,image/png,image/webp" aria-label="Pilih file foto pasien" class="w-full rounded-xl border border-slate-300 p-3 text-sm">
            <p data-photo-message role="status" aria-live="polite" class="text-sm text-slate-500">Belum ada foto baru dipilih.</p>
        </section>
        <section class="grid gap-5 rounded-2xl border border-slate-200 bg-white p-6">
            <h3 class="text-lg font-bold">Preview foto</h3>
            <div class="flex min-h-64 items-center justify-center rounded-xl bg-blue-50 p-4"><img data-photo-preview @if($hasPhoto) src="{{ route('pemeriksaan.attachment', [$kunjungan, $peserta, 'foto', 'v' => hash('sha256', $peserta->foto)]) }}" @else hidden @endif alt="Preview foto pasien" class="max-h-96 max-w-full rounded-xl object-contain"><span data-photo-placeholder @if($hasPhoto) hidden @endif class="text-sm text-slate-500">Preview muncul setelah foto dipilih atau diambil.</span></div>
            <p data-photo-details class="text-xs text-slate-500">{{ $hasPhoto ? 'Foto saat ini sudah tersimpan. Pilih foto baru jika ingin mengganti.' : 'Foto belum tersimpan.' }}</p>
            <div class="flex flex-wrap gap-3"><button data-photo-change type="button" class="rounded-xl border border-slate-300 px-4 py-3 text-sm font-semibold">Ganti gambar</button><button data-camera-retake type="button" class="rounded-xl border border-slate-300 px-4 py-3 text-sm font-semibold">Ambil ulang</button></div>
            <div class="rounded-xl bg-emerald-50 p-4 text-sm leading-relaxed text-emerald-900">Foto dikompres di server menjadi WebP, kualitas 80%, sisi terpanjang maksimal 1.200 px. Penyimpanan privat dan ukuran file lebih hemat.</div>
            <button data-photo-save type="submit" disabled class="rounded-xl bg-emerald-700 px-5 py-3 text-sm font-bold text-white disabled:cursor-not-allowed disabled:bg-slate-300">{{ ($photoModal ?? false) ? 'Simpan foto baru' : 'Simpan foto & lanjut pemeriksaan' }}</button>
            @if($photoModal ?? false)<button data-photo-modal-close type="button" class="rounded-xl border border-slate-300 px-5 py-3 text-sm font-semibold">Batal</button>@elseif($hasPhoto)<a href="{{ route('pemeriksaan.edit', [$kunjungan, $peserta]) }}" class="text-center text-sm font-semibold text-emerald-700">Batal mengganti, gunakan foto tersimpan →</a>@endif
            <noscript><p class="text-sm text-amber-700">Aktifkan JavaScript untuk kamera dan preview, atau gunakan tombol berikut setelah memilih file.</p><button type="submit" class="rounded-xl bg-emerald-700 px-5 py-3 text-white">Simpan foto</button></noscript>
        </section>
    </div>
</form>
