@extends('layouts.mcu')

@section('title', 'Daftar Laporan & Riwayat MCU')

@section('content')
<div class="mx-auto grid max-w-7xl gap-8">
    <header class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h2 class="text-2xl font-bold text-slate-900">Laporan & Riwayat MCU</h2>
            <p class="mt-1 text-sm text-slate-500">Lihat laporan terbit dan riwayat versi perubahan pemeriksaan peserta.</p>
        </div>
    </header>

    <section class="grid gap-6 rounded-2xl border border-slate-200 bg-white p-6 sm:p-8">
        <form method="GET" action="{{ route('laporan-riwayat.index') }}" class="flex flex-wrap items-center justify-between gap-4">
            <div class="flex flex-1 flex-wrap items-center gap-3 min-w-64">
                <input type="search" name="q" value="{{ $search }}" placeholder="Cari nama, NIK, NIP, atau nomor MCU..." class="w-full max-w-md rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:border-emerald-600 focus:ring-emerald-600">
                <select name="status" onchange="this.form.submit()" class="rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:border-emerald-600 focus:ring-emerald-600">
                    <option value="selesai" @selected($statusFilter === 'selesai')>Laporan Terbit (Selesai)</option>
                    <option value="semua" @selected($statusFilter === 'semua')>Semua Peserta</option>
                </select>
                <button type="submit" class="rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800">Cari</button>
            </div>
        </form>

        <div class="overflow-x-auto rounded-xl border border-slate-200">
            <table class="w-full text-left text-sm text-slate-600">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500 border-b border-slate-200">
                    <tr>
                        <th scope="col" class="px-6 py-4 font-semibold">Peserta</th>
                        <th scope="col" class="px-6 py-4 font-semibold">Perusahaan & Paket</th>
                        <th scope="col" class="px-6 py-4 font-semibold">Tanggal MCU</th>
                        <th scope="col" class="px-6 py-4 font-semibold">Status MCU</th>
                        <th scope="col" class="px-6 py-4 font-semibold text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 bg-white">
                    @forelse($participants as $participant)
                        <tr class="hover:bg-slate-50/50">
                            <td class="px-6 py-4">
                                <strong class="block font-bold text-slate-900">{{ $participant->nama_karyawan }}</strong>
                                <small class="text-xs text-slate-500">{{ $participant->nomor_mcu }} · NIK: {{ $participant->nik ?: '—' }}</small>
                            </td>
                            <td class="px-6 py-4">
                                <span class="block font-semibold text-slate-800">{{ $participant->perusahaan->nama_perusahaan }}</span>
                                <small class="text-xs text-slate-500">{{ $participant->paket->nama_paket }}</small>
                            </td>
                            <td class="px-6 py-4">
                                {{ $participant->tanggal_mcu?->format('d/m/Y') ?: '—' }}
                            </td>
                            <td class="px-6 py-4">
                                @if($participant->status_pemeriksaan === 'selesai')
                                    <span class="inline-flex items-center rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-800">Laporan terbit</span>
                                @elseif($participant->status_pemeriksaan === 'siap_finalisasi')
                                    <span class="inline-flex items-center rounded-full bg-blue-100 px-3 py-1 text-xs font-semibold text-blue-800">Siap finalisasi</span>
                                @else
                                    <span class="inline-flex items-center rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-800">Dalam proses</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-right">
                                <a href="{{ route('pemeriksaan.laporan-riwayat', [$participant->rencanaKunjungan, $participant]) }}" class="inline-flex items-center rounded-xl bg-emerald-700 px-4 py-2 text-xs font-bold text-white shadow-sm transition-colors hover:bg-emerald-800">
                                    Laporan & Riwayat →
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="p-8 text-center text-sm text-slate-500">
                                Belum ada data peserta MCU yang sesuai.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $participants->links() }}
        </div>
    </section>
</div>
@endsection
