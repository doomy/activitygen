<?php

namespace App\DataSource;

interface DataSourceInterface
{
    /**
     * Fetch all activities
     *
     * @return array<array{activity: string, priority: float}>
     */
    public function getActivities(): array;

    /**
     * Get single activity by name
     *
     * @param string $name
     * @return array{activity: string, priority: float}|null
     */
    public function getActivityByName(string $name): ?array;

    /**
     * Add a new activity
     *
     * @param string $name
     * @param float $priority
     * @throws \Exception If activity already exists
     */
    public function addActivity(string $name, float $priority): void;

    /**
     * Delete an activity
     *
     * @param string $name
     * @return bool True if deleted, false if not found
     */
    public function deleteActivity(string $name): bool;

    /**
     * Update activity priority
     *
     * @param string $name
     * @param float $priority
     */
    public function updatePriority(string $name, float $priority): void;

    /**
     * Get maximum priority from all activities
     *
     * @return float
     */
    public function getMaxPriority(): float;

    /**
     * Select random activity based on priority-weighted algorithm
     *
     * @param float $minRoll
     * @return array{activity: string, priority: float}|null
     */
    public function selectRandomActivity(float $minRoll): ?array;
}
