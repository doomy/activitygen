<?php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\ConnectionManager;
use App\Sync\SyncManager;

class SyncCommand extends Command
{
    protected static $defaultName = 'sync';

    private ConnectionManager $connectionManager;

    public function __construct(ConnectionManager $connectionManager)
    {
        parent::__construct();
        $this->connectionManager = $connectionManager;
    }

    protected function configure(): void
    {
        $this->setDescription('Synchronize local and remote activity data');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->connectionManager->isOnline()) {
            $output->writeln('<error>Cannot sync: Not connected to remote database</error>');
            $localDataSource = $this->connectionManager->getLocalDataSource();
            
            if ($localDataSource->hasPendingSync()) {
                $pendingCount = count($localDataSource->getSyncQueue());
                $output->writeln("<info>You have {$pendingCount} pending operations waiting to sync</info>");
            }
            
            return Command::FAILURE;
        }

        $remoteDataSource = $this->connectionManager->getRemoteDataSource();
        $localDataSource = $this->connectionManager->getLocalDataSource();

        if (!$remoteDataSource) {
            $output->writeln('<error>Remote data source not available</error>');
            return Command::FAILURE;
        }

        $syncManager = new SyncManager($remoteDataSource, $localDataSource);

        $output->writeln('<info>Starting synchronization...</info>');

        try {
            $pendingCount = $syncManager->getPendingSyncCount();
            
            if ($pendingCount > 0) {
                $output->writeln("<info>Found {$pendingCount} pending operations</info>");
            }

            $output->writeln('<info>Syncing from remote to local...</info>');
            $result = $syncManager->fullSync();

            $output->writeln("<info>Sync complete: {$result['success']} operations synced successfully</info>");

            if ($result['skipped'] > 0) {
                $output->writeln("<comment>{$result['skipped']} operations skipped (activities no longer exist remotely)</comment>");
            }

            if ($result['failed'] > 0) {
                $output->writeln("<error>{$result['failed']} operations failed</error>");
                foreach ($result['errors'] as $error) {
                    if ($error['severity'] === 'error') {
                        $output->writeln("<error>  - {$error['operation']} on '{$error['activity']}': {$error['error']}</error>");
                    } else {
                        $output->writeln("<comment>  - {$error['operation']} on '{$error['activity']}': {$error['error']}</comment>");
                    }
                }
                return Command::FAILURE;
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln("<error>Sync failed: {$e->getMessage()}</error>");
            return Command::FAILURE;
        }
    }
}
