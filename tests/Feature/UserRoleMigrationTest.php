<?php

namespace Tests\Feature;

use App\Models\Dokter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class UserRoleMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_conversion_preserves_accounts_and_master_doctor_relationships(): void
    {
        $legacy = User::factory()->create(['role' => 'dokter', 'username' => 'dokter.lama']);
        $deleted = User::factory()->create(['role' => 'dokter']);
        $deleted->delete();
        $admin = User::factory()->create(['role' => 'admin']);
        $operator = User::factory()->create(['role' => 'petugas']);
        $doctor = Dokter::factory()->create(['user_id' => $legacy->id]);
        $original = $legacy->fresh()->getAttributes();
        $migration = require database_path('migrations/2026_10_14_135534_convert_doctor_user_role_to_petugas.php');

        $migration->up();

        $this->assertSame(array_replace($original, ['role' => 'petugas']), $legacy->fresh()->getAttributes());
        $this->assertSame('petugas', User::withTrashed()->findOrFail($deleted->id)->role);
        $this->assertSoftDeleted($deleted);
        $this->assertSame('admin', $admin->fresh()->role);
        $this->assertSame('petugas', $operator->fresh()->role);
        $this->assertSame($legacy->id, $doctor->fresh()->user_id);
        $this->assertSame(4, User::withTrashed()->count());
        $this->assertTrue(Gate::forUser($legacy->fresh())->allows('view-patients'));
        $this->assertTrue(Gate::forUser($legacy->fresh())->allows('examine-mcu'));
        $migration->up();
        $this->assertSame(4, User::withTrashed()->count());
    }
}
