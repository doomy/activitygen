<?php

namespace App\DataSource;

interface DataSourceInterface
{
    /**
     * Fetch all projects
     *
     * @return array<array{id: int, name: string}>
     */
    public function getProjects(): array;

    /**
     * Get single project by id
     *
     * @return array{id: int, name: string}|null
     */
    public function getProjectById(int $id): ?array;

    /**
     * Fetch all activities for a project
     *
     * @return array<array{activity: string, priority: float}>
     */
    public function getActivities(int $projectId): array;

    /**
     * Get single activity by name within a project
     *
     * @return array{activity: string, priority: float}|null
     */
    public function getActivityByName(int $projectId, string $name): ?array;

    /**
     * Add a new activity to a project
     *
     * @throws \Exception If activity already exists in the project
     */
    public function addActivity(int $projectId, string $name, float $priority): void;

    /**
     * Delete an activity from a project
     *
     * @return bool True if deleted, false if not found
     */
    public function deleteActivity(int $projectId, string $name): bool;

    /**
     * Update activity priority within a project
     */
    public function updatePriority(int $projectId, string $name, float $priority): void;

    /**
     * Get maximum priority from all activities in a project
     */
    public function getMaxPriority(int $projectId): float;

    /**
     * Select random activity based on priority-weighted algorithm within a project
     *
     * @return array{activity: string, priority: float}|null
     */
    public function selectRandomActivity(int $projectId, float $minRoll): ?array;
}
