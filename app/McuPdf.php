<?php

namespace App;

use App\Models\Dokter;
use App\Models\Karyawan;
use Dompdf\Dompdf;
use Dompdf\Image\Cache as ImageCache;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class McuPdf
{
    /** @var array<string, string> */
    private array $brandAssets = [];

    public function __construct(private McuReport $reports) {}

    public function render(Karyawan $participant, ?string $workingDirectory = null): string
    {
        gc_collect_cycles();
        $participant->loadMissing('anamnesa', 'paket', 'perusahaan');
        if (! $participant->is_closing || $participant->status_pemeriksaan !== 'selesai' || $participant->anamnesa?->status !== 'final') {
            throw new RuntimeException('Laporan belum dirilis.');
        }
        $sections = $this->reports->sections($participant, $participant->anamnesa);
        foreach ($sections as &$section) {
            foreach ($section['groups'] as &$group) {
                $rows = [];
                foreach ($group['rows'] as $row) {
                    $fields = [];
                    foreach (['label', 'value', 'unit', 'reference', 'remark'] as $field) {
                        $width = $section['kind'] === 'laboratory' ? (['label' => 28, 'value' => 14, 'unit' => 14, 'reference' => 24, 'remark' => 20][$field]) : ($field === 'label' ? 30 : 70);
                        $fields[$field] = $this->fragments((string) ($row[$field] ?? ''), $width);
                    }
                    $count = max(array_map('count', $fields));
                    for ($index = 0; $index < $count; $index++) {
                        $fragment = $row;
                        foreach ($fields as $field => $parts) {
                            $fragment[$field] = $parts[$index] ?? '';
                        }
                        $rows[] = $fragment;
                    }
                }
                $group['rows'] = $rows;
            }
            unset($group);
            foreach ($section['attachments'] as &$attachment) {
                $attachment['image'] = $attachment['mime'] === 'application/pdf' ? null : $this->image($attachment['path'], $participant);
                if ($attachment['image']) {
                    $attachment += $this->imageSize($attachment['image'], 515, 350);
                }
            }
            unset($attachment);
        }
        unset($section);
        $photo = $participant->foto ? $this->image($participant->foto, $participant) : null;
        $options = new Options;
        $temporaryDirectory = ($workingDirectory ?? 'mcu-pdf').'/'.Str::uuid();
        Storage::disk('local')->makeDirectory($temporaryDirectory);
        $options->set('tempDir', Storage::disk('local')->path($temporaryDirectory));
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('isJavascriptEnabled', false);
        $options->set('chroot', [public_path(), Storage::disk('local')->path('mcu/'.$participant->id)]);
        $options->set('defaultFont', 'DejaVu Sans');
        $pdf = new Dompdf($options);
        $pdf->setPaper('A4');
        try {
            $pdf->loadHtml(view('mcu.laporan-pdf', [
                'peserta' => $participant, 'exam' => $participant->anamnesa, 'sections' => $sections,
                'photo' => $photo,
                'photoSize' => $photo ? $this->imageSize($photo, 95, 120) : null,
                'doctors' => Dokter::withTrashed()->pluck('nama_dokter', 'id')->all(),
                'brand' => $this->brandAssets['letterhead'] ??= 'data:image/jpeg;base64,'.base64_encode(file_get_contents(public_path('Template/assets/report-pdf-letterhead.jpg'))),
                'footer' => $this->brandAssets['footer'] ??= 'data:image/jpeg;base64,'.base64_encode(file_get_contents(public_path('Template/assets/report-pdf-footer.jpg'))),
                'cover' => $this->brandAssets['cover'] ??= 'data:image/jpeg;base64,'.base64_encode(file_get_contents(public_path('Template/assets/report-pdf-cover.jpg'))),
            ])->render());
            $pdf->render();
            $pdf->getCanvas()->page_text(520, 822, '{PAGE_NUM} / {PAGE_COUNT}', $pdf->getFontMetrics()->getFont('DejaVu Sans'), 7, [0.4, 0.45, 0.5]);

            return $pdf->output();
        } finally {
            ImageCache::clear();
            Storage::disk('local')->deleteDirectory($temporaryDirectory);
            unset($pdf, $sections);
            gc_collect_cycles();
        }
    }

    /** @return list<string> */
    private function fragments(string $text, int $width): array
    {
        $lines = [];
        foreach (preg_split('/\R/u', $text) ?: [''] as $line) {
            $current = '';
            foreach (preg_split('/(?<=\s)/u', $line) ?: [''] as $word) {
                if ($current !== '' && mb_strlen($current.$word) > $width) {
                    $lines[] = rtrim($current);
                    $current = '';
                }
                $pieces = mb_str_split($word, $width) ?: [''];
                while (count($pieces) > 1) {
                    $lines[] = array_shift($pieces);
                }
                $current .= $pieces[0];
            }
            $lines[] = rtrim($current);
        }

        return array_map(fn (array $chunk): string => implode("\n", $chunk), array_chunk($lines, 8));
    }

    private function image(string $path, Karyawan $participant): string
    {
        $disk = Storage::disk('local');
        if (! str_starts_with($path, 'mcu/'.$participant->id.'/') || str_contains($path, '..') || ! $disk->exists($path)) {
            throw new RuntimeException('Gambar laporan tidak tersedia.');
        }

        return $this->encodeImage($disk->get($path));
    }

    /** @return array{width: float, height: float} */
    private function imageSize(string $image, float $maximumWidth, float $maximumHeight): array
    {
        $dimensions = getimagesizefromstring(base64_decode(substr($image, strpos($image, ',') + 1)));
        $scale = min($maximumWidth / $dimensions[0], $maximumHeight / $dimensions[1]);

        return ['width' => round($dimensions[0] * $scale, 2), 'height' => round($dimensions[1] * $scale, 2)];
    }

    private function encodeImage(string $bytes): string
    {
        $dimensions = @getimagesizefromstring($bytes);
        if (! $dimensions || $dimensions[0] * $dimensions[1] > 12000000) {
            throw new RuntimeException('Gambar laporan terlalu besar atau tidak valid.');
        }
        $limit = ini_parse_quantity(ini_get('memory_limit'));
        if ($limit > 0 && memory_get_usage(true) + $dimensions[0] * $dimensions[1] * 8 + 16000000 > $limit) {
            throw new RuntimeException('Memori tidak cukup untuk memproses gambar laporan.');
        }
        $image = @imagecreatefromstring($bytes);
        if (! $image) {
            throw new RuntimeException('Gambar laporan tidak valid.');
        }
        $scale = min(1, 1240 / imagesx($image), 1754 / imagesy($image));
        $canvas = imagecreatetruecolor(max(1, (int) (imagesx($image) * $scale)), max(1, (int) (imagesy($image) * $scale)));
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, imagesx($canvas), imagesy($canvas), imagesx($image), imagesy($image));
        imagedestroy($image);
        ob_start();
        try {
            imagejpeg($canvas, null, 90);
            $bytes = ob_get_contents();
        } finally {
            ob_end_clean();
            imagedestroy($canvas);
        }

        return 'data:image/jpeg;base64,'.base64_encode($bytes);
    }
}
