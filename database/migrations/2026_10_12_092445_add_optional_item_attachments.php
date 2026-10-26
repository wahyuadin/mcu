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
        Schema::table('mcu_items', function (Blueprint $table): void {
            $table->boolean('allow_attachment')->default(false);
        });
        Schema::table('anamnesas', function (Blueprint $table): void {
            $table->json('dynamic_attachments')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('anamnesas', function (Blueprint $table): void {
            $table->dropColumn('dynamic_attachments');
        });
        Schema::table('mcu_items', function (Blueprint $table): void {
            $table->dropColumn('allow_attachment');
        });
    }
};
