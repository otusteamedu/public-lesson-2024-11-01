<?php

namespace App\Service;

class SynchronizationService
{
    public function waitForWakeUp(int $sleepTimeMicroseconds, ?callable $isWaitingFunc = null): void
    {
        $isWaitingFunc ??= static fn () => true;

        while ($isWaitingFunc()) {
            sleep($sleepTimeMicroseconds);
        }
    }

    public function wakeUpProcess(int $pid): void
    {
        posix_kill($pid, SIGUSR1);
    }
}
