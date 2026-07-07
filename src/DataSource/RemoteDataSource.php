<?php

namespace App\DataSource;

use PDO;

class RemoteDataSource implements DataSourceInterface
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function getProjects(): array
    {
        $statement = $this->pdo->query('SELECT id, name FROM t_project ORDER BY name');
        return array_map(
            fn ($project) => ['id' => (int)$project['id'], 'name' => $project['name']],
            $statement->fetchAll(),
        );
    }

    public function getProjectById(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT id, name FROM t_project WHERE id = :id');
        $statement->execute(['id' => $id]);
        $result = $statement->fetch();

        if (!$result) {
            return null;
        }

        return ['id' => (int)$result['id'], 'name' => $result['name']];
    }

    /**
     * Add a new project
     *
     * @return array{id: int, name: string}
     * @throws \Exception If a project with this name already exists
     */
    public function addProject(string $name): array
    {
        $statement = $this->pdo->prepare('INSERT INTO t_project (name) VALUES (:name)');
        $statement->execute(['name' => $name]);

        return ['id' => (int)$this->pdo->lastInsertId(), 'name' => $name];
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
