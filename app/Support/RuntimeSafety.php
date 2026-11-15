<?php

namespace App\Support;

use Illuminate\Foundation\Application;
use RuntimeException;

final class RuntimeSafety
{
    public static function check(Application $app, ?string $command = null): void
    {
        if (defined('AOP_TEST_RUN') || $app->environment('testing')) {
            self::assertTesting($app);

            return;
        }

        $command ??= $_SERVER['argv'][1] ?? '';
        if (! $app->runningInConsole() || ! preg_match('/^(migrate(?::(fresh|refresh|reset|rollback|install))?|db:wipe|aop:reset-schedule-data|aop:sync-scheduled-events)$/', $command) ||
            ($command === 'aop:sync-scheduled-events' && ! in_array('--repair', $_SERVER['argv'], true))) {
            return;
        }

        $connection = $app['config']->get('database.default');
        $database = $app['config']->get("database.connections.{$connection}");
        $target = $database['database'] ?? '';
        $expected = getenv('AOP_EXPECTED_DATABASE');
        if (($database['driver'] ?? '') !== 'sqlite' || ! empty($database['url']) ||
            ! is_string($expected) || $expected === '' || $target !== $expected ||
            $target === ':memory:' || ! is_file($target) || realpath($target) !== $target) {
            throw new RuntimeException('Database maintenance refused. Verify the effective database, then set AOP_EXPECTED_DATABASE to its existing absolute SQLite path.');
        }
    }

    public static function assertTesting(Application $app): void
    {
        $config = $app['config'];
        $connection = $config->get('database.default');
        $database = $config->get("database.connections.{$connection}", []);
        $target = $database['database'] ?? '';
        $testingDirectory = realpath($app->basePath('storage/framework/testing'));
        $realTarget = is_string($target) && is_file($target) ? realpath($target) : false;
        $disposable = $target === ':memory:' || ($testingDirectory !== false && $realTarget !== false &&
            str_starts_with($realTarget, $testingDirectory.DIRECTORY_SEPARATOR));

        if (! $app->environment('testing') || $app->configurationIsCached() ||
            ($database['driver'] ?? '') !== 'sqlite' || ! empty($database['url']) || ! $disposable) {
            throw new RuntimeException('Tests refused: require uncached testing configuration and SQLite :memory: or a file under storage/framework/testing.');
        }

        foreach (['cache.default' => 'array', 'session.driver' => 'array', 'mail.default' => 'array', 'queue.default' => 'sync'] as $key => $value) {
            if ($config->get($key) !== $value) {
                throw new RuntimeException("Tests refused: {$key} must be {$value}.");
            }
        }
        $storage = realpath($app->storagePath());
        $localStorage = realpath($app->basePath('storage'));
        if ($storage === false || $storage !== $localStorage || is_link($app->basePath('storage'))) {
            throw new RuntimeException('Tests refused: storage must belong to the isolated checkout.');
        }
    }
}
