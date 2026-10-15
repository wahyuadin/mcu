<?php

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
