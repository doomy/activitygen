<?php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use PDO;

class DeleteActivityCommand extends Command
{
    protected static $defaultName = 'activity:delete';

    private PDO $database;

    public function __construct(PDO $database)
    {
        parent::__construct();
        $this->database = $database;
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Delete an activity from the database')
            ->addArgument('activity', InputArgument::REQUIRED, 'The activity name to delete');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $activityName = $input->getArgument('activity');

        try {
            $deleted = $this->deleteActivity($activityName);
            
            if ($deleted) {
                $output->writeln("<info>Activity '{$activityName}' has been deleted</info>");
                return Command::SUCCESS;
            } else {
                $output->writeln("<error>Activity '{$activityName}' not found</error>");
                return Command::FAILURE;
            }
        } catch (\PDOException $e) {
            $output->writeln("<error>Database error: {$e->getMessage()}</error>");
            return Command::FAILURE;
        }
    }

    private function deleteActivity(string $activityName): bool
    {
        $statement = $this->database->prepare(
            'DELETE FROM t_activity WHERE activity = :activity'
        );
        $statement->execute(['activity' => $activityName]);
        
        return $statement->rowCount() > 0;
    }
}
