<?php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use App\DataSource\DataSourceInterface;
use App\Service\ActivityService;

class DeleteActivityCommand extends Command
{
    protected static $defaultName = 'activity:delete';

    private ActivityService $activityService;

    public function __construct(DataSourceInterface $dataSource)
    {
        parent::__construct();
        $this->activityService = new ActivityService($dataSource);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Delete an activity from the database')
            ->addArgument('activity', InputArgument::REQUIRED, 'The activity name to delete')
            ->addOption('project', 'p', InputOption::VALUE_OPTIONAL, 'Project name', 'General');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $activityName = $input->getArgument('activity');
        $projectName = $input->getOption('project');

        $project = $this->activityService->getProjectByName($projectName);
        if (!$project) {
            $output->writeln("<error>Project '{$projectName}' not found</error>");
            return Command::FAILURE;
        }
        $projectId = (int) $project['id'];

        try {
            $deleted = $this->activityService->deleteActivity($activityName, $projectId);

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
