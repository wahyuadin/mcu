<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sees_user_menu_modals_and_filtered_paginated_users(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'Administrator']);
        User::factory()->count(31)->create(['role' => 'petugas', 'name' => 'Petugas Lab']);
        $this->actingAs($admin);

        $this->get(route('users.index', ['q' => '  Petugas  ']))->assertOk()
            ->assertViewHas('search', 'Petugas')->assertViewHas('users', fn ($users): bool => $users->total() === 31 && str_contains($users->nextPageUrl(), 'q='))
            ->assertSee('data-user-create', false)->assertSee('data-user-edit=', false)->assertSee('data-bulk-delete-confirm', false)
            ->assertSee('data-user-delete-modal', false)->assertSee('Pilih semua')->assertSee(route('users.index'));
        $this->get(route('users.index', ['q' => $admin->email]))->assertOk()->assertViewHas('users', fn ($users): bool => $users->total() === 1);
        $admin->update(['username' => 'adminunik']);
        $this->get(route('users.index', ['q' => 'adminunik']))->assertOk()->assertViewHas('users', fn ($users): bool => $users->total() === 1);
        $this->get(route('users.index', ['q' => "' OR 1=1 --"]))->assertOk()->assertSee('Tidak ada user yang cocok');
        $this->get(route('users.index', ['q' => ['invalid']]))->assertSessionHasErrors('q');
    }

    public function test_admin_can_create_user_with_hashed_password_and_user_can_login(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $data = ['name' => 'Petugas Baru', 'username' => 'petugas.baru', 'email' => 'petugas@example.test', 'role' => 'petugas', 'password' => 'PasswordBaru123', 'password_confirmation' => 'PasswordBaru123'];

        $this->post(route('users.store'), $data)->assertRedirect(route('users.index'))->assertSessionHasNoErrors();

        $user = User::where('email', $data['email'])->firstOrFail();
        $this->assertSame('petugas', $user->role);
        $this->assertTrue(Hash::check($data['password'], $user->password));
        $this->post(route('logout'));
        $this->post(route('login.store'), ['login' => $data['username'], 'password' => $data['password']])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_editing_user_keeps_password_when_blank_and_can_reset_password(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $user = User::factory()->create(['role' => 'petugas']);
        $originalPassword = $user->password;
        $originalToken = $user->remember_token;
        $data = ['name' => 'Nama Diperbarui', 'username' => 'petugas.baru', 'email' => $user->email, 'role' => 'petugas', 'password' => '', 'password_confirmation' => ''];

        $this->put(route('users.update', $user), $data)->assertRedirect(route('users.index'))->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertSame('Nama Diperbarui', $user->name);
        $this->assertSame('petugas', $user->role);
        $this->assertSame($originalPassword, $user->password);
        $this->put(route('users.update', $user), array_replace($data, ['password' => 'PasswordReset123', 'password_confirmation' => 'PasswordReset123']))->assertRedirect(route('users.index'));
        $this->assertTrue(Hash::check('PasswordReset123', $user->fresh()->password));
        $this->assertNotSame($originalToken, $user->fresh()->remember_token);
    }

    public function test_invalid_user_data_is_rejected_and_form_reopens_without_password_values(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'username' => 'admin']);
        $this->actingAs($admin);

        $this->followingRedirects()->from(route('users.index'))->post(route('users.store'), ['name' => '', 'username' => 'admin', 'email' => $admin->email, 'role' => 'invalid', 'password' => 'short', 'password_confirmation' => 'different'])
            ->assertOk()->assertSee('data-reopen="true"', false)->assertDontSee('value="short"', false)->assertDontSee('value="different"', false);

        $this->assertSame(1, User::count());
    }

    public function test_last_admin_cannot_be_demoted_and_edit_modal_reopens_with_user(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        $this->followingRedirects()->from(route('users.index'))->put(route('users.update', $admin), ['_user_id' => $admin->id, 'name' => $admin->name, 'email' => $admin->email, 'role' => 'petugas'])
            ->assertOk()->assertSee('Admin terakhir tidak dapat diubah menjadi role lain.')
            ->assertViewHas('editingUser', fn ($user): bool => $user->is($admin))->assertSee('data-reopen="true"', false);

        $this->assertSame('admin', $admin->fresh()->role);
    }

    public function test_admin_can_demote_another_admin_when_an_admin_remains(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $other = User::factory()->create(['role' => 'admin']);

        $this->put(route('users.update', $other), ['name' => $other->name, 'email' => $other->email, 'role' => 'petugas'])->assertRedirect(route('users.index'))->assertSessionHasNoErrors();

        $this->assertSame('petugas', $other->fresh()->role);
    }

    public function test_admin_can_delete_another_user_but_not_own_account(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $other = User::factory()->create(['role' => 'petugas']);
        $this->actingAs($admin);

        $this->delete(route('users.destroy', $other))->assertRedirect(route('users.index'));
        $this->assertSoftDeleted($other);
        $this->from(route('users.index'))->delete(route('users.destroy', $admin))->assertSessionHasErrors(['user' => 'Anda tidak dapat menghapus akun sendiri.']);
        $this->assertNotSoftDeleted($admin);
        $this->put(route('users.update', $other), ['name' => 'Deleted'])->assertNotFound();
    }

    public function test_bulk_delete_preserves_filters_skips_self_and_missing_and_leaves_unselected_users(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $other = User::factory()->create(['role' => 'admin']);
        $unselected = User::factory()->create(['role' => 'petugas']);
        $this->actingAs($admin);
        $filters = ['q' => 'User', 'page' => 2];

        $this->post(route('users.bulk-destroy'), ['ids' => [$admin->id, $other->id, (string) Str::uuid()]] + $filters)
            ->assertRedirect(route('users.index', $filters))
            ->assertSessionHas('bulkDeleteResults', fn (array $results): bool => count($results) === 3 && ! $results[0]['deleted'] && $results[1]['deleted'] && ! $results[2]['deleted']);

        $this->assertNotSoftDeleted($admin);
        $this->assertSoftDeleted($other);
        $this->assertNotSoftDeleted($unselected);
    }

    public function test_bulk_delete_rejects_invalid_selection_without_deleting_user(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $other = User::factory()->create(['role' => 'petugas']);
        $this->actingAs($admin);

        $this->post(route('users.bulk-destroy'), ['ids' => [$other->id, $other->id]])->assertSessionHasErrors('ids.0');
        $this->post(route('users.bulk-destroy'), ['ids' => []])->assertSessionHasErrors('ids');

        $this->assertNotSoftDeleted($other);
    }

    public function test_guests_and_non_admins_cannot_access_user_management(): void
    {
        $user = User::factory()->create(['role' => 'petugas']);
        $requests = [['GET', route('users.index')], ['POST', route('users.store')], ['PUT', route('users.update', $user)], ['DELETE', route('users.destroy', $user)], ['POST', route('users.bulk-destroy')]];
        foreach ($requests as [$method, $url]) {
            $this->call($method, $url)->assertRedirect(route('login'));
        }
        foreach (['petugas', 'dokter'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            foreach ($requests as [$method, $url]) {
                $this->call($method, $url)->assertForbidden();
            }
            $response = $this->get(route('dashboard'));
            if ($role === 'dokter') {
                $response->assertRedirect(route('kunjungan-mcu.index'));
            } else {
                $response->assertOk()->assertDontSee(route('users.index'));
            }
        }
        $this->assertNotSoftDeleted($user);
    }

    public function test_user_names_are_escaped_and_passwords_are_not_rendered(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $user = User::factory()->create(['name' => '<script>alert("x")</script>', 'role' => 'petugas']);

        $this->get(route('users.index'))->assertOk()->assertSee($user->name)->assertDontSee($user->name, false)->assertDontSee($user->password, false);
    }

    public function test_duplicate_credentials_invalid_role_and_unconfirmed_password_are_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'username' => 'admin']);
        $this->actingAs($admin);

        $this->post(route('users.store'), ['name' => 'User Baru', 'username' => 'admin', 'email' => $admin->email, 'role' => 'invalid', 'password' => 'PasswordBaru123', 'password_confirmation' => 'Different123'])
            ->assertSessionHasErrors(['username', 'email', 'role', 'password']);

        $this->assertSame(1, User::count());
    }

    public function test_user_audit_keeps_account_changes_without_passwords_or_remember_tokens(): void
    {
        config(['audit.console' => true]);
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->post(route('users.store'), ['name' => 'User Audit', 'email' => 'audit@example.test', 'role' => 'petugas', 'password' => 'PasswordBaru123', 'password_confirmation' => 'PasswordBaru123'])->assertRedirect(route('users.index'));

        $user = User::where('email', 'audit@example.test')->firstOrFail();
        $audit = $user->audits()->firstOrFail();
        $this->assertSame('User Audit', $audit->new_values['name']);
        $this->assertArrayNotHasKey('password', $audit->new_values);
        $this->assertArrayNotHasKey('remember_token', $audit->new_values);
    }

    public function test_admin_can_upload_user_avatar_and_replace_it_without_leaving_old_file(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $data = ['name' => 'User Foto', 'email' => 'foto@example.test', 'role' => 'petugas', 'password' => 'PasswordBaru123', 'password_confirmation' => 'PasswordBaru123'];

        $this->post(route('users.store'), $data + ['avatar' => UploadedFile::fake()->image('avatar.png', 200, 200)])->assertRedirect(route('users.index'))->assertSessionHasNoErrors();

        $user = User::where('email', $data['email'])->firstOrFail();
        $original = $user->avatar;
        Storage::disk('local')->assertExists($original);
        $this->assertStringStartsWith('users/'.$user->id.'/avatar/', $original);
        $this->get(route('users.avatar', $user))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->put(route('users.update', $user), ['name' => $user->name, 'email' => $user->email, 'role' => $user->role])->assertRedirect(route('users.index'));
        $this->assertSame($original, $user->fresh()->avatar);
        $this->put(route('users.update', $user), ['name' => $user->name, 'email' => $user->email, 'role' => $user->role, 'avatar' => UploadedFile::fake()->image('new.jpg', 300, 300)])->assertRedirect(route('users.index'));
        Storage::disk('local')->assertMissing($original);
        Storage::disk('local')->assertExists($user->fresh()->avatar);
    }

    public function test_avatar_is_private_and_only_owner_or_admin_can_view_it(): void
    {
        $owner = User::factory()->create(['role' => 'petugas']);
        $path = 'users/'.$owner->id.'/avatar/photo.png';
        Storage::disk('local')->put($path, 'image-content');
        $owner->avatar = $path;
        $owner->save();

        $this->get(route('users.avatar', $owner))->assertRedirect(route('login'));
        $this->actingAs($owner)->get(route('users.avatar', $owner))->assertOk();
        $this->actingAs(User::factory()->create(['role' => 'dokter']))->get(route('users.avatar', $owner))->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('users.avatar', $owner))->assertOk();
        $owner->avatar = 'users/other/avatar/photo.png';
        $owner->save();
        $this->get(route('users.avatar', $owner))->assertNotFound();
    }

    public function test_invalid_avatar_is_rejected_and_reopens_modal_without_creating_user(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $data = ['name' => 'User Foto', 'email' => 'foto@example.test', 'role' => 'petugas', 'password' => 'PasswordBaru123', 'password_confirmation' => 'PasswordBaru123'];

        $this->followingRedirects()->from(route('users.index'))->post(route('users.store'), $data + ['avatar' => UploadedFile::fake()->create('malicious.svg', 1, 'image/svg+xml')])->assertOk()->assertSee('data-reopen="true"', false)->assertSee('Pilih ulang gambar');

        $this->assertSame(1, User::count());
        $this->assertSame([], Storage::disk('local')->allFiles('users'));
    }

    public function test_oversized_avatar_is_rejected_without_replacing_existing_photo(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $user = User::factory()->create(['role' => 'petugas']);
        $path = 'users/'.$user->id.'/avatar/original.png';
        Storage::disk('local')->put($path, 'original');
        $user->avatar = $path;
        $user->save();

        $this->put(route('users.update', $user), ['name' => $user->name, 'email' => $user->email, 'role' => $user->role, 'avatar' => UploadedFile::fake()->image('huge.jpg')->size(2049)])->assertSessionHasErrors('avatar');

        $this->assertSame($path, $user->fresh()->avatar);
        Storage::disk('local')->assertExists($path);
    }

    public function test_avatar_with_excessive_dimensions_is_rejected(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $data = ['name' => 'User Foto', 'email' => 'foto@example.test', 'role' => 'petugas', 'password' => 'PasswordBaru123', 'password_confirmation' => 'PasswordBaru123'];

        $this->post(route('users.store'), $data + ['avatar' => UploadedFile::fake()->image('wide.png', 4097, 1)])->assertSessionHasErrors('avatar');

        $this->assertSame(1, User::count());
    }

    public function test_supported_roles_include_hrd_and_doctor_without_allowing_the_last_admin_to_change_role(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        $this->get(route('users.index'))->assertOk()->assertViewHas('roles', ['admin' => 'Master Admin', 'dokter' => 'Dokter', 'petugas' => 'Petugas', 'hrd' => 'HRD'])
            ->assertSee('<option value="dokter"', false);
        $this->post(route('users.store'), ['name' => 'User Baru', 'email' => 'baru@example.test', 'role' => 'dokter', 'password' => 'PasswordBaru123', 'password_confirmation' => 'PasswordBaru123'])->assertSessionHasNoErrors();
        $this->put(route('users.update', $admin), ['name' => $admin->name, 'email' => $admin->email, 'role' => 'dokter'])->assertSessionHasErrors('role');

        $this->assertSame(2, User::count());
        $this->assertSame('admin', $admin->fresh()->role);
    }
}
