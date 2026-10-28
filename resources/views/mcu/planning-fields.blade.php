@php($editingPlan = $editingPlan ?? false)
<label class="grid gap-2 text-sm font-semibold">Perusahaan *<select name="perusahaan_id" required class="min-h-12 min-w-0 rounded-xl border border-slate-300 bg-white px-3">
    <option value="">Pilih perusahaan</option>
    @foreach($perusahaans as $company)<option value="{{ $company->id }}" @selected(!$editingPlan && old('perusahaan_id') === $company->id)>{{ $company->nama_perusahaan }}</option>@endforeach
</select>@if(!$editingPlan) @error('perusahaan_id')<span class="text-xs text-red-700">{{ $message }}</span>@enderror @endif</label>
@foreach(['nomor_kunjungan' => ['Nomor PKS *', 'Contoh: MCU-2026-001'], 'periode_kunjungan' => ['Periode kunjungan *', 'Contoh: Oktober 2026']] as $field => [$label, $placeholder])
    <label class="grid gap-2 text-sm font-semibold">{{ $label }}<input name="{{ $field }}" value="{{ $editingPlan ? '' : old($field) }}" required maxlength="255" placeholder="{{ $placeholder }}" class="min-h-12 min-w-0 rounded-xl border border-slate-300 px-3">@if(!$editingPlan) @error($field)<span class="text-xs text-red-700">{{ $message }}</span>@enderror @endif</label>
@endforeach
<div class="grid gap-4 sm:grid-cols-2">
    @foreach(['tgl_mulai' => 'Tanggal mulai pelaksanaan', 'tgl_selesai' => 'Tanggal selesai pelaksanaan'] as $field => $label)
        <label class="grid gap-2 text-sm font-semibold">{{ $label }}<input type="date" name="{{ $field }}" value="{{ $editingPlan ? '' : old($field, now()->addDays($field === 'tgl_selesai' ? 14 : 0)->format('Y-m-d')) }}" class="min-h-12 min-w-0 rounded-xl border border-slate-300 px-3">@if(!$editingPlan) @error($field)<span class="text-xs text-red-700">{{ $message }}</span>@enderror @endif</label>
    @endforeach
</div>
<p class="text-xs text-slate-500">Tanggal selesai otomatis 14 hari setelah tanggal mulai dan tetap dapat diedit.</p>
<label class="grid gap-2 text-sm font-semibold">Jumlah peserta direncanakan *<input type="number" name="jumlah_peserta_direncanakan" value="{{ $editingPlan ? '' : old('jumlah_peserta_direncanakan') }}" placeholder="Contoh: 529" min="1" max="1000000" required class="min-h-12 min-w-0 rounded-xl border border-slate-300 px-3">@if(!$editingPlan) @error('jumlah_peserta_direncanakan')<span class="text-xs text-red-700">{{ $message }}</span>@enderror @endif</label>
<p class="text-xs text-slate-500">Target peserta untuk ringkasan dashboard, bukan batas kuota pendaftaran.</p>
