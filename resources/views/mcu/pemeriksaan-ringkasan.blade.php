@php
    $pendingExaminations = array_filter($examinations, fn ($item) => $item['status'] !== 'Selesai');
    $priorityKey = isset($pendingExaminations['laboratorium']) ? 'laboratorium' : array_key_first($pendingExaminations);
    $priority = $priorityKey !== null ? $pendingExaminations[$priorityKey] : null;
    $currentStage = $exam->status === 'final' ? 4 : ($pendingExaminations !== [] ? 1 : ($progress['kesimpulan']['status'] !== 'Selesai' ? 3 : 2));
@endphp
<div class="grid items-start gap-6 xl:grid-cols-[minmax(0,2.5fr)_minmax(0,1fr)]">
    <section class="min-w-0 rounded-2xl border border-slate-200 bg-white p-6 sm:p-8">
        <div class="flex flex-wrap items-start justify-between gap-3"><div><h3 class="text-xl font-bold">Daftar pemeriksaan</h3><p class="mt-2 text-sm text-slate-500">Paket {{ $peserta->paket->nama_paket }} · Dokter PJ: {{ $exam->dokter?->nama_dokter ?? 'Belum ditentukan' }}</p></div><a href="{{ route('pemeriksaan.edit', [$kunjungan, $peserta, 'tab' => 'identitas']) }}" class="rounded-lg px-3 py-2 text-sm font-semibold text-blue-800 hover:bg-blue-50">Identitas MCU →</a></div>
        <div class="my-7 grid gap-3">
            <div class="flex items-center justify-between gap-3 text-sm font-semibold text-slate-500"><span>Kelengkapan hasil</span><span>{{ $completedExaminations }} dari {{ count($examinations) }} bagian</span></div>
            <progress value="{{ $completedExaminations }}" max="{{ max(1, count($examinations)) }}" aria-label="Kelengkapan hasil pemeriksaan" class="exam-completion h-2.5 w-full overflow-hidden rounded-full">{{ $completionPercent }}%</progress>
            <p class="text-xs text-slate-500">Identitas MCU dan pemeriksaan dalam paket dihitung. Kesimpulan dan review tetap wajib sebelum finalisasi.</p>
        </div>
        <div class="divide-y divide-slate-200">
            @forelse($examinations as $key => $item)
                <div data-examination="{{ $key }}" class="grid items-center gap-4 py-5 sm:grid-cols-[minmax(0,1fr)_auto_auto]">
                    <div class="flex min-w-0 items-start gap-4"><span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-emerald-700"><svg aria-hidden="true" class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v15h14V7a2 2 0 00-2-2h-2M9 3h6v4H9zM9 12h6m-6 4h6"/></svg></span><div class="min-w-0"><h4 class="font-bold">{{ $item['label'] }}</h4><p class="mt-1 text-sm text-slate-500">{{ $item['description'] }}</p>@if($item['skipped'])<p class="mt-1 text-xs text-amber-700">Tidak dilakukan — alasan tercatat</p>@endif</div></div>
                    <span class="justify-self-start rounded-full px-5 py-2 text-xs font-bold sm:min-w-32 sm:text-center {{ match($item['status']) { 'Selesai' => 'bg-emerald-50 text-emerald-700', 'Draft' => 'bg-blue-50 text-blue-800', default => 'bg-amber-50 text-amber-800' } }}">{{ $item['status'] === 'Selesai' ? 'Selesai diisi' : $item['status'] }}</span>
                    <a href="{{ route('pemeriksaan.edit', [$kunjungan, $peserta, 'tab' => $item['tab']]).($item['anchor'] ? '#'.$item['anchor'] : '') }}" aria-label="Isi / lihat {{ $item['label'] }}" class="rounded-xl border border-slate-300 px-5 py-3 text-center text-sm font-bold text-slate-900 hover:border-emerald-700 hover:bg-emerald-50">Isi / lihat</a>
                </div>
            @empty
                <p class="rounded-xl bg-slate-50 p-5 text-sm text-slate-500">Belum ada pemeriksaan yang termasuk dalam paket ini.</p>
            @endforelse
        </div>
    </section>

    <aside aria-label="Panduan penyelesaian pemeriksaan" class="grid min-w-0 gap-6">
        <section class="grid gap-5 rounded-2xl border border-slate-200 bg-white p-6">
            <h3 class="text-lg font-bold">Tahapan pemeriksaan</h3>
            <ol class="grid gap-5">@foreach([1 => 'Pencatatan hasil', 2 => 'Review kelengkapan', 3 => 'Kesimpulan dokter', 4 => 'Finalisasi & laporan'] as $number => $label)<li @if($number === $currentStage) aria-current="step" @endif class="flex items-center gap-4 text-sm {{ $number === $currentStage ? 'font-bold text-slate-900' : 'text-slate-500' }}"><span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full font-bold {{ $number === $currentStage ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500' }}">{{ $number }}</span>{{ $label }}</li>@endforeach</ol>
            <p class="text-xs leading-relaxed text-slate-500">Panduan alur satu operator, bukan status verifikasi terpisah. Review dan kesimpulan tetap dapat dibuka kapan pun.</p>
        </section>
        <section class="grid gap-4 rounded-2xl border border-slate-200 bg-white p-6">
            <h3 class="text-lg font-bold">{{ $exam->status === 'final' ? 'Hasil terkunci' : 'Perlu dilengkapi' }}</h3>
            @if($exam->status === 'final')
                <p class="text-sm leading-relaxed text-slate-500">Hasil telah difinalisasi. Koreksi melalui revisi beralasan pada halaman Review.</p>
            @else
                @foreach($pendingExaminations as $item)<p class="text-sm text-slate-500">{{ $item['label'] }} {{ $item['status'] === 'Draft' ? 'masih dalam draft.' : 'belum diisi.' }}</p>@endforeach
                @if($progress['kesimpulan']['status'] !== 'Selesai')<p class="text-sm text-slate-500">Kesimpulan dokter belum selesai.</p>@endif
                @if(blank($peserta->tanggal_lahir) || blank($peserta->nip) || blank($peserta->bagian) || blank($peserta->tanggal_mcu))<a href="{{ route('pemeriksaan.edit', [$kunjungan, $peserta, 'tab' => 'identitas']) }}" class="text-sm font-semibold text-blue-800">Identitas MCU perlu dilengkapi →</a>@endif
                @if($finalizationErrors === [])<p class="text-sm text-emerald-700">Seluruh bagian lengkap. Hasil siap ditinjau dan difinalisasi.</p>@endif
            @endif
            @if($priority && !$readonly)
                <a href="{{ route('pemeriksaan.edit', [$kunjungan, $peserta, 'tab' => $priority['tab']]).($priority['anchor'] ? '#'.$priority['anchor'] : '') }}" class="rounded-xl bg-emerald-700 px-4 py-3 text-center text-sm font-bold text-white hover:bg-emerald-800">Lengkapi {{ mb_strtolower($priority['label']) }}</a>
            @else
                <a href="{{ route('pemeriksaan.edit', [$kunjungan, $peserta, 'tab' => 'review']) }}" class="rounded-xl bg-emerald-700 px-4 py-3 text-center text-sm font-bold text-white hover:bg-emerald-800">Buka review hasil</a>
            @endif
        </section>
        <section class="grid gap-4 rounded-2xl border border-slate-200 bg-white p-6">
            <h3 class="text-lg font-bold">Dokter penanggung jawab</h3>
            <p class="text-lg font-bold">{{ $exam->dokter?->nama_dokter ?? 'Belum ditentukan' }}</p>
            <p class="text-sm leading-relaxed text-slate-500">Kesimpulan dan kelayakan diinput berdasarkan penilaian dokter, bukan ditentukan otomatis oleh program.</p>
            <a href="{{ route('pemeriksaan.edit', [$kunjungan, $peserta, 'tab' => 'kesimpulan']) }}" class="justify-self-start text-sm font-semibold text-blue-800">{{ $readonly ? 'Lihat kesimpulan' : 'Isi kesimpulan' }} →</a>
        </section>
    </aside>
</div>
<section class="grid gap-4 rounded-2xl border border-slate-200 bg-white p-6">
    <h3 class="text-lg font-bold">Review & penerbitan hasil</h3>
    <p class="text-sm text-slate-500">{{ $exam->status === 'final' ? 'Hasil final tersedia. Buka review untuk melihat riwayat atau mengajukan revisi.' : ($readonly ? 'Pemeriksaan hanya dapat dilihat. Kunjungan ditutup atau peserta tidak aktif.' : ($finalizationErrors === [] ? 'Data lengkap. Cocokkan dengan hasil sumber sebelum finalisasi.' : 'Hasil belum dapat difinalisasi. Lengkapi pemeriksaan, identitas, dan kesimpulan yang masih kurang.')) }}</p>
    <div class="flex flex-wrap gap-3">
        <a target="_blank" rel="noopener" href="{{ route('pemeriksaan.report', [$kunjungan, $peserta]) }}" class="rounded-xl border border-slate-300 px-5 py-3 text-sm font-semibold">Pratinjau laporan ↗</a>
        <a href="{{ route('pemeriksaan.edit', [$kunjungan, $peserta, 'tab' => 'review']) }}" class="rounded-xl border border-slate-300 px-5 py-3 text-sm font-semibold">Review hasil</a>
        @if($exam->status !== 'final')
            @if($finalizationErrors !== [] || $readonly)<button type="button" disabled class="cursor-not-allowed rounded-xl bg-slate-100 px-5 py-3 text-sm font-semibold text-slate-400">Finalisasi terkunci</button>
            @else<a href="{{ route('pemeriksaan.edit', [$kunjungan, $peserta, 'tab' => 'review']) }}" class="rounded-xl bg-emerald-700 px-5 py-3 text-sm font-semibold text-white">Lanjut ke finalisasi →</a>@endif
        @endif
    </div>
</section>
