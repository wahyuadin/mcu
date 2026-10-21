@extends('layouts.mcu')
@section('title', 'Registrasi PKS / Rencana Kunjungan')
@section('content')
    <div class="mx-auto grid max-w-6xl gap-6">
        <a href="{{ route('rencana-kunjungan.index') }}" class="text-sm font-semibold text-slate-500">← Rencana
            Kunjungan</a>
        <section class="grid gap-4 rounded-2xl border border-slate-200 bg-white p-6">
            <h2 class="text-2xl font-bold">{{ $kunjungan->perusahaan->nama_perusahaan }}</h2>
            <p class="text-sm text-slate-500">{{ $kunjungan->nomor_kunjungan }} · {{ $kunjungan->periode_kunjungan }}
                @if ($kunjungan->kuota_peserta > 0)
                    · Kuota {{ $kunjungan->kuota_peserta }} peserta
                @endif
            </p>
            <p class="text-sm">Halaman registrasi PKS dan konfigurasi paket. Pengisian hasil dan pengelolaan peserta
                dilakukan melalui Kunjungan MCU.</p>
            <a href="{{ route('kunjungan-mcu.show', $kunjungan) }}"
                class="justify-self-start rounded-xl bg-[#00008B] px-5 py-3 text-sm font-semibold text-white">Buka
                operasional Kunjungan MCU →</a>
        </section>
        <section class="grid gap-4 rounded-2xl border border-slate-200 bg-white p-6">
            <h3 class="text-lg font-bold">Paket terdaftar</h3>
            @forelse($kunjungan->pakets as $paket)
                <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 p-4">
                    <a href="{{ route('paket.show', $paket) }}"><strong>{{ $paket->nama_paket }}</strong>
                        <p class="mt-1 text-sm text-slate-500">{{ $paket->itemCount() }} item pemeriksaan</p>
                    </a>
                    @if (!$kunjungan->is_closing)
                        <form method="POST" action="{{ route('rencana-kunjungan.paket.unbind', [$kunjungan, $paket]) }}">
                            @csrf @method('DELETE')<button type="submit"
                                class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold">Lepas dari
                                rencana</button></form>
                    @endif
                </div>
            @empty
                <p class="text-sm text-slate-500">Belum ada paket. Pilih paket master di bawah.</p>
            @endforelse
            @if (!$kunjungan->is_closing)
                <form method="POST" action="{{ route('rencana-kunjungan.paket.bind', $kunjungan) }}"
                    class="grid gap-3 border-t border-slate-200 pt-5 sm:grid-cols-[minmax(0,1fr)_auto]">@csrf
                    <label class="grid gap-2 text-sm font-semibold">Pilih paket master<select name="paket_id" required
                            class="min-h-12 min-w-0 rounded-xl border border-slate-300 bg-white px-3">
                            <option value="">Pilih paket yang sudah dibuat</option>
                            @foreach ($availablePackages as $package)
                                <option value="{{ $package->id }}">{{ $package->nama_paket }} —
                                    {{ $package->itemCount() }} item</option>
                            @endforeach
                        </select></label>
                    <button type="submit" @disabled($availablePackages->isEmpty())
                        class="self-end rounded-xl bg-emerald-700 px-5 py-3 text-sm font-bold text-white disabled:opacity-50">Tambahkan
                        paket</button>
                    <p class="text-xs text-slate-500 sm:col-span-2">Paket yang sama bisa digunakan oleh beberapa perusahaan
                        tanpa mapping ulang.</p>
                </form>
                <a href="{{ route('paket.create', ['rencana_kunjungan_id' => $kunjungan->id]) }}"
                    class="justify-self-start text-sm font-semibold text-[#00008B]">+ Buat paket master baru</a>
            @endif
        </section>
    </div>
@endsection
