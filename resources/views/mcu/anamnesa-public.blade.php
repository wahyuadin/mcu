<!doctype html>
<html lang="id">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow"><meta name="referrer" content="no-referrer"><title>Formulir Anamnesa MCU</title>@vite(['resources/css/app.css', 'resources/js/app.js'])</head>
<body class="min-h-dvh bg-slate-100 font-sans text-slate-800">
<main data-anamnesa-app class="mx-auto grid w-full min-w-0 max-w-2xl gap-4 px-4 py-8 sm:py-12">
    <noscript><p class="rounded-xl border border-amber-200 bg-white p-5 text-amber-800">Aktifkan JavaScript pada browser agar pengisian tanpa reload dapat digunakan.</p></noscript>
    <header class="grid gap-4 rounded-xl border border-slate-200 border-t-8 border-t-blue-900 bg-white p-6 sm:p-8">
        <div class="flex items-center justify-between gap-6"><img src="{{ asset('Template/assets/logo-nayaka.jpeg') }}" alt="Nayaka Era Husada" class="h-12 w-36 object-contain">@if($kunjungan->perusahaan->logo_path)<img src="{{ route('anamnesa.logo', $token) }}" alt="Logo {{ $kunjungan->perusahaan->nama_perusahaan }}" class="h-12 w-28 object-contain">@endif</div>
        <h1 class="text-2xl font-semibold">Formulir Anamnesa MCU</h1><p>{{ $kunjungan->perusahaan->nama_perusahaan }} · {{ $kunjungan->nomor_kunjungan }}</p>
        <p class="text-sm leading-6 text-slate-600">Isi riwayat kesehatan Anda dengan benar. Jawaban bersifat rahasia dan hanya digunakan untuk keperluan medis. Tidak perlu login.</p>
        @if(!$sent)<p class="border-t border-slate-100 pt-3 text-sm text-red-700">* Menandai pertanyaan wajib diisi</p>@endif
    </header>
    <p data-spa-status hidden role="alert" class="rounded-xl border border-red-200 bg-white p-5 text-red-700"></p>
    @error('form')<p role="alert" class="rounded-xl border border-red-200 bg-white p-5 text-red-700">{{ $message }}</p>@enderror
    @if($sent)
        <section class="grid gap-4 rounded-xl border border-slate-200 bg-white p-8"><h2 class="text-xl font-semibold">✓ Anamnesa berhasil dikirim</h2><p>Jawaban Anda sudah diterima dan akan ditinjau petugas. Silakan lanjutkan proses MCU sesuai arahan petugas.</p><p class="text-sm text-slate-500">Pengiriman ulang tidak diperlukan.</p></section>
        <form data-anamnesa-request method="POST" action="{{ route('anamnesa.reset', $token) }}">@csrf<button class="min-h-11 rounded-lg border border-slate-300 bg-white px-5 text-sm font-semibold">Selesai / peserta berikutnya</button></form>
    @elseif(!$participant)
        <form data-anamnesa-request method="POST" action="{{ route('anamnesa.identify', $token) }}" class="grid gap-4">@csrf
            <section class="grid gap-6 rounded-xl border border-slate-200 bg-white p-6 sm:p-8"><h2 class="text-lg font-semibold">Identifikasi peserta</h2>
                <label class="grid gap-3"><span>NIK KTP atau NIK/NIP karyawan <span class="text-red-700">*</span></span><input name="nip" value="{{ old('nip') }}" placeholder="Masukkan NIK KTP atau NIK/NIP karyawan" required maxlength="50" autocomplete="off" class="min-h-12 border-0 border-b border-slate-300 bg-transparent text-base outline-none focus:border-blue-900">@error('nip')<span role="alert" class="text-sm text-red-700">{{ $message }}</span>@enderror</label>
                <label class="grid gap-3"><span>Tanggal lahir <span class="text-red-700">*</span></span><input type="date" name="tanggal_lahir" value="{{ old('tanggal_lahir') }}" aria-describedby="birthdate-help" required class="min-h-12 border-0 border-b border-slate-300 bg-transparent text-base outline-none focus:border-blue-900"><span id="birthdate-help" class="text-xs text-slate-500">Pilih tanggal, bulan, dan tahun lahir Anda.</span>@error('tanggal_lahir')<span role="alert" class="text-sm text-red-700">{{ $message }}</span>@enderror</label>
                <p class="text-xs leading-5 text-slate-500">Masukkan salah satu: NIK KTP atau NIK/NIP karyawan yang terdaftar pada kunjungan ini. Tanggal lahir harus sesuai. Jika data tidak cocok, hubungi petugas. Sesi pengisian berlaku 30 menit.</p>
            </section><button class="min-h-12 justify-self-start rounded-lg bg-blue-900 px-6 font-semibold text-white">Lanjutkan →</button>
        </form>
    @else
        <section class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white p-5"><p class="min-w-0 break-words text-sm">Peserta: <strong>{{ $participant->nama_karyawan }}</strong></p><form data-anamnesa-request data-anamnesa-reset method="POST" action="{{ route('anamnesa.reset', $token) }}">@csrf<button class="min-h-11 text-sm font-semibold text-blue-900">Identitas tidak sesuai? Kembali</button></form></section>
        <form data-anamnesa-request method="POST" action="{{ route('anamnesa.submit', $token) }}" data-public-anamnesa class="grid min-w-0 gap-4">@csrf
            <p data-form-progress role="status" aria-live="polite" class="text-sm font-semibold text-slate-600"></p>
            @foreach($groups as $group => $items)
                <fieldset data-form-section class="grid min-w-0 gap-4"><legend class="mb-4 w-full rounded-xl bg-blue-900 px-6 py-4 text-lg font-semibold text-white">{{ $group }}</legend>
                    @foreach($items as $key => $item)
                        <section data-answer-card data-answer-key="{{ $key }}" data-label="{{ $item['label'] }}" class="grid min-w-0 gap-4 rounded-xl border border-slate-200 bg-white p-6 sm:p-8">
                            <h3 id="question-{{ $key }}" class="font-medium">{{ $item['label'] }} <span class="text-red-700">*</span></h3>
                            @if(in_array($key, ['keluhan_saat_ini', 'riwayat_obat'], true))
                                <input data-answer-input type="text" id="answer-{{ $key }}" aria-labelledby="question-{{ $key }}" name="answers[{{ $key }}][answer]" value="{{ old('answers.'.$key.'.answer') }}" required maxlength="2000" placeholder="{{ $key === 'keluhan_saat_ini' ? 'Tuliskan keluhan Anda, atau Tidak ada keluhan' : 'Tuliskan riwayat obat-obatan Anda, atau Tidak ada' }}" class="min-h-12 w-full min-w-0 rounded-lg border border-slate-300 bg-transparent px-3 text-base outline-none focus:border-blue-900">
                            @elseif($item['type'] === 'choice')
                                <div role="group" aria-labelledby="question-{{ $key }}" class="grid gap-3">@foreach(['Ya', 'Tidak'] as $option)<label class="flex min-h-11 items-center gap-3"><input type="radio" name="answers[{{ $key }}][answer]" value="{{ $option }}" required @checked(old('answers.'.$key.'.answer') === $option) class="h-5 w-5 accent-blue-900">{{ $option }}</label>@endforeach</div>
                            @else
                                <textarea id="answer-{{ $key }}" aria-labelledby="question-{{ $key }}" name="answers[{{ $key }}][answer]" required maxlength="2000" rows="2" placeholder="{{ 'Tuliskan jawaban untuk '.$item['label'] }}" class="w-full border-0 border-b border-slate-300 bg-transparent text-base outline-none focus:border-blue-900">{{ old('answers.'.$key.'.answer') }}</textarea>
                            @endif
                            @error('answers.'.$key.'.answer')<p role="alert" class="text-sm text-red-700">{{ $message }}</p>@enderror
                            <p data-client-error hidden role="alert" class="text-sm text-red-700"></p>
                            <label class="grid min-w-0 gap-2 text-sm text-slate-500">Keterangan / sejak kapan (opsional)<textarea name="answers[{{ $key }}][note]" maxlength="2000" rows="1" placeholder="Tambahkan rincian atau sejak kapan (opsional)" style="height: 83px;" class="w-full min-w-0 border-0 border-b border-slate-200 bg-transparent text-base outline-none focus:border-blue-900">{{ old('answers.'.$key.'.note') }}</textarea></label>
                            @error('answers.'.$key.'.note')<p role="alert" class="text-sm text-red-700">{{ $message }}</p>@enderror
                        </section>
                    @endforeach
                </fieldset>
            @endforeach
            <section data-form-section data-form-review aria-labelledby="review-title" class="grid w-full min-w-0 gap-5 rounded-xl border border-slate-200 bg-white p-6 sm:p-8"><h2 id="review-title" class="min-w-0 break-words text-lg font-semibold">Periksa jawaban</h2><p class="min-w-0 break-words text-sm text-slate-600">Pastikan jawaban sesuai kondisi Anda sebelum dikirim.</p><dl data-review-answers class="grid min-w-0 gap-3"></dl><label class="flex min-w-0 items-start gap-3"><input type="checkbox" name="confirmed" value="1" required @checked(old('confirmed')) class="mt-1 h-5 w-5 shrink-0 accent-blue-900"><span class="min-w-0 break-words">Saya menyatakan jawaban ini sesuai dengan kondisi dan riwayat saya.</span></label>@error('confirmed')<p role="alert" class="text-sm text-red-700">{{ $message }}</p>@enderror</section>
            <div class="flex flex-wrap justify-between gap-3"><button type="button" data-form-back hidden class="min-h-12 rounded-lg border border-slate-300 bg-white px-5 font-semibold text-blue-900">← Kembali</button><button type="button" data-form-next hidden class="min-h-12 rounded-lg bg-blue-900 px-6 font-semibold text-white">Berikutnya →</button><button type="submit" data-form-submit class="min-h-12 rounded-lg bg-blue-900 px-6 font-semibold text-white">Kirim anamnesa</button></div>
            <p class="text-xs text-slate-500">Jawaban belum tersimpan sampai Anda menekan Kirim. Jangan masukkan kata sandi atau informasi akun.</p>
        </form>
    @endif
    <dialog data-anamnesa-confirm aria-labelledby="reset-confirm-title" aria-describedby="reset-confirm-description" class="m-auto max-h-[90dvh] w-[calc(100%-2rem)] max-w-md overflow-y-auto rounded-2xl border-0 bg-white p-0 text-slate-800 shadow-2xl">
        <div class="grid gap-5 p-6 sm:p-8">
            <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-amber-50 text-amber-700" aria-hidden="true"><svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M10.3 3.9 2.6 17.2a2 2 0 0 0 1.7 3h15.4a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/></svg></div>
            <div class="grid min-w-0 gap-2"><h2 id="reset-confirm-title" class="break-words text-xl font-semibold">Kembali ke identifikasi?</h2><p id="reset-confirm-description" class="text-sm leading-6 text-slate-600">Jawaban yang belum dikirim akan dibuang. Pilih Batal untuk melanjutkan pengisian tanpa kehilangan jawaban.</p></div>
            <div class="grid gap-3 sm:grid-cols-2"><button type="button" data-confirm-cancel autofocus class="min-h-12 rounded-xl border border-slate-300 bg-white px-4 font-semibold text-slate-700">Batal</button><button type="button" data-confirm-accept class="min-h-12 rounded-xl bg-blue-900 px-4 font-semibold text-white">Kembali ke identifikasi</button></div>
        </div>
    </dialog>
    <footer class="py-4 text-center text-xs text-slate-500">Nayaka Era Husada · Rahasia medis</footer>
</main>
</body></html>
