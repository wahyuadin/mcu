<?php

use App\MedicalImage;
use App\Models\McuExport;
use App\Models\PatientImportBatch;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('model:prune', ['--model' => [PatientImportBatch::class]])->daily();
Schedule::command('model:prune', ['--model' => [McuExport::class]])->hourly()->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('mcu:normalize-image {source} {destination} {format} {width} {height} {quality}', function (MedicalImage $images, string $source, string $destination, string $format, string $width, string $height, string $quality): int {
    $images->convert($source, $destination, $format, (int) $width, (int) $height, (int) $quality);

    return 0;
})->purpose('Normalize a private MCU image in an isolated process')->setHidden(true);
