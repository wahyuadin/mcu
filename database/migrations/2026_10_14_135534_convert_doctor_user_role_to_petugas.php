<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('users')->where('role', 'dokter')->update(['role' => 'petugas']);
    }

    /**
     * Keep converted roles on rollback because original petugas accounts cannot be distinguished safely.
     */
    public function down(): void {}
};
