<div data-patient-results>
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-4 sm:px-6"><h3 class="font-bold text-slate-900">Daftar pasien</h3><p class="text-xs text-slate-500">{{ $pasiens->total() }} pasien sesuai filter</p></div>
    <div class="p-4 md:p-0">
        <table class="block w-full text-left text-sm md:table">
            <thead class="hidden bg-slate-50 text-xs uppercase tracking-wide text-slate-500 md:table-header-group"><tr>
                <th class="px-6 py-4" aria-sort="{{ $sort === 'nama' ? ($direction === 'asc' ? 'ascending' : 'descending') : 'none' }}"><a data-patient-list-link href="{{ route('pasien.index', [...request()->except('page', 'sort', 'direction'), 'sort' => 'nama', 'direction' => $sort === 'nama' && $direction === 'asc' ? 'desc' : 'asc']) }}" class="inline-flex items-center gap-2">Pasien <span aria-hidden="true">{{ $sort === 'nama' ? ($direction === 'asc' ? '↑' : '↓') : '↕' }}</span></a></th>
                <th class="px-4 py-4">MCU terbaru</th><th class="px-4 py-4">Kunjungan</th><th class="px-6 py-4 text-right">Aksi</th>
            </tr></thead>
            <tbody class="grid gap-4 md:table-row-group md:divide-y md:divide-slate-100">
                @forelse($pasiens as $identity)
                    @php
                        $episode = $identity->episodes->first();
                        $canEdit = $profiles->editable($episode);
                        $statusLabels = ['belum' => 'Terdaftar', 'proses' => 'Dalam pengisian', 'siap_finalisasi' => 'Siap finalisasi', 'selesai' => 'Final', 'batal' => 'Batal', 'tidak_hadir' => 'Tidak hadir'];
                    @endphp
                    <tr data-patient-row="{{ $identity->id }}" class="grid grid-cols-2 items-center gap-y-2 rounded-2xl border border-slate-200 bg-white md:table-row md:rounded-none md:border-0">
                        <td class="col-span-2 min-w-0 p-5 md:w-[40%] md:px-6 md:py-5">
                            <div class="flex min-w-0 items-center gap-4">
                                @if($patientPhotos[$identity->id])<img src="{{ $patientPhotos[$identity->id] }}" alt="Foto {{ $identity->nama }}" loading="lazy" class="h-20 w-16 shrink-0 rounded-xl border border-slate-100 object-cover">
                                @else<span aria-hidden="true" class="flex h-20 w-16 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-xl font-bold text-blue-800">{{ mb_strtoupper(mb_substr($identity->nama, 0, 2)) }}</span>@endif
                                <div class="grid min-w-0 gap-1"><strong class="break-words text-base text-slate-900">{{ $identity->nama }}</strong><p class="text-xs text-slate-500">{{ $identity->tanggal_lahir ? $identity->tanggal_lahir->age.' tahun' : 'Umur belum dicatat' }}</p><p class="break-words text-xs font-medium text-slate-600">{{ $episode?->perusahaan?->nama_perusahaan ?? 'Perusahaan belum dicatat' }}</p><p class="break-all text-xs text-slate-500">NPK: <span class="font-medium text-slate-700">{{ $episode?->nip ?? '—' }}</span></p></div>
                            </div>
                        </td>
                        <td class="min-w-0 px-5 py-2 md:px-4 md:py-5">@if($episode)<p class="break-all text-xs font-semibold text-slate-700">{{ $episode->nomor_mcu }}</p><p class="mt-1 break-words text-xs text-slate-500">{{ $episode->paket?->nama_paket ?? '—' }}</p><span class="mt-2 inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $episode->status_pemeriksaan === 'selesai' ? 'bg-emerald-50 text-emerald-700' : 'bg-blue-50 text-blue-800' }}">{{ $statusLabels[$episode->status_pemeriksaan] ?? $episode->status_pemeriksaan }}</span>@else<span class="text-xs text-slate-400">Belum ada episode MCU aktif.</span>@endif</td>
                        <td class="px-5 py-2 md:px-4 md:py-5"><strong class="text-lg text-slate-800">{{ $identity->episodes_count }}</strong><span class="ml-1 text-xs text-slate-500">episode</span></td>
                        <td class="col-span-2 border-t border-slate-100 px-5 py-4 md:border-0 md:px-6 md:py-5"><div class="flex items-center justify-end gap-2">
                            <button type="button" data-patient-open="detail" data-url="{{ route('pasien.identity.show', $identity) }}" data-patient-id="{{ $identity->id }}" title="Detail" aria-label="Detail {{ $identity->nama }}" aria-haspopup="dialog" aria-controls="patient-workspace-modal" class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border border-blue-100 bg-blue-50 text-blue-800 hover:bg-blue-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-700">@include('mcu.pasiens.action-icon', ['icon' => 'detail'])</button>
                            @can('manage-mcu')
                                <span title="{{ $canEdit ? 'Edit' : 'Edit terkunci · hasil final/ditutup' }}"><button type="button" data-patient-open="edit" data-url="{{ route('pasien.identity.edit', [$identity, 'modal' => 1]) }}" data-patient-id="{{ $identity->id }}" @disabled(!$canEdit) aria-label="Edit {{ $identity->nama }}" aria-haspopup="dialog" aria-controls="patient-workspace-modal" class="flex h-11 w-11 items-center justify-center rounded-xl border border-amber-200 bg-amber-50 text-amber-700 hover:bg-amber-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-700 disabled:cursor-not-allowed disabled:border-slate-200 disabled:bg-slate-50 disabled:text-slate-300">@include('mcu.pasiens.action-icon', ['icon' => 'edit'])</button></span>
                                <span title="{{ $identity->visits_count > 0 ? 'Pasien sudah memiliki kunjungan.' : 'Hapus' }}"><button type="button" data-patient-delete data-url="{{ route('pasien.identity.destroy', $identity) }}" data-patient-id="{{ $identity->id }}" data-name="{{ $identity->nama }}" @disabled($identity->visits_count > 0) aria-label="Hapus {{ $identity->nama }}{{ $identity->visits_count > 0 ? ' — Pasien sudah memiliki kunjungan.' : '' }}" aria-haspopup="dialog" aria-controls="patient-delete-modal" class="flex h-11 w-11 items-center justify-center rounded-xl border border-red-100 bg-red-50 text-red-700 hover:bg-red-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-700 disabled:cursor-not-allowed disabled:border-slate-200 disabled:bg-slate-50 disabled:text-slate-300">@include('mcu.pasiens.action-icon', ['icon' => 'delete'])</button></span>
                            @endcan
                        </div></td>
                    </tr>
                @empty
                    <tr class="block md:table-row"><td colspan="4" class="block p-12 text-center md:table-cell"><p class="font-semibold text-slate-700">Pasien tidak ditemukan.</p><p class="mt-2 text-sm text-slate-500">Coba ubah pencarian atau filter Anda.</p></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="border-t border-slate-100 px-5 py-4 sm:px-6">{{ $pasiens->links() }}</div>
</div>
