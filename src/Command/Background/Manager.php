<?php

namespace App\Command\Background;

use App\Command\Main\Manager as MainManager;
use App\Service\FileService;
use App\Service\RaceConditionService;
use App\Service\SynchronizationService;
use App\Service\UserService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Manager
{
    public const ITEMS_COUNT = 100;

    private const DEFAULT_RACE_CONDITION_PROBABILITY_PERCENT = 20;
    private const SLEEP_TIME_MICROSECONDS = 100_000;

    private array $shouldBeLocked = [];
    private int $operationLength;
    private int $raceConditionProbability;
    private int $currentNumber;
    private string $loginPrefix;
    private int $foregroundPid;

    public function __construct(
        private readonly FileService $fileService,
        private readonly RaceConditionService $raceConditionService,
        private readonly SynchronizationService $synchronizationService,
        private readonly UserService $userService,
    ) {
    }

    public function initialize(InputInterface $input, OutputInterface $output): void
    {
        $this->initFromInput($input);

        $numbers = range(0, self::ITEMS_COUNT - 1);
        $this->shouldBeLocked = array_fill_keys($numbers, false);
        shuffle($numbers);
        $numbersToLock = array_slice($numbers, 0, $this->raceConditionProbability);
        foreach ($numbersToLock as $number) {
            $this->shouldBeLocked[$number] = true;
        }

        $this->currentNumber = 0;

        $this->initFiles($output);
    }

    public function warmUp(): void
    {
        $this->userService->storeUser($this->loginPrefix.'_background');
    }

    public function cleanUp(): void
    {
        $this->fileService->removeFile(FileService::BACKGROUND_PID_FILE);
        $this->fileService->removeFile(FileService::LOGIN_FILE);
    }

    public function shouldContinueWork(): bool
    {
        return $this->currentNumber < self::ITEMS_COUNT;
    }

    public function getForegroundPid(): int
    {
        if (!isset($this->foregroundPid)) {
            $this->foregroundPid =
                (int)$this->fileService->getFileContentsWhenAppears(FileService::FOREGROUND_PID_FILE);
        }

        return $this->foregroundPid;
    }

    public function lockNextIfNecessary(): void
    {
        if ($this->shouldBeLocked[$this->currentNumber]) {
            $this->raceConditionService->executeBackgroundOperation(
                $this->operationLength,
                $this->loginPrefix.$this->currentNumber,
                $this->getForegroundPid(),
            );
        } else {
            $this->synchronizationService->wakeUpProcess($this->getForegroundPid());
        }
        $this->currentNumber++;
    }

    public function waitForWakeUp(): void
    {
        $this->synchronizationService->waitForWakeUp(self::SLEEP_TIME_MICROSECONDS);
    }

    private function initFromInput(InputInterface $input): void
    {
        $this->operationLength = (int)($input->getOption('operation') ?? MainManager::DEFAULT_OPERATION_LENGTH);
        $this->raceConditionProbability = (int)($input->getOption('prob') ?? self::DEFAULT_RACE_CONDITION_PROBABILITY_PERCENT);
    }

    private function initFiles(OutputInterface $output): void
    {
        $this->loginPrefix = base64_encode(random_bytes(32));
        $this->fileService->writeFile(FileService::LOGIN_FILE, $this->loginPrefix);
        $output->writeln("Login prefix initialized as $this->loginPrefix");
        $this->fileService->writePidFile(FileService::BACKGROUND_PID_FILE);
        $output->writeln('Background ready, pid '.getmypid());

        $output->writeln("Foreground pid was set to {$this->getForegroundPid()}");
    }
}
