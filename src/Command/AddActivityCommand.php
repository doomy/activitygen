<?php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use PDO;

class AddActivityCommand extends Command
{
    protected static $defaultName = 'activity:add';

    private const DEFAULT_PRIORITY = 1.0;

    private PDO $database;

    public function __construct(PDO $database)
    {
        parent::__construct();
        $this->database = $database;
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Add a new activity with optional custom priority')
            ->addArgument('activity', InputArgument::REQUIRED, 'The activity name')
            ->addArgument('rating', InputArgument::OPTIONAL, 'Custom priority rating (whole number)', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $activityName = $input->getArgument('activity');
        $rating = $input->getArgument('rating');
        $priority = $rating !== null ? (float) $rating : self::DEFAULT_PRIORITY;

        try {
            $this->addActivity($activityName, $priority);
            $output->writeln("<info>Activity '{$activityName}' added with priority {$priority}</info>");
            return Command::SUCCESS;
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                $output->writeln("<error>Activity '{$activityName}' already exists</error>");
            } else {
                $output->writeln("<error>Database error: {$e->getMessage()}</error>");
            }
            return Command::FAILURE;
        }
    }

    private function addActivity(string $activityName, float $priority): void
    {
        $statement = $this->database->prepare(
            'INSERT INTO t_activity (activity, priority) VALUES (:activity, :priority)'
        );
        $statement->execute([
            'activity' => $activityName,
            'priority' => $priority,
        ]);
    }
}
