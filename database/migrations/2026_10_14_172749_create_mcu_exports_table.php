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
        Schema::create('mcu_exports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('perusahaan_id')->constrained('perusahaans')->restrictOnDelete();
            $table->string('status')->default('queued');
            $table->json('manifest');
            $table->unsignedInteger('total');
            $table->unsignedInteger('processed')->default(0);
            $table->string('path')->nullable();
            $table->timestamp('expires_at')->index();
            $table->index(['user_id', 'status']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mcu_exports');
    }
};
