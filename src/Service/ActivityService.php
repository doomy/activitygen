<?php

namespace App\Service;

use App\DataSource\DataSourceInterface;

class ActivityService
{
    private const MINIMUM_PRIORITY = 0.1;
    private const PRIORITY_ADJUSTMENT = 0.1;

    private DataSourceInterface $dataSource;

    public function __construct(DataSourceInterface $dataSource)
    {
        $this->dataSource = $dataSource;
    }

    public function getRandomSuggestion(int $projectId = 1): ?array
    {
        $maxPriority = $this->dataSource->getMaxPriority($projectId);

        if ($maxPriority == 0) {
            return null;
        }

        $minRoll = mt_rand(0, (int) ($maxPriority * 10)) / 10;
        $result = $this->dataSource->selectRandomActivity($minRoll, $projectId);

        if ($result) {
            $result['minRoll'] = $minRoll;
        }

        return $result;
    }

    public function adjustPriority(string $activityName, float $delta, int $projectId = 1): float
    {
        $activity = $this->dataSource->getActivityByName($activityName, $projectId);

        if (!$activity) {
            throw new \Exception("Activity not found: $activityName");
        }

        $newPriority = $this->calculateNewPriority($activity['priority'], $delta);
        $this->dataSource->updatePriority($activityName, $newPriority, $projectId);

        return $newPriority;
    }

    private function calculateNewPriority(float $currentPriority, float $delta): float
    {
        $newPriority = $currentPriority + $delta;
        return max(self::MINIMUM_PRIORITY, round($newPriority, 1));
    }

    public function getAllActivities(int $projectId = 1): array
    {
        return $this->dataSource->getActivities($projectId);
    }

    public function getActivityByName(string $name, int $projectId = 1): ?array
    {
        return $this->dataSource->getActivityByName($name, $projectId);
    }

    public function addActivity(string $name, float $priority = 1.0, int $projectId = 1): void
    {
        $this->dataSource->addActivity($name, $priority, $projectId);
    }

    public function deleteActivity(string $name, int $projectId = 1): bool
    {
        return $this->dataSource->deleteActivity($name, $projectId);
    }

    public function getProjects(): array
    {
        return $this->dataSource->getProjects();
    }

    public function getProjectByName(string $name): ?array
    {
        return $this->dataSource->getProjectByName($name);
    }

    public static function getPriorityAdjustment(): float
    {
        return self::PRIORITY_ADJUSTMENT;
    }

    public static function getMinimumPriority(): float
    {
        return self::MINIMUM_PRIORITY;
    }
}
