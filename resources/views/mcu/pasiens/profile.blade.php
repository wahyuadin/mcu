    @php
        $patientName = $identity?->nama ?? $pasien->nama_karyawan;
        $birthDate = $identity?->tanggal_lahir ?? $pasien->tanggal_lahir;
        $gender = $identity?->jenis_kelamin ?? $pasien->jenis_kelamin;
        $statusLabels = [
            'belum' => 'Terdaftar',
            'proses' => 'Dalam pemeriksaan',
            'siap_finalisasi' => 'Siap finalisasi',
            'selesai' => 'Hasil final',
            'batal' => 'Batal',
            'tidak_hadir' => 'Tidak hadir',
        ];
    @endphp
    <div data-patient-modal-content class="mx-auto grid max-w-7xl gap-7">
        @if(!($profileModal ?? false))
        <a href="{{ route('pasien.index') }}" class="justify-self-start text-sm font-semibold text-slate-500">← Daftar
            pasien</a>
        @endif
        <div class="flex flex-wrap items-start justify-between gap-5">
            <div class="min-w-0">
                <h2 class="break-words text-3xl font-bold text-slate-900">{{ $patientName }}</h2>
                <p class="mt-3 text-sm text-slate-500 sm:text-base">Identitas dan riwayat pekerjaan untuk setiap kunjungan
                    MCU.</p>
            </div>
            @can('manage-mcu')
                <a href="{{ route('pasien.create', ['pasien_id' => $pasien->pasien_id]) }}"
                    class="rounded-xl bg-emerald-700 px-6 py-3 text-center text-sm font-bold text-white hover:bg-emerald-800">+
                    Buat kunjungan MCU</a>
            @endcan
        </div>

        <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,2.3fr)]">
            <section class="min-w-0 rounded-2xl border border-slate-200 bg-white p-6 sm:p-8">
                <h3 class="text-xl font-bold">Profil pasien</h3>
                <div class="my-8 grid justify-items-center gap-4 text-center">
                    @if ($patientPhotoUrl)
                        <img src="{{ $patientPhotoUrl }}"
                            alt="Foto {{ $patientName }}" class="h-40 w-40 rounded-3xl object-cover">
                    @else
                        <div class="flex h-40 w-40 items-center justify-center rounded-3xl bg-blue-50 text-slate-400"><svg
                                aria-hidden="true" class="h-32 w-32" fill="none" stroke="currentColor"
                                viewBox="0 0 24 24">
                                <circle cx="12" cy="8" r="4" stroke-width="1" />
                                <path d="M4 21v-2a8 8 0 0116 0v2" stroke-width="1" />
                            </svg></div>
                    @endif
                    <h4 class="break-words text-xl font-bold">{{ $patientName }}</h4>
                    <p class="max-w-full break-all text-xs text-slate-500">ID pasien · {{ $pasien->pasien_id }}</p>
                </div>
                <dl class="grid gap-6 border-t border-slate-200 pt-6 text-sm">
                    <div>
                        <dt class="text-slate-500">Tempat lahir</dt>
                        <dd class="mt-2 break-words font-bold text-slate-900">
                            {{ $identity?->tempat_lahir ?? ($pasien->tempat_lahir ?? 'Belum dicatat') }}</dd>
                    </div>
                    @foreach (['Tanggal lahir' => $birthDate?->translatedFormat('d F Y'), 'Jenis kelamin' => $gender === 'P' ? 'Perempuan' : 'Laki-laki', 'Umur' => $patientAge === null ? null : $patientAge . ' tahun', 'NPK / No. karyawan' => $currentEmployment->nip, 'Perusahaan' => $currentEmployment->perusahaan?->nama_perusahaan, 'NIK identitas' => $identity?->nik ?? $pasien->nik, 'Telepon' => $identity?->telepon ?? $pasien->telepon] as $label => $value)
                        <div>
                            <dt class="text-slate-500">{{ $label }}</dt>
                            <dd class="mt-2 break-words font-bold text-slate-900">{{ $value ?? 'Belum dicatat' }}</dd>
                        </div>
                    @endforeach
                </dl>
            </section>

            <div class="grid min-w-0 gap-6">
                <section class="grid gap-6 rounded-2xl border border-slate-200 bg-white p-6 sm:p-8">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h3 class="text-xl font-bold">Pekerjaan saat ini</h3>
                            <p class="mt-2 text-sm text-slate-500">Penempatan terakhir yang tercatat pada kunjungan MCU.</p>
                        </div><span class="rounded-full bg-emerald-50 px-5 py-2 text-xs font-bold text-emerald-700">Terbaru
                            tercatat</span>
                    </div>
                    <dl class="grid gap-6 text-sm sm:grid-cols-2">
                        @foreach (['Perusahaan' => $currentEmployment->perusahaan?->nama_perusahaan, 'Departemen' => $currentEmployment->bagian, 'No. karyawan' => $currentEmployment->nip, 'Mulai penempatan' => null, 'Jabatan' => $currentEmployment->jabatan, 'Tanggal tercatat' => $currentEmployment->tanggal_mcu?->translatedFormat('d F Y')] as $label => $value)
                            <div>
                                <dt class="text-slate-500">{{ $label }}</dt>
                                <dd class="mt-2 break-words text-base font-bold">{{ $value ?? 'Belum dicatat' }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </section>

                <section class="grid gap-5 rounded-2xl border border-slate-200 bg-white p-6 sm:p-8">
                    <div>
                        <h3 class="text-xl font-bold">Riwayat pekerjaan</h3>
                        <p class="mt-2 text-sm text-slate-500">Penempatan pada setiap MCU. Tanggal tercatat bukan tanggal mulai atau akhir masa kerja.</p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="bg-slate-50 text-slate-500">
                                <tr>
                                    <th class="rounded-l-xl p-3 font-semibold">Departemen / perusahaan</th>
                                    <th class="p-3 font-semibold">Tanggal tercatat</th>
                                    <th class="rounded-r-xl p-3 font-semibold">Catatan</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($episodes as $episode)
                                    <tr>
                                        <td class="p-3"><strong
                                                class="block">{{ $episode->bagian ?? 'Belum dicatat' }}</strong><span
                                                class="mt-1 block text-xs text-slate-500">{{ $episode->perusahaan->nama_perusahaan }}
                                                · {{ $episode->jabatan ?? 'Jabatan belum dicatat' }}</span></td>
                                        <td class="whitespace-nowrap p-3 text-slate-500">
                                            {{ $episode->tanggal_mcu?->translatedFormat('d M Y') ?? 'Belum dicatat' }}</td>
                                        <td class="p-3"><span
                                                class="whitespace-nowrap rounded-full px-4 py-2 text-xs font-bold {{ $episode->id === $currentEmployment->id ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500' }}">{{ $episode->id === $currentEmployment->id ? 'Terbaru' : 'Riwayat MCU' }}</span>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="3" class="p-5 text-slate-500">Belum ada riwayat pekerjaan.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="grid gap-5 rounded-2xl border border-slate-200 bg-white p-6 sm:p-8">
                    <div>
                        <h3 class="text-xl font-bold">Riwayat MCU</h3>
                        <p class="mt-2 text-sm text-slate-500">Kunjungan tetap menyimpan penempatan saat pemeriksaan.</p>
                    </div>
                    <div class="divide-y divide-slate-200">
                        @forelse ($episodes as $episode)
                            <div class="grid items-center gap-4 py-4 sm:grid-cols-[minmax(0,1fr)_auto_auto]">
                                <div class="min-w-0"><strong
                                        class="block">{{ $episode->tanggal_mcu?->translatedFormat('d M Y') ?? 'Tanggal MCU belum diisi' }}</strong>
                                    <p class="mt-2 break-all text-sm text-slate-500">{{ $episode->nomor_mcu }}</p>
                                    <p class="mt-1 text-xs text-slate-500">{{ $episode->perusahaan->nama_perusahaan }} ·
                                        {{ $episode->bagian ?? 'Departemen belum dicatat' }} ·
                                        {{ $episode->paket->nama_paket }}</p>
                                </div>
                                <div class="grid gap-2"><span
                                        class="text-xs font-semibold text-slate-600">{{ $episode->hasStoredPhoto() ? 'Present' : 'Not Present' }}</span><span
                                        class="text-xs text-slate-500">{{ $statusLabels[$episode->status_pemeriksaan] ?? $episode->status_pemeriksaan }}</span>
                                </div>
                                @can('examine-mcu')
                                    <a href="{{ route('pemeriksaan.edit', [$episode->rencanaKunjungan, $episode]) }}"
                                        class="rounded-xl border border-slate-300 px-5 py-3 text-center text-sm font-bold hover:border-emerald-700 hover:bg-emerald-50">Lihat
                                    kunjungan</a>@else<a
                                        href="{{ route('kunjungan-mcu.show', $episode->rencanaKunjungan) }}"
                                        class="rounded-xl border border-slate-300 px-5 py-3 text-center text-sm font-bold">Lihat
                                        kunjungan</a>
                                @endcan
                            </div>
                        @empty
                            <p class="py-5 text-sm text-slate-500">Pasien belum memiliki kunjungan MCU.</p>
                        @endforelse
                    </div>
                </section>
            </div>
        </div>

        @if ($pasien->alasan_status)
            <p class="rounded-xl bg-amber-50 p-4 text-sm text-amber-900">Alasan status terakhir:
                {{ $pasien->alasan_status }}</p>
        @endif
    </div>
