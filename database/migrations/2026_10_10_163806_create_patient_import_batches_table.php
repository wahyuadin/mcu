<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('patient_import_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $table->string('file_name');
            $table->string('status')->default('preview');
            $table->json('headers');
            $table->longText('source_rows');
            $table->json('mapping');
            $table->longText('results')->nullable();
            $table->text('file_error')->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
        Schema::create('patient_import_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('fingerprint', 64)->unique();
            $table->foreignUuid('karyawan_id')->constrained('karyawans')->restrictOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('patient_import_receipts');
        Schema::dropIfExists('patient_import_batches');
    }
};
