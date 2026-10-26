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
        Schema::create('category_result_imports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('rencana_kunjungan_id')->constrained('rencana_kunjungans')->cascadeOnDelete();
            $table->foreignUuid('mcu_category_id')->constrained('mcu_categories')->cascadeOnDelete();
            $table->string('file_name');
            $table->longText('source');
            $table->string('digest', 64);
            $table->string('status')->default('preview');
            $table->longText('results')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('category_result_imports');
    }
};
