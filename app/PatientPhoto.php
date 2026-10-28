<?php

namespace App;

use App\Models\Karyawan;
use App\Models\Pasien;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PatientPhoto
{
    public function store(UploadedFile $file, Karyawan|Pasien $participant): string
    {
        if (! function_exists('imagewebp')) {
            throw ValidationException::withMessages(['foto_pasien' => 'Server memerlukan GD dengan dukungan WebP. Hubungi administrator.']);
        }
        $dimensions = @getimagesize($file->getRealPath());
        if (! $dimensions || $dimensions[0] * $dimensions[1] > 20000000) {
            throw ValidationException::withMessages(['foto_pasien' => 'Gambar tidak valid atau terlalu besar. Maksimal 20 megapiksel.']);
        }
        $image = @imagecreatefromstring(file_get_contents($file->getRealPath()));
        if (! $image) {
            throw ValidationException::withMessages(['foto_pasien' => 'Gambar tidak dapat diproses. Pilih JPG, PNG, atau WebP lain.']);
        }
        $orientation = 1;
        if ($file->getMimeType() === 'image/jpeg' && function_exists('exif_read_data')) {
            $exif = @exif_read_data($file->getRealPath());
            $orientation = (int) ($exif['Orientation'] ?? 1);
        }
        $scale = min(1, 1200 / max(imagesx($image), imagesy($image)));
        $width = max(1, (int) round(imagesx($image) * $scale));
        $height = max(1, (int) round(imagesy($image) * $scale));
        $canvas = imagecreatetruecolor($width, $height);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image));
        unset($image);
        if (in_array($orientation, [3, 4, 5, 6, 7, 8], true)) {
            $canvas = imagerotate($canvas, match ($orientation) {
                3, 4 => 180, 5, 6 => -90, default => 90
            }, 0);
        }
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($canvas, IMG_FLIP_HORIZONTAL);
        }
        $stream = fopen('php://temp', 'w+b');
        try {
            if (! imagewebp($canvas, $stream, 80)) {
                throw ValidationException::withMessages(['foto_pasien' => 'Kompresi foto gagal. Coba gambar lain.']);
            }
            rewind($stream);
            $bytes = stream_get_contents($stream);
            $path = ($participant instanceof Pasien ? 'patients/' : 'mcu/').$participant->id.'/foto-'.Str::uuid().'.webp';
            if (! $bytes || ! Storage::disk('local')->put($path, $bytes)) {
                throw ValidationException::withMessages(['foto_pasien' => 'Foto gagal disimpan. Silakan coba lagi.']);
            }

            return $path;
        } finally {
            fclose($stream);
        }
    }
}
