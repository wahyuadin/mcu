@extends('layouts.mcu')
@section('title', 'Dashboard')
@section('content')
<div @can('view-patients') data-dashboard-workspace @endcan data-csrf="{{ csrf_token() }}" data-data-url="{{ route('dashboard.data') }}" data-layout-url="{{ route('dashboard.preferences') }}" data-preview-url="{{ route('dashboard.preview') }}" class="grid min-w-0 gap-6">
<div class="flex flex-wrap items-center justify-between gap-3"><div><p data-hrd-layout-note class="text-sm text-slate-500">Dashboard bawaan · personalisasi bersifat opsional.</p><p data-dashboard-status role="status" class="mt-1 text-xs text-slate-400">Data terkini</p></div><div class="flex flex-wrap items-center gap-3">@if($companies->isNotEmpty())<label class="sr-only" for="dashboard-context">Cakupan dashboard</label><select id="dashboard-context" data-dashboard-context class="hrd-input max-w-72"><option value="">Ringkasan operasional</option>@foreach($companies as $company)<option value="{{ route('dashboard', ['company' => $company->id]) }}">{{ $company->nama_perusahaan }}</option>@endforeach</select>@endif @can('view-patients')<button type="button" data-hrd-builder-open disabled class="hrd-primary" aria-controls="hrd-builder">Atur dashboard</button>@endcan</div></div>
<p data-dashboard-error hidden role="alert" class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"></p>
@include('mcu.dashboard-toolbar')
<section data-dashboard-widgets hidden class="hrd-widget-grid" aria-label="Widget dashboard pribadi"></section>
<div data-dashboard-default class="grid min-w-0 gap-6">
<section class="grid grid-cols-1 gap-6 sm:grid-cols-2 xl:grid-cols-4">
    @foreach([
        ['id' => 'perusahaan', 'label' => 'Perusahaan', 'value' => $stats['perusahaan'], 'tone' => 'blue'],
        ['id' => 'kunjungan_aktif', 'label' => 'Kunjungan Aktif', 'value' => $stats['kunjungan_aktif'], 'tone' => 'green'],
        ['id' => 'peserta', 'label' => 'Total Peserta', 'value' => $stats['peserta'], 'tone' => 'orange'],
        ['id' => 'selesai', 'label' => 'Pemeriksaan Selesai', 'value' => $stats['selesai'], 'tone' => 'green'],
    ] as $card)
    <article class="flex items-center justify-between rounded-2xl border border-slate-100 bg-white p-6 shadow-md shadow-slate-200/50">
        <div><p class="text-xs font-semibold uppercase tracking-wider text-slate-400">{{ $card['label'] }}</p><h2 data-dashboard-stat="{{ $card['id'] }}" class="mt-2 text-3xl font-extrabold text-slate-800">{{ number_format($card['value']) }}</h2><p class="mt-1 text-xs text-slate-500">Data terkini</p></div>
        <div class="flex h-14 w-14 items-center justify-center rounded-2xl {{ $card['tone'] === 'blue' ? 'bg-blue-50 text-[#00008B]' : ($card['tone'] === 'orange' ? 'bg-amber-50 text-[#FF8C00]' : 'bg-emerald-50 text-[#008000]') }}"><svg class="h-7 w-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div>
    </article>
    @endforeach
</section>
<section class="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-md shadow-slate-200/50">
    <div class="flex items-center justify-between border-b border-slate-100 p-6"><div><h2 class="text-lg font-bold text-slate-800">Kunjungan MCU Terbaru</h2><p class="text-xs text-slate-400">Lima rencana kunjungan terakhir</p></div><a href="{{ auth()->user()->can('manage-mcu') ? route('rencana-kunjungan.index') : route('kunjungan-mcu.index') }}" class="text-xs font-semibold text-[#00008B] hover:underline">Lihat Semua →</a></div>
    <div class="overflow-x-auto"><table class="w-full text-left text-sm text-slate-600"><thead class="bg-slate-50 text-xs font-semibold uppercase text-slate-400"><tr><th class="px-6 py-4">Nomor</th><th class="px-6 py-4">Perusahaan</th><th class="px-6 py-4">Periode</th><th class="px-6 py-4">Peserta</th><th class="px-6 py-4 text-right">Aksi</th></tr></thead><tbody data-dashboard-visits class="divide-y divide-slate-100">@forelse($kunjungans as $kunjungan)<tr class="hover:bg-slate-50/50"><td class="px-6 py-4 font-bold text-[#00008B]">{{ $kunjungan->nomor_kunjungan }}</td><td class="px-6 py-4 font-medium text-slate-800">{{ $kunjungan->perusahaan->nama_perusahaan }}</td><td class="px-6 py-4">{{ $kunjungan->periode_kunjungan }}</td><td class="px-6 py-4">{{ $kunjungan->karyawans_count }}@if($kunjungan->kuota_peserta > 0)/{{ $kunjungan->kuota_peserta }}@endif</td><td class="px-6 py-4 text-right"><a href="{{ auth()->user()->can('manage-mcu') ? route('rencana-kunjungan.show', $kunjungan) : route('kunjungan-mcu.show', $kunjungan) }}" class="rounded-lg bg-slate-100 px-3 py-2 text-xs font-semibold hover:bg-[#00008B] hover:text-white">{{ auth()->user()->can('manage-mcu') ? 'Kelola' : 'Buka' }}</a></td></tr>@empty<tr><td colspan="5" class="px-6 py-12 text-center text-slate-400">Belum ada kunjungan.</td></tr>@endforelse</tbody></table></div>
</section>
</div>
@include('mcu.hrd-widget-panels')
</div>
@endsection
