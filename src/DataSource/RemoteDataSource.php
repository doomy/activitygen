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

    public function getActivities(): array
    {
        $statement = $this->pdo->query('SELECT activity, priority FROM t_activity');
        $activities = $statement->fetchAll();
        
        // Convert priority to float
        return array_map(function($activity) {
            $activity['priority'] = (float)$activity['priority'];
            return $activity;
        }, $activities);
    }

    public function getActivityByName(string $name): ?array
    {
        $statement = $this->pdo->prepare('SELECT activity, priority FROM t_activity WHERE activity = :activity');
        $statement->execute(['activity' => $name]);
        $result = $statement->fetch();
        
        if ($result) {
            $result['priority'] = (float)$result['priority'];
        }
        
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
    }

    public function deleteActivity(string $name): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM t_activity WHERE activity = :activity');
        $statement->execute(['activity' => $name]);
        return $statement->rowCount() > 0;
    }

    public function updatePriority(string $name, float $priority): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE t_activity SET priority = :priority WHERE activity = :activity',
        );
        $statement->execute([
            'priority' => $priority,
            'activity' => $name,
        ]);
    }

    public function getMaxPriority(): float
    {
        $statement = $this->pdo->query('SELECT MAX(priority) as max_priority FROM t_activity');
        $result = $statement->fetch();
        return (float)$result['max_priority'];
    }

    public function selectRandomActivity(float $minRoll): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT activity, priority FROM t_activity 
             WHERE priority >= :minRoll 
             ORDER BY RAND() 
             LIMIT 1',
        );
        $statement->execute(['minRoll' => $minRoll]);
        $result = $statement->fetch();
        
        if ($result) {
            $result['priority'] = (float)$result['priority'];
        }
        
        return $result ?: null;
    }
}
