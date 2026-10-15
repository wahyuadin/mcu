<!doctype html>
<html lang="id"><head><meta charset="utf-8"><title>Laporan MCU {{ $peserta->nomor_mcu }}</title>
<style>
@page { size: A4; margin: 100pt 40pt 145pt; }
@page :first { margin: 0; }
body { margin: 0; padding: 0; font-family: DejaVu Sans, sans-serif; font-size: 8pt; color: #2d3748; line-height: 1.35; }
.brand { position: fixed; top: -100pt; left: -40pt; width: 595pt; height: 85pt; }
.footer { position: fixed; bottom: -145pt; left: -40pt; width: 595pt; height: 141.89pt; }
.cover { page-break-after: always; position: relative; height: 830pt; width: 595pt; }
.cover-art { display: block; position: absolute; top: 0; left: 0; width: 595pt; height: 840pt; }
.cover-info { position: absolute; top: 369pt; left: 80pt; width: 475pt; background: white; }
h1, h2, h3 { color: #1a365d; } h1 { font-size: 14pt; text-align: center; } h2 { font-size: 12pt; border-bottom: 1pt solid #1a365d; padding-bottom: 6pt; } h3 { font-size: 9pt; margin: 12pt 0 5pt; }
.section { page-break-before: always; } table { width: 100%; border-collapse: collapse; table-layout: fixed; }
thead { display: table-header-group; } tr { page-break-inside: avoid; } th, td { padding: 4pt; vertical-align: top; border-bottom: .5pt solid #d7e1e9; word-wrap: break-word; }
th { color: #1a365d; text-align: left; } .result { white-space: pre-line; } .abnormal { color: #b42318; }
.health { background: #d69c28; color: white; padding: 9pt; text-align: center; } .photo { width: 95pt; height: 120pt; object-fit: contain; }
.attachment-block { page-break-inside: avoid; } .doctor { text-align: right; margin-top: 15pt; }
p { white-space: pre-line; word-wrap: break-word; } .confidential { color: #b42318; font-size: 7pt; }
</style></head><body>
<img class="brand" src="{{ $brand }}" alt="Nayaka">
<img class="footer" src="{{ $footer }}" alt="Rahasia medis">
<div class="cover"><img class="cover-art" src="{{ $cover }}" alt="Sampul laporan"><div class="cover-info"><table>
@foreach(['Perusahaan' => $peserta->perusahaan->nama_perusahaan, 'Nama' => $peserta->nama_karyawan, 'NIK KTP' => $peserta->nik, 'NIP' => $peserta->nip] as $label => $value)<tr><td style="width:25%">{{ $label }}</td><td>{{ $value ?? '—' }}</td></tr>@endforeach
</table><p class="confidential">RAHASIA MEDIS · Hanya untuk kepentingan medis pasien.</p></div></div>
<h1>LAPORAN MEDICAL CHECK UP</h1><h2>Informasi pasien</h2><table><tr><td style="width:75%"><table>
@foreach(['Nama' => $peserta->nama_karyawan, 'Tanggal lahir' => $peserta->tanggal_lahir?->format('d/m/Y'), 'Jenis kelamin' => $peserta->jenis_kelamin === 'L' ? 'Laki-laki' : 'Perempuan', 'NIK KTP' => $peserta->nik, 'NIP' => $peserta->nip, 'Perusahaan' => $peserta->perusahaan->nama_perusahaan, 'Departemen' => $peserta->bagian, 'Paket' => $peserta->paket->nama_paket, 'No. MCU' => $peserta->nomor_mcu] as $label => $value)<tr><td style="width:30%">{{ $label }}</td><td>{{ $value ?? '—' }}</td></tr>@endforeach
</table></td><td>@if($photo)<img style="width:{{ $photoSize['width'] }}pt;height:{{ $photoSize['height'] }}pt" src="{{ $photo }}" alt="Foto pasien">@else Foto tidak tersedia @endif</td></tr></table>
<p class="health">Status Kesehatan: {{ $exam->status_kelaikan }}</p>
<h2>Kesimpulan</h2><p>{{ $exam->catatan_dokter }}</p><h2>Saran</h2><p>{{ $exam->anjuran_saran }}</p>
<p>Lakukan pemeriksaan kesehatan berkala setidaknya setiap 1 tahun sekali.</p><p class="doctor">Dokter Penanggung Jawab: {{ $doctors[$exam->dokter_id ?? ''] ?? '—' }}</p>
@foreach($sections as $section)
<div class="section"><h1>{{ $section['title'] }}</h1><p>{{ $peserta->nama_karyawan }} · {{ $peserta->nomor_mcu }} · {{ $peserta->perusahaan->nama_perusahaan }}</p>
@foreach($section['groups'] as $group)
@if($group['name'])<h3>{{ $group['name'] }}</h3>@endif
<table><thead><tr><th style="width:{{ $section['kind'] === 'laboratory' ? '28%' : '38%' }}">Pemeriksaan</th><th>Hasil</th>@if($section['kind'] === 'laboratory')<th style="width:12%">Unit</th><th style="width:24%">Nilai Normal</th><th style="width:20%">Keterangan</th>@endif</tr></thead><tbody>
@foreach($group['rows'] as $row)<tr><td class="result">{{ $row['label'] }}</td><td class="result {{ ($row['abnormal'] ?? false) ? 'abnormal' : '' }}">{{ $row['value'] }}</td>@if($section['kind'] === 'laboratory')<td class="result">{{ $row['unit'] }}</td><td class="result">{{ $row['reference'] }}</td><td class="result">{{ $row['remark'] }}</td>@endif</tr>@endforeach
</tbody></table>
@endforeach
@foreach($section['attachments'] as $attachment)<div class="attachment-block"><h3>Lampiran {{ $attachment['label'] }}</h3>@if($attachment['image'])<img style="width:{{ $attachment['width'] }}pt;height:{{ $attachment['height'] }}pt" src="{{ $attachment['image'] }}" alt="{{ $attachment['label'] }}">@else<p>Lampiran PDF disertakan dalam folder lampiran di ZIP.</p>@endif</div> @endforeach
@if(($section['show_doctor'] ?? false) || $section['doctor_id'])<p class="doctor">Dokter Pemeriksa: {{ $doctors[$section['doctor_id'] ?? ''] ?? '—' }}</p>@endif
</div>@endforeach
</body></html>
