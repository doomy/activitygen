<?php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use App\DataSource\DataSourceInterface;
use App\Service\ActivityService;

class AddActivityCommand extends Command
{
    protected static $defaultName = 'activity:add';

    private const DEFAULT_PRIORITY = 1.0;

    private ActivityService $activityService;

    public function __construct(DataSourceInterface $dataSource)
    {
        parent::__construct();
        $this->activityService = new ActivityService($dataSource);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Add a new activity with optional custom priority')
            ->addArgument('activity', InputArgument::REQUIRED, 'The activity name')
            ->addArgument('rating', InputArgument::OPTIONAL, 'Custom priority rating (whole number)', null)
            ->addOption('project', 'p', InputOption::VALUE_OPTIONAL, 'Project name', 'General');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $activityName = $input->getArgument('activity');
        $rating = $input->getArgument('rating');
        $priority = $rating !== null ? (float) $rating : self::DEFAULT_PRIORITY;
        $projectName = $input->getOption('project');

        $project = $this->activityService->getProjectByName($projectName);
        if (!$project) {
            $output->writeln("<error>Project '{$projectName}' not found</error>");
            return Command::FAILURE;
        }
        $projectId = (int) $project['id'];

        try {
            $this->activityService->addActivity($activityName, $priority, $projectId);
            $output->writeln("<info>Activity '{$activityName}' added with priority {$priority} to project '{$projectName}'</info>");
            return Command::SUCCESS;
        } catch (\Exception $e) {
            if (strpos($e->getMessage(), 'UNIQUE constraint') !== false || strpos($e->getMessage(), 'Duplicate entry') !== false) {
                $output->writeln("<error>Activity '{$activityName}' already exists</error>");
            } else {
                $output->writeln("<error>Database error: {$e->getMessage()}</error>");
            }
            return Command::FAILURE;
        }
    }

}
