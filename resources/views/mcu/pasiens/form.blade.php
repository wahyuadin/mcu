@extends('layouts.mcu')
@section('title', $pasien->exists ? 'Ubah Pasien' : 'Tambah Peserta MCU')
@section('content')
@php
    $selectedVisitId = old('rencana_kunjungan_id', $pasien->rencana_kunjungan_id ?? request('rencana_kunjungan_id'));
    $selectedVisit = $kunjungans->firstWhere('id', $selectedVisitId);
    $backUrl = $pasien->exists ? route('pasien.show', $pasien) : ($selectedVisit ? route('kunjungan-mcu.show', $selectedVisit) : route('pasien.index'));
@endphp
<div class="mx-auto grid max-w-7xl gap-6">
    <a href="{{ $backUrl }}" class="justify-self-start text-sm font-semibold text-slate-500">← {{ $pasien->exists ? 'Detail pasien' : ($selectedVisit ? 'Peserta kunjungan' : 'Daftar pasien') }}</a>
    <section class="flex flex-wrap items-start justify-between gap-5 rounded-2xl border border-slate-200 bg-white p-6 sm:p-8">
        <div><p class="text-xs font-bold uppercase tracking-wider text-emerald-700">Registrasi peserta MCU</p><h2 class="mt-2 text-2xl font-bold sm:text-3xl">{{ $pasien->exists ? 'Ubah data pasien' : 'Daftarkan peserta MCU' }}</h2><p class="mt-3 text-sm text-slate-500">Lengkapi identitas, informasi pekerjaan, dan paket pemeriksaan. Kolom bertanda * wajib diisi.</p></div>
        <span class="rounded-full bg-blue-50 px-4 py-2 text-xs font-bold text-blue-800">{{ $pasien->exists ? 'Pembaruan data' : 'Pendaftaran baru' }}</span>
    </section>

    <form action="{{ $pasien->exists ? route('pasien.update', $pasien) : route('pasien.store') }}" method="POST" class="grid gap-6">
        @csrf
        @if($pasien->exists) @method('PUT') @endif
        @if(!$pasien->exists && $selectedPatient)
            <input type="hidden" name="pasien_id" value="{{ $selectedPatient->id }}">
            <p class="rounded-xl border border-blue-100 bg-blue-50 p-4 text-sm text-blue-900">Identitas dipilih: <strong>{{ $selectedPatient->nama }}</strong>. Data identitas menggunakan master pasien. <a href="{{ route('pasien.create', ['rencana_kunjungan_id' => $selectedVisitId]) }}" class="font-semibold underline">Buat pasien baru</a></p>
        @endif
        <div class="grid items-start gap-6 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
            <div class="grid min-w-0 gap-6">
                <section class="grid gap-5 rounded-2xl border border-slate-200 bg-white p-6 sm:p-8">
                    <div class="flex items-start gap-4"><span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-sm font-bold text-emerald-700">01</span><div><h3 class="text-xl font-bold">Identitas pasien</h3><p class="mt-2 text-sm text-slate-500">Masukkan data sesuai identitas resmi pasien.</p></div></div>
                    <div class="grid gap-5 sm:grid-cols-2">
                        <label for="patient-name" class="grid gap-2 text-sm font-semibold sm:col-span-2">Nama lengkap *<input id="patient-name" name="nama_karyawan" required maxlength="255" autocomplete="name" value="{{ old('nama_karyawan', $pasien->nama_karyawan) }}" placeholder="Nama lengkap sesuai identitas" class="min-h-12 min-w-0 rounded-xl border border-slate-300 px-3 focus:border-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-100">@error('nama_karyawan')<span class="text-xs font-normal text-red-700">{{ $message }}</span>@enderror</label>
                        <label for="patient-nik" class="grid gap-2 text-sm font-semibold">NIK identitas (KTP)<input id="patient-nik" name="nik" inputmode="numeric" maxlength="16" value="{{ old('nik', $pasien->nik) }}" placeholder="16 digit NIK identitas" class="min-h-12 min-w-0 rounded-xl border border-slate-300 px-3 focus:border-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-100"><span class="text-xs font-normal text-slate-500">Bukan nomor karyawan.</span>@error('nik')<span class="text-xs font-normal text-red-700">{{ $message }}</span>@enderror</label>
                        <label for="patient-birth-place" class="grid gap-2 text-sm font-semibold">Tempat lahir<input id="patient-birth-place" name="tempat_lahir" maxlength="255" value="{{ old('tempat_lahir', $pasien->tempat_lahir) }}" placeholder="Contoh: Bandung" class="min-h-12 min-w-0 rounded-xl border border-slate-300 px-3 focus:border-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-100">@error('tempat_lahir')<span class="text-xs font-normal text-red-700">{{ $message }}</span>@enderror</label>
                        <label for="patient-birth" class="grid gap-2 text-sm font-semibold">Tanggal lahir<input id="patient-birth" type="date" name="tanggal_lahir" autocomplete="bday" value="{{ old('tanggal_lahir', $pasien->tanggal_lahir?->format('Y-m-d')) }}" class="min-h-12 min-w-0 rounded-xl border border-slate-300 px-3 focus:border-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-100">@error('tanggal_lahir')<span class="text-xs font-normal text-red-700">{{ $message }}</span>@enderror</label>
                        <label for="patient-gender" class="grid gap-2 text-sm font-semibold">Jenis kelamin *<select id="patient-gender" name="jenis_kelamin" required class="min-h-12 min-w-0 rounded-xl border border-slate-300 bg-white px-3 focus:border-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-100"><option value="">Pilih jenis kelamin</option><option value="L" @selected(old('jenis_kelamin', $pasien->jenis_kelamin) === 'L')>Laki-laki</option><option value="P" @selected(old('jenis_kelamin', $pasien->jenis_kelamin) === 'P')>Perempuan</option></select>@error('jenis_kelamin')<span class="text-xs font-normal text-red-700">{{ $message }}</span>@enderror</label>
                        <label for="patient-phone" class="grid gap-2 text-sm font-semibold">Nomor telepon<input id="patient-phone" name="telepon" type="tel" maxlength="255" autocomplete="tel" value="{{ old('telepon', $pasien->telepon) }}" placeholder="Contoh: 081234567890" class="min-h-12 min-w-0 rounded-xl border border-slate-300 px-3 focus:border-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-100">@error('telepon')<span class="text-xs font-normal text-red-700">{{ $message }}</span>@enderror</label>
                    </div>
                </section>
                <section class="grid gap-5 rounded-2xl border border-slate-200 bg-white p-6 sm:p-8">
                    <div class="flex items-start gap-4"><span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-sm font-bold text-emerald-700">02</span><div><h3 class="text-xl font-bold">Informasi pekerjaan</h3><p class="mt-2 text-sm text-slate-500">Penempatan karyawan saat mengikuti kunjungan ini.</p></div></div>
                    <div class="grid gap-5 sm:grid-cols-2">
                        @foreach(['nip' => ['Nomor karyawan', 'Contoh: EMP-001'], 'bagian' => ['Departemen', 'Contoh: Operasional'], 'jabatan' => ['Jabatan', 'Contoh: Supervisor']] as $field => [$label, $placeholder])
                            <label for="patient-{{ $field }}" class="grid gap-2 text-sm font-semibold">{{ $label }}<input id="patient-{{ $field }}" name="{{ $field }}" maxlength="{{ $field === 'nip' ? 50 : 255 }}" value="{{ old($field, $pasien->$field) }}" placeholder="{{ $placeholder }}" class="min-h-12 min-w-0 rounded-xl border border-slate-300 px-3 focus:border-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-100">@error($field)<span class="text-xs font-normal text-red-700">{{ $message }}</span>@enderror</label>
                        @endforeach
                    </div>
                    <p class="text-xs leading-relaxed text-slate-500">Nomor karyawan dan departemen dapat dilengkapi kemudian, tetapi wajib tersedia sebelum hasil MCU difinalisasi.</p>
                </section>
            </div>

            <aside class="grid min-w-0 gap-6" aria-label="Penempatan dan panduan registrasi MCU">
                <section class="grid gap-5 rounded-2xl border border-slate-200 bg-white p-6">
                    <div class="flex items-start gap-4"><span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-sm font-bold text-emerald-700">03</span><div><h3 class="text-xl font-bold">Penempatan MCU</h3><p class="mt-2 text-sm text-slate-500">Pilih kunjungan dan paket yang sesuai.</p></div></div>
                    <label for="patient-visit" class="grid gap-2 text-sm font-semibold">Kunjungan MCU *<select id="patient-visit" name="rencana_kunjungan_id" required class="min-h-12 min-w-0 rounded-xl border border-slate-300 bg-white px-3 focus:border-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-100"><option value="">Pilih kunjungan</option>@foreach($kunjungans as $kunjungan)<option value="{{ $kunjungan->id }}" @selected($selectedVisitId === $kunjungan->id)>{{ $kunjungan->nomor_kunjungan }} — {{ $kunjungan->perusahaan->nama_perusahaan }}</option>@endforeach</select>@error('rencana_kunjungan_id')<span class="text-xs font-normal text-red-700">{{ $message }}</span>@enderror</label>
                    <label for="patient-package" class="grid gap-2 text-sm font-semibold">Paket pemeriksaan *<select id="patient-package" name="paket_id" required class="min-h-12 min-w-0 rounded-xl border border-slate-300 bg-white px-3 focus:border-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-100"><option value="">Pilih paket</option>@foreach($pakets as $paket)<option value="{{ $paket->id }}" @selected(old('paket_id', $pasien->paket_id) === $paket->id)>{{ $paket->nama_paket }}</option>@endforeach</select><span class="text-xs font-normal text-slate-500">Semua paket master dapat dipakai ulang. Paket pilihan otomatis ditambahkan ke kunjungan saat disimpan.</span>@error('paket_id')<span class="text-xs font-normal text-red-700">{{ $message }}</span>@enderror</label>
                    <label for="patient-date" class="grid gap-2 text-sm font-semibold">Tanggal MCU<input id="patient-date" name="tanggal_mcu" type="date" value="{{ old('tanggal_mcu', $pasien->tanggal_mcu?->format('Y-m-d')) }}" class="min-h-12 min-w-0 rounded-xl border border-slate-300 px-3 focus:border-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-100">@error('tanggal_mcu')<span class="text-xs font-normal text-red-700">{{ $message }}</span>@enderror</label>
                    <div class="rounded-xl bg-slate-50 p-4"><p class="text-xs text-slate-500">Nomor MCU</p><p class="mt-2 break-all text-sm font-semibold">{{ $pasien->nomor_mcu ?? 'Dibuat otomatis saat pendaftaran' }}</p></div>
                </section>
                <section class="grid gap-3 rounded-2xl border border-slate-200 bg-white p-6"><h3 class="font-bold">Setelah pendaftaran</h3><p class="text-sm leading-relaxed text-slate-500">Peserta akan muncul pada kunjungan yang dipilih. Buka pemeriksaan untuk mulai mencatat anamnesis, fisik, laboratorium, dan penunjang sesuai paket.</p><p class="text-xs text-slate-500">Data pasien dan hasil MCU sebelumnya tetap tersimpan.</p></section>
            </aside>
        </div>
        <div class="sticky bottom-3 z-10 flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-lg">
            <p class="text-sm text-slate-500">Pastikan identitas dan paket sesuai sebelum menyimpan.</p>
            <div class="flex flex-wrap gap-3"><a href="{{ $backUrl }}" class="rounded-xl border border-slate-300 px-5 py-3 text-sm font-semibold">Batal</a><button class="rounded-xl bg-emerald-700 px-5 py-3 text-sm font-bold text-white hover:bg-emerald-800">{{ $pasien->exists ? 'Simpan perubahan' : 'Simpan pendaftaran' }}</button></div>
        </div>
    </form>
</div>
@endsection
