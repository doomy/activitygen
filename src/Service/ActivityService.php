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
     * Get a random activity suggestion based on priority-weighted selection
     *
     * @return array{activity: string, priority: float, minRoll: float}|null
     */
    public function getRandomSuggestion(): ?array
    {
        $maxPriority = $this->dataSource->getMaxPriority();
        
        if ($maxPriority == 0) {
            return null;
        }

        $minRoll = mt_rand(0, (int)($maxPriority * 10)) / 10;
        $result = $this->dataSource->selectRandomActivity($minRoll);
        
        if ($result) {
            $result['minRoll'] = $minRoll;
        }

        return $result;
    }

    /**
     * Adjust activity priority by a delta amount
     *
     * @param string $activityName
     * @param float $delta
     * @return float The new priority value
     * @throws \Exception If activity not found
     */
    public function adjustPriority(string $activityName, float $delta): float
    {
        $activity = $this->dataSource->getActivityByName($activityName);
        
        if (!$activity) {
            throw new \Exception("Activity not found: $activityName");
        }

        $newPriority = $this->calculateNewPriority($activity['priority'], $delta);
        $this->dataSource->updatePriority($activityName, $newPriority);

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
     * Get all activities
     *
     * @return array<array{activity: string, priority: float}>
     */
    public function getAllActivities(): array
    {
        return $this->dataSource->getActivities();
    }

    /**
     * Get a single activity by name
     *
     * @param string $name
     * @return array{activity: string, priority: float}|null
     */
    public function getActivityByName(string $name): ?array
    {
        return $this->dataSource->getActivityByName($name);
    }

    /**
     * Add a new activity
     *
     * @param string $name
     * @param float $priority
     * @throws \Exception If activity already exists
     */
    public function addActivity(string $name, float $priority = 1.0): void
    {
        $this->dataSource->addActivity($name, $priority);
    }

    /**
     * Delete an activity
     *
     * @param string $name
     * @return bool True if deleted, false if not found
     */
    public function deleteActivity(string $name): bool
    {
        return $this->dataSource->deleteActivity($name);
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
