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

    /**
     * Get all projects
     *
     * @return array<array{id: int, name: string}>
     */
    public function getProjects(): array
    {
        return $this->dataSource->getProjects();
    }

    /**
     * Get a single project by id
     *
     * @return array{id: int, name: string}|null
     */
    public function getProjectById(int $id): ?array
    {
        return $this->dataSource->getProjectById($id);
    }

    /**
     * Get a random activity suggestion based on priority-weighted selection
     *
     * @return array{activity: string, priority: float, minRoll: float}|null
     */
    public function getRandomSuggestion(int $projectId): ?array
    {
        $maxPriority = $this->dataSource->getMaxPriority($projectId);

        if ($maxPriority == 0) {
            return null;
        }

        $minRoll = mt_rand(0, (int)($maxPriority * 10)) / 10;
        $result = $this->dataSource->selectRandomActivity($projectId, $minRoll);

        if ($result) {
            $result['minRoll'] = $minRoll;
        }

        return $result;
    }

    /**
     * Adjust activity priority by a delta amount
     *
     * @return float The new priority value
     * @throws \Exception If activity not found
     */
    public function adjustPriority(int $projectId, string $activityName, float $delta): float
    {
        $activity = $this->dataSource->getActivityByName($projectId, $activityName);

        if (!$activity) {
            throw new \Exception("Activity not found: $activityName");
        }

        $newPriority = $this->calculateNewPriority($activity['priority'], $delta);
        $this->dataSource->updatePriority($projectId, $activityName, $newPriority);

        return $newPriority;
    }

    /**
     * Calculate new priority ensuring it stays within valid bounds
     */
    private function calculateNewPriority(float $currentPriority, float $delta): float
    {
        $newPriority = $currentPriority + $delta;
        return max(self::MINIMUM_PRIORITY, round($newPriority, 1));
    }

    /**
     * Get all activities in a project
     *
     * @return array<array{activity: string, priority: float}>
     */
    public function getAllActivities(int $projectId): array
    {
        return $this->dataSource->getActivities($projectId);
    }

    /**
     * Get a single activity by name within a project
     *
     * @return array{activity: string, priority: float}|null
     */
    public function getActivityByName(int $projectId, string $name): ?array
    {
        return $this->dataSource->getActivityByName($projectId, $name);
    }

    /**
     * Add a new activity to a project
     *
     * @throws \Exception If activity already exists
     */
    public function addActivity(int $projectId, string $name, float $priority = 1.0): void
    {
        $this->dataSource->addActivity($projectId, $name, $priority);
    }

    /**
     * Delete an activity from a project
     *
     * @return bool True if deleted, false if not found
     */
    public function deleteActivity(int $projectId, string $name): bool
    {
        return $this->dataSource->deleteActivity($projectId, $name);
    }

    /**
     * Get the standard priority adjustment increment
     */
    public static function getPriorityAdjustment(): float
    {
        return self::PRIORITY_ADJUSTMENT;
    }

    /**
     * Get the minimum allowed priority
     */
    public static function getMinimumPriority(): float
    {
        return self::MINIMUM_PRIORITY;
    }
}
