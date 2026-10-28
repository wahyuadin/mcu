<?php

namespace App\Providers;

use App\McuCategoryPresentation;
use App\Models\Karyawan;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(McuCategoryPresentation::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('manage-mcu', fn (User $user): bool => $user->role === 'admin');
        Gate::define('import-category-results', fn (User $user): bool => in_array($user->role, ['admin', 'dokter'], true));
        Gate::define('view-visits', fn (User $user): bool => $user->can('view-patients') || $user->can('import-category-results'));
        Gate::define('view-hrd', fn (User $user): bool => $user->role === 'admin' || ($user->role === 'hrd' && $user->perusahaan_id !== null && $user->perusahaan()->exists()));
        Gate::define('view-patients', fn (User $user): bool => in_array($user->role, ['admin', 'petugas'], true));
        Gate::define('examine-mcu', fn (User $user): bool => in_array($user->role, ['admin', 'petugas'], true));
        Gate::define('view-examination', function (User $user, Karyawan $peserta): Response {
            if ($user->can('examine-mcu') || $user->can('import-category-results')) {
                return Response::allow();
            }
            if (! $user->can('view-hrd')) {
                return Response::deny();
            }

            return $peserta->perusahaan_id === $user->perusahaan_id && Karyawan::released()->whereKey($peserta->id)->where('perusahaan_id', $user->perusahaan_id)
                ->whereHas('rencanaKunjungan', fn ($query) => $query->where('perusahaan_id', $user->perusahaan_id))->exists()
                ? Response::allow() : Response::denyAsNotFound();
        });
    }
}
