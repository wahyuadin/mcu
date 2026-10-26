<?php

namespace App;

use App\Models\Karyawan;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

class MedicalImage
{
    /** @return array{path: string, mime: string, original_path: string, original_mime: string} */
    public function store(UploadedFile $file, Karyawan $participant, string $directory, string $field): array
    {
        $disk = Storage::disk('local');
        $paths = [];
        try {
            $this->dimensions($file->getRealPath());
            $original = $file->store('mcu/'.$participant->id.'/originals', 'local');
            if (! $original) {
                throw new RuntimeException('Berkas sumber gambar gagal disimpan.');
            }
            $paths[] = $original;
            $disk->setVisibility($original, 'private');
            $disk->makeDirectory($directory);
            $path = $directory.'/'.Str::uuid().'.webp';
            $paths[] = $path;
            $this->normalize($disk->path($original), $disk->path($path), 'webp', 2560, 2560, 90);
            $disk->setVisibility($path, 'private');

            return ['path' => $path, 'mime' => 'image/webp', 'original_path' => $original, 'original_mime' => $file->getMimeType()];
        } catch (Throwable $exception) {
            $disk->delete($paths);
            throw ValidationException::withMessages([$field => $exception instanceof RuntimeException ? $exception->getMessage() : 'Gambar gagal diproses. Silakan coba lagi.']);
        }
    }

    public function normalize(string $source, string $destination, string $format, int $width, int $height, int $quality): void
    {
        $dimensions = $this->dimensions($source);
        try {
            if ($dimensions[0] * $dimensions[1] > 12000000 || ! $this->hasMemory($dimensions)) {
                $php = (new PhpExecutableFinder)->find(false);
                if (! $php) {
                    throw new RuntimeException('PHP CLI diperlukan untuk memproses gambar besar. Hubungi administrator.');
                }
                try {
                    $process = new Process([$php, '-d', 'memory_limit=768M', base_path('artisan'), 'mcu:normalize-image',
                        $source, $destination, $format, (string) $width, (string) $height, (string) $quality, '--no-interaction', '--no-ansi'], base_path());
                    $process->setTimeout(45);
                    $process->run();
                    if (! $process->isSuccessful()) {
                        $output = $process->getErrorOutput().$process->getOutput();
                        throw new RuntimeException(str_contains($output, 'Allowed memory size') ? 'Batas memori konversi gambar tercapai.' :
                            (str_contains($output, 'Gambar tidak dapat dibaca.') ? 'Gambar tidak dapat dibaca.' : 'Konversi gambar gagal (kode '.$process->getExitCode().').'));
                    }
                } catch (Throwable $exception) {
                    throw new RuntimeException('Gambar tidak dapat dikonversi dalam batas sumber daya. Silakan gunakan gambar lebih kecil.', 0, $exception);
                }
            } else {
                $this->convert($source, $destination, $format, $width, $height, $quality);
            }
            $result = @getimagesize($destination);
            $mime = $format === 'webp' ? 'image/webp' : 'image/jpeg';
            if (! $result || $result['mime'] !== $mime || $result[0] > $width || $result[1] > $height) {
                throw new RuntimeException('Hasil konversi gambar tidak valid.');
            }
        } catch (Throwable $exception) {
            if (is_file($destination)) {
                unlink($destination);
            }
            throw $exception;
        }
    }

    public function convert(string $source, string $destination, string $format, int $width, int $height, int $quality): void
    {
        if (! in_array($format, ['webp', 'jpeg'], true) || min($width, $height, $quality) < 1 || max($width, $height) > 4096 || $quality > 100) {
            throw new RuntimeException('Pengaturan konversi gambar tidak valid.');
        }
        $dimensions = $this->dimensions($source);
        if (! $this->hasMemory($dimensions)) {
            throw new RuntimeException('Memori tidak cukup untuk memproses gambar laporan.');
        }
        $image = @imagecreatefromstring(file_get_contents($source));
        if (! $image) {
            throw new RuntimeException('Gambar tidak dapat dibaca.');
        }
        $canvas = null;
        try {
            $orientation = $dimensions['mime'] === 'image/jpeg' ? $this->orientation($source) : 1;
            $swapped = in_array($orientation, [5, 6, 7, 8], true);
            $scale = min(1, ($swapped ? $height : $width) / $dimensions[0], ($swapped ? $width : $height) / $dimensions[1]);
            $canvas = imagecreatetruecolor(max(1, (int) round($dimensions[0] * $scale)), max(1, (int) round($dimensions[1] * $scale)));
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
            imagecopyresampled($canvas, $image, 0, 0, 0, 0, imagesx($canvas), imagesy($canvas), $dimensions[0], $dimensions[1]);
            if (in_array($orientation, [3, 4, 5, 6, 7, 8], true)) {
                $canvas = imagerotate($canvas, match ($orientation) {
                    3, 4 => 180, 5, 6 => -90, default => 90
                }, 0);
            }
            if (in_array($orientation, [2, 4, 5, 7], true)) {
                imageflip($canvas, IMG_FLIP_HORIZONTAL);
            }
            $written = $format === 'webp' ? imagewebp($canvas, $destination, $quality) : imagejpeg($canvas, $destination, $quality);
            if (! $written) {
                throw new RuntimeException('Gambar hasil konversi gagal disimpan.');
            }
            chmod($destination, 0600);
        } finally {
            unset($image, $canvas);
            gc_collect_cycles();
        }
    }

    /** @return array{0: int, 1: int, mime: string} */
    private function dimensions(string $path): array
    {
        $dimensions = is_file($path) ? @getimagesize($path) : false;
        if (! $dimensions || ! in_array($dimensions['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new RuntimeException('Gunakan gambar JPG, PNG, atau WebP yang valid.');
        }
        if (filesize($path) > 20971520 || $dimensions[0] * $dimensions[1] > 80000000) {
            throw new RuntimeException('Gambar terlalu besar. Maksimal 80 megapiksel dan 20 MB.');
        }

        return [0 => $dimensions[0], 1 => $dimensions[1], 'mime' => $dimensions['mime']];
    }

    /** @param array{0: int, 1: int, mime: string} $dimensions */
    private function hasMemory(array $dimensions): bool
    {
        $limit = ini_parse_quantity(ini_get('memory_limit'));

        return $limit <= 0 || memory_get_usage(true) + $dimensions[0] * $dimensions[1] * 8 + 48000000 < $limit;
    }

    /** Read only the bounded TIFF orientation tag; the optional PHP EXIF extension is not required. */
    private function orientation(string $path): int
    {
        $metadata = [];
        getimagesize($path, $metadata);
        $exif = $metadata['APP1'] ?? '';
        if (! str_starts_with($exif, "Exif\0\0")) {
            return 1;
        }
        $tiff = substr($exif, 6);
        $order = substr($tiff, 0, 2);
        if (! in_array($order, ['II', 'MM'], true)) {
            return 1;
        }
        $read = static function (int $offset, int $length) use ($tiff, $order): ?int {
            if ($offset < 0 || $offset + $length > strlen($tiff)) {
                return null;
            }

            return unpack($length === 2 ? ($order === 'II' ? 'v' : 'n') : ($order === 'II' ? 'V' : 'N'), substr($tiff, $offset, $length))[1];
        };
        $offset = $read(4, 4);
        if ($read(2, 2) !== 42 || $offset === null || ($count = $read($offset, 2)) === null || $count > intdiv(max(0, strlen($tiff) - $offset - 2), 12)) {
            return 1;
        }
        for ($index = 0; $index < $count; $index++) {
            $entry = $offset + 2 + $index * 12;
            if ($read($entry, 2) === 0x0112 && $read($entry + 2, 2) === 3 && $read($entry + 4, 4) === 1) {
                $orientation = $read($entry + 8, 2);

                return in_array($orientation, range(1, 8), true) ? $orientation : 1;
            }
        }

        return 1;
    }
}
