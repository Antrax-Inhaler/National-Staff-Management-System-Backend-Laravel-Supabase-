<?php

use App\Http\Middleware\ForceCors;
use App\Http\Middleware\RoleMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use App\Http\Middleware\SupabaseAuth;
use App\Http\Middleware\VerifySupabaseToken;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: array_merge(
            glob(__DIR__ . '/../routes/api/*.php'),
            glob(__DIR__ . '/../routes/api/v1/*.php'),
            [__DIR__ . '/../routes/api.php']
        ),
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(ForceCors::class);

        
        // Global middleware
        // $middleware->append(HandleCors::class);


        // 👇 Route middleware aliases
        $middleware->alias([
            'supabase' => SupabaseAuth::class,
            'supabase.auth' => App\Http\Middleware\SupabaseAuthMiddleware::class,
            'role' => RoleMiddleware::class

        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->create();
