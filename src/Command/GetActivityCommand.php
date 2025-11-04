<?php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use PDO;

class GetActivityCommand extends Command
{
    protected static $defaultName = 'activity:get';

    private const MINIMUM_PRIORITY = 0.1;
    private const PRIORITY_ADJUSTMENT = 0.1;

    private PDO $database;
    private OutputInterface $output;

    public function __construct(PDO $database)
    {
        parent::__construct();
        $this->database = $database;
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
        } catch (\PDOException $e) {
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
        $maxPriority = $this->getMaxPriority();
        $minRoll = mt_rand(0, (int)($maxPriority * 10)) / 10;

        $statement = $this->database->prepare(
            'SELECT activity, priority FROM t_activity 
             WHERE priority >= :minRoll 
             ORDER BY RAND() 
             LIMIT 1'
        );
        $statement->execute(['minRoll' => $minRoll]);

        $result = $statement->fetch();
        if ($result) {
            $result['minRoll'] = $minRoll;
        }

        return $result ?: null;
    }

    private function getMaxPriority(): float
    {
        $statement = $this->database->query('SELECT MAX(priority) as max_priority FROM t_activity');
        $result = $statement->fetch();
        return (float)$result['max_priority'];
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
        $statement = $this->database->prepare(
            'UPDATE t_activity SET priority = :priority WHERE activity = :activity'
        );
        $statement->execute([
            'priority' => $newPriority,
            'activity' => $activityName
        ]);
    }

    private function displayPriorityChange(float $newPriority): void
    {
        $this->output->writeln("<info>Priority adjusted to {$newPriority}</info>");
        $this->output->writeln("");
    }
}
