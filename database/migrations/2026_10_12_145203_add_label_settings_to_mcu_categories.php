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
        Schema::table('mcu_categories', function (Blueprint $table) {
            $table->boolean('label_enabled')->default(false);
            $table->string('label_barcode_type', 16)->default('qr');
            $table->unsignedSmallInteger('label_width_mm')->default(80);
            $table->unsignedSmallInteger('label_height_mm')->default(40);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mcu_categories', function (Blueprint $table) {
            $table->dropColumn(['label_enabled', 'label_barcode_type', 'label_width_mm', 'label_height_mm']);
        });
    }
};
