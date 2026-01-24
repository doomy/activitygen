<?php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\DataSource\DataSourceInterface;

class GetActivityCommand extends Command
{
    protected static $defaultName = 'activity:get';

    private const MINIMUM_PRIORITY = 0.1;
    private const PRIORITY_ADJUSTMENT = 0.1;

    private DataSourceInterface $dataSource;
    private OutputInterface $output;

    public function __construct(DataSourceInterface $dataSource)
    {
        parent::__construct();
        $this->dataSource = $dataSource;
    }

    protected function configure(): void
    {
        $this->setDescription('Get a random activity based on priority');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->output = $output;

        try {
            return $this->runActivityLoop();
        } catch (\Exception $e) {
            $output->writeln("<error>Database error: {$e->getMessage()}</error>");
            return Command::FAILURE;
        }
    }

    private function runActivityLoop(): int
    {
        while (true) {
            $activity = $this->selectRandomActivity();

            if (!$activity) {
                $this->output->writeln('No activity found');
                return Command::FAILURE;
            }

            $this->displayActivity($activity);

            $userInput = $this->getUserInput();

            if ($this->shouldExit($userInput)) {
                return Command::SUCCESS;
            }

            $this->handlePriorityAdjustment($userInput, $activity);
        }
    }

    private function selectRandomActivity(): ?array
    {
        $maxPriority = $this->dataSource->getMaxPriority();
        $minRoll = mt_rand(0, (int)($maxPriority * 10)) / 10;

        $result = $this->dataSource->selectRandomActivity($minRoll);
        if ($result) {
            $result['minRoll'] = $minRoll;
        }

        return $result;
    }

    private function displayActivity(array $activity): void
    {
        $this->output->writeln("Selected activity: {$activity['activity']}");
        $this->output->writeln("Priority: {$activity['priority']}");
        $this->output->writeln("Minimum roll: {$activity['minRoll']}");
        $this->output->writeln("");
    }

    private function getUserInput(): string
    {
        $this->output->write("Press [+] to increase rating, [-] to decrease, Q to exit, or any other key to continue...");

        system('stty cbreak -echo');
        $char = fgetc(STDIN);
        system('stty -cbreak echo');

        $this->output->writeln("");

        return $char === false ? '' : $char;
    }

    private function shouldExit(string $userInput): bool
    {
        return $userInput === '' || strtolower($userInput) === 'q';
    }

    private function handlePriorityAdjustment(string $userInput, array $activity): void
    {
        $adjustment = $this->getPriorityAdjustment($userInput);

        if ($adjustment === 0) {
            return;
        }

        $newPriority = $this->calculateNewPriority($activity['priority'], $adjustment);
        $this->updateActivityPriority($activity['activity'], $newPriority);
        $this->displayPriorityChange($newPriority);
        
        // Wait for another keystroke before continuing
        $this->waitForKeystroke();
    }

    private function getPriorityAdjustment(string $userInput): float
    {
        if ($userInput === '+' || $userInput === '=') {
            return self::PRIORITY_ADJUSTMENT;
        }

        if ($userInput === '-' || $userInput === '_') {
            return -self::PRIORITY_ADJUSTMENT;
        }

        return 0;
    }

    private function calculateNewPriority(float $currentPriority, float $adjustment): float
    {
        $newPriority = $currentPriority + $adjustment;
        return max(self::MINIMUM_PRIORITY, round($newPriority, 1));
    }

    private function updateActivityPriority(string $activityName, float $newPriority): void
    {
        $this->dataSource->updatePriority($activityName, $newPriority);
    }

    private function displayPriorityChange(float $newPriority): void
    {
        $this->output->writeln("<info>Priority adjusted to {$newPriority}</info>");
        $this->output->writeln("");
    }

    private function waitForKeystroke(): void
    {
        $this->output->write("Press any key to continue...");

        system('stty cbreak -echo');
        fgetc(STDIN);
        system('stty -cbreak echo');

        $this->output->writeln("");
        $this->output->writeln("");
    }
}
