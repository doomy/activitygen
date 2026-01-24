<?php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\DataSource\DataSourceInterface;

class DeleteActivityCommand extends Command
{
    protected static $defaultName = 'activity:delete';

    private DataSourceInterface $dataSource;

    public function __construct(DataSourceInterface $dataSource)
    {
        parent::__construct();
        $this->dataSource = $dataSource;
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
            $deleted = $this->dataSource->deleteActivity($activityName);
            
            if ($deleted) {
                $output->writeln("<info>Activity '{$activityName}' has been deleted</info>");
                return Command::SUCCESS;
            } else {
                $output->writeln("<error>Activity '{$activityName}' not found</error>");
                return Command::FAILURE;
            }
        } catch (\Exception $e) {
            $output->writeln("<error>Database error: {$e->getMessage()}</error>");
            return Command::FAILURE;
        }
    }

}
