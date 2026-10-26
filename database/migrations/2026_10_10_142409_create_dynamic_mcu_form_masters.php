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
        Schema::create('mcu_categories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('legacy_group')->nullable()->unique();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('mcu_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('mcu_category_id')->constrained('mcu_categories')->restrictOnDelete();
            $table->string('label');
            $table->string('input_type');
            $table->string('legacy_key')->nullable()->unique();
            $table->text('yes_report')->nullable();
            $table->text('no_report')->nullable();
            $table->text('help_text')->nullable();
            $table->boolean('is_required')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
        Schema::table('pakets', function (Blueprint $table): void {
            $table->json('dynamic_schema')->nullable();
        });
        Schema::table('anamnesas', function (Blueprint $table): void {
            $table->json('dynamic_schema')->nullable();
            $table->json('dynamic_answers')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('anamnesas', function (Blueprint $table): void {
            $table->dropColumn(['dynamic_schema', 'dynamic_answers']);
        });
        Schema::table('pakets', function (Blueprint $table): void {
            $table->dropColumn('dynamic_schema');
        });
        Schema::dropIfExists('mcu_items');
        Schema::dropIfExists('mcu_categories');
    }
};
