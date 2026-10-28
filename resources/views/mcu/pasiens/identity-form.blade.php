@extends('layouts.mcu')
@section('title', 'Edit identitas pasien')
@section('content')
<div class="mx-auto grid max-w-2xl gap-6">
    <a href="{{ route('pasien.index') }}" class="text-sm font-semibold text-slate-500">← Daftar pasien</a>
    <form action="{{ route('pasien.identity.update', $identity) }}" method="POST" class="grid gap-5 rounded-2xl border border-slate-200 bg-white p-6">@csrf @method('PUT')
        <div><h2 class="text-xl font-bold">Edit identitas pasien</h2><p class="mt-2 text-sm text-slate-500">Perubahan identitas master tidak mengubah snapshot dan hasil episode MCU lama.</p></div>
        @foreach(['nama' => 'Nama lengkap *', 'nik' => 'NIK identitas (KTP, opsional)', 'tempat_lahir' => 'Tempat lahir', 'tanggal_lahir' => 'Tanggal lahir *', 'telepon' => 'Telepon'] as $field => $label)
            <label class="grid gap-2 text-sm font-semibold">{{ $label }}<input name="{{ $field }}" type="{{ $field === 'tanggal_lahir' ? 'date' : 'text' }}" value="{{ old($field, $field === 'tanggal_lahir' ? $identity->tanggal_lahir?->format('Y-m-d') : $identity->$field) }}" @required(in_array($field, ['nama', 'tanggal_lahir'])) @if($field === 'nik') inputmode="numeric" pattern="[0-9]{16}" maxlength="16" @elseif($field !== 'tanggal_lahir') maxlength="255" @endif class="min-h-12 min-w-0 rounded-xl border border-slate-300 px-3">@error($field)<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label>
        @endforeach
        <label class="grid gap-2 text-sm font-semibold">Jenis kelamin *<select name="jenis_kelamin" required class="min-h-12 rounded-xl border border-slate-300 px-3"><option value="L" @selected(old('jenis_kelamin', $identity->jenis_kelamin) === 'L')>Laki-laki</option><option value="P" @selected(old('jenis_kelamin', $identity->jenis_kelamin) === 'P')>Perempuan</option></select>@error('jenis_kelamin')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label>
        <button class="min-h-12 rounded-xl bg-[#00008B] px-5 font-bold text-white">Simpan perubahan</button>
    </form>
</div>
@endsection
