@extends('layouts.mcu')

@section('title', 'Laporan & Riwayat MCU')

@section('content')
<div class="mx-auto grid max-w-6xl gap-8">
    <header class="grid gap-2">
        <nav class="flex items-center gap-2 text-xs font-semibold text-slate-400">
            <a href="{{ route('laporan-riwayat.index') }}" class="hover:text-slate-600">MCU</a>
            <span>/</span>
            <span class="text-slate-600">Laporan & riwayat</span>
        </nav>
        <h2 class="text-2xl font-bold text-slate-900">
            {{ $exam->status === 'final' ? 'Laporan MCU berhasil diterbitkan' : 'Laporan MCU dalam proses' }}
        </h2>
        <p class="text-sm text-slate-500">Lihat versi laporan dan telusuri riwayat perubahan pemeriksaan.</p>
    </header>

    <section class="flex flex-wrap items-center justify-between gap-6 rounded-2xl border border-emerald-100 bg-emerald-50/80 p-6 sm:p-8">
        <div class="flex items-center gap-4 min-w-0">
            <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-emerald-700 text-white shadow-sm">
                <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
            </div>
            <div class="min-w-0 grid gap-0.5">
                <h3 class="text-lg font-bold text-slate-900">
                    {{ $exam->status === 'final' ? 'Laporan versi '.$currentVersionNumber.' siap dilihat' : 'Draft / Revisi MCU' }}
                </h3>
                <p class="truncate text-sm text-slate-600">
                    {{ $peserta->nomor_mcu }} · {{ $peserta->nama_karyawan }} · Terbit {{ $exam->finalized_at ? $exam->finalized_at->translatedFormat('d F Y, H.i \W\I\B') : 'Belum difinalisasi' }}
                </p>
            </div>
        </div>
        <a href="{{ route('pemeriksaan.report', [$kunjungan, $peserta]) }}" target="_blank" class="inline-flex cursor-pointer items-center justify-center rounded-xl bg-emerald-700 px-6 py-3.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-emerald-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700">
            Lihat laporan
        </a>
    </section>

    <div class="grid items-start gap-8 lg:grid-cols-3">
        <section class="grid gap-6 rounded-2xl border border-slate-200 bg-white p-6 sm:p-8 lg:col-span-2">
            <h3 class="text-lg font-bold text-slate-900 border-b border-slate-100 pb-4">Detail penerbitan</h3>
            <dl class="grid gap-5 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-slate-500">Pasien</dt>
                    <dd class="mt-1 font-bold text-slate-900">{{ $peserta->nama_karyawan }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Perusahaan / departemen</dt>
                    <dd class="mt-1 font-bold text-slate-900">{{ $peserta->perusahaan->nama_perusahaan }}{{ $peserta->bagian ? ' / ' . $peserta->bagian : '' }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">No. karyawan</dt>
                    <dd class="mt-1 font-bold text-slate-900">{{ $peserta->nip ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Dokter penanggung jawab</dt>
                    <dd class="mt-1 font-bold text-slate-900">{{ $exam->dokterFisik->nama_dokter ?? ($exam->dokter->nama_dokter ?? 'Dokter Pemeriksa') }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Status laporan</dt>
                    <dd class="mt-1 font-bold text-slate-900">{{ $exam->status === 'final' ? 'Laporan terbit' : 'Draft / Dalam revisi' }}</dd>
                </div>
            </dl>
        </section>

        <section class="grid gap-6 rounded-2xl border border-slate-200 bg-white p-6 sm:p-8">
            <h3 class="text-lg font-bold text-slate-900 border-b border-slate-100 pb-4">Tindakan berikutnya</h3>

            <div class="grid gap-2">
                @if($exam->status === 'final')
                    @can('examine-mcu')
                        <button type="button" data-revision-modal-open aria-haspopup="dialog" aria-controls="revision-modal" class="w-full rounded-xl border border-slate-300 py-3 px-4 text-sm font-bold text-slate-800 transition-colors hover:bg-slate-50">
                            Buat revisi hasil
                        </button>
                    @endcan
                @else
                    <a href="{{ route('pemeriksaan.edit', [$kunjungan, $peserta]) }}" class="w-full text-center rounded-xl bg-emerald-700 py-3 px-4 text-sm font-bold text-white transition-colors hover:bg-emerald-800">
                        Lanjutkan pemeriksaan
                    </a>
                @endif
                <p class="text-xs text-slate-500">Gunakan revisi untuk koreksi hasil setelah laporan diterbitkan.</p>
            </div>

            <div class="grid gap-2 pt-2">
                @can('manage-mcu')
                    <a href="{{ route('pasien.create', ['pasien_id' => $peserta->pasien_id ?: $peserta->id]) }}" class="w-full text-center rounded-xl bg-emerald-700 py-3.5 px-4 text-sm font-bold text-white transition-colors hover:bg-emerald-800">
                        + Buat MCU berikutnya
                    </a>
                @endcan
                <p class="text-xs text-slate-500">Versi terbit sebelumnya tetap tersedia.</p>
            </div>
        </section>
    </div>

    <section class="grid gap-6 rounded-2xl border border-slate-200 bg-white p-6 sm:p-8">
        <div>
            <h3 class="text-lg font-bold text-slate-900">Riwayat versi laporan</h3>
            <p class="mt-1 text-sm text-slate-500">Setiap penerbitan menyimpan hasil dan penilaian yang dipakai</p>
        </div>

        <div class="overflow-x-auto rounded-xl border border-slate-200">
            <table class="w-full text-left text-sm text-slate-600">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500 border-b border-slate-200">
                    <tr>
                        <th scope="col" class="px-6 py-4 font-semibold">Versi</th>
                        <th scope="col" class="px-6 py-4 font-semibold">Waktu terbit</th>
                        <th scope="col" class="px-6 py-4 font-semibold">Dokter</th>
                        <th scope="col" class="px-6 py-4 font-semibold">Status</th>
                        <th scope="col" class="px-6 py-4 font-semibold text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 bg-white">
                    <tr class="hover:bg-slate-50/50">
                        <td class="px-6 py-4 font-bold text-slate-900">Versi {{ $currentVersionNumber }}</td>
                        <td class="px-6 py-4">
                            {{ $exam->finalized_at ? $exam->finalized_at->translatedFormat('d M Y · H.i \W\I\B') : 'Belum difinalisasi' }}
                        </td>
                        <td class="px-6 py-4">{{ $exam->dokterFisik->nama_dokter ?? ($exam->dokter->nama_dokter ?? 'Dokter Pemeriksa') }}</td>
                        <td class="px-6 py-4">
                            @if($exam->status === 'final')
                                <span class="inline-flex items-center rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-800">Aktif / terbaru</span>
                            @else
                                <span class="inline-flex items-center rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-800">Draft / Dalam revisi</span>
                            @endif
                        </td>
                        <td class="px-6 py-4 text-right">
                            <a href="{{ route('pemeriksaan.report', [$kunjungan, $peserta]) }}" target="_blank" class="inline-flex items-center rounded-xl border border-slate-300 px-4 py-2 text-xs font-bold text-slate-700 transition-colors hover:bg-slate-100">
                                Lihat
                            </a>
                        </td>
                    </tr>
                    @foreach(array_reverse($revisions, true) as $idx => $rev)
                        @php($versionNum = $idx + 1)
                        <tr class="hover:bg-slate-50/50">
                            <td class="px-6 py-4 font-bold text-slate-900">Versi {{ $versionNum }}</td>
                            <td class="px-6 py-4">
                                {{ isset($rev['at']) ? \Illuminate\Support\Carbon::parse($rev['at'])->translatedFormat('d M Y · H.i \W\I\B') : '—' }}
                            </td>
                            <td class="px-6 py-4">{{ $rev['operator'] ?? 'Dokter' }}</td>
                            <td class="px-6 py-4">
                                <span class="inline-flex items-center rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">Revisi terdahulu</span>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <a href="{{ route('pemeriksaan.laporan-riwayat.version', [$kunjungan, $peserta, $versionNum]) }}" target="_blank" class="inline-flex items-center rounded-xl border border-slate-300 px-4 py-2 text-xs font-bold text-slate-700 transition-colors hover:bg-slate-100">
                                    Lihat
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
</div>

@can('examine-mcu')
    <dialog id="revision-modal" data-revision-modal aria-labelledby="revision-modal-title" class="m-auto max-h-[90dvh] w-[calc(100%-2rem)] max-w-lg overflow-y-auto rounded-2xl border-0 bg-white p-0 text-slate-900 shadow-2xl">
        <div class="flex items-center justify-between gap-4 border-b border-slate-100 p-5">
            <div>
                <h3 id="revision-modal-title" class="text-lg font-bold text-slate-900">Buka Revisi Hasil MCU</h3>
                <p class="mt-1 text-xs text-slate-500">{{ $peserta->nama_karyawan }} · {{ $peserta->nomor_mcu }}</p>
            </div>
            <button type="button" data-revision-modal-close aria-label="Tutup" class="rounded-xl border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-600">Tutup</button>
        </div>
        <form method="POST" action="{{ route('pemeriksaan.revise', [$kunjungan, $peserta]) }}" class="grid gap-5 p-6">
            @csrf
            <div class="grid gap-2">
                <label for="revision-reason" class="text-sm font-bold text-slate-700">Alasan Revisi <span class="text-red-500">*</span></label>
                <textarea id="revision-reason" name="reason" rows="4" required minlength="5" placeholder="Tuliskan alasan perubahan data atau koreksi hasil pemeriksaan..." class="w-full rounded-xl border border-slate-300 p-3 text-sm focus:border-emerald-600 focus:ring-emerald-600"></textarea>
                <p class="text-xs text-slate-500">Riwayat revisi dan data terdahulu tetap tersimpan permanen.</p>
            </div>
            <div class="flex justify-end gap-3 pt-2">
                <button type="button" data-revision-modal-close class="rounded-xl border border-slate-300 px-5 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50">Batal</button>
                <button type="submit" class="rounded-xl bg-amber-600 px-5 py-3 text-sm font-bold text-white hover:bg-amber-700">Buka Revisi</button>
            </div>
        </form>
    </dialog>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const modal = document.getElementById('revision-modal');
            const openBtn = document.querySelector('[data-revision-modal-open]');
            if (!modal) return;
            openBtn?.addEventListener('click', () => modal.showModal());
            modal.querySelectorAll('[data-revision-modal-close]').forEach(b => b.addEventListener('click', () => modal.close()));
        });
    </script>
@endcan
@endsection
