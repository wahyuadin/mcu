@extends('layouts.mcu')
@section('title', 'Kunjungan MCU — '.$kunjungan->perusahaan->nama_perusahaan)
@section('content')
@php
    $statusLabels = ['belum' => 'Belum diisi', 'proses' => 'Dalam pemeriksaan', 'siap_finalisasi' => 'Siap finalisasi', 'selesai' => 'Final', 'batal' => 'Batal', 'tidak_hadir' => 'Tidak hadir'];
    $visitUrl = route('kunjungan-mcu.show', $kunjungan);
@endphp
<div class="mx-auto grid max-w-7xl gap-6">
    <a href="{{ route('kunjungan-mcu.index') }}" class="justify-self-start text-sm font-semibold text-slate-500">← Kembali ke Kunjungan MCU</a>
    <section class="flex flex-wrap items-start justify-between gap-5 rounded-2xl border border-slate-200 bg-white p-6 sm:p-8">
        <div class="flex min-w-0 items-center gap-4">
            @if($kunjungan->perusahaan->logo_path)
                <img src="{{ route('perusahaan.logo', $kunjungan->perusahaan) }}" alt="Logo {{ $kunjungan->perusahaan->nama_perusahaan }}" class="h-16 w-16 shrink-0 rounded-2xl border border-slate-100 bg-white object-contain p-2 sm:h-20 sm:w-20">
            @else
                @include('mcu.company-icon')
            @endif
            <div class="min-w-0"><h2 class="break-words text-2xl font-bold sm:text-3xl">{{ $kunjungan->perusahaan->nama_perusahaan }}</h2><p class="mt-3 break-words text-sm text-slate-500">{{ $kunjungan->nomor_kunjungan }} · {{ $kunjungan->periode_kunjungan }}</p></div>
        </div>
        <div class="grid justify-items-start gap-3 sm:justify-items-end">
            @can('manage-mcu') @if(!$kunjungan->is_closing)<a href="{{ route('pasien.create', ['rencana_kunjungan_id' => $kunjungan->id]) }}" class="rounded-xl bg-emerald-700 px-5 py-3 text-sm font-bold text-white hover:bg-emerald-800">+ Daftarkan peserta</a>@endif @endcan
            <span class="rounded-full px-5 py-2 text-xs font-bold {{ $kunjungan->is_closing ? 'bg-slate-100 text-slate-500' : 'bg-blue-50 text-blue-800' }}">{{ $kunjungan->is_closing ? 'Ditutup' : (['draft' => 'Draft', 'ongoing' => 'Berlangsung', 'completed' => 'Selesai'][$kunjungan->status] ?? $kunjungan->status) }}</span>
        </div>
    </section>
    <section class="grid gap-3 rounded-2xl border border-slate-200 bg-white p-6">
        <div class="flex flex-wrap items-center justify-between gap-3 text-sm"><h3 class="font-bold">Progres kunjungan</h3><span class="font-semibold text-slate-500">{{ $counts['selesai'] ?? 0 }} dari {{ $totalParticipants }} hasil final · {{ $finalizedPercent }}%</span></div>
        <progress value="{{ $counts['selesai'] ?? 0 }}" max="{{ max(1, $totalParticipants) }}" aria-label="Progres hasil final kunjungan" class="exam-completion h-2.5 w-full overflow-hidden rounded-full">{{ $finalizedPercent }}%</progress>
        <p class="text-xs text-slate-500">Progres hasil final dihitung dari seluruh peserta terdaftar. Batal dan tidak hadir dicatat terpisah.</p>
    </section>
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-5">
        <a href="{{ $visitUrl }}" class="rounded-2xl border p-5 {{ !request('status') ? 'border-emerald-700 bg-emerald-50' : 'border-slate-200 bg-white hover:border-emerald-700' }}"><p class="text-xs font-semibold text-slate-500">Total peserta</p><strong class="mt-2 block text-2xl">{{ $totalParticipants }}</strong></a>
        @foreach(['belum', 'proses', 'siap_finalisasi', 'selesai'] as $status)<a href="{{ route('kunjungan-mcu.show', [$kunjungan, 'status' => $status]) }}" @if(request('status') === $status) aria-current="page" @endif class="rounded-2xl border p-5 {{ request('status') === $status ? 'border-emerald-700 bg-emerald-50' : 'border-slate-200 bg-white hover:border-emerald-700' }}"><p class="text-xs font-semibold text-slate-500">{{ $statusLabels[$status] }}</p><strong class="mt-2 block text-2xl">{{ $counts[$status] ?? 0 }}</strong></a>@endforeach
    </div>

    <div class="grid items-start gap-6 xl:grid-cols-[minmax(0,2.5fr)_minmax(0,1fr)]">
        <section class="grid min-w-0 gap-5 rounded-2xl border border-slate-200 bg-white p-6">
            <div class="flex flex-wrap items-center justify-between gap-3"><h3 class="text-xl font-bold">Peserta MCU</h3><span class="text-sm text-slate-500">{{ $pesertas->total() }} peserta sesuai filter</span></div>
            <form method="GET" action="{{ $visitUrl }}" class="flex flex-wrap gap-3"><input name="q" value="{{ request('q') }}" placeholder="Cari nama / no. karyawan / MCU" aria-label="Cari peserta" class="min-h-12 min-w-0 flex-1 rounded-xl border border-slate-300 px-3"><select name="status" aria-label="Status peserta" class="min-h-12 rounded-xl border border-slate-300 px-3"><option value="">Semua status</option>@foreach($statusLabels as $status => $label)<option value="{{ $status }}" @selected(request('status') === $status)>{{ $label }}</option>@endforeach</select><button class="rounded-xl border border-slate-300 px-4 py-3 text-sm font-semibold">Cari</button></form>
            @if(request('q') || request('status'))<a href="{{ $visitUrl }}" class="justify-self-start text-xs font-semibold text-emerald-700">Hapus filter →</a>@endif
            <div class="divide-y divide-slate-200">
                @forelse($pesertas as $peserta)
                    @php($completion = $participantProgress[$peserta->id])
                    <article data-participant="{{ $peserta->id }}" class="grid items-center gap-4 py-5 sm:grid-cols-[minmax(0,1fr)_auto]">
                        <div class="flex min-w-0 items-center gap-3">
                            @php($photoEpisode = $participantPhotos[$peserta->id])
                            <a href="{{ route('pasien.show', $peserta) }}" aria-label="Profil {{ $peserta->nama_karyawan }}" class="shrink-0 rounded-xl focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700">
                                @if($photoEpisode)
                                    <img src="{{ route('pemeriksaan.attachment', [$photoEpisode->rencana_kunjungan_id, $photoEpisode, 'foto', 'v' => hash('sha256', $photoEpisode->foto)]) }}" alt="Foto {{ $peserta->nama_karyawan }}" loading="lazy" class="h-16 w-16 rounded-xl object-cover">
                                @else
                                    <span data-patient-default-icon aria-hidden="true" class="flex h-16 w-16 items-center justify-center rounded-xl bg-blue-50 text-slate-400"><svg class="h-9 w-9" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4" stroke-width="1.5"/><path d="M4 21v-2a8 8 0 0116 0v2" stroke-width="1.5"/></svg></span>
                                @endif
                            </a>
                            <div class="min-w-0"><a href="{{ route('pasien.show', $peserta) }}" class="break-words font-bold text-slate-900 hover:text-emerald-700">{{ $peserta->nama_karyawan }}</a><p class="mt-2 break-all text-xs text-slate-500">{{ $peserta->nomor_mcu }}</p><p class="mt-1 break-words text-xs text-slate-500">No. karyawan {{ $peserta->nip ?? '—' }} · {{ $peserta->paket->nama_paket }}</p></div>
                        </div>
                        <div class="flex flex-wrap items-center gap-3 sm:justify-end"><span class="text-xs font-semibold text-slate-500">{{ $completion['completed'] }}/{{ $completion['total'] }} bagian</span><span class="rounded-full px-4 py-2 text-xs font-bold {{ match($peserta->status_pemeriksaan) { 'selesai' => 'bg-emerald-50 text-emerald-700', 'belum' => 'bg-amber-50 text-amber-800', 'batal', 'tidak_hadir' => 'bg-slate-100 text-slate-500', default => 'bg-blue-50 text-blue-800' } }}">{{ $statusLabels[$peserta->status_pemeriksaan] ?? $peserta->status_pemeriksaan }}</span></div>
                        <div class="min-w-0"><progress value="{{ $completion['completed'] }}" max="{{ max(1, $completion['total']) }}" aria-label="Progres pemeriksaan {{ $peserta->nama_karyawan }}" class="exam-completion h-1.5 w-full overflow-hidden rounded-full">{{ $completion['percent'] }}%</progress></div>
                        @can('examine-mcu')<a href="{{ route('pemeriksaan.edit', [$kunjungan, $peserta]) }}" class="rounded-xl border border-slate-300 px-4 py-3 text-center text-sm font-bold hover:border-emerald-700 hover:bg-emerald-50">{{ $kunjungan->is_closing || in_array($peserta->status_pemeriksaan, ['selesai', 'batal', 'tidak_hadir'], true) ? 'Lihat hasil' : ($peserta->status_pemeriksaan === 'siap_finalisasi' ? 'Review hasil' : ($peserta->status_pemeriksaan === 'belum' ? 'Mulai pengisian' : 'Buka pemeriksaan')) }} →</a>@else<a href="{{ route('pasien.show', $peserta) }}" class="rounded-xl border border-slate-300 px-4 py-3 text-center text-sm font-semibold">Lihat peserta →</a>@endcan
                    </article>
                @empty
                    <div class="grid gap-3 py-10 text-center"><p class="font-semibold">{{ $totalParticipants === 0 ? 'Belum ada peserta terdaftar' : 'Peserta tidak ditemukan' }}</p><p class="text-sm text-slate-500">{{ $totalParticipants === 0 ? 'Daftarkan peserta untuk mulai mencatat hasil MCU.' : 'Coba ubah kata pencarian atau filter status.' }}</p></div>
                @endforelse
            </div>
            {{ $pesertas->links() }}
            <p class="text-xs text-slate-500">Progres bagian mengikuti ringkasan pemeriksaan dan cakupan paket; bukan penentu status final.</p>
        </section>

        <aside aria-label="Informasi dan tindak lanjut kunjungan" class="grid min-w-0 gap-6">
            <section class="grid gap-5 rounded-2xl border border-slate-200 bg-white p-6"><h3 class="text-lg font-bold">Informasi kunjungan</h3><dl class="grid gap-5 text-sm">
                @foreach(['Periode' => $kunjungan->periode_kunjungan, 'Jadwal' => ($kunjungan->tgl_mulai?->format('d/m/Y') ?? 'Belum ditentukan').' — '.($kunjungan->tgl_selesai?->format('d/m/Y') ?? 'Belum ditentukan'), 'Peserta' => $totalParticipants.($kunjungan->kuota_peserta > 0 ? ' / '.$kunjungan->kuota_peserta : '').' terdaftar'] as $label => $value)<div><dt class="text-slate-500">{{ $label }}</dt><dd class="mt-2 font-semibold">{{ $value }}</dd></div>@endforeach
            </dl>@if($kunjungan->catatan)<p class="whitespace-pre-line border-t border-slate-200 pt-4 text-sm text-slate-500">{{ $kunjungan->catatan }}</p>@endif</section>
            <section class="grid gap-4 rounded-2xl border border-slate-200 bg-white p-6"><h3 class="text-lg font-bold">Paket pemeriksaan</h3>
                @forelse($kunjungan->pakets as $paket)<div class="border-t border-slate-100 pt-4"><strong class="text-sm">{{ $paket->nama_paket }}</strong><p class="mt-2 text-xs text-slate-500">{{ $paket->karyawans_count }} peserta · {{ $paket->itemCount() }} item pemeriksaan</p>@can('manage-mcu')<a href="{{ route('paket.show', $paket) }}" class="mt-2 inline-block text-xs font-semibold text-emerald-700">Lihat paket →</a>@endcan</div>@empty<p class="text-sm text-slate-500">Belum ada paket terdaftar.</p>@endforelse
                @can('manage-mcu') @if(!$kunjungan->is_closing)<a href="{{ route('rencana-kunjungan.show', $kunjungan) }}" class="rounded-xl border border-dashed border-slate-300 px-4 py-3 text-center text-sm font-semibold text-emerald-700">+ Pilih paket master</a>@endif @endcan
            </section>
            <section class="grid gap-4 rounded-2xl border border-slate-200 bg-white p-6"><h3 class="text-lg font-bold">Perlu ditindaklanjuti</h3>
                @foreach(['belum' => 'belum mulai', 'proses' => 'masih pengisian', 'siap_finalisasi' => 'siap finalisasi'] as $status => $label)<a href="{{ route('kunjungan-mcu.show', [$kunjungan, 'status' => $status]) }}" class="text-sm text-slate-500"><strong class="text-slate-900">{{ $counts[$status] ?? 0 }}</strong> {{ $label }} →</a>@endforeach
                <a href="{{ route('kunjungan-mcu.show', [$kunjungan, 'status' => 'siap_finalisasi']) }}" class="rounded-xl bg-emerald-700 px-4 py-3 text-center text-sm font-bold text-white hover:bg-emerald-800">Lihat siap final →</a>
                <div class="grid gap-2 border-t border-slate-200 pt-4 text-xs text-slate-500"><a href="{{ route('kunjungan-mcu.show', [$kunjungan, 'status' => 'batal']) }}">Batal: {{ $counts['batal'] ?? 0 }} →</a><a href="{{ route('kunjungan-mcu.show', [$kunjungan, 'status' => 'tidak_hadir']) }}">Tidak hadir: {{ $counts['tidak_hadir'] ?? 0 }} →</a></div>
            </section>
        </aside>
    </div>

    @can('manage-mcu')
        <section class="grid gap-4 rounded-2xl border border-slate-200 bg-white p-6"><h3 class="text-lg font-bold">Penyelesaian kunjungan</h3>
            @if($kunjungan->is_closing)<p class="text-sm text-slate-500">Kunjungan telah ditutup. Pengisian dan revisi hasil menjadi hanya baca.</p>
            @else
                <p class="text-sm text-slate-500">{{ $totalParticipants === 0 ? 'Daftarkan peserta terlebih dahulu sebelum menyelesaikan kunjungan.' : ($remainingParticipants > 0 ? 'Masih ada '.$remainingParticipants.' peserta yang belum final, batal, atau tidak hadir.' : 'Seluruh peserta sudah final, batal, atau tidak hadir. Kunjungan dapat diselesaikan.') }}</p>
                <p class="text-xs text-slate-500">Penutupan mengunci pengisian dan revisi. Pastikan seluruh hasil telah ditinjau sebelum melanjutkan.</p>
                <form method="POST" action="{{ route('rencana-kunjungan.status', $kunjungan) }}" class="flex flex-wrap gap-3">@csrf
                    @if($kunjungan->status === 'draft')<button name="status" value="ongoing" class="rounded-xl bg-emerald-700 px-5 py-3 text-sm font-bold text-white">Mulai kegiatan</button>@endif
                    <button name="status" value="completed" @disabled(!$canComplete) class="rounded-xl border border-slate-300 px-5 py-3 text-sm font-semibold disabled:cursor-not-allowed disabled:bg-slate-50 disabled:text-slate-400">Tandai kegiatan selesai</button>
                    <button name="status" value="closed" @disabled(!$canComplete) onclick="return confirm('Tutup kunjungan? Pengisian dan revisi tidak bisa dilakukan setelah ditutup.')" class="rounded-xl border border-red-200 px-5 py-3 text-sm font-semibold text-red-700 disabled:cursor-not-allowed disabled:bg-slate-50 disabled:text-slate-400">Tutup kunjungan</button>
                </form>
            @endif
        </section>
    @endcan
</div>
@endsection
