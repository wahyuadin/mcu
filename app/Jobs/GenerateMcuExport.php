<?php

namespace App\Jobs;

use App\McuPdf;
use App\McuReport;
use App\Models\Karyawan;
use App\Models\McuExport;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

class GenerateMcuExport implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 5;

    public int $maxExceptions = 1;

    public bool $failOnTimeout = true;

    public function __construct(public string $exportId)
    {
        $this->onConnection('mcu-reports')->onQueue('mcu-reports')->afterCommit();
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('mcu-export-'.$this->exportId))->releaseAfter(5)->expireAfter(180)];
    }

    /**
     * Execute the job.
     */
    public function handle(McuPdf $pdf, McuReport $reports): void
    {
        $export = McuExport::find($this->exportId);
        if (! $export || in_array($export->status, ['ready', 'failed'], true) || $export->expires_at->isPast()) {
            return;
        }
        $disk = Storage::disk('local');
        $directory = 'mcu-exports/'.$export->id;
        $disk->makeDirectory($directory);
        $path = $directory.'/reports.zip';
        $zip = new ZipArchive;
        $opened = false;
        try {
            $user = User::find($export->user_id);
            if (! $user || ! $export->accessibleBy($user) || $export->manifest === []) {
                throw new RuntimeException('Akses ekspor tidak lagi tersedia.');
            }
            if ($export->total !== count($export->manifest)) {
                throw new RuntimeException('Manifest tidak lengkap.');
            }
            $export->update(['status' => 'processing']);
            foreach (array_slice($export->manifest, $export->processed, 10) as $entry) {
                $participant = $this->participant($export, $entry);
                $participantDirectory = $directory.'/parts/'.$participant->id;
                $disk->makeDirectory($participantDirectory);
                if (! $disk->put($participantDirectory.'/report.pdf', $pdf->render($participant, $directory.'/temp'))) {
                    throw new RuntimeException('PDF gagal disimpan.');
                }
                $attachmentIndex = 0;
                foreach ($reports->sections($participant, $participant->anamnesa) as $section) {
                    foreach ($section['attachments'] as $attachment) {
                        if ($attachment['mime'] === 'application/pdf') {
                            $attachmentPath = $attachment['path'];
                            if (! str_starts_with($attachmentPath, 'mcu/'.$participant->id.'/') || str_contains($attachmentPath, '..') || ! $disk->exists($attachmentPath)
                                || ! $disk->copy($attachmentPath, $participantDirectory.'/attachment-'.$attachmentIndex++.'.pdf')) {
                                throw new RuntimeException('Lampiran PDF tidak tersedia.');
                            }
                        }
                    }
                }
                $export->increment('processed');
                unset($participant);
                gc_collect_cycles();
            }
            if ($export->processed < $export->total) {
                self::dispatch($export->id);

                return;
            }
            if ($zip->open($disk->path($path), ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('ZIP gagal dibuat.');
            }
            $opened = true;
            foreach ($export->manifest as $entry) {
                $participant = $this->participant($export, $entry);
                $name = Str::limit(Str::slug($participant->nomor_mcu.'_'.$participant->nama_karyawan), 120, '').'_'.$participant->id;
                $participantDirectory = $directory.'/parts/'.$participant->id;
                if (! $disk->exists($participantDirectory.'/report.pdf') || ! $zip->addFile($disk->path($participantDirectory.'/report.pdf'), $name.'.pdf')) {
                    throw new RuntimeException('PDF gagal dimasukkan.');
                }
                $attachmentIndex = 0;
                foreach ($reports->sections($participant, $participant->anamnesa) as $section) {
                    foreach ($section['attachments'] as $attachment) {
                        if ($attachment['mime'] !== 'application/pdf') {
                            continue;
                        }
                        $attachmentPath = $participantDirectory.'/attachment-'.$attachmentIndex++.'.pdf';
                        if (! $disk->exists($attachmentPath) || ! $zip->addFile($disk->path($attachmentPath), 'lampiran/'.$name.'_'.$attachmentIndex.'.pdf')) {
                            throw new RuntimeException('Lampiran PDF tidak tersedia.');
                        }
                    }
                }
            }
            if (! $zip->close()) {
                throw new RuntimeException('ZIP gagal diselesaikan.');
            }
            $opened = false;
            $disk->deleteDirectory($directory.'/parts');
            $export->update(['status' => 'ready', 'path' => $path]);
        } catch (Throwable $exception) {
            if ($opened) {
                $zip->close();
            }
            $disk->deleteDirectory($directory);
            $export->update(['status' => 'failed', 'path' => null]);
            Log::warning('Pembuatan ZIP MCU gagal.', ['export_id' => $export->id, 'exception_type' => $exception::class]);
            throw new RuntimeException('Pembuatan ZIP MCU gagal. Tidak ada laporan parsial yang dirilis.');
        }
    }

    /** @param array{id: string, version: int} $entry */
    private function participant(McuExport $export, array $entry): Karyawan
    {
        $participant = Karyawan::released()->where('perusahaan_id', $export->perusahaan_id)->with('anamnesa', 'paket', 'perusahaan')->find($entry['id']);
        if (! $participant || $participant->anamnesa->lock_version !== $entry['version']) {
            throw new RuntimeException('Status atau versi peserta berubah.');
        }

        return $participant;
    }

    public function failed(?Throwable $exception): void
    {
        if (McuExport::whereKey($this->exportId)->where('status', 'ready')->exists()) {
            return;
        }
        Storage::disk('local')->deleteDirectory('mcu-exports/'.$this->exportId);
        McuExport::whereKey($this->exportId)->update(['status' => 'failed', 'path' => null]);
    }
}
