<?php

namespace App\Command\Main;

use App\Command\Background\Command as BackgroundCommand;
use App\Command\Background\Manager as BackgroundManager;
use App\Service\RaceConditionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command as BaseCommand;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'lock:main')]
class Command extends BaseCommand implements SignalableCommandInterface
{
    private bool $isWaiting;

    public function __construct(
        private readonly Manager $manager,
    ) {
        parent::__construct();
    }

    protected function configure()
    {
        $this->addOption('pessimistic', 'p', InputOption::VALUE_NONE, 'Run in pessimistic mode')
            ->addOption('lock', null, InputOption::VALUE_REQUIRED, 'Lock length')
            ->addOption('operation', null, InputOption::VALUE_REQUIRED, 'Operation length')
            ->addOption('refresh', null, InputOption::VALUE_REQUIRED, 'Refresh length');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->manager->initialize($input, $output);

            // needed, because first request lasts much longer than our estimation
            $this->manager->warmUp();

            $progressBar = new ProgressBar($output, $this->manager->getItemsCount());
            while ($this->manager->shouldContinueWork()) {
                $this->manager->processNext();
                $progressBar->advance();
            }
            $progressBar->finish();

            $this->manager->showResults($output);
        } finally {
            $this->manager->cleanUp();
        }

        return self::SUCCESS;
    }

    public function getSubscribedSignals(): array
    {
        return [SIGUSR1, SIGINT, SIGTERM];
    }

    public function handleSignal(int $signal)
    {
        if ($signal === SIGUSR1) {
            $this->manager->wakeUp();

            return false;
        }

        $this->manager->cleanUp();

        return true;
    }
}
