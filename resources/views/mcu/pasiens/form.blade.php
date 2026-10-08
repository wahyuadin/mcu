@extends('layouts.mcu')
@section('title', $pasien->exists ? 'Ubah Pasien' : 'Tambah Pasien')
@section('content')
<form action="{{ $pasien->exists ? route('pasien.update', $pasien) : route('pasien.store') }}" method="POST" class="mx-auto max-w-5xl space-y-6">
    @csrf
    @if($pasien->exists) @method('PUT') @endif
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-center gap-4"><a href="{{ $pasien->exists ? route('pasien.show', $pasien) : route('pasien.index') }}" class="flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-500 shadow-sm hover:border-[#00008B] hover:text-[#00008B]"><svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg></a><div><p class="text-xs font-bold uppercase tracking-wider text-[#00008B]">Registrasi MCU</p><h2 class="mt-1 text-2xl font-bold text-slate-900">{{ $pasien->exists ? 'Ubah data pasien' : 'Daftarkan pasien baru' }}</h2></div></div>
        <button class="rounded-xl bg-[#00008B] px-6 py-3 text-sm font-bold text-white shadow-lg shadow-blue-900/15 active:scale-[.97]">Simpan Data</button>
    </div>

    <section class="overflow-hidden rounded-2xl border border-slate-200/70 bg-white shadow-sm">
        <div class="flex items-center gap-4 border-b border-slate-100 px-6 py-5"><span class="flex h-10 w-10 items-center justify-center rounded-xl bg-blue-50 font-bold text-[#00008B]">01</span><div><h3 class="font-bold text-slate-900">Penempatan MCU</h3><p class="text-sm text-slate-400">Pilih kunjungan dan paket yang sesuai.</p></div></div>
        <div class="grid gap-5 p-6 sm:grid-cols-2">
            <label class="grid gap-2 text-sm font-semibold text-slate-700">Rencana kunjungan<select name="rencana_kunjungan_id" required class="h-12 rounded-xl border border-slate-200 bg-white px-3 outline-none focus:border-[#00008B] focus:ring-4 focus:ring-blue-100"><option value="">Pilih kunjungan</option>@foreach($kunjungans as $kunjungan)<option value="{{ $kunjungan->id }}" @selected(old('rencana_kunjungan_id', $pasien->rencana_kunjungan_id ?? request('rencana_kunjungan_id')) === $kunjungan->id)>{{ $kunjungan->nomor_kunjungan }} — {{ $kunjungan->perusahaan->nama_perusahaan }}</option>@endforeach</select></label>
            <label class="grid gap-2 text-sm font-semibold text-slate-700">Paket pemeriksaan<select name="paket_id" required class="h-12 rounded-xl border border-slate-200 bg-white px-3 outline-none focus:border-[#00008B] focus:ring-4 focus:ring-blue-100"><option value="">Pilih paket</option>@foreach($pakets as $paket)<option value="{{ $paket->id }}" data-kunjungan="{{ $paket->rencana_kunjungan_id }}" @selected(old('paket_id', $pasien->paket_id) === $paket->id)>{{ $paket->nama_paket }}</option>@endforeach</select><span class="text-xs font-normal text-slate-400">Paket otomatis disaring berdasarkan kunjungan.</span></label>
        </div>
    </section>

    <section class="overflow-hidden rounded-2xl border border-slate-200/70 bg-white shadow-sm">
        <div class="flex items-center gap-4 border-b border-slate-100 px-6 py-5"><span class="flex h-10 w-10 items-center justify-center rounded-xl bg-orange-50 font-bold text-[#FF8C00]">02</span><div><h3 class="font-bold text-slate-900">Identitas pasien</h3><p class="text-sm text-slate-400">Masukkan data sesuai identitas resmi pasien.</p></div></div>
        <div class="grid gap-x-5 gap-y-5 p-6 sm:grid-cols-2">
            <label class="grid gap-2 text-sm font-semibold text-slate-700 sm:col-span-2">Nama lengkap <span class="sr-only">wajib</span><input name="nama_karyawan" required value="{{ old('nama_karyawan', $pasien->nama_karyawan) }}" placeholder="Nama lengkap pasien" class="h-12 rounded-xl border border-slate-200 px-4 outline-none focus:border-[#00008B] focus:ring-4 focus:ring-blue-100"></label>
            <label class="grid gap-2 text-sm font-semibold text-slate-700">NIK<input name="nik" inputmode="numeric" maxlength="16" value="{{ old('nik', $pasien->nik) }}" placeholder="16 digit NIK" class="h-12 rounded-xl border border-slate-200 px-4 outline-none focus:border-[#00008B] focus:ring-4 focus:ring-blue-100"></label>
            <label class="grid gap-2 text-sm font-semibold text-slate-700">Tanggal lahir<input type="date" name="tanggal_lahir" value="{{ old('tanggal_lahir', $pasien->tanggal_lahir?->format('Y-m-d')) }}" class="h-12 rounded-xl border border-slate-200 px-4 outline-none focus:border-[#00008B] focus:ring-4 focus:ring-blue-100"></label>
            <label class="grid gap-2 text-sm font-semibold text-slate-700">Jenis kelamin<select name="jenis_kelamin" class="h-12 rounded-xl border border-slate-200 bg-white px-3 outline-none focus:border-[#00008B] focus:ring-4 focus:ring-blue-100"><option value="L" @selected(old('jenis_kelamin', $pasien->jenis_kelamin) === 'L')>Laki-laki</option><option value="P" @selected(old('jenis_kelamin', $pasien->jenis_kelamin) === 'P')>Perempuan</option></select></label>
            <label class="grid gap-2 text-sm font-semibold text-slate-700">Nomor telepon<input name="telepon" value="{{ old('telepon', $pasien->telepon) }}" placeholder="08xxxxxxxxxx" class="h-12 rounded-xl border border-slate-200 px-4 outline-none focus:border-[#00008B] focus:ring-4 focus:ring-blue-100"></label>
        </div>
    </section>

    <section class="overflow-hidden rounded-2xl border border-slate-200/70 bg-white shadow-sm">
        <div class="flex items-center gap-4 border-b border-slate-100 px-6 py-5"><span class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-50 font-bold text-emerald-700">03</span><div><h3 class="font-bold text-slate-900">Informasi pekerjaan</h3><p class="text-sm text-slate-400">Data kepegawaian saat mengikuti MCU.</p></div></div>
        <div class="grid gap-5 p-6 sm:grid-cols-3">
            @foreach(['nip' => ['Nomor karyawan', 'Contoh: EMP-001'], 'bagian' => ['Departemen', 'Contoh: Operasional'], 'jabatan' => ['Jabatan', 'Contoh: Supervisor']] as $field => [$label, $placeholder])
                <label class="grid gap-2 text-sm font-semibold text-slate-700">{{ $label }}<input name="{{ $field }}" value="{{ old($field, $pasien->$field) }}" placeholder="{{ $placeholder }}" class="h-12 rounded-xl border border-slate-200 px-4 outline-none focus:border-[#00008B] focus:ring-4 focus:ring-blue-100"></label>
            @endforeach
        </div>
    </section>
</form>
<script>const kunjungan=document.querySelector('[name="rencana_kunjungan_id"]');const paket=document.querySelector('[name="paket_id"]');const filterPaket=()=>{for(const option of paket.options){if(option.value)option.hidden=option.dataset.kunjungan!==kunjungan.value}if(paket.selectedOptions[0]?.hidden)paket.value=''};kunjungan.addEventListener('change',filterPaket);filterPaket();</script>
@endsection
