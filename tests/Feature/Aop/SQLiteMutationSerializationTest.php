<?php

namespace Tests\Feature\Aop;

use App\Database\ImmediateSQLiteConnection;
use PDO;
use Tests\TestCase;

class SQLiteMutationSerializationTest extends TestCase
{
    public function test_file_backed_concurrent_conflict_checks_are_serialized_and_retried(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('The pcntl extension is required for the contention test.');
        }

        $path = storage_path('framework/testing/contention-'.bin2hex(random_bytes(6)).'.sqlite');
        touch($path);
        [$parentSocket, $childSocket] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $parent = $this->connection($path, 80);
        $parent->statement('CREATE TABLE schedule_slots (id INTEGER PRIMARY KEY AUTOINCREMENT, slot TEXT NOT NULL)');

        $pid = pcntl_fork();
        if ($pid === 0) {
            fclose($parentSocket);
            $child = $this->connection($path, 80);
            fwrite($childSocket, "ready\n");
            try {
                $result = $child->transaction(function () use ($child) {
                    if ((int) $child->table('schedule_slots')->count() > 0) {
                        return 'skipped';
                    }

                    $child->table('schedule_slots')->insert(['slot' => 'child']);

                    return 'inserted';
                }, 3);
                fwrite($childSocket, $result."\n");
                fclose($childSocket);
                exit(0);
            } catch (\Throwable $exception) {
                fwrite($childSocket, 'error:'.$exception->getMessage()."\n");
                fclose($childSocket);
                exit(1);
            }
        }

        fclose($childSocket);
        try {
            $parent->transaction(function () use ($parent, $parentSocket) {
                $this->assertSame("ready\n", fgets($parentSocket));
                usleep(150_000);
                $parent->table('schedule_slots')->insert(['slot' => 'parent']);
            }, 3);

            $this->assertSame("skipped\n", fgets($parentSocket));
            pcntl_waitpid($pid, $status);
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status));
            $this->assertSame(['parent'], $parent->table('schedule_slots')->pluck('slot')->all());
        } finally {
            fclose($parentSocket);
            if (isset($pid) && pcntl_waitpid($pid, $status, WNOHANG) === 0) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
            }
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }

    private function connection(string $path, int $busyTimeout): ImmediateSQLiteConnection
    {
        $pdo = new PDO('sqlite:'.$path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = '.$busyTimeout);

        return new ImmediateSQLiteConnection($pdo, $path, '', [
            'driver' => 'sqlite',
            'database' => $path,
            'transaction_mode' => 'IMMEDIATE',
        ]);
    }
}
