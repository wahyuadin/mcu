<details data-dashboard-reports class="min-w-0 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6" @if(!$company) hidden @endif>
    <summary class="min-h-11 cursor-pointer py-2 text-lg font-bold text-slate-800">Peserta & laporan perusahaan</summary>
    <div data-dashboard-report-workspace data-company="{{ $company?->id }}" data-reports-only="true" data-data-url="{{ route('hrd.data') }}" data-participant-url="{{ route('hrd.participants.show', ['participant' => '00000000-0000-0000-0000-000000000000']) }}" data-export-url="{{ route('hrd.exports.store') }}" data-recap-url="{{ route('hrd.recap') }}" class="mt-4 grid min-w-0 gap-5">
        <p class="text-xs leading-5 text-slate-500">Statistik medis dan unduhan hanya dari hasil final yang sudah dirilis. Pilihan perusahaan mengikuti dashboard di atas.</p>
        <form data-hrd-filters class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
            @csrf
            <label hidden class="hrd-field">Kunjungan<select name="visit" class="hrd-input"><option value="">Semua kunjungan</option></select></label>
            <label class="hrd-field">Periode<select name="period" class="hrd-input"><option value="">Semua periode</option></select></label>
            <label class="hrd-field">Paket<select name="package" class="hrd-input"><option value="">Semua paket</option></select></label>
            <label class="hrd-field">Cari peserta<input name="q" type="search" maxlength="255" placeholder="Nama / NIP / nomor MCU" enterkeyhint="search" class="hrd-input"></label>
            <div class="flex items-end gap-2"><button type="button" data-hrd-reset class="hrd-button">Reset rincian</button></div>
        </form>
        <div class="flex flex-wrap items-center justify-between gap-3"><p data-hrd-updated class="text-xs text-slate-500">Pembaruan otomatis setiap 4 detik.</p><span data-hrd-live role="status" class="text-xs text-emerald-700">Menghubungkan…</span><button type="button" data-hrd-recap class="hrd-button">↓ Unduh rekap CSV</button></div>
        <button type="button" data-hrd-cross-clear hidden class="hrd-button"></button>
        <p data-hrd-error role="alert" hidden class="rounded-xl bg-amber-50 p-4 text-sm text-amber-900"></p>
        <section data-hrd-widgets hidden></section>
        <section id="hrd-participants" class="grid min-w-0 gap-4">
            <div class="flex flex-wrap items-center justify-between gap-3"><div><h3 class="text-lg font-bold">Peserta & laporan</h3><p data-hrd-participant-count class="mt-1 text-sm text-slate-500">Menyiapkan daftar…</p></div><button type="button" data-hrd-export disabled class="hrd-primary">Unduh laporan ZIP</button></div>
            <label class="flex min-h-11 cursor-pointer items-start gap-3 py-2 text-sm"><input type="checkbox" data-hrd-all class="mt-1 h-4 w-4 shrink-0 accent-[#00008B]"><span>Pilih semua peserta yang sudah dirilis sesuai filter <small class="block text-xs text-slate-500">Seluruh halaman · maksimal 1000 peserta per ZIP.</small></span></label>
            <p data-hrd-selected class="text-xs font-semibold text-slate-500">0 peserta dipilih</p>
            <div class="overflow-x-auto"><table class="w-full min-w-[760px] text-left text-sm" aria-label="Peserta MCU perusahaan"><thead class="border-y border-slate-100 bg-slate-50 text-xs uppercase text-slate-500"><tr><th class="px-3 py-4">Pilih</th><th class="px-3 py-4">Peserta</th><th class="px-3 py-4">Paket</th><th class="px-3 py-4">Departemen</th><th class="px-3 py-4">Status</th></tr></thead><tbody data-hrd-participants class="divide-y divide-slate-100"></tbody></table></div>
            <div class="flex items-center justify-between gap-3"><button type="button" data-hrd-prev disabled class="hrd-button">← Sebelumnya</button><span data-hrd-page class="text-xs text-slate-500"></span><button type="button" data-hrd-next disabled class="hrd-button">Berikutnya →</button></div>
            <div data-hrd-export-state hidden class="grid gap-3 rounded-xl border border-blue-100 bg-blue-50 p-4"><p data-hrd-export-status role="status" aria-live="polite" class="text-sm text-blue-900"></p><progress data-hrd-export-progress max="100" value="0" class="w-full"></progress><a data-hrd-download hidden class="hrd-primary justify-self-start">Unduh ZIP</a></div>
        </section>
        <dialog data-user-modal data-hrd-confirm aria-labelledby="hrd-confirm-title" class="fixed inset-0 m-auto w-[calc(100%-2rem)] max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-2xl"><h2 id="hrd-confirm-title" class="text-xl font-bold">Siapkan laporan ZIP?</h2><p data-hrd-confirm-description class="mt-3 text-sm leading-6 text-slate-600"></p><div class="mt-6 flex justify-end gap-3"><button type="button" data-hrd-confirm-cancel autofocus class="hrd-button">Batal</button><button type="button" data-hrd-confirm-submit class="hrd-primary">Siapkan ZIP</button></div></dialog>
        <dialog data-user-modal data-hrd-detail aria-labelledby="hrd-detail-title" class="fixed inset-0 m-auto max-h-[90dvh] w-[calc(100%-2rem)] max-w-3xl overflow-y-auto rounded-2xl border border-slate-200 bg-white p-6 shadow-2xl"><div class="flex items-center justify-between gap-3"><h2 id="hrd-detail-title" class="min-w-0 break-words text-xl font-bold"></h2><button type="button" data-hrd-detail-close aria-label="Tutup detail" class="hrd-button shrink-0">Tutup</button></div><div data-hrd-detail-content class="mt-5 grid gap-4"></div></dialog>
    </div>
</details>
