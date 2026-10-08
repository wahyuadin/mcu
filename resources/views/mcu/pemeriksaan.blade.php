@extends('layouts.mcu')
@section('title', 'Pemeriksaan '.$peserta->nama_karyawan)
@section('content')
<p class="mb-6 text-slate-500">{{ $peserta->paket->nama_paket }} · {{ $kunjungan->nomor_kunjungan }} · <a href="{{ route('rencana-kunjungan.show', $kunjungan) }}" class="text-[#00008B] underline">Kembali ke kunjungan</a></p>
<form method="POST" action="{{ route('pemeriksaan.update', [$kunjungan, $peserta]) }}" class="grid gap-6">@csrf @method('PUT')
    @foreach($catalog as $group => $items)
    @php($active = array_filter($items, fn ($label, $key) => $peserta->paket->isItemActive($key), ARRAY_FILTER_USE_BOTH))
    @if(count($active))
    <fieldset class="rounded-2xl bg-white p-6 shadow-sm"><legend class="px-3 text-lg font-bold">{{ ucwords(str_replace('_', ' ', $group)) }}</legend>
        <div class="grid gap-4 sm:grid-cols-2">
        @foreach($active as $key => $label)
            @if($key === 'bmi')
                <p class="rounded-lg bg-slate-50 p-3 text-sm">BMI dihitung otomatis dari tinggi dan berat saat disimpan. Nilai sekarang: <strong>{{ $peserta->anamnesa?->bmi ?? '—' }}</strong> ({{ $peserta->anamnesa?->kategori_bmi ?? '—' }})</p>
            @elseif(in_array($key, $historyKeys))
                <label class="grid gap-1 text-sm font-medium">{{ $label }}<select name="history[{{ $key }}]" class="rounded-lg border border-slate-300 p-3"><option value="0" @selected(!old('history.'.$key, ($peserta->anamnesa?->riwayat_penyakit_dahulu ?? [])[$key] ?? false))>Tidak</option><option value="1" @selected(old('history.'.$key, ($peserta->anamnesa?->riwayat_penyakit_dahulu ?? [])[$key] ?? false))>Ya</option></select></label>
            @else
                @foreach($grouped[$key] ?? [$key] as $field)
                <label class="grid gap-1 text-sm font-medium">{{ $label }} @if(count($grouped[$key] ?? []) > 1) {{ strtoupper(str_contains($field, '_od_') ? 'OD' : (str_contains($field, '_os_') ? 'OS' : '')) }} @endif
                    <input name="{{ $field }}" value="{{ old($field, $peserta->anamnesa?->{$field}) }}" type="{{ in_array($field, ['tinggi_badan','berat_badan','lingkar_pinggang','suhu_badan','nadi','pernapasan']) ? 'number' : 'text' }}" @if(in_array($field, ['tinggi_badan','berat_badan','lingkar_pinggang','suhu_badan'])) step="0.01" @endif class="rounded-lg border border-slate-300 p-3"></label>
                @endforeach
            @endif
        @endforeach
        </div>
    </fieldset>
    @endif
    @endforeach
    <section class="grid gap-4 rounded-2xl bg-white p-6 shadow-sm"><h2 class="text-lg font-bold">Kesimpulan</h2>
        <label class="grid gap-1 text-sm">Catatan dokter<textarea name="catatan_dokter" class="rounded-lg border border-slate-300 p-3">{{ old('catatan_dokter', $peserta->anamnesa?->catatan_dokter) }}</textarea></label>
        <label class="grid gap-1 text-sm">Anjuran<textarea name="anjuran_saran" class="rounded-lg border border-slate-300 p-3">{{ old('anjuran_saran', $peserta->anamnesa?->anjuran_saran) }}</textarea></label>
        <label class="grid gap-1 text-sm">Status kelaikan<select name="status_kelaikan" class="rounded-lg border border-slate-300 p-3"><option value="">Belum ditentukan</option>@foreach(['Fit to Work','Fit with Note','Temporarily Unfit','Unfit'] as $status)<option value="{{ $status }}" @selected(old('status_kelaikan', $peserta->anamnesa?->status_kelaikan) === $status)>{{ $status }}</option>@endforeach</select></label>
        <div class="flex flex-wrap gap-3"><button name="status" value="draft" class="rounded-lg border border-[#00008B] px-6 py-3 font-semibold text-[#00008B]">Simpan draft</button><button name="status" value="final" class="rounded-lg bg-[#00008B] px-6 py-3 font-semibold text-white">Finalisasi</button></div>
    </section>
</form>
@endsection
