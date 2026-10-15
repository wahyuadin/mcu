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
            $table->json('dashboards')->nullable();
            $table->string('active_dashboard', 100)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dashboard_preferences', function (Blueprint $table) {
            $table->dropColumn(['dashboards', 'active_dashboard']);
        });
    }
};
