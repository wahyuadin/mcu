<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_navbar_has_master_avatar_profile_menu_and_readonly_username(): void
    {
        $user = User::factory()->create(['role' => 'petugas', 'username' => 'petugas.utama']);
        $user->avatar = 'users/'.$user->id.'/avatar/photo.png';
        $user->save();

        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertSee(route('users.avatar', $user))
            ->assertSee('Profil Saya')->assertSee('Keluar')->assertSee('data-account-form="profile"', false)
            ->assertSee('data-account-form="password"', false)->assertSee('value="petugas.utama" readonly', false)
            ->assertSee('Password sebelumnya')->assertSee('popover="auto"', false);
    }

    public function test_user_can_update_own_profile_without_changing_username_role_or_password(): void
    {
        $user = User::factory()->create(['role' => 'petugas', 'username' => 'petugas.utama']);
        $originalPassword = $user->password;
        $other = User::factory()->create(['name' => 'Tetap']);

        $this->actingAs($user)->patchJson(route('profile.update'), ['name' => 'Nama Terbaru', 'email' => 'baru@example.test', 'id' => $other->id])
            ->assertOk()->assertJsonPath('user.name', 'Nama Terbaru')->assertJsonPath('user.email', 'baru@example.test')
            ->assertJsonMissingPath('user.password')->assertJsonMissingPath('user.remember_token')->assertHeader('Cache-Control', 'no-store, private');

        $user->refresh();
        $this->assertSame('petugas.utama', $user->username);
        $this->assertSame('petugas', $user->role);
        $this->assertSame($originalPassword, $user->password);
        $this->assertNull($user->email_verified_at);
        $this->assertSame('Tetap', $other->fresh()->name);
    }

    public function test_profile_rejects_username_role_and_password_changes(): void
    {
        $user = User::factory()->create(['role' => 'petugas', 'username' => 'tetap']);
        $originalPassword = $user->password;

        $this->actingAs($user)->patchJson(route('profile.update'), ['name' => 'Ditolak', 'email' => $user->email, 'username' => 'berubah', 'role' => 'admin', 'password' => 'Injected123'])
            ->assertUnprocessable()->assertJsonValidationErrors(['username', 'role', 'password']);

        $this->assertSame('tetap', $user->fresh()->username);
        $this->assertSame('petugas', $user->fresh()->role);
        $this->assertSame($originalPassword, $user->fresh()->password);
    }

    public function test_invalid_profile_returns_field_errors_as_json_without_redirect(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($user)->patchJson(route('profile.update'), ['name' => '', 'email' => $other->email])
            ->assertUnprocessable()->assertJsonValidationErrors(['name', 'email']);

        $this->assertSame($user->email, $user->fresh()->email);
        $this->assertSame($user->name, $user->fresh()->name);
    }

    public function test_profile_upload_replaces_master_avatar_and_returns_its_private_url(): void
    {
        $user = User::factory()->create(['role' => 'petugas']);
        $original = 'users/'.$user->id.'/avatar/old.png';
        Storage::disk('local')->put($original, 'old');
        $user->avatar = $original;
        $user->save();

        $response = $this->actingAs($user)->post(route('profile.update'), ['_method' => 'PATCH', 'name' => $user->name, 'email' => $user->email, 'avatar' => UploadedFile::fake()->image('new.png')], ['Accept' => 'application/json']);

        $response->assertOk();
        Storage::disk('local')->assertMissing($original);
        Storage::disk('local')->assertExists($user->fresh()->avatar);
        $this->assertStringStartsWith(route('users.avatar', $user).'?v=', $response->json('user.avatar_url'));
        $this->get($response->json('user.avatar_url'))->assertOk();
    }

    public function test_invalid_avatar_returns_json_and_preserves_stored_profile(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('profile.update'), ['_method' => 'PATCH', 'name' => 'Ditolak', 'email' => $user->email, 'avatar' => UploadedFile::fake()->create('evil.svg', 1, 'image/svg+xml')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('avatar');

        $this->assertSame($user->name, $user->fresh()->name);
        $this->assertNull($user->fresh()->avatar);
    }

    public function test_wrong_previous_password_returns_json_and_keeps_existing_password(): void
    {
        $user = User::factory()->create(['password' => 'PasswordLama123']);

        $this->actingAs($user)->putJson(route('profile.password'), ['current_password' => 'PasswordSalah123', 'password' => 'PasswordBaru123', 'password_confirmation' => 'PasswordBaru123'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password')->assertJsonPath('errors.current_password.0', 'Password sebelumnya tidak sesuai.');

        $this->assertTrue(Hash::check('PasswordLama123', $user->fresh()->password));
    }

    public function test_password_change_requires_previous_password_and_matching_confirmation(): void
    {
        $user = User::factory()->create(['password' => 'PasswordLama123']);
        $this->actingAs($user);

        $this->putJson(route('profile.password'), ['password' => 'PasswordBaru123', 'password_confirmation' => 'PasswordBaru123'])->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->putJson(route('profile.password'), ['current_password' => 'PasswordLama123', 'password' => 'PasswordBaru123', 'password_confirmation' => 'Different123'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->putJson(route('profile.password'), ['current_password' => 'PasswordLama123', 'password' => 'short', 'password_confirmation' => 'short'])->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->assertTrue(Hash::check('PasswordLama123', $user->fresh()->password));
    }

    public function test_successful_password_change_rotates_token_and_returns_current_csrf_without_logging_out(): void
    {
        $user = User::factory()->create(['password' => 'PasswordLama123']);
        $rememberToken = $user->remember_token;

        $response = $this->actingAs($user)->putJson(route('profile.password'), ['current_password' => 'PasswordLama123', 'password' => 'PasswordBaru123', 'password_confirmation' => 'PasswordBaru123']);

        $response->assertOk()->assertJsonPath('message', 'Password berhasil diubah.');
        $this->assertSame(csrf_token(), $response->json('csrf_token'));
        $this->assertTrue(Hash::check('PasswordBaru123', $user->fresh()->password));
        $this->assertNotSame($rememberToken, $user->fresh()->remember_token);
        $this->assertAuthenticatedAs($user);
    }

    public function test_guests_cannot_update_profile_or_password(): void
    {
        $this->patchJson(route('profile.update'), [])->assertUnauthorized();
        $this->putJson(route('profile.password'), [])->assertUnauthorized();
    }

    public function test_repeated_wrong_password_attempts_are_rate_limited(): void
    {
        $user = User::factory()->create(['password' => 'PasswordLama123']);
        $this->actingAs($user);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->putJson(route('profile.password'), ['current_password' => 'wrong', 'password' => 'PasswordBaru123', 'password_confirmation' => 'PasswordBaru123'])->assertUnprocessable();
        }

        $this->putJson(route('profile.password'), ['current_password' => 'wrong', 'password' => 'PasswordBaru123', 'password_confirmation' => 'PasswordBaru123'])->assertTooManyRequests();

        $this->assertTrue(Hash::check('PasswordLama123', $user->fresh()->password));
    }
}
