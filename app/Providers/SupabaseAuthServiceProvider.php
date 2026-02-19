<?php

namespace App\Providers;

use App\Services\SupabaseGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

class SupabaseAuthServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        Auth::extend('supabase', function ($app, $name, array $config) {
            return new SupabaseGuard(
                Auth::createUserProvider($config['provider']),
                $app['request']
            );
        });
    }
}