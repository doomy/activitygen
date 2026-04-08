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
            CREATE TABLE IF NOT EXISTS t_project (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE
            )
        ');

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS t_activity (
                activity TEXT NOT NULL,
                priority REAL NOT NULL DEFAULT 1.0,
                project_id INTEGER NOT NULL DEFAULT 1,
                PRIMARY KEY (activity, project_id),
                FOREIGN KEY (project_id) REFERENCES t_project(id)
            )
        ');

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS t_sync_queue (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                operation TEXT NOT NULL,
                activity TEXT NOT NULL,
                delta REAL,
                project_id INTEGER NOT NULL DEFAULT 1,
                timestamp INTEGER NOT NULL
            )
        ');

        $existing = $this->pdo->query("SELECT COUNT(*) as cnt FROM t_project WHERE id = 1")->fetch();
        if ((int) $existing['cnt'] === 0) {
            $this->pdo->exec("INSERT INTO t_project (id, name) VALUES (1, 'General')");
        }
    }

    public function getActivities(int $projectId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT activity, priority FROM t_activity WHERE project_id = :projectId',
        );
        $statement->execute(['projectId' => $projectId]);
        $activities = $statement->fetchAll();

        return array_map(function ($activity) {
            $activity['priority'] = (float) $activity['priority'];
            return $activity;
        }, $activities);
    }

    public function getActivityByName(string $name, int $projectId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT activity, priority FROM t_activity WHERE activity = :activity AND project_id = :projectId',
        );
        $statement->execute(['activity' => $name, 'projectId' => $projectId]);
        $result = $statement->fetch();

        if ($result) {
            $result['priority'] = (float) $result['priority'];
        }

        return $result ?: null;
    }

    public function addActivity(string $name, float $priority, int $projectId): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO t_activity (activity, priority, project_id) VALUES (:activity, :priority, :projectId)',
        );
        $statement->execute([
            'activity' => $name,
            'priority' => $priority,
            'projectId' => $projectId,
        ]);

        if ($this->queueEnabled) {
            $this->queueOperation('ADD_ACTIVITY', $name, $priority, $projectId);
        }
    }

    public function deleteActivity(string $name, int $projectId): bool
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM t_activity WHERE activity = :activity AND project_id = :projectId',
        );
        $statement->execute(['activity' => $name, 'projectId' => $projectId]);
        $deleted = $statement->rowCount() > 0;

        if ($deleted && $this->queueEnabled) {
            $this->queueOperation('DELETE_ACTIVITY', $name, null, $projectId);
        }

        return $deleted;
    }

    public function updatePriority(string $name, float $priority, int $projectId): void
    {
        $currentActivity = $this->getActivityByName($name, $projectId);

        if ($currentActivity) {
            $delta = $priority - $currentActivity['priority'];

            $statement = $this->pdo->prepare(
                'UPDATE t_activity SET priority = :priority WHERE activity = :activity AND project_id = :projectId',
            );
            $statement->execute([
                'priority' => $priority,
                'activity' => $name,
                'projectId' => $projectId,
            ]);

            if ($this->queueEnabled && $delta != 0) {
                $this->queueOperation('PRIORITY_ADJUST', $name, $delta, $projectId);
            }
        }
    }

    public function getMaxPriority(int $projectId): float
    {
        $statement = $this->pdo->prepare(
            'SELECT MAX(priority) as max_priority FROM t_activity WHERE project_id = :projectId',
        );
        $statement->execute(['projectId' => $projectId]);
        $result = $statement->fetch();
        return (float) ($result['max_priority'] ?? 0);
    }

    public function selectRandomActivity(float $minRoll, int $projectId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT activity, priority FROM t_activity
             WHERE priority >= :minRoll AND project_id = :projectId
             ORDER BY RANDOM()
             LIMIT 1',
        );
        $statement->execute(['minRoll' => $minRoll, 'projectId' => $projectId]);
        $result = $statement->fetch();

        if ($result) {
            $result['priority'] = (float) $result['priority'];
        }

        return $result ?: null;
    }

    public function getProjects(): array
    {
        $statement = $this->pdo->query('SELECT id, name FROM t_project ORDER BY id');
        return $statement->fetchAll();
    }

    public function getProjectByName(string $name): ?array
    {
        $statement = $this->pdo->prepare('SELECT id, name FROM t_project WHERE name = :name');
        $statement->execute(['name' => $name]);
        $result = $statement->fetch();
        return $result ?: null;
    }

    private function queueOperation(string $operation, string $activity, ?float $delta, int $projectId): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO t_sync_queue (operation, activity, delta, project_id, timestamp)
             VALUES (:operation, :activity, :delta, :projectId, :timestamp)',
        );
        $statement->execute([
            'operation' => $operation,
            'activity' => $activity,
            'delta' => $delta,
            'projectId' => $projectId,
            'timestamp' => time(),
        ]);
    }

    public function getSyncQueue(): array
    {
        $statement = $this->pdo->query(
            'SELECT id, operation, activity, delta, project_id, timestamp FROM t_sync_queue ORDER BY timestamp ASC',
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
                'INSERT INTO t_activity (activity, priority, project_id) VALUES (:activity, :priority, :projectId)',
            );

            foreach ($activities as $activity) {
                $statement->execute([
                    'activity' => $activity['activity'],
                    'priority' => $activity['priority'],
                    'projectId' => $activity['project_id'],
                ]);
            }

            $this->pdo->commit();
        } catch (\Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function replaceAllProjects(array $projects): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec('DELETE FROM t_activity');
            $this->pdo->exec('DELETE FROM t_project');

            $statement = $this->pdo->prepare(
                'INSERT INTO t_project (id, name) VALUES (:id, :name)',
            );

            foreach ($projects as $project) {
                $statement->execute([
                    'id' => $project['id'],
                    'name' => $project['name'],
                ]);
            }

            $this->pdo->commit();
        } catch (\Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function replaceAllProjectsAndActivities(array $projects, array $activities): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec('DELETE FROM t_activity');
            $this->pdo->exec('DELETE FROM t_project');

            $projectStatement = $this->pdo->prepare(
                'INSERT INTO t_project (id, name) VALUES (:id, :name)',
            );

            foreach ($projects as $project) {
                $projectStatement->execute([
                    'id' => $project['id'],
                    'name' => $project['name'],
                ]);
            }

            $activityStatement = $this->pdo->prepare(
                'INSERT INTO t_activity (activity, priority, project_id) VALUES (:activity, :priority, :projectId)',
            );

            foreach ($activities as $activity) {
                $activityStatement->execute([
                    'activity' => $activity['activity'],
                    'priority' => $activity['priority'],
                    'projectId' => $activity['project_id'],
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
