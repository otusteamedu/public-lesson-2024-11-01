<?php

namespace App\Service;

use App\DTO\TimeResult;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\Lock\LockFactory;

class RaceConditionService
{
    public const TIME_PRECISION = 100;
    public const MICROSECONDS_IN_SECOND = 1_000_000;

    public function __construct(
        private readonly LockFactory $lockFactory,
        private readonly UserService $userService,
        private readonly SynchronizationService $synchronizationService,
    ) {
    }

    public function runPessimistic(int $operationLength, string $login, int $lockLength): TimeResult
    {
        $estimatedTime = $lockLength + $operationLength;
        $start = microtime(true);

        $lock = $this->runForDesiredTime(
            $lockLength,
            fn () => $this->lockFactory->createLock($login),
            $start,
        );

        $beforeLock = microtime(true);

        $lock->acquire(true);

        $waitedForLock = microtime(true) - $beforeLock;
        // emulate estimation of X / 2 when lock is occurred
        if ($waitedForLock >= self::TIME_PRECISION) {
            $estimatedTime += $operationLength / 2;
        }

        $this->executeOperation($operationLength, $login.'_pessimistic');

        $lock->release();

        return $this->createTimeResult($start, $estimatedTime);

    }

    public function runOptimistic(int $operationLength, string $login, int $refreshLength): TimeResult
    {
        $estimatedTime = $operationLength;
        $start = microtime(true);

        try {
            $this->executeOperation($operationLength, $login);
        } catch (UniqueConstraintViolationException) {
            // exception was thrown before this call in executeOperation
            $this->runForDesiredTime($operationLength, null, $start);

            $this->refresh($refreshLength);

            $this->executeOperation($operationLength, $login.'_fixed');

            $estimatedTime += $operationLength + $refreshLength;
        }

        return $this->createTimeResult($start, $estimatedTime);
    }

    /**
     * @throws UniqueConstraintViolationException
     */
    public function executeOperation(int $operationLength, string $login): void
    {
        $this->runForDesiredTime(
            $operationLength,
            fn () => $this->userService->storeUser($login),
        );
    }

    public function executeBackgroundOperation(int $operationLength, string $login, int $foregroundPid): void
    {
        $lock = $this->lockFactory->createLock($login);
        $lock->acquire(true);

        $this->userService->storeUser($login);

        $this->synchronizationService->wakeUpProcess($foregroundPid);

        $this->runForDesiredTime(random_int(0, $operationLength));

        $lock->release();
    }

    public function refresh(int $refreshLength): void
    {
        $this->runForDesiredTime(
            $refreshLength,
            fn() => $this->userService->refreshManager(),
        );
    }

    private function runForDesiredTime(int $length, ?callable $func = null, ?float $start = null)
    {
        $start ??= microtime(true);

        $result = ($func === null) ? null : $func();

        while (true) {
            $current = microtime(true);
            if (($current - $start) * self::MICROSECONDS_IN_SECOND - $length > -self::TIME_PRECISION) {
                break;
            }
            usleep(self::TIME_PRECISION);
        }

        return $result;
    }

    private function createTimeResult(float $start, int $estimatedTime): TimeResult
    {
        return new TimeResult((microtime(true) - $start) * self::MICROSECONDS_IN_SECOND, $estimatedTime);
    }
}
