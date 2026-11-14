<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->prepend(\App\Http\Middleware\RequireHttps::class);
        $middleware->prepend(\App\Http\Middleware\TrustConfiguredProxies::class);
        $middleware->append(\App\Http\Middleware\AddSecurityHeaders::class);

        $middleware->alias([
            'admin' => \App\Http\Middleware\EnsureAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();

// Run before providers, migrations, or RefreshDatabase can open a connection.
$app->afterBootstrapping(\Illuminate\Foundation\Bootstrap\LoadConfiguration::class, function (Application $app) {
    \App\Support\RuntimeSafety::check($app);
});

// CommandStarting supplies canonical names, including abbreviated Artisan commands.
$app->make('events')->listen(\Illuminate\Console\Events\CommandStarting::class, function ($event) use ($app) {
    \App\Support\RuntimeSafety::check($app, $event->command);
});

return $app;
