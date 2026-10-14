<div class="report-identity">
    <h1>LAPORAN MEDICAL CHECK UP</h1>
    <dl class="identity-grid">
        <div>
            <div class="identity-row"><dt>Nama</dt><dd>{{ $peserta->nama_karyawan }}</dd></div>
            <div class="identity-row"><dt>NIK KTP</dt><dd>{{ $peserta->nik ?? '—' }}</dd></div>
            <div class="identity-row"><dt>Tgl. Lahir</dt><dd>{{ $peserta->tanggal_lahir?->format('d/m/Y') ?? '—' }} {{ $peserta->usia !== null ? '('.$peserta->usia.' thn)' : '' }}</dd></div>
            <div class="identity-row"><dt>NIP / Karyawan</dt><dd>{{ $peserta->nip ?? '—' }}</dd></div>
        </div>
        <div>
            <div class="identity-row"><dt>Jenis Kelamin</dt><dd>{{ $peserta->jenis_kelamin === 'L' ? 'Laki-Laki' : 'Perempuan' }}</dd></div>
            <div class="identity-row"><dt>No. MCU</dt><dd>{{ $peserta->nomor_mcu }}</dd></div>
            <div class="identity-row"><dt>Departemen</dt><dd>{{ $peserta->bagian ?? '—' }}</dd></div>
        </div>
    </dl>
</div>
