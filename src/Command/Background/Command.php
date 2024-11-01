<?php

namespace App\Command\Background;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command as BaseCommand;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'lock:background')]
class Command extends BaseCommand implements SignalableCommandInterface
{
    public function __construct(
        private readonly Manager $manager,
    ) {
        parent::__construct();
    }

    protected function configure()
    {
        $this->addOption('prob', null, InputOption::VALUE_REQUIRED, 'Race condition probability in percents')
            ->addOption('operation', null, InputOption::VALUE_REQUIRED, 'Operation length');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->manager->initialize($input, $output);

            // needed, because first request lasts much longer than our estimation
            $this->manager->warmUp();

            $this->manager->waitForWakeUp();
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
            $this->manager->lockNextIfNecessary();
            if ($this->manager->shouldContinueWork()) {
                return false;
            }
        }

        $this->manager->cleanUp();

        return true;
    }
}
