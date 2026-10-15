@extends('layouts.mcu')
@section('title', 'Dashboard')
@section('content')
    <div @if ($canCustomize) data-dashboard-workspace @endif data-company="{{ $company?->id }}"
        data-visit="{{ request('visit', '') }}" data-visit-status="{{ request('visit_status', '') }}"
        data-csrf="{{ csrf_token() }}" data-data-url="{{ route('dashboard.data') }}"
        data-layout-url="{{ route('dashboard.preferences') }}" data-preview-url="{{ route('dashboard.preview') }}"
        data-saved-url="{{ route('dashboard.saved.store') }}" data-selection-url="{{ route('dashboard.selection') }}"
        class="grid min-w-0 gap-6">
        @if ($canCustomize)
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div class="flex min-w-0 flex-1 flex-wrap gap-4">
                    <label class="hrd-field w-full sm:w-64">Dashboard<select data-dashboard-select disabled
                            class="hrd-input">
                            <option value="default">{{ $defaultDashboardName }}</option>
                        </select></label>
                    @if ($companies->isNotEmpty())<label
                            class="hrd-field w-full sm:w-72" for="dashboard-context">Perusahaan<select
                                id="dashboard-context" data-dashboard-context class="hrd-input">
                                <option value="">Semua Perusahaan</option>
                                @foreach ($companies as $option)
                                    <option value="{{ $option->id }}" @selected($company?->is($option))>
                                        {{ $option->nama_perusahaan }}</option>
                                @endforeach
                            </select>
                        </label>
                    @elseif($company)
                        <div class="hrd-field w-full sm:w-72">Perusahaan<div class="hrd-input break-words bg-slate-50">
                                {{ $company->nama_perusahaan }} <span class="text-xs text-slate-400">· terkunci</span>
                            </div>
                        </div>
                    @endif
                </div>
                <div data-dashboard-library-actions class="flex flex-wrap gap-2"><button type="button" data-dashboard-new
                        disabled class="hrd-button">＋ Dashboard baru</button><button type="button" data-dashboard-duplicate
                        disabled class="hrd-button">Duplikat</button><button type="button" data-dashboard-rename hidden
                        class="hrd-button">Ganti nama</button><button type="button" data-dashboard-delete hidden
                        class="hrd-button text-red-700">Hapus</button><button type="button" data-hrd-builder-open disabled
                        class="hrd-primary" aria-controls="hrd-builder">Atur dashboard</button></div>
            </div>
            <div class="grid min-w-0 items-end gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto]">
                <label class="hrd-field">Kunjungan<select data-dashboard-visit class="hrd-input">
                        <option value="">Semua Kunjungan</option>
                        @foreach ($visitOptions as $visit)
                            <option value="{{ $visit['id'] }}" data-company-id="{{ $visit['company_id'] }}"
                                data-status="{{ $visit['status'] }}" @selected(request('visit') === $visit['id'])>{{ $visit['number'] }} ·
                                {{ $visit['period'] }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="hrd-field">Status kunjungan<select data-dashboard-visit-status class="hrd-input">
                        @foreach (['' => 'Semua', 'draft' => 'Draft', 'ongoing' => 'Berjalan', 'completed' => 'Selesai', 'closed' => 'Ditutup'] as $value => $label)
                            <option value="{{ $value }}" @selected(request('visit_status', '') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <button type="button" data-dashboard-filter-reset class="hrd-button">Reset filter</button>
            </div>
            <div>
                <p data-hrd-layout-note class="text-sm text-slate-500">Dashboard bawaan · personalisasi bersifat opsional.
                </p>
                <p data-dashboard-status role="status" class="mt-1 text-xs text-slate-400">Data terkini · otomatis setiap 4
                    detik</p><button type="button" data-dashboard-cross-clear hidden
                    class="hrd-button mt-2 bg-amber-50 text-amber-900"></button>
            </div>
            <p data-dashboard-error hidden role="alert"
                class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"></p>
            @include('mcu.dashboard-toolbar', ['namedDashboards' => true])
        @endif
        <div data-dashboard-editor class="dashboard-editor">
            @if ($canCustomize)
                <section data-dashboard-widgets hidden class="hrd-widget-grid" aria-label="Widget dashboard pribadi">
                </section>
            @endif
            <div data-dashboard-default class="grid min-w-0 gap-6">
                <section class="grid grid-cols-2 gap-3 sm:gap-6 xl:grid-cols-4">
                    @foreach ([['id' => 'perusahaan', 'label' => 'Perusahaan', 'value' => $stats['perusahaan'], 'tone' => 'blue'], ['id' => 'kunjungan_aktif', 'label' => 'Kunjungan Aktif', 'value' => $stats['kunjungan_aktif'], 'tone' => 'green'], ['id' => 'peserta', 'label' => 'Total Peserta', 'value' => $stats['peserta'], 'tone' => 'orange'], ['id' => 'selesai', 'label' => 'Pemeriksaan Selesai', 'value' => $stats['selesai'], 'tone' => 'green']] as $card)
                        <article
                            class="flex min-w-0 items-center justify-between gap-3 rounded-2xl border border-slate-100 bg-white p-4 shadow-md shadow-slate-200/50 sm:p-6">
                            <div class="min-w-0">
                                <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">
                                    {{ $card['label'] }}</p>
                                <h2 data-dashboard-stat="{{ $card['id'] }}"
                                    class="mt-2 break-words text-2xl font-extrabold text-slate-800 sm:text-3xl">
                                    {{ number_format($card['value']) }}</h2>
                                <p class="mt-1 text-xs text-slate-500">Data terkini</p>
                            </div>
                            <div
                                class="hidden h-14 w-14 shrink-0 items-center justify-center rounded-2xl sm:flex {{ $card['tone'] === 'blue' ? 'bg-blue-50 text-[#00008B]' : ($card['tone'] === 'orange' ? 'bg-amber-50 text-[#FF8C00]' : 'bg-emerald-50 text-[#008000]') }}">
                                <svg class="h-7 w-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg></div>
                        </article>
                    @endforeach
                </section>
                <section class="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-md shadow-slate-200/50">
                    <div class="flex items-center justify-between gap-3 border-b border-slate-100 p-6">
                        <div>
                            <h2 class="text-lg font-bold text-slate-800">Kunjungan MCU Terbaru</h2>
                            <p class="text-xs text-slate-400">Lima rencana kunjungan terakhir</p>
                        </div>
                        @can('view-patients')
                            <a href="{{ auth()->user()->can('manage-mcu') ? route('rencana-kunjungan.index') : route('kunjungan-mcu.index') }}"
                                class="shrink-0 text-xs font-semibold whitespace-nowrap text-[#00008B] hover:underline">Lihat
                                Semua →</a>
                        @endcan
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm text-slate-600">
                            <thead class="bg-slate-50 text-xs font-semibold uppercase text-slate-400">
                                <tr>
                                    <th class="px-6 py-4">Nomor</th>
                                    <th class="px-6 py-4">Perusahaan</th>
                                    <th class="px-6 py-4">Periode</th>
                                    <th class="px-6 py-4">Peserta</th>
                                    <th class="px-6 py-4 text-right">Aksi</th>
                                </tr>
                            </thead>
                            <tbody data-dashboard-visits class="divide-y divide-slate-100">
                                @forelse($recentVisits as $visit)
                                    <tr class="hover:bg-slate-50/50">
                                        <td class="px-6 py-4 font-bold text-[#00008B]">{{ $visit['number'] }}</td>
                                        <td class="px-6 py-4 font-medium text-slate-800">{{ $visit['company'] }}</td>
                                        <td class="px-6 py-4">{{ $visit['period'] }}</td>
                                        <td class="px-6 py-4">{{ $visit['participants'] }}@if ($visit['quota'] > 0)
                                                /{{ $visit['quota'] }}
                                            @endif
                                        </td>
                                        <td class="px-6 py-4 text-right"><a href="{{ $visit['url'] }}"
                                                class="rounded-lg bg-slate-100 px-3 py-2 text-xs font-semibold hover:bg-[#00008B] hover:text-white">{{ $visit['action_label'] }}</a>
                                        </td>
                                    </tr>@empty<tr>
                                            <td colspan="5" class="px-6 py-12 text-center text-slate-400">Belum ada
                                                kunjungan.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>
                @if ($canCustomize)
                    @include('mcu.hrd-widget-panels', ['inlineEditor' => true])
                @endif
            </div>
            @if ($canCustomize)
                <dialog data-dashboard-name-dialog data-user-modal aria-labelledby="dashboard-name-title"
                    class="fixed inset-0 m-auto w-[calc(100%-2rem)] max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-2xl">
                    <form data-dashboard-name-form class="grid gap-4">
                        <h2 id="dashboard-name-title" class="text-xl font-bold">Simpan dashboard</h2><label
                            class="hrd-field">Nama dashboard<input name="name" required maxlength="100" class="hrd-input"
                                autocomplete="off"></label>
                        <p data-dashboard-name-error role="alert" class="text-sm text-red-700" hidden></p>
                        <div class="flex justify-end gap-2"><button type="button" data-dashboard-name-cancel
                                class="hrd-button">Batal</button><button class="hrd-primary">Simpan</button></div>
                    </form>
                </dialog>
            @endif
            @can('view-hrd')
                @include('mcu.dashboard-reports')
            @endcan
        </div>
    @endsection
