<?php

namespace App\Models;

use Database\Factories\PatientImportReceiptFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PatientImportReceipt extends Model
{
    /** @use HasFactory<PatientImportReceiptFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['fingerprint', 'karyawan_id'];

    public function karyawan(): BelongsTo
    {
        return $this->belongsTo(Karyawan::class);
    }
}
