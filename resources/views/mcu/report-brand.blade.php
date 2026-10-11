<div class="brand-header">
    <div class="provider"><img src="{{ asset('Template/assets/logo-nayaka.jpeg') }}" alt="Nayaka Era Husada"><span>Medical Check-Up</span></div>
    <div class="company-brand">
        @if($peserta->perusahaan->logo_path)
            <img src="{{ route('perusahaan.logo', $peserta->perusahaan) }}" alt="Logo {{ $peserta->perusahaan->nama_perusahaan }}">
        @endif
        <strong>{{ $peserta->perusahaan->nama_perusahaan }}</strong>
    </div>
</div>
<div class="brand-rule"></div>
