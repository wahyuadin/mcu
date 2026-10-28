<form data-patient-edit-form data-patient-modal-content action="{{ route('pasien.identity.update', $identity) }}" method="POST" enctype="multipart/form-data" class="grid gap-6">
    @csrf @method('PUT')
    <input type="hidden" name="patient_workspace" value="1">
    @if($currentEmployment->exists)<input type="hidden" name="episode_id" value="{{ $currentEmployment->id }}">@endif
    <div class="min-w-0"><h3 class="break-words text-2xl font-bold text-slate-900">{{ $identity->nama }}</h3><p class="mt-2 text-sm text-slate-500">Perbarui profil pasien dan penempatan pada kunjungan terbaru. Riwayat kunjungan sebelumnya tidak diubah.</p></div>
    <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,2.3fr)]">
        <section class="grid min-w-0 gap-5 rounded-2xl border border-slate-200 bg-white p-6">
            <h3 class="text-lg font-bold">Profil pasien</h3>
            <div class="grid justify-items-center gap-4 text-center">
                <img data-patient-photo-preview src="{{ $patientPhotoUrl ?? '' }}" @if(!$patientPhotoUrl) hidden @endif alt="Foto {{ $identity->nama }}" class="h-40 w-40 rounded-3xl object-cover">
                <div data-patient-photo-placeholder @if($patientPhotoUrl) hidden @endif class="flex h-40 w-40 items-center justify-center rounded-3xl bg-blue-50 text-4xl font-bold text-blue-800">{{ mb_strtoupper(mb_substr($identity->nama, 0, 2)) }}</div>
                <p class="break-words font-bold">{{ $identity->nama }}</p>
            </div>
            <label class="grid gap-2 text-sm font-semibold">Perbarui foto<input data-patient-photo-input type="file" name="foto_pasien" accept="image/jpeg,image/png,image/webp" class="min-h-11 min-w-0 w-full text-xs font-normal file:mr-2 file:rounded-lg file:border-0 file:bg-blue-50 file:px-3 file:py-3 file:font-semibold file:text-blue-900"></label>
            <p class="text-xs leading-5 text-slate-500">JPG, PNG, atau WebP, maksimal 5 MB. Foto baru dapat ditinjau sebelum disimpan.</p>
            <button data-patient-photo-reset type="button" hidden class="min-h-11 rounded-xl border border-slate-300 px-3 text-sm font-semibold">Batalkan foto baru</button>
            <p class="break-all border-t border-slate-100 pt-4 text-xs text-slate-400">ID pasien · {{ $identity->id }}</p>
        </section>
        <div class="grid min-w-0 gap-6">
            <section class="grid gap-5 rounded-2xl border border-slate-200 bg-white p-6">
                <div><h3 class="text-lg font-bold">Identitas pasien</h3><p class="mt-1 text-sm text-slate-500">Kolom bertanda * wajib diisi.</p></div>
                <div class="grid gap-5 sm:grid-cols-2">
                    @foreach(['nama' => ['Nama lengkap *', 'text'], 'nik' => ['NIK identitas (opsional)', 'text'], 'tempat_lahir' => ['Tempat lahir', 'text'], 'tanggal_lahir' => ['Tanggal lahir *', 'date'], 'telepon' => ['Telepon', 'tel']] as $field => [$label, $type])
                        <label class="grid min-w-0 gap-2 text-sm font-semibold {{ $field === 'nama' ? 'sm:col-span-2' : '' }}">{{ $label }}<input name="{{ $field }}" type="{{ $type }}" value="{{ $field === 'tanggal_lahir' ? $identity->tanggal_lahir?->format('Y-m-d') : $identity->$field }}" @required(in_array($field, ['nama', 'tanggal_lahir'])) @if($field === 'nik') inputmode="numeric" pattern="[0-9]{16}" maxlength="16" @elseif($type !== 'date') maxlength="255" @endif class="min-h-12 min-w-0 rounded-xl border border-slate-300 px-3 text-base focus:border-blue-800 focus:outline-none focus:ring-2 focus:ring-blue-100"></label>
                    @endforeach
                    <label class="grid gap-2 text-sm font-semibold">Jenis kelamin *<select name="jenis_kelamin" required class="min-h-12 rounded-xl border border-slate-300 bg-white px-3 text-base"><option value="L" @selected($identity->jenis_kelamin === 'L')>Laki-laki</option><option value="P" @selected($identity->jenis_kelamin === 'P')>Perempuan</option></select></label>
                </div>
            </section>
            <section class="grid gap-5 rounded-2xl border border-slate-200 bg-white p-6">
                <div class="flex flex-wrap items-center justify-between gap-3"><h3 class="text-lg font-bold">Pekerjaan saat ini</h3><span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-700">Terbaru tercatat</span></div>
                @if($currentEmployment->exists)
                    <div class="rounded-xl bg-slate-50 p-4"><strong class="break-words text-sm">{{ $currentEmployment->perusahaan?->nama_perusahaan ?? '—' }}</strong><p class="mt-1 text-xs text-slate-500">{{ $currentEmployment->nomor_mcu }} · {{ $currentEmployment->paket?->nama_paket ?? '—' }}</p></div>
                    <div class="grid gap-5 sm:grid-cols-2">
                        @foreach(['nip' => 'NPK / No. karyawan *', 'bagian' => 'Departemen *', 'jabatan' => 'Jabatan'] as $field => $label)
                            <label class="grid min-w-0 gap-2 text-sm font-semibold">{{ $label }}<input name="{{ $field }}" value="{{ $currentEmployment->$field }}" @required($field !== 'jabatan') maxlength="{{ $field === 'nip' ? 50 : 255 }}" class="min-h-12 min-w-0 rounded-xl border border-slate-300 px-3 text-base focus:border-blue-800 focus:outline-none focus:ring-2 focus:ring-blue-100"></label>
                        @endforeach
                    </div>
                    <p class="text-xs leading-5 text-slate-500">Perusahaan, kunjungan, dan paket tetap mengikuti registrasi MCU. Pengubahan di sini tidak memindahkan peserta.</p>
                @else
                    <p class="text-sm text-slate-500">Belum ada kunjungan MCU. Data pekerjaan akan dicatat saat pasien didaftarkan.</p>
                @endif
            </section>
        </div>
    </div>
    <div class="sticky bottom-0 flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <p data-patient-form-status role="alert" tabindex="-1" class="min-w-0 flex-1 text-sm text-red-700"></p>
        <div class="flex gap-3"><button data-patient-modal-close type="button" class="min-h-12 rounded-xl border border-slate-300 px-5 font-semibold">Batal</button><button data-patient-save class="min-h-12 rounded-xl bg-[#00008B] px-5 font-bold text-white disabled:opacity-50">Simpan perubahan</button></div>
    </div>
</form>
