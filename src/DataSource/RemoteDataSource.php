<?php

namespace App\DataSource;

use PDO;

class RemoteDataSource implements DataSourceInterface
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureSchema();
    }

    /**
     * Adds the `archived` flag to a pre-existing t_project table.
     *
     * The rest of the schema predates this code base, but archiving projects
     * needs one new column, so it is added in place the same way
     * LocalDataSource migrates the SQLite mirror.
     */
    private function ensureSchema(): void
    {
        $statement = $this->pdo->query("SHOW COLUMNS FROM t_project LIKE 'archived'");

        if ($statement->fetch()) {
            return;
        }

        $this->pdo->exec('ALTER TABLE t_project ADD COLUMN archived TINYINT(1) NOT NULL DEFAULT 0');
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
     * Add a new project, or restore the archived project of that name.
     *
     * Archived projects keep their (unique) name, so adding a project whose
     * name is archived unarchives it together with all of its activities.
     *
     * @return array{id: int, name: string, restored: bool}
     * @throws \Exception If a visible project with this name already exists
     */
    public function addProject(string $name): array
    {
        $archived = $this->findArchivedProjectByName($name);

        if ($archived !== null) {
            $this->unarchiveProject($archived['id']);

            return ['id' => $archived['id'], 'name' => $archived['name'], 'restored' => true];
        }

        $statement = $this->pdo->prepare('INSERT INTO t_project (name) VALUES (:name)');
        $statement->execute(['name' => $name]);

        return ['id' => (int)$this->pdo->lastInsertId(), 'name' => $name, 'restored' => false];
    }

    /**
     * @return array{id: int, name: string}|null
     */
    private function findArchivedProjectByName(string $name): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, name FROM t_project WHERE name = :name AND archived = 1',
        );
        $statement->execute(['name' => $name]);
        $result = $statement->fetch();

        if (!$result) {
            return null;
        }

        return ['id' => (int)$result['id'], 'name' => $result['name']];
    }

    /**
     * Restore an archived project, making it visible to clients again
     */
    public function unarchiveProject(int $id): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE t_project SET archived = 0 WHERE id = :id AND archived = 1',
        );
        $statement->execute(['id' => $id]);

        return $statement->rowCount() > 0;
    }

    /**
     * Archive a project: it disappears from every client, but neither the
     * project row nor its activities are erased, so it can be restored.
     *
     * @return bool True if the project was archived by this call
     */
    public function archiveProject(int $id): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE t_project SET archived = 1 WHERE id = :id AND archived = 0',
        );
        $statement->execute(['id' => $id]);

        return $statement->rowCount() > 0;
    }

    /**
     * Fetch every project, archived ones included, for building the local sync
     * mirror. Clients read projects through getProjects() instead.
     *
     * @return array<array{id: int, name: string, archived: bool}>
     */
    public function getAllProjectsForSync(): array
    {
        $statement = $this->pdo->query('SELECT id, name, archived FROM t_project');
        return array_map(
            fn ($project) => [
                'id' => (int)$project['id'],
                'name' => $project['name'],
                'archived' => (bool)$project['archived'],
            ],
            $statement->fetchAll(),
        );
    }

    /**
     * Fetch all activities across all projects, for building the local sync mirror.
     *
     * @return array<array{project_id: int, activity: string, priority: float}>
     */
    public function getAllActivitiesForSync(): array
    {
        $statement = $this->pdo->query('SELECT project_id, activity, priority FROM t_activity');
        return array_map(function ($activity) {
            $activity['project_id'] = (int)$activity['project_id'];
            $activity['priority'] = (float)$activity['priority'];
            return $activity;
        }, $statement->fetchAll());
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
    }

    public function deleteActivity(int $projectId, string $name): bool
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM t_activity WHERE project_id = :project_id AND activity = :activity',
        );
        $statement->execute(['project_id' => $projectId, 'activity' => $name]);
        return $statement->rowCount() > 0;
    }

    public function updatePriority(int $projectId, string $name, float $priority): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE t_activity SET priority = :priority WHERE project_id = :project_id AND activity = :activity',
        );
        $statement->execute([
            'priority' => $priority,
            'project_id' => $projectId,
            'activity' => $name,
        ]);
    }

    public function getMaxPriority(int $projectId): float
    {
        $statement = $this->pdo->prepare(
            'SELECT MAX(priority) as max_priority FROM t_activity WHERE project_id = :project_id',
        );
        $statement->execute(['project_id' => $projectId]);
        $result = $statement->fetch();
        return (float)$result['max_priority'];
    }

    public function selectRandomActivity(int $projectId, float $minRoll): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT activity, priority FROM t_activity
             WHERE project_id = :project_id AND priority >= :minRoll
             ORDER BY RAND()
             LIMIT 1',
        );
        $statement->execute(['project_id' => $projectId, 'minRoll' => $minRoll]);
        $result = $statement->fetch();

        if ($result) {
            $result['priority'] = (float)$result['priority'];
        }

        return $result ?: null;
    }
}
