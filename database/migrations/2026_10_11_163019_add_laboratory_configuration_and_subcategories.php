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
        Schema::create('mcu_subcategories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('mcu_category_id')->constrained('mcu_categories')->restrictOnDelete();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
        Schema::table('mcu_items', function (Blueprint $table): void {
            $table->foreignUuid('mcu_subcategory_id')->nullable()->constrained('mcu_subcategories')->restrictOnDelete();
            $table->json('lab_config')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mcu_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('mcu_subcategory_id');
            $table->dropColumn('lab_config');
        });
        Schema::dropIfExists('mcu_subcategories');
    }
};
