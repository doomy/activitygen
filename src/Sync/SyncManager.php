<?php

namespace App\Sync;

use App\DataSource\RemoteDataSource;
use App\DataSource\LocalDataSource;

class SyncManager
{
    private RemoteDataSource $remoteDataSource;
    private LocalDataSource $localDataSource;

    // PDO error codes
    private const SQLSTATE_INTEGRITY_CONSTRAINT_VIOLATION = '23000';
    private const MYSQL_ERROR_DUPLICATE_ENTRY = 1062;

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
                    // Provide more specific message based on operation
                    $reason = $this->getSkipReason($item['operation']);
                    $errors[] = [
                        'operation' => $item['operation'],
                        'activity' => $item['activity'],
                        'error' => $reason,
                        'severity' => 'warning',
                    ];
                    // Still mark as processed to remove from queue
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

        // Remove successfully processed items (including skipped ones)
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
     * Full bidirectional sync: queue→remote first, then remote→local
     *
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
                try {
                    $this->remoteDataSource->addActivity($item['activity'], $item['delta']);
                    return 'success';
                } catch (\PDOException $e) {
                    // Check if this is a unique constraint violation (activity already exists)
                    if ($this->isUniqueConstraintViolation($e)) {
                        // Activity already exists in remote database (possibly added by another client)
                        // Skip this operation as the activity is already there
                        return 'skipped';
                    }
                    // Re-throw other PDO exceptions
                    throw $e;
                }

            case 'DELETE_ACTIVITY':
                $this->remoteDataSource->deleteActivity($item['activity']);
                return 'success';

            case 'PRIORITY_ADJUST':
                $currentActivity = $this->remoteDataSource->getActivityByName($item['activity']);
                if (!$currentActivity) {
                    // Activity doesn't exist remotely - skip this operation
                    return 'skipped';
                }
                $newPriority = max(0.1, round($currentActivity['priority'] + $item['delta'], 1));
                $this->remoteDataSource->updatePriority($item['activity'], $newPriority);
                return 'success';

            default:
                throw new \RuntimeException("Unknown operation: {$item['operation']}");
        }
    }

    private function isUniqueConstraintViolation(\PDOException $e): bool
    {
        // Check SQLSTATE code for integrity constraint violations
        if ($e->getCode() === self::SQLSTATE_INTEGRITY_CONSTRAINT_VIOLATION) {
            return true;
        }
        
        // Check specific database error codes via errorInfo array
        $errorInfo = $e->errorInfo;
        if ($errorInfo !== null && isset($errorInfo[1])) {
            // MySQL-specific duplicate entry error code
            if ($errorInfo[1] === self::MYSQL_ERROR_DUPLICATE_ENTRY) {
                return true;
            }
        }
        
        // Fallback: Check error message patterns for SQLite and other databases
        // This is a last resort when error codes are not available
        $message = $e->getMessage();
        return strpos($message, 'Duplicate entry') !== false ||
               strpos($message, 'UNIQUE constraint failed') !== false;
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
