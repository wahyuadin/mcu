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
        Schema::table('dashboard_preferences', function (Blueprint $table) {
            $table->string('default_name', 100)->nullable();
            $table->unsignedInteger('default_version')->default(1);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dashboard_preferences', function (Blueprint $table) {
            $table->dropColumn(['default_name', 'default_version']);
        });
    }
};
