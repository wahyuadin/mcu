@extends('layouts.mcu')
@section('title', 'Daftar pasien')
@section('content')
<div data-patient-workspace class="mx-auto grid max-w-7xl gap-6">
    <div class="flex flex-wrap items-center justify-between gap-4"><div><h2 class="text-2xl font-bold">Identitas pasien</h2><p class="mt-2 text-sm text-slate-500">Satu pasien dapat mengikuti beberapa episode MCU. Pilih kunjungan untuk mengelola hasil per peserta.</p></div>@can('manage-mcu')<div class="flex flex-wrap gap-3"><button type="button" data-patient-import-open aria-haspopup="dialog" aria-controls="patient-import-modal" class="inline-flex min-h-12 items-center gap-2 rounded-xl border border-blue-200 bg-white px-5 py-3 text-sm font-semibold text-[#00008B]"><svg aria-hidden="true" class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M12 16V4m-4 4 4-4 4 4M4 16v4h16v-4"/></svg>Import pasien</button><a href="{{ route('pasien.create') }}" class="rounded-xl bg-[#00008B] px-5 py-3 text-sm font-semibold text-white">+ Daftarkan peserta MCU</a></div>@endcan</div>
    <div data-patient-stats class="grid grid-cols-3 gap-3 sm:gap-4">@foreach(['total' => 'Pasien terdaftar', 'process' => 'Episode dalam pengisian', 'completed' => 'Episode final'] as $key => $label)<div class="grid min-w-0 content-between gap-3 rounded-2xl border border-slate-200 bg-white p-3 sm:flex sm:items-center sm:justify-between sm:px-5 sm:py-4"><p class="text-xs font-medium leading-5 text-slate-500">{{ $label }}</p><strong class="text-2xl text-slate-900">{{ $stats[$key] }}</strong></div>@endforeach</div>
    <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <form data-patient-filter method="GET" action="{{ route('pasien.index') }}" class="grid gap-4 border-b border-slate-100 p-5 sm:p-6">
            @if(request('kunjungan'))<input type="hidden" name="kunjungan" value="{{ request('kunjungan') }}">@endif
            <label class="grid gap-2 text-xs font-semibold text-slate-600">Cari pasien<input name="q" type="search" autocomplete="off" value="{{ request('q') }}" placeholder="Cari nama, NIK, NPK, atau nomor MCU…" class="min-h-12 min-w-0 rounded-xl border border-slate-200 bg-slate-50 px-4 text-base outline-none focus:border-blue-800 focus:bg-white focus:ring-2 focus:ring-blue-100"></label>
            <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
                <label class="grid min-w-0 gap-2 text-xs font-semibold text-slate-600">Perusahaan<select name="perusahaan" class="min-h-11 min-w-0 rounded-xl border border-slate-200 bg-white px-3 text-sm font-normal"><option value="">Semua perusahaan</option>@foreach($perusahaans as $company)<option value="{{ $company->id }}" @selected(request('perusahaan') === $company->id)>{{ $company->nama_perusahaan }}</option>@endforeach</select></label>
                <label class="grid min-w-0 gap-2 text-xs font-semibold text-slate-600">Status episode<select name="status" class="min-h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm font-normal"><option value="">Semua status</option>@foreach(['belum' => 'Terdaftar', 'proses' => 'Dalam pengisian', 'siap_finalisasi' => 'Siap finalisasi', 'selesai' => 'Final', 'batal' => 'Batal', 'tidak_hadir' => 'Tidak hadir'] as $key => $label)<option value="{{ $key }}" @selected(request('status') === $key)>{{ $label }}</option>@endforeach</select></label>
                <label class="grid min-w-0 gap-2 text-xs font-semibold text-slate-600">Urut berdasarkan<select name="sort" class="min-h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm font-normal">@foreach(['created_at' => 'Tanggal pendaftaran', 'nama' => 'Nama pasien', 'tanggal_lahir' => 'Tanggal lahir'] as $key => $label)<option value="{{ $key }}" @selected($sort === $key)>{{ $label }}</option>@endforeach</select></label>
                <label class="grid min-w-0 gap-2 text-xs font-semibold text-slate-600">Arah urutan<select name="direction" class="min-h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm font-normal"><option value="desc" @selected($direction === 'desc')>Menurun · Z–A / terbaru</option><option value="asc" @selected($direction === 'asc')>Menaik · A–Z / terlama</option></select></label>
            </div>
        </form>
        @include('mcu.pasiens.results')
        @include('mcu.loading-skeleton')
    </section>
    <p data-patient-workspace-status role="status" tabindex="-1" class="text-sm text-slate-600"></p>
</div>
<dialog id="patient-workspace-modal" data-patient-modal aria-labelledby="patient-modal-title" class="mcu-modal m-auto max-h-[92dvh] w-[calc(100%-1rem)] max-w-6xl overflow-y-auto rounded-2xl border-0 bg-slate-50 p-0 text-slate-900 shadow-2xl sm:w-[calc(100%-3rem)]">
    <div class="sticky top-0 z-20 flex items-center justify-between gap-4 border-b border-slate-200 bg-white px-5 py-4 sm:px-7"><h2 id="patient-modal-title" data-patient-modal-title class="text-lg font-bold">Detail pasien</h2><button type="button" data-patient-modal-close autofocus aria-label="Tutup modal pasien" class="flex h-11 w-11 items-center justify-center rounded-xl border border-slate-200 text-xl text-slate-500">×</button></div>
    <div class="p-4 sm:p-7">@include('mcu.loading-skeleton')<p data-patient-modal-error role="alert" tabindex="-1" hidden class="mb-4 rounded-xl bg-red-50 p-4 text-sm text-red-700"></p><button data-patient-modal-retry type="button" hidden class="mb-4 min-h-11 rounded-xl border border-slate-300 bg-white px-4 text-sm font-semibold">Coba lagi</button><div data-patient-modal-body></div></div>
</dialog>
@can('manage-mcu') @include('mcu.pasiens.import-modal') @endcan
@can('manage-mcu')
<dialog id="patient-delete-modal" data-patient-delete-modal data-realtime="true" aria-labelledby="patient-delete-title" aria-describedby="patient-delete-description" class="mcu-modal m-auto w-[calc(100%-2rem)] max-w-md rounded-2xl border-0 bg-white p-6 shadow-xl">
    <div aria-hidden="true" class="mb-5 flex h-12 w-12 items-center justify-center rounded-2xl bg-red-50 text-xl font-bold text-red-700">!</div>
    <h2 id="patient-delete-title" class="text-xl font-bold text-slate-900">Hapus pasien?</h2>
    <p id="patient-delete-description" class="mt-3 text-sm leading-6 text-slate-600">Anda akan menghapus <strong data-patient-delete-name class="break-words text-slate-900"></strong> dari daftar pasien. Pastikan data ini tidak diperlukan lagi. Pasien dengan riwayat kunjungan tidak dapat dihapus.</p>
    <p data-patient-delete-error role="alert" tabindex="-1" class="mt-3 text-sm text-red-700"></p>
    <form data-patient-delete-form method="POST" class="mt-6 flex justify-end gap-3">@csrf @method('DELETE')
        <button type="button" data-patient-delete-cancel autofocus class="min-h-12 rounded-xl border border-slate-300 px-5 font-semibold">Batal</button>
        <button data-patient-delete-confirm class="min-h-12 rounded-xl bg-red-700 px-5 font-bold text-white disabled:opacity-50">Hapus pasien</button>
    </form>
</dialog>
@endcan
@endsection
