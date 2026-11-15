<?php

namespace App\Database;

use Closure;
use Illuminate\Database\SQLiteConnection;
use InvalidArgumentException;
use Throwable;

class ImmediateSQLiteConnection extends SQLiteConnection
{
    public function transaction(Closure $callback, $attempts = 1)
    {
        for ($currentAttempt = 1; $currentAttempt <= $attempts; $currentAttempt++) {
            try {
                $this->beginTransaction();
            } catch (Throwable $exception) {
                if ($this->causedByConcurrencyError($exception) && $currentAttempt < $attempts) {
                    usleep(25_000 * $currentAttempt);

                    continue;
                }

                throw $exception;
            }

            try {
                $result = $callback($this);
            } catch (Throwable $exception) {
                $this->handleTransactionException($exception, $currentAttempt, $attempts);

                continue;
            }

            try {
                $this->commit();
            } catch (Throwable $exception) {
                $this->handleCommitTransactionException($exception, $currentAttempt, $attempts);

                continue;
            }

            return $result;
        }
    }

    public function commit(): void
    {
        if ($this->transactionLevel() === 1) {
            $this->fireConnectionEvent('committing');
            $this->getPdo()->exec('COMMIT');
        }

        [$levelBeingCommitted, $this->transactions] = [
            $this->transactions,
            max(0, $this->transactions - 1),
        ];

        $this->transactionsManager?->commit(
            $this->getName(),
            $levelBeingCommitted,
            $this->transactions,
        );
        $this->fireConnectionEvent('committed');
    }

    protected function executeBeginTransactionStatement(): void
    {
        $mode = strtoupper((string) ($this->getConfig('transaction_mode') ?? 'IMMEDIATE'));
        if (! in_array($mode, ['DEFERRED', 'IMMEDIATE', 'EXCLUSIVE'], true)) {
            throw new InvalidArgumentException("Unsupported SQLite transaction mode [{$mode}].");
        }

        $this->getPdo()->exec("BEGIN {$mode} TRANSACTION");
    }

    protected function performRollBack($toLevel): void
    {
        if ($toLevel === 0) {
            $this->getPdo()->exec('ROLLBACK');

            return;
        }

        parent::performRollBack($toLevel);
    }
}
