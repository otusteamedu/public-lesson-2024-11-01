<?php

namespace App\Command\Main;

use App\Command\Background\Manager as BackgroundManager;
use App\Service\FileService;
use App\Service\RaceConditionService;
use App\Service\SynchronizationService;
use App\Service\UserService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Manager
{
    public const DEFAULT_OPERATION_LENGTH = 60000;

    private const DEFAULT_REFRESH_LENGTH = 3000;
    private const DEFAULT_LOCK_LENGTH = 5000;
    private const SLEEP_TIME_MICROSECONDS = 100;

    private int $operationLength;
    private int $refreshLength;
    private int $lockLength;
    private bool $pessimisticMode;
    private string $modeName;
    private string $loginPrefix;
    private int $backgroundPid;
    private int $currentNumber;
    private int $actualTime;
    private int $estimatedTime;
    private bool $isWaiting;

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
        $output->writeln("Running in $this->modeName mode...");

        $this->actualTime = 0;
        $this->estimatedTime = 0;
        $this->currentNumber = 0;

        $this->initFiles($output);
    }

    public function cleanUp(): void
    {
        $this->fileService->removeFile(FileService::FOREGROUND_PID_FILE);
    }

    public function warmUp(): void
    {
        $this->userService->storeUser($this->loginPrefix.'_foreground');
    }

    public function getItemsCount(): int
    {
        return BackgroundManager::ITEMS_COUNT;
    }

    public function shouldContinueWork(): bool
    {
        return $this->currentNumber < $this->getItemsCount();
    }

    public function processNext(): void
    {
        $this->isWaiting = true;
        $this->synchronizationService->wakeUpProcess($this->backgroundPid);
        $this->synchronizationService->waitForWakeUp(self::SLEEP_TIME_MICROSECONDS, fn () => $this->isWaiting);

        $login = $this->loginPrefix.$this->currentNumber;
        $this->currentNumber++;

        $timeResult = match ($this->pessimisticMode) {
            true => $this->raceConditionService->runPessimistic($this->operationLength, $login, $this->lockLength),
            false => $this->raceConditionService->runOptimistic($this->operationLength, $login, $this->refreshLength),
        };

        $this->actualTime += $timeResult->actualTime;
        $this->estimatedTime += $timeResult->estimatedTime;
    }

    public function showResults(OutputInterface $output): void
    {
        $actualMs = (float)$this->actualTime / RaceConditionService::MICROSECONDS_IN_SECOND;
        $estimatedMs = (float)$this->estimatedTime / RaceConditionService::MICROSECONDS_IN_SECOND;
        $output->writeln(sprintf("\n<info>%s mode estimated time: %.3f seconds</info>", $this->modeName, $estimatedMs));
        $output->writeln(sprintf('<info>%s mode actual time: %.3f seconds</info>', $this->modeName, $actualMs));
    }

    public function wakeUp(): void
    {
        $this->isWaiting = false;
    }

    private function initFromInput(InputInterface $input): void
    {
        $this->operationLength = (int)($input->getOption('operation') ?? self::DEFAULT_OPERATION_LENGTH);
        $this->refreshLength = (int)($input->getOption('refresh') ?? self::DEFAULT_REFRESH_LENGTH);
        $this->lockLength = (int)($input->getOption('lock') ?? self::DEFAULT_LOCK_LENGTH);
        $this->pessimisticMode = $input->getOption('pessimistic') ?? false;
        $this->modeName = $this->pessimisticMode ? 'Pessimistic' : 'Optimistic';
    }

    private function initFiles(OutputInterface $output): void
    {
        $this->fileService->writePidFile(FileService::FOREGROUND_PID_FILE);

        $this->loginPrefix =
            $this->fileService->getFileContentsWhenAppears(FileService::LOGIN_FILE);
        $output->writeln("Login prefix was set to $this->loginPrefix");
        $this->backgroundPid =
            (int)$this->fileService->getFileContentsWhenAppears(FileService::BACKGROUND_PID_FILE);
        $output->writeln("Background pid was set to $this->backgroundPid");
    }
}
