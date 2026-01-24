<?php

namespace App\Sync;

use App\DataSource\RemoteDataSource;
use App\DataSource\LocalDataSource;

class SyncManager
{
    private RemoteDataSource $remoteDataSource;
    private LocalDataSource $localDataSource;

    private const SQLSTATE_INTEGRITY_CONSTRAINT_VIOLATION = '23000';
    private const MYSQL_ERROR_DUPLICATE_ENTRY = 1062;
    private const MIN_PRIORITY = 0.1;

    public function __construct(RemoteDataSource $remoteDataSource, LocalDataSource $localDataSource)
    {
        $this->remoteDataSource = $remoteDataSource;
        $this->localDataSource = $localDataSource;
    }

    public function syncFromRemote(): void
    {
        $activities = $this->remoteDataSource->getActivities();
        $this->localDataSource->replaceAllActivities($activities);
    }

    public function syncToRemote(): array
    {
        $queue = $this->localDataSource->getSyncQueue();
        $successCount = 0;
        $failedCount = 0;
        $skippedCount = 0;
        $errors = [];
        $processedIds = [];

        foreach ($queue as $item) {
            try {
                $result = $this->processQueueItem($item);
                
                if ($result === 'skipped') {
                    $skippedCount++;
                    $errors[] = [
                        'operation' => $item['operation'],
                        'activity' => $item['activity'],
                        'error' => $this->getSkipReason($item['operation']),
                        'severity' => 'warning',
                    ];
                    $processedIds[] = $item['id'];
                } else {
                    $successCount++;
                    $processedIds[] = $item['id'];
                }
            } catch (\Exception $e) {
                $failedCount++;
                $errors[] = [
                    'operation' => $item['operation'],
                    'activity' => $item['activity'],
                    'error' => $e->getMessage(),
                    'severity' => 'error',
                ];
            }
        }

        foreach ($processedIds as $id) {
            $this->localDataSource->removeSyncQueueItem($id);
        }

        return [
            'success' => $successCount,
            'skipped' => $skippedCount,
            'failed' => $failedCount,
            'errors' => $errors,
        ];
    }

    /**
     * @return array{success: int, failed: int, errors: array}
     */
    public function fullSync(): array
    {
        $result = $this->syncToRemote();
        $this->syncFromRemote();
        return $result;
    }

    private function processQueueItem(array $item): string
    {
        switch ($item['operation']) {
            case 'ADD_ACTIVITY':
                return $this->processAddActivity($item);

            case 'DELETE_ACTIVITY':
                $this->remoteDataSource->deleteActivity($item['activity']);
                return 'success';

            case 'PRIORITY_ADJUST':
                return $this->processPriorityAdjust($item);

            default:
                throw new \RuntimeException("Unknown operation: {$item['operation']}");
        }
    }

    private function processAddActivity(array $item): string
    {
        try {
            $this->remoteDataSource->addActivity($item['activity'], $item['delta']);
            return 'success';
        } catch (\PDOException $e) {
            if ($this->isUniqueConstraintViolation($e)) {
                return 'skipped';
            }
            throw $e;
        }
    }

    private function processPriorityAdjust(array $item): string
    {
        $currentActivity = $this->remoteDataSource->getActivityByName($item['activity']);
        if (!$currentActivity) {
            return 'skipped';
        }
        
        $newPriority = max(self::MIN_PRIORITY, round($currentActivity['priority'] + $item['delta'], 1));
        $this->remoteDataSource->updatePriority($item['activity'], $newPriority);
        return 'success';
    }

    private function isUniqueConstraintViolation(\PDOException $e): bool
    {
        $code = (string) $e->getCode();
        if ($code === self::SQLSTATE_INTEGRITY_CONSTRAINT_VIOLATION) {
            return true;
        }
        
        $errorInfo = $e->errorInfo ?? null;
        if (is_array($errorInfo) && isset($errorInfo[1])) {
            if ($errorInfo[1] === self::MYSQL_ERROR_DUPLICATE_ENTRY) {
                return true;
            }
        }
        
        $message = $e->getMessage();
        return str_contains($message, 'Duplicate entry') ||
               str_contains($message, 'UNIQUE constraint failed');
    }

    private function getSkipReason(string $operation): string
    {
        return $operation === 'ADD_ACTIVITY'
            ? 'Activity already exists in remote database (possibly added by another client)'
            : 'Activity no longer exists in remote database';
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
