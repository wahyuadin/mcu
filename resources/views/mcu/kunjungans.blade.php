@extends('layouts.mcu')
@section('title', 'Rencana Kunjungan')
@section('content')
    @php($statusLabels = ['draft' => 'Draft', 'ongoing' => 'Berlangsung', 'completed' => 'Selesai', 'closed' => 'Ditutup'])
    <div data-planning-workspace class="mx-auto grid max-w-7xl gap-6">
        <section
            class="flex flex-wrap items-start justify-between gap-5 rounded-2xl border border-slate-200 bg-white p-6 sm:p-8">
            <div class="min-w-0">
                <p class="text-xs font-bold uppercase tracking-wider text-emerald-700">Registrasi PKS & perencanaan</p>
                <h2 class="mt-2 text-2xl font-bold sm:text-3xl">Rencana Kunjungan</h2>
                <p class="mt-3 max-w-2xl text-sm leading-relaxed text-slate-500">Daftarkan perusahaan, jadwal, dan periode
                    saat ada PKS baru. Konfigurasi paket melalui detail rencana.</p>
            </div>
            <a href="#registrasi-rencana"
                class="rounded-xl bg-emerald-700 px-5 py-3 text-sm font-bold text-white hover:bg-emerald-800">+ Buat rencana
                kunjungan</a>
        </section>
        <div data-planning-stats class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-5">
            @foreach (['total' => 'Total rencana'] + $statusLabels as $key => $label)
                <a href="{{ $key === 'total' ? route('rencana-kunjungan.index') : route('rencana-kunjungan.index', ['status' => $key]) }}"
                    class="rounded-2xl border p-5 {{ request('status', 'total') === $key ? 'border-emerald-700 bg-emerald-50' : 'border-slate-200 bg-white hover:border-emerald-700' }}">
                    <p class="text-xs font-semibold text-slate-500">{{ $label }}</p><strong
                        class="mt-2 block text-2xl">{{ $stats[$key] }}</strong>
                </a>
            @endforeach
        </div>

        <div class="grid items-start gap-6 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
            <section class="grid min-w-0 gap-5 rounded-2xl border border-slate-200 bg-white p-6">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h3 class="text-xl font-bold">Daftar rencana kunjungan</h3><span
                        data-planning-count class="text-sm text-slate-500">{{ $kunjungans->total() }} rencana sesuai filter</span>
                </div>
                <form data-planning-filter method="GET" action="{{ route('rencana-kunjungan.index') }}" class="grid gap-3 sm:grid-cols-2">
                    <input name="q" value="{{ request('q') }}" placeholder="Cari perusahaan, nomor, atau periode"
                        aria-label="Cari rencana kunjungan"
                        class="min-h-12 min-w-0 rounded-xl border border-slate-300 px-3 sm:col-span-2">
                    <select name="perusahaan" aria-label="Filter perusahaan"
                        class="min-h-12 min-w-0 rounded-xl border border-slate-300 px-3">
                        <option value="">Semua perusahaan</option>
                        @foreach ($perusahaans as $company)
                            <option value="{{ $company->id }}" @selected(request('perusahaan') === $company->id)>{{ $company->nama_perusahaan }}
                            </option>
                        @endforeach
                    </select>
                    <div class="flex min-w-0 gap-3"><select name="status" aria-label="Filter status rencana"
                            class="min-h-12 min-w-0 flex-1 rounded-xl border border-slate-300 px-3">
                            <option value="">Semua status</option>
                            @foreach ($statusLabels as $key => $label)
                                <option value="{{ $key }}" @selected(request('status') === $key)>{{ $label }}
                                </option>
                            @endforeach
                        </select>
                        <button class="rounded-xl border border-slate-300 px-4 py-3 text-sm font-semibold">Cari</button>
                    </div>
                </form>
                <div data-planning-results>
                @if (request('q') || request('status') || request('perusahaan'))
                    <a href="{{ route('rencana-kunjungan.index') }}"
                        class="justify-self-start text-xs font-semibold text-emerald-700">Hapus filter →</a>
                @endif
                <div class="divide-y divide-slate-200">
                    @forelse($kunjungans as $kunjungan)
                        @php($statusKey = $kunjungan->is_closing ? 'closed' : $kunjungan->status)
                        <article class="grid gap-4 py-5">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="flex max-w-full min-w-0 items-center gap-3">
                                    @if($kunjungan->perusahaan->logo_path)
                                        <img src="{{ route('perusahaan.logo', $kunjungan->perusahaan) }}" alt="Logo {{ $kunjungan->perusahaan->nama_perusahaan }}" loading="lazy" class="h-12 w-12 shrink-0 rounded-xl border border-slate-100 bg-white object-contain p-2">
                                    @else
                                        @include('mcu.company-icon')
                                    @endif
                                    <div class="min-w-0">
                                    <h4 class="break-words font-bold"><button type="button" data-planning-edit data-url="{{ route('rencana-kunjungan.update', $kunjungan) }}" data-plan="{{ json_encode(['perusahaan_id' => $kunjungan->perusahaan_id, 'nomor_kunjungan' => $kunjungan->nomor_kunjungan, 'periode_kunjungan' => $kunjungan->periode_kunjungan, 'tgl_mulai' => $kunjungan->tgl_mulai?->format('Y-m-d'), 'tgl_selesai' => $kunjungan->tgl_selesai?->format('Y-m-d'), 'jumlah_peserta_direncanakan' => $kunjungan->jumlah_peserta_direncanakan]) }}" @disabled($kunjungan->is_closing) aria-haspopup="dialog" aria-controls="planning-edit-modal" class="text-left underline decoration-dotted underline-offset-4 hover:text-emerald-700 disabled:cursor-not-allowed">{{ $kunjungan->perusahaan->nama_perusahaan }}</button></h4>
                                    <p class="mt-2 break-all text-xs text-slate-500">{{ $kunjungan->nomor_kunjungan }} ·
                                        {{ $kunjungan->periode_kunjungan }}</p>
                                    </div>
                                </div><span
                                    class="rounded-full px-4 py-2 text-xs font-bold {{ match ($statusKey) {'completed' => 'bg-emerald-50 text-emerald-700','ongoing' => 'bg-blue-50 text-blue-800','closed' => 'bg-slate-100 text-slate-500',default => 'bg-amber-50 text-amber-800'} }}">{{ $statusLabels[$statusKey] ?? $statusKey }}</span>
                            </div>
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div class="grid gap-1 text-xs text-slate-500">
                                    <p>{{ $kunjungan->tgl_mulai?->format('d/m/Y') ?? 'Jadwal belum ditentukan' }} —
                                        {{ $kunjungan->tgl_selesai?->format('d/m/Y') ?? '—' }}</p>
                                    <p>{{ $kunjungan->pakets_count }} paket · {{ $kunjungan->karyawans_count }}@if($kunjungan->kuota_peserta > 0) / {{ $kunjungan->kuota_peserta }}@endif peserta terdaftar</p>
                                </div><a href="{{ route('rencana-kunjungan.show', $kunjungan) }}"
                                    class="rounded-xl border border-slate-300 px-4 py-3 text-sm font-bold hover:border-emerald-700 hover:bg-emerald-50">{{ 'Detail rencana' }} →</a>
                            </div>
                        </article>
                    @empty
                        <div class="grid gap-3 py-10 text-center">
                            <p class="font-semibold">
                                {{ $stats['total'] === 0 ? 'Belum ada rencana kunjungan' : 'Rencana tidak ditemukan' }}</p>
                            <p class="text-sm text-slate-500">
                                {{ $stats['total'] === 0 ? 'Mulai dengan mendaftarkan perusahaan dan jadwal kunjungan.' : 'Coba ubah kata pencarian atau filter.' }}
                            </p>
                        </div>
                    @endforelse
                </div>
                {{ $kunjungans->links() }}
                </div>
                @include('mcu.loading-skeleton')
                <p data-planning-status role="status" class="text-sm text-slate-600"></p>
            </section>

            <div class="grid min-w-0 gap-6">
                <section id="registrasi-rencana"
                    class="grid scroll-mt-6 gap-5 rounded-2xl border border-slate-200 bg-white p-6">
                    <div>
                        <h3 class="text-xl font-bold">Buat rencana kunjungan</h3>
                        <p class="mt-2 text-sm text-slate-500">Isi data registrasi. Kolom bertanda * wajib diisi.</p>
                    </div>
                    @if ($perusahaans->isEmpty())
                        <p class="rounded-xl bg-amber-50 p-4 text-sm text-amber-900">Belum ada perusahaan. <a
                                href="{{ route('perusahaan.index') }}" class="font-semibold underline">{{ 'Daftarkan perusahaan terlebih dahulu.' }}</a></p>
                    @endif
                    <form data-planning-dates action="{{ route('rencana-kunjungan.store') }}" method="POST" class="grid gap-4">@csrf
                        @include('mcu.planning-fields', ['editingPlan' => false])
                        <button @disabled($perusahaans->isEmpty())
                            class="rounded-xl bg-emerald-700 px-5 py-3 text-sm font-bold text-white hover:bg-emerald-800 disabled:cursor-not-allowed disabled:bg-slate-300">Simpan
                            rencana kunjungan</button>
                    </form>
                </section>
                <section class="grid gap-4 rounded-2xl border border-slate-200 bg-white p-6">
                    <h3 class="text-lg font-bold">Alur setelah registrasi</h3>
                    <ol class="grid list-inside list-decimal gap-3 text-sm text-slate-500">
                        <li>Simpan rencana kunjungan.</li>
                        <li>Daftarkan paket melalui detail rencana.</li>
                        <li>Buka Kunjungan MCU untuk peserta dan hasil.</li>
                    </ol><a href="{{ route('kunjungan-mcu.index') }}"
                        class="justify-self-start text-sm font-semibold text-emerald-700">Buka operasional Kunjungan MCU
                        →</a>
                </section>
            </div>
        </div>
    </div>
    <dialog id="planning-edit-modal" data-planning-modal aria-labelledby="planning-edit-title" class="mcu-modal m-auto max-h-[90dvh] w-[calc(100%-2rem)] max-w-xl overflow-y-auto rounded-2xl border-0 bg-white p-6 shadow-xl">
        <div class="mb-5 flex items-center justify-between gap-3"><h2 id="planning-edit-title" class="text-xl font-bold">Edit rencana kunjungan</h2><button type="button" data-planning-close autofocus class="min-h-11 rounded-xl border border-slate-300 px-4">Tutup</button></div>
        <form data-planning-edit-form data-planning-dates method="POST" class="grid gap-4">@csrf @method('PUT')
            @include('mcu.planning-fields', ['editingPlan' => true])
            <p data-planning-errors role="alert" tabindex="-1" class="text-sm text-red-700"></p>
            <div class="flex justify-end gap-3"><button type="button" data-planning-close class="min-h-12 rounded-xl border border-slate-300 px-5">Batal</button><button data-planning-save class="min-h-12 rounded-xl bg-emerald-700 px-5 font-bold text-white disabled:opacity-50">Simpan perubahan</button></div>
        </form>
    </dialog>
@endsection
