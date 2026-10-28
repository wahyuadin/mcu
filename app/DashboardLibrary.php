<?php

namespace App;

use App\Models\DashboardPreference;
use App\Models\HrdDashboardPreference;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DashboardLibrary
{
    /** @return array{dashboards: list<array<string, mixed>>, active: string, default_dashboard: array{id: string, name: string, version: int}} */
    public function state(User $user, ?DashboardPreference $preference = null): array
    {
        $preference ??= DashboardPreference::where('user_id', $user->id)->where('role', $user->role)->first();
        $dashboards = $preference?->dashboards;
        if ($dashboards === null) {
            $dashboards = [];
            if ($preference?->widgets !== null) {
                $dashboards[] = ['id' => 'legacy-operational', 'name' => 'Dashboard pribadi sebelumnya', 'widgets' => $preference->widgets, 'company_id' => null, 'version' => 1];
            }
            if (in_array($user->role, ['admin', 'hrd'], true)) {
                $legacy = HrdDashboardPreference::where('user_id', $user->id)->whereNotNull('widgets')
                    ->when($user->role === 'hrd', fn ($query) => $query->where('perusahaan_id', $user->perusahaan_id))->with('perusahaan')->get();
                foreach ($legacy as $entry) {
                    $dashboards[] = ['id' => 'legacy-'.$entry->id, 'name' => 'Dashboard lama · '.($entry->perusahaan?->nama_perusahaan ?? 'Perusahaan'), 'widgets' => $entry->widgets,
                        'company_id' => $user->role === 'hrd' ? $entry->perusahaan_id : null, 'version' => 1];
                }
            }
        }
        $dashboards = array_values(array_filter($dashboards, fn (array $dashboard): bool => $user->role !== 'hrd' || ($dashboard['company_id'] ?? null) === $user->perusahaan_id));
        $active = $preference?->active_dashboard ?? ($preference?->widgets !== null ? 'legacy-operational' : ($user->role === 'hrd' ? ($dashboards[0]['id'] ?? 'default') : 'default'));
        if ($active !== 'default' && ! in_array($active, array_column($dashboards, 'id'), true)) {
            $active = 'default';
        }

        return ['dashboards' => $dashboards, 'active' => $active, 'default_dashboard' => ['id' => 'default', 'name' => $preference?->default_name ?? 'Dashboard', 'version' => $preference?->default_version ?? 1]];
    }

    /** @param array{dashboards: list<array<string, mixed>>, active: string} $state
     * @return array<string, mixed>|null
     */
    public function find(array $state, string $id): ?array
    {
        if ($id === 'default') {
            return null;
        }

        return collect($state['dashboards'])->firstWhere('id', $id) ?? abort(404);
    }

    /** @param list<array<string, mixed>> $widgets
     * @return array<string, mixed>
     */
    public function create(User $user, string $name, array $widgets): array
    {
        return $this->mutate($user, function (DashboardPreference $preference, array $state) use ($user, $name, $widgets): array {
            if (count($state['dashboards']) >= 50) {
                throw ValidationException::withMessages(['name' => 'Maksimal 50 dashboard pribadi. Hapus dashboard yang tidak digunakan.']);
            }
            $dashboard = ['id' => (string) Str::uuid(), 'name' => $name, 'widgets' => $widgets, 'company_id' => $user->role === 'hrd' ? $user->perusahaan_id : null, 'version' => 1];
            $preference->update(['dashboards' => [...$state['dashboards'], $dashboard], 'active_dashboard' => $dashboard['id']]);

            return $dashboard;
        });
    }

    /** @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    public function update(User $user, string $id, int $version, array $changes): array
    {
        return $this->mutate($user, function (DashboardPreference $preference, array $state) use ($id, $version, $changes): array {
            if ($id === 'default') {
                if (array_keys($changes) !== ['name']) {
                    throw ValidationException::withMessages(['widgets' => 'Gunakan Simpan sebagai untuk membuat dashboard dengan susunan pribadi.']);
                }
                abort_unless($state['default_dashboard']['version'] === $version, 409, 'Nama dashboard berubah di tab lain. Muat ulang sebelum mengganti nama.');
                $preference->update(['default_name' => $changes['name'], 'default_version' => $version + 1, 'dashboards' => $state['dashboards']]);

                return ['id' => 'default', 'name' => $changes['name'], 'version' => $version + 1];
            }
            $dashboard = $this->find($state, $id);
            abort_unless($dashboard, 404);
            abort_unless($dashboard['version'] === $version, 409, 'Dashboard berubah di tab lain. Simpan sebagai dashboard baru atau muat ulang.');
            $dashboard = [...$dashboard, ...$changes, 'version' => $version + 1];
            $preference->update(['dashboards' => array_map(fn (array $entry): array => $entry['id'] === $id ? $dashboard : $entry, $state['dashboards']), 'active_dashboard' => $id]);

            return $dashboard;
        });
    }

    public function delete(User $user, string $id, int $version): void
    {
        $this->mutate($user, function (DashboardPreference $preference, array $state) use ($id, $version): array {
            $dashboard = $this->find($state, $id);
            abort_unless($dashboard, 404);
            abort_unless($dashboard['version'] === $version, 409, 'Dashboard berubah di tab lain. Muat ulang sebelum menghapus.');
            $preference->update(['dashboards' => array_values(array_filter($state['dashboards'], fn (array $entry): bool => $entry['id'] !== $id)), 'active_dashboard' => $state['active'] === $id ? 'default' : $state['active']]);

            return [];
        });
    }

    public function select(User $user, string $id): void
    {
        $this->mutate($user, function (DashboardPreference $preference, array $state) use ($id): array {
            $this->find($state, $id);
            $preference->update(['dashboards' => $state['dashboards'], 'active_dashboard' => $id]);

            return [];
        });
    }

    /** @param Closure(DashboardPreference, array<string, mixed>): array<string, mixed> $action
     * @return array<string, mixed>
     */
    private function mutate(User $user, Closure $action): array
    {
        return DB::transaction(function () use ($user, $action): array {
            $actor = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->role === $user->role && $actor->perusahaan_id === $user->perusahaan_id, 403);
            $preference = DashboardPreference::withTrashed()->firstOrCreate(['user_id' => $user->id, 'role' => $user->role]);
            if ($preference->trashed()) {
                $preference->restore();
            }
            $hidden = $user->role === 'hrd' ? array_values(array_filter($preference->dashboards ?? [], fn (array $dashboard): bool => ($dashboard['company_id'] ?? null) !== $user->perusahaan_id)) : [];
            $result = $action($preference, $this->state($user, $preference));
            if ($hidden !== []) {
                $preference->update(['dashboards' => [...$hidden, ...($preference->dashboards ?? [])]]);
            }

            return $result;
        });
    }
}
