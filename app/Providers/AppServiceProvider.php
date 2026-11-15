<?php

namespace App\Providers;

use App\Database\ImmediateSQLiteConnection;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        Connection::resolverFor(
            'sqlite',
            fn ($connection, $database, $prefix, $config) => new ImmediateSQLiteConnection(
                $connection,
                $database,
                $prefix,
                $config,
            ),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ON');
        }
    }
}
