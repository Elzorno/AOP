<?php

namespace App\Providers;

use App\Database\ImmediateSQLiteConnection;
use App\Models\MeetingBlock;
use App\Models\OfficeHourBlock;
use App\Models\OpenLab;
use App\Services\ScheduleEventSyncService;
use App\Services\ScheduleSourceWriteGuard;
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

        if ($this->app->runningUnitTests()) {
            $this->registerScheduleFixtureProjection();
        }
    }

    private function registerScheduleFixtureProjection(): void
    {
        ScheduleSourceWriteGuard::disableForTestFixtures();

        MeetingBlock::saved(fn (MeetingBlock $source) => app(ScheduleEventSyncService::class)->syncMeetingBlock($source));
        MeetingBlock::deleting(fn (MeetingBlock $source) => app(ScheduleEventSyncService::class)->removeForSource($source));
        OpenLab::saved(fn (OpenLab $source) => app(ScheduleEventSyncService::class)->syncOpenLab($source));
        OpenLab::deleting(fn (OpenLab $source) => app(ScheduleEventSyncService::class)->removeForSource($source));
        OfficeHourBlock::saved(
            fn (OfficeHourBlock $source) => app(ScheduleEventSyncService::class)->syncOfficeHourBlock($source)
        );
        OfficeHourBlock::deleting(
            fn (OfficeHourBlock $source) => app(ScheduleEventSyncService::class)->removeForSource($source)
        );
    }
}
