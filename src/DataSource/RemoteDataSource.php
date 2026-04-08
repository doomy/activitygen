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
    }

    public function deleteActivity(string $name, int $projectId): bool
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM t_activity WHERE activity = :activity AND project_id = :projectId',
        );
        $statement->execute(['activity' => $name, 'projectId' => $projectId]);
        return $statement->rowCount() > 0;
    }

    public function updatePriority(string $name, float $priority, int $projectId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE t_activity SET priority = :priority WHERE activity = :activity AND project_id = :projectId',
        );
        $statement->execute([
            'priority' => $priority,
            'activity' => $name,
            'projectId' => $projectId,
        ]);
    }

    public function getMaxPriority(int $projectId): float
    {
        $statement = $this->pdo->prepare(
            'SELECT MAX(priority) as max_priority FROM t_activity WHERE project_id = :projectId',
        );
        $statement->execute(['projectId' => $projectId]);
        $result = $statement->fetch();
        return (float) $result['max_priority'];
    }

    public function selectRandomActivity(float $minRoll, int $projectId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT activity, priority FROM t_activity
             WHERE priority >= :minRoll AND project_id = :projectId
             ORDER BY RAND()
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
}
