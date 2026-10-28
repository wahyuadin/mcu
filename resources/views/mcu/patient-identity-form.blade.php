<form data-patient-identity method="POST" action="{{ route('pasien.update', $peserta) }}" class="grid gap-5 rounded-2xl border border-slate-200 bg-white p-6">
    @csrf @method('PUT')
    <input type="hidden" name="identity_modal" value="1">
    <input type="hidden" name="rencana_kunjungan_id" value="{{ $kunjungan->id }}">
    <input type="hidden" name="paket_id" value="{{ $peserta->paket_id }}">
    <h3 class="text-lg font-bold">Identitas pasien</h3>
    <p class="text-sm text-slate-500">Identitas dan foto disimpan melalui tombol masing-masing. Tanggal MCU dicatat otomatis saat foto pertama pada episode ini disimpan.</p>
    <div class="grid gap-5 sm:grid-cols-2">
        @foreach(['nama_karyawan' => 'Nama pasien', 'nik' => 'NIK identitas', 'nip' => 'NIK/NIP karyawan', 'tempat_lahir' => 'Tempat lahir', 'tanggal_lahir' => 'Tanggal lahir', 'bagian' => 'Departemen', 'jabatan' => 'Jabatan', 'telepon' => 'Telepon'] as $field => $label)
            @include('mcu.field', ['field' => $field, 'label' => $label, 'required' => in_array($field, ['nip', 'tanggal_lahir', 'bagian'], true), 'type' => $field === 'tanggal_lahir' ? 'date' : 'text', 'value' => $field === 'tanggal_lahir' ? $peserta->{$field}?->format('Y-m-d') : $peserta->{$field}, 'placeholder' => match($field) {
                'nama_karyawan' => 'Masukkan nama lengkap sesuai identitas',
                'nik' => 'Masukkan 16 digit NIK',
                'nip' => 'Contoh: EMP-876',
                'tempat_lahir' => 'Contoh: Jakarta',
                'bagian' => 'Contoh: Operasional',
                'jabatan' => 'Contoh: Staf administrasi',
                'telepon' => 'Contoh: 081234567890',
                default => null,
            }])
        @endforeach
        @include('mcu.field', ['field' => 'jenis_kelamin', 'label' => 'Jenis kelamin', 'type' => 'select', 'options' => ['L' => 'Laki-laki', 'P' => 'Perempuan'], 'value' => $peserta->jenis_kelamin, 'selectPlaceholder' => 'Pilih jenis kelamin'])
    </div>
    <div class="flex flex-wrap gap-3"><button type="submit" class="rounded-xl bg-blue-900 px-5 py-3 text-sm font-semibold text-white">Simpan identitas</button><button type="button" data-photo-modal-close class="rounded-xl border border-slate-300 px-5 py-3 text-sm font-semibold">Batal</button></div>
</form>
