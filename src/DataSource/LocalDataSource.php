<?php

namespace App\DataSource;

use PDO;

class LocalDataSource implements DataSourceInterface
{
    private PDO $pdo;
    private bool $queueEnabled;

    public function __construct(string $dbPath, bool $queueEnabled = true)
    {
        $this->queueEnabled = $queueEnabled;
        $this->pdo = new PDO("sqlite:$dbPath", null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        
        $this->initializeSchema();
    }

    private function initializeSchema(): void
    {
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS t_activity (
                activity TEXT PRIMARY KEY,
                priority REAL NOT NULL DEFAULT 1.0
            )
        ');

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS t_sync_queue (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                operation TEXT NOT NULL,
                activity TEXT NOT NULL,
                delta REAL,
                timestamp INTEGER NOT NULL
            )
        ');
    }

    public function getActivities(): array
    {
        $statement = $this->pdo->query('SELECT activity, priority FROM t_activity');
        return $statement->fetchAll();
    }

    public function getActivityByName(string $name): ?array
    {
        $statement = $this->pdo->prepare('SELECT activity, priority FROM t_activity WHERE activity = :activity');
        $statement->execute(['activity' => $name]);
        $result = $statement->fetch();
        return $result ?: null;
    }

    public function addActivity(string $name, float $priority): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO t_activity (activity, priority) VALUES (:activity, :priority)',
        );
        $statement->execute([
            'activity' => $name,
            'priority' => $priority,
        ]);

        if ($this->queueEnabled) {
            $this->queueOperation('ADD_ACTIVITY', $name, $priority);
        }
    }

    public function deleteActivity(string $name): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM t_activity WHERE activity = :activity');
        $statement->execute(['activity' => $name]);
        $deleted = $statement->rowCount() > 0;

        if ($deleted && $this->queueEnabled) {
            $this->queueOperation('DELETE_ACTIVITY', $name, null);
        }

        return $deleted;
    }

    public function updatePriority(string $name, float $priority): void
    {
        $currentActivity = $this->getActivityByName($name);
        
        if ($currentActivity) {
            $delta = $priority - $currentActivity['priority'];
            
            $statement = $this->pdo->prepare(
                'UPDATE t_activity SET priority = :priority WHERE activity = :activity',
            );
            $statement->execute([
                'priority' => $priority,
                'activity' => $name,
            ]);

            if ($this->queueEnabled && $delta != 0) {
                $this->queueOperation('PRIORITY_ADJUST', $name, $delta);
            }
        }
    }

    public function getMaxPriority(): float
    {
        $statement = $this->pdo->query('SELECT MAX(priority) as max_priority FROM t_activity');
        $result = $statement->fetch();
        return (float)($result['max_priority'] ?? 0);
    }

    public function selectRandomActivity(float $minRoll): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT activity, priority FROM t_activity 
             WHERE priority >= :minRoll 
             ORDER BY RANDOM() 
             LIMIT 1',
        );
        $statement->execute(['minRoll' => $minRoll]);
        $result = $statement->fetch();
        return $result ?: null;
    }

    private function queueOperation(string $operation, string $activity, ?float $delta): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO t_sync_queue (operation, activity, delta, timestamp) 
             VALUES (:operation, :activity, :delta, :timestamp)',
        );
        $statement->execute([
            'operation' => $operation,
            'activity' => $activity,
            'delta' => $delta,
            'timestamp' => time(),
        ]);
    }

    public function getSyncQueue(): array
    {
        $statement = $this->pdo->query(
            'SELECT id, operation, activity, delta, timestamp FROM t_sync_queue ORDER BY timestamp ASC',
        );
        return $statement->fetchAll();
    }

    public function clearSyncQueue(): void
    {
        $this->pdo->exec('DELETE FROM t_sync_queue');
    }

    public function removeSyncQueueItem(int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM t_sync_queue WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    public function hasPendingSync(): bool
    {
        $statement = $this->pdo->query('SELECT COUNT(*) as count FROM t_sync_queue');
        $result = $statement->fetch();
        return $result['count'] > 0;
    }

    public function replaceAllActivities(array $activities): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec('DELETE FROM t_activity');
            
            $statement = $this->pdo->prepare(
                'INSERT INTO t_activity (activity, priority) VALUES (:activity, :priority)',
            );
            
            foreach ($activities as $activity) {
                $statement->execute([
                    'activity' => $activity['activity'],
                    'priority' => $activity['priority'],
                ]);
            }
            
            $this->pdo->commit();
        } catch (\Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function getPdo(): PDO
    {
        return $this->pdo;
    }
}
