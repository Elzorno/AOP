<?php

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class RuntimeSafetyTest extends TestCase
{
    public function test_destructive_migration_rejects_a_database_outside_disposable_storage(): void
    {
        $path = database_path('safety-sentinel.sqlite');
        $pdo = new \PDO('sqlite:'.$path);
        $pdo->exec('CREATE TABLE sentinel (value TEXT); INSERT INTO sentinel VALUES ("keep me")');
        $pdo = null;
        $before = hash_file('sha256', $path);

        try {
            $result = $this->artisanProcess(['migrate:fresh', '--force'], ['DB_DATABASE' => $path]);
            $this->assertFalse($result->isSuccessful());
            $this->assertStringContainsString('Tests refused', $result->getOutput().$result->getErrorOutput());
            $this->assertSame($before, hash_file('sha256', $path));
        } finally {
            @unlink($path);
        }
    }

    public function test_database_url_cannot_override_the_safe_test_database(): void
    {
        $result = $this->artisanProcess(['migrate:fresh', '--force'], ['DB_URL' => 'sqlite:////var/www/aop/database/database.sqlite']);
        $this->assertFalse($result->isSuccessful());
        $this->assertStringContainsString('Tests refused', $result->getOutput().$result->getErrorOutput());
    }

    public function test_cached_configuration_is_rejected_even_with_memory_database(): void
    {
        $path = storage_path('framework/testing/safety-config.php');
        file_put_contents($path, '<?php return '.var_export(config()->all(), true).';');
        try {
            $result = $this->artisanProcess(['migrate:fresh', '--force'], ['APP_CONFIG_CACHE' => $path]);
            $this->assertFalse($result->isSuccessful());
            $this->assertStringContainsString('Tests refused', $result->getOutput().$result->getErrorOutput());
        } finally {
            @unlink($path);
        }
    }

    public function test_uncached_disposable_database_allows_fresh_migrations(): void
    {
        $result = $this->artisanProcess(['migrate:fresh', '--force']);
        $this->assertTrue($result->isSuccessful(), $result->getOutput().$result->getErrorOutput());
    }

    public function test_abbreviated_maintenance_command_requires_explicit_database_target(): void
    {
        $result = $this->artisanProcess(['migrate:ref', '--force'], ['APP_ENV' => 'local']);
        $this->assertFalse($result->isSuccessful());
        $this->assertStringContainsString('Database maintenance refused', $result->getOutput().$result->getErrorOutput());
    }

    public function test_read_only_migration_status_does_not_require_maintenance_authorization(): void
    {
        $result = $this->artisanProcess(['migrate:status', '--no-ansi'], ['APP_ENV' => 'local']);

        $this->assertStringNotContainsString('Database maintenance refused', $result->getOutput().$result->getErrorOutput());
    }

    private function artisanProcess(array $arguments, array $environment = []): Process
    {
        $process = new Process([PHP_BINARY, base_path('artisan'), ...$arguments], base_path(), array_merge([
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:',
            'DB_URL' => false, 'APP_CONFIG_CACHE' => false, 'AOP_EXPECTED_DATABASE' => false,
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
        ], $environment));
        $process->run();

        return $process;
    }
}
