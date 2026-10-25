<?php

namespace App\DataSource;

use PDO;

class LocalDataSource implements DataSourceInterface
{
    private const DEFAULT_PROJECT_ID = 1;
    private const DEFAULT_PROJECT_NAME = 'Default';

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
                name TEXT NOT NULL UNIQUE,
                archived INTEGER NOT NULL DEFAULT 0
            )
        ');

        $this->migrateProjectTable();

        $this->pdo->exec(sprintf(
            "INSERT OR IGNORE INTO t_project (id, name, archived) VALUES (%d, '%s', 0)",
            self::DEFAULT_PROJECT_ID,
            self::DEFAULT_PROJECT_NAME,
        ));

        $this->migrateActivityTable();
        $this->migrateSyncQueueTable();
    }

    /**
     * Adds the `archived` flag to a local project cache created before
     * archiving existed.
     */
    private function migrateProjectTable(): void
    {
        if ($this->columnExists('t_project', 'archived')) {
            return;
        }

        $this->pdo->exec('ALTER TABLE t_project ADD COLUMN archived INTEGER NOT NULL DEFAULT 0');
    }

    /**
     * Creates t_activity with the project-scoped schema, migrating any
     * pre-existing (pre-project) table in place so local data isn't lost.
     */
    private function migrateActivityTable(): void
    {
        if (!$this->tableExists('t_activity')) {
            $this->pdo->exec('
                CREATE TABLE t_activity (
                    project_id INTEGER NOT NULL DEFAULT ' . self::DEFAULT_PROJECT_ID . ',
                    activity TEXT NOT NULL,
                    priority REAL NOT NULL DEFAULT 1.0,
                    PRIMARY KEY (project_id, activity)
                )
            ');
            return;
        }

        if ($this->columnExists('t_activity', 'project_id')) {
            return;
        }

        $this->pdo->exec('ALTER TABLE t_activity RENAME TO t_activity_old');
        $this->pdo->exec('
            CREATE TABLE t_activity (
                project_id INTEGER NOT NULL DEFAULT ' . self::DEFAULT_PROJECT_ID . ',
                activity TEXT NOT NULL,
                priority REAL NOT NULL DEFAULT 1.0,
                PRIMARY KEY (project_id, activity)
            )
        ');
        $this->pdo->exec(
            'INSERT INTO t_activity (project_id, activity, priority)
             SELECT ' . self::DEFAULT_PROJECT_ID . ', activity, priority FROM t_activity_old',
        );
        $this->pdo->exec('DROP TABLE t_activity_old');
    }

    private function migrateSyncQueueTable(): void
    {
        if (!$this->tableExists('t_sync_queue')) {
            $this->pdo->exec('
                CREATE TABLE t_sync_queue (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    project_id INTEGER NOT NULL DEFAULT ' . self::DEFAULT_PROJECT_ID . ',
                    operation TEXT NOT NULL,
                    activity TEXT NOT NULL,
                    delta REAL,
                    timestamp INTEGER NOT NULL
                )
            ');
            return;
        }

        if ($this->columnExists('t_sync_queue', 'project_id')) {
            return;
        }

        $this->pdo->exec(
            'ALTER TABLE t_sync_queue ADD COLUMN project_id INTEGER NOT NULL DEFAULT ' . self::DEFAULT_PROJECT_ID,
        );
    }

    private function tableExists(string $table): bool
    {
        $statement = $this->pdo->prepare(
            "SELECT name FROM sqlite_master WHERE type='table' AND name = :name",
        );
        $statement->execute(['name' => $table]);
        return (bool)$statement->fetch();
    }

    private function columnExists(string $table, string $column): bool
    {
        $statement = $this->pdo->query("PRAGMA table_info($table)");
        foreach ($statement->fetchAll() as $columnInfo) {
            if ($columnInfo['name'] === $column) {
                return true;
            }
        }
        return false;
    }

    public function getProjects(): array
    {
        $statement = $this->pdo->query('SELECT id, name FROM t_project WHERE archived = 0 ORDER BY name');
        return array_map(
            fn ($project) => ['id' => (int)$project['id'], 'name' => $project['name']],
            $statement->fetchAll(),
        );
    }

    public function getProjectById(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT id, name, archived FROM t_project WHERE id = :id');
        $statement->execute(['id' => $id]);
        $result = $statement->fetch();

        if (!$result) {
            return null;
        }

        return [
            'id' => (int)$result['id'],
            'name' => $result['name'],
            'archived' => (bool)$result['archived'],
        ];
    }

    /**
     * Replace the full local project cache with the given projects (used during
     * sync). Archived projects are mirrored too, so that unarchiving on the
     * remote propagates on the next sync.
     *
     * @param array<array{id: int, name: string, archived?: bool}> $projects
     */
    public function replaceAllProjects(array $projects): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec('DELETE FROM t_project');

            $statement = $this->pdo->prepare(
                'INSERT INTO t_project (id, name, archived) VALUES (:id, :name, :archived)',
            );

            foreach ($projects as $project) {
                $statement->execute([
                    'id' => $project['id'],
                    'name' => $project['name'],
                    'archived' => !empty($project['archived']) ? 1 : 0,
                ]);
            }

            $this->pdo->commit();
        } catch (\Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function getActivities(int $projectId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT activity, priority FROM t_activity WHERE project_id = :project_id',
        );
        $statement->execute(['project_id' => $projectId]);
        $activities = $statement->fetchAll();

        // Convert priority to float
        return array_map(function($activity) {
            $activity['priority'] = (float)$activity['priority'];
            return $activity;
        }, $activities);
    }

    public function getActivityByName(int $projectId, string $name): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT activity, priority FROM t_activity WHERE project_id = :project_id AND activity = :activity',
        );
        $statement->execute(['project_id' => $projectId, 'activity' => $name]);
        $result = $statement->fetch();

        if ($result) {
            $result['priority'] = (float)$result['priority'];
        }

        return $result ?: null;
    }

    public function addActivity(int $projectId, string $name, float $priority): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO t_activity (project_id, activity, priority) VALUES (:project_id, :activity, :priority)',
        );
        $statement->execute([
            'project_id' => $projectId,
            'activity' => $name,
            'priority' => $priority,
        ]);

        if ($this->queueEnabled) {
            $this->queueOperation($projectId, 'ADD_ACTIVITY', $name, $priority);
        }
    }

    public function deleteActivity(int $projectId, string $name): bool
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM t_activity WHERE project_id = :project_id AND activity = :activity',
        );
        $statement->execute(['project_id' => $projectId, 'activity' => $name]);
        $deleted = $statement->rowCount() > 0;

        if ($deleted && $this->queueEnabled) {
            $this->queueOperation($projectId, 'DELETE_ACTIVITY', $name, null);
        }

        return $deleted;
    }

    public function updatePriority(int $projectId, string $name, float $priority): void
    {
        $currentActivity = $this->getActivityByName($projectId, $name);

        if ($currentActivity) {
            $delta = $priority - $currentActivity['priority'];

            $statement = $this->pdo->prepare(
                'UPDATE t_activity SET priority = :priority WHERE project_id = :project_id AND activity = :activity',
            );
            $statement->execute([
                'priority' => $priority,
                'project_id' => $projectId,
                'activity' => $name,
            ]);

            if ($this->queueEnabled && $delta != 0) {
                $this->queueOperation($projectId, 'PRIORITY_ADJUST', $name, $delta);
            }
        }
    }

    public function getMaxPriority(int $projectId): float
    {
        $statement = $this->pdo->prepare(
            'SELECT MAX(priority) as max_priority FROM t_activity WHERE project_id = :project_id',
        );
        $statement->execute(['project_id' => $projectId]);
        $result = $statement->fetch();
        return (float)($result['max_priority'] ?? 0);
    }

    public function selectRandomActivity(int $projectId, float $minRoll): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT activity, priority FROM t_activity
             WHERE project_id = :project_id AND priority >= :minRoll
             ORDER BY RANDOM()
             LIMIT 1',
        );
        $statement->execute(['project_id' => $projectId, 'minRoll' => $minRoll]);
        $result = $statement->fetch();

        if ($result) {
            $result['priority'] = (float)$result['priority'];
        }

        return $result ?: null;
    }

    private function queueOperation(int $projectId, string $operation, string $activity, ?float $delta): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO t_sync_queue (project_id, operation, activity, delta, timestamp)
             VALUES (:project_id, :operation, :activity, :delta, :timestamp)',
        );
        $statement->execute([
            'project_id' => $projectId,
            'operation' => $operation,
            'activity' => $activity,
            'delta' => $delta,
            'timestamp' => time(),
        ]);
    }

    public function getSyncQueue(): array
    {
        $statement = $this->pdo->query(
            'SELECT id, project_id, operation, activity, delta, timestamp FROM t_sync_queue ORDER BY timestamp ASC',
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
                'INSERT INTO t_activity (project_id, activity, priority) VALUES (:project_id, :activity, :priority)',
            );

            foreach ($activities as $activity) {
                $statement->execute([
                    'project_id' => $activity['project_id'],
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
