<?php

namespace App\Sync;

use App\DataSource\RemoteDataSource;
use App\DataSource\LocalDataSource;

class SyncManager
{
    private RemoteDataSource $remoteDataSource;
    private LocalDataSource $localDataSource;

    public function __construct(RemoteDataSource $remoteDataSource, LocalDataSource $localDataSource)
    {
        $this->remoteDataSource = $remoteDataSource;
        $this->localDataSource = $localDataSource;
    }

    /**
     * Sync data from remote to local (full copy)
     */
    public function syncFromRemote(): void
    {
        $activities = $this->remoteDataSource->getActivities();
        $this->localDataSource->replaceAllActivities($activities);
    }

    /**
     * Push queued operations to remote database
     *
     * @return array{success: int, failed: int, errors: array}
     */
    public function syncToRemote(): array
    {
        $queue = $this->localDataSource->getSyncQueue();
        $successCount = 0;
        $failedCount = 0;
        $errors = [];

        foreach ($queue as $item) {
            try {
                $this->processQueueItem($item);
                $successCount++;
            } catch (\Exception $e) {
                $failedCount++;
                $errors[] = [
                    'operation' => $item['operation'],
                    'activity' => $item['activity'],
                    'error' => $e->getMessage(),
                ];
            }
        }

        if ($failedCount === 0) {
            $this->localDataSource->clearSyncQueue();
        }

        return [
            'success' => $successCount,
            'failed' => $failedCount,
            'errors' => $errors,
        ];
    }

    /**
     * Full bidirectional sync: remote→local, then queue→remote
     *
     * @return array{success: int, failed: int, errors: array}
     */
    public function fullSync(): array
    {
        $this->syncFromRemote();
        return $this->syncToRemote();
    }

    private function processQueueItem(array $item): void
    {
        switch ($item['operation']) {
            case 'ADD_ACTIVITY':
                $this->remoteDataSource->addActivity($item['activity'], $item['delta']);
                break;

            case 'DELETE_ACTIVITY':
                $this->remoteDataSource->deleteActivity($item['activity']);
                break;

            case 'PRIORITY_ADJUST':
                $currentActivity = $this->remoteDataSource->getActivityByName($item['activity']);
                if ($currentActivity) {
                    $newPriority = max(0.1, round($currentActivity['priority'] + $item['delta'], 1));
                    $this->remoteDataSource->updatePriority($item['activity'], $newPriority);
                }
                break;

            default:
                throw new \RuntimeException("Unknown operation: {$item['operation']}");
        }
    }

    public function hasPendingSync(): bool
    {
        return $this->localDataSource->hasPendingSync();
    }

    public function getPendingSyncCount(): int
    {
        return count($this->localDataSource->getSyncQueue());
    }
}
