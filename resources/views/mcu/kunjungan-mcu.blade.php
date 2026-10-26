@extends('layouts.mcu')
@section('title', 'Kunjungan MCU')
@section('content')
    <div class="space-y-6">
        <section
            class="relative overflow-hidden rounded-3xl bg-[#00008B] px-6 py-7 text-white shadow-xl shadow-blue-950/10 sm:px-8">
            <div class="absolute -right-16 -top-20 h-64 w-64 rounded-full bg-white/10"></div>
            <div class="relative flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.2em] text-blue-200">Operasional lapangan</p>
                    <h2 class="mt-2 text-2xl font-bold sm:text-3xl">Kunjungan MCU</h2>
                    <p class="mt-2 max-w-xl text-sm leading-6 text-blue-100">Pantau pelaksanaan kunjungan, jumlah peserta,
                        dan progres pemeriksaan secara langsung.</p>
                </div>
                @can('manage-mcu')
                    <a href="{{ route('rencana-kunjungan.index') }}"
                        class="inline-flex items-center justify-center gap-2 rounded-xl bg-white px-5 py-3 text-sm font-bold text-[#00008B] shadow-lg shadow-blue-950/20 active:scale-[.97]"><svg
                            class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>Buat Rencana</a>
                @endcan
            </div>
        </section>

        <section class="grid gap-4 sm:grid-cols-3">
            @foreach ([['Kunjungan aktif', $stats['active'], 'bg-blue-50 text-[#00008B]'], ['Total peserta', $stats['participants'], 'bg-orange-50 text-[#FF8C00]'], ['Pemeriksaan selesai', $stats['completed'], 'bg-emerald-50 text-emerald-700']] as [$label, $value, $tone])
                <article class="flex items-center gap-4 rounded-2xl border border-slate-200/70 bg-white p-5 shadow-sm"><span
                        class="flex h-11 w-11 items-center justify-center rounded-xl {{ $tone }}"><svg
                            class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M8 7V3m8 4V3M5 11h14M5 5h14a2 2 0 012 2v12a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2z" />
                        </svg></span>
                    <div>
                        <p class="text-xs font-bold uppercase tracking-wider text-slate-400">{{ $label }}</p>
                        <p class="mt-1 text-2xl font-extrabold text-slate-900">{{ number_format($value) }}</p>
                    </div>
                </article>
            @endforeach
        </section>

        <section class="rounded-2xl border border-slate-200/70 bg-white p-5 shadow-sm">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h3 class="font-bold text-slate-900">Pelaksanaan kunjungan</h3>
                    <p class="mt-1 text-sm text-slate-400">{{ $kunjungans->total() }} kunjungan tercatat</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    @foreach (['' => 'Semua', 'draft' => 'Draft', 'ongoing' => 'Berjalan', 'completed' => 'Selesai'] as $key => $label)
                        <a href="{{ route('kunjungan-mcu.index', array_filter(['status' => $key])) }}"
                            class="rounded-lg px-3 py-2 text-xs font-bold {{ request('status', '') === $key ? 'bg-[#00008B] text-white' : 'bg-slate-100 text-slate-500 hover:text-[#00008B]' }}">{{ $label }}</a>
                    @endforeach
                </div>
            </div>
            <div class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                @forelse($kunjungans as $kunjungan)
                    @php
                        $progress =
                            $kunjungan->karyawans_count > 0
                                ? min(100, round(($kunjungan->finalized_count / $kunjungan->karyawans_count) * 100))
                                : 0;
                        $statusStyle = match ($kunjungan->status) {
                            'completed' => 'bg-emerald-50 text-emerald-700',
                            'draft' => 'bg-slate-100 text-slate-600',
                            default => 'bg-orange-50 text-orange-700',
                        };
                    @endphp
                    <article
                        class="rounded-2xl border border-slate-200 p-5 transition-colors hover:border-blue-200 hover:bg-blue-50/20">
                        <div class="flex items-start justify-between gap-3"><span
                                class="rounded-lg bg-blue-50 px-2.5 py-1.5 text-xs font-extrabold text-[#00008B]">{{ $kunjungan->nomor_kunjungan }}</span><span
                                class="rounded-full px-2.5 py-1 text-[10px] font-extrabold uppercase tracking-wider {{ $statusStyle }}">{{ $kunjungan->status === 'ongoing' ? 'Berjalan' : $kunjungan->status }}</span>
                        </div>
                        <h4 class="mt-4 font-bold text-slate-900">{{ $kunjungan->perusahaan->nama_perusahaan }}</h4>
                        <p class="mt-1 text-sm text-slate-400">{{ $kunjungan->periode_kunjungan }}</p>
                        <div class="mt-5 grid grid-cols-2 gap-3">
                            <div class="rounded-xl bg-slate-50 p-3">
                                <p class="text-[10px] font-bold uppercase text-slate-400">Peserta</p>
                                <p class="mt-1 font-extrabold text-slate-800">{{ $kunjungan->karyawans_count }}@if ($kunjungan->kuota_peserta > 0)
                                        <span
                                            class="text-xs font-medium text-slate-400">/{{ $kunjungan->kuota_peserta }}</span>
                                    @endif
                                </p>
                            </div>
                            <div class="rounded-xl bg-slate-50 p-3">
                                <p class="text-[10px] font-bold uppercase text-slate-400">Paket</p>
                                <p class="mt-1 font-extrabold text-slate-800">{{ $kunjungan->pakets_count }}</p>
                            </div>
                        </div>
                        <div class="mt-4">
                            <div class="flex justify-between text-xs"><span class="font-semibold text-slate-500">Progres
                                    pemeriksaan</span><strong class="text-[#00008B]">{{ $progress }}%</strong></div>
                            <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-100">
                                <div class="h-full rounded-full bg-[#00008B]" style="width: {{ $progress }}%"></div>
                            </div>
                        </div>
                        <div class="mt-5 flex flex-wrap items-stretch gap-2">@can('view-visits')<a data-visit-action
                                href="{{ route('kunjungan-mcu.show', $kunjungan) }}"
                                class="inline-flex min-h-11 min-w-0 flex-1 cursor-pointer items-center justify-center rounded-xl bg-slate-900 px-4 py-2.5 text-center text-xs font-bold text-white hover:bg-[#00008B]">Buka
                                peserta & hasil →</a>@endcan
                            @can('examine-mcu')
                                <form data-barcode-form method="POST" action="{{ route('anamnesa.barcode', $kunjungan) }}"
                                    class="flex shrink-0">@csrf<button type="submit" data-visit-action aria-haspopup="dialog"
                                        @disabled($kunjungan->is_closing || $kunjungan->status === 'completed')
                                        class="inline-flex min-h-11 cursor-pointer items-center justify-center gap-2 rounded-xl border border-blue-200 bg-blue-50 px-4 py-2.5 text-center text-xs font-bold text-blue-900 disabled:cursor-not-allowed disabled:opacity-40"><span
                                            aria-hidden="true">▦</span>QR Code</button></form>
                            @endcan
                        </div>
                    </article>
                    @empty
                        <div class="col-span-full py-14 text-center">
                            <p class="font-bold text-slate-700">Belum ada kunjungan</p>
                            <p class="mt-1 text-sm text-slate-400">Buat rencana kunjungan untuk memulai.</p>
                        </div>
                    @endforelse
                </div>
                @if ($kunjungans->hasPages())
                    <div class="mt-5 border-t border-slate-100 pt-5">{{ $kunjungans->links() }}</div>
                @endif
            </section>
        </div>
        <dialog data-barcode-dialog aria-labelledby="barcode-title"
            class="m-auto max-h-[90dvh] w-[calc(100%-2rem)] max-w-xl rounded-2xl border-0 bg-white p-0 shadow-xl">
            <div class="flex items-center justify-between border-b border-slate-200 p-4">
                <h2 id="barcode-title" class="font-bold">QR Code Anamnesa</h2><button type="button" data-barcode-close
                    class="min-h-11 px-4" autofocus>Tutup</button>
            </div><iframe name="anamnesa-barcode-frame" title="QR Code formulir anamnesa"
                class="h-[70dvh] w-full border-0"></iframe>
        </dialog>
    @endsection
