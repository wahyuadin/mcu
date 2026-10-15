<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('manage-mcu', fn (User $user): bool => $user->role === 'admin');
        Gate::define('view-hrd', fn (User $user): bool => $user->role === 'admin' || ($user->role === 'hrd' && $user->perusahaan_id !== null && $user->perusahaan()->exists()));
        Gate::define('view-patients', fn (User $user): bool => in_array($user->role, ['admin', 'petugas'], true));
        Gate::define('examine-mcu', fn (User $user): bool => in_array($user->role, ['admin', 'petugas'], true));
    }
}
