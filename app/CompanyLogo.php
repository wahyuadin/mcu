<?php

namespace App;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CompanyLogo
{
    public function store(UploadedFile $file): string
    {
        if (! function_exists('imagewebp')) {
            throw ValidationException::withMessages(['logo' => 'Server memerlukan dukungan WebP untuk menyimpan logo.']);
        }
        $dimensions = @getimagesize($file->getRealPath());
        if (! $dimensions || $dimensions[0] * $dimensions[1] > 16000000) {
            throw ValidationException::withMessages(['logo' => 'Logo tidak valid atau melebihi 16 megapiksel.']);
        }
        $image = @imagecreatefromstring(file_get_contents($file->getRealPath()));
        if (! $image) {
            throw ValidationException::withMessages(['logo' => 'Logo tidak dapat diproses. Pilih gambar lain.']);
        }
        $scale = min(1, 512 / max(imagesx($image), imagesy($image)));
        $width = max(1, (int) round(imagesx($image) * $scale));
        $height = max(1, (int) round(imagesy($image) * $scale));
        $canvas = imagecreatetruecolor($width, $height);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image));
        unset($image);
        $stream = fopen('php://temp', 'w+b');
        try {
            if (! imagewebp($canvas, $stream, 85)) {
                throw ValidationException::withMessages(['logo' => 'Kompresi logo gagal. Pilih gambar lain.']);
            }
            rewind($stream);
            $bytes = stream_get_contents($stream);
            $path = 'perusahaan/logo-'.Str::uuid().'.webp';
            if (! $bytes || ! Storage::disk('local')->put($path, $bytes)) {
                throw ValidationException::withMessages(['logo' => 'Logo gagal disimpan. Silakan coba lagi.']);
            }

            return $path;
        } finally {
            fclose($stream);
        }
    }
}
