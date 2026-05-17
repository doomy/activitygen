<?php

namespace App\DataSource;

interface DataSourceInterface
{
    public function getActivities(int $projectId): array;

    public function getActivityByName(string $name, int $projectId): ?array;

    public function addActivity(string $name, float $priority, int $projectId): void;

    public function deleteActivity(string $name, int $projectId): bool;

    public function updatePriority(string $name, float $priority, int $projectId): void;

    public function getMaxPriority(int $projectId): float;

    public function selectRandomActivity(float $minRoll, int $projectId): ?array;

    public function getProjects(): array;

    public function getProjectByName(string $name): ?array;
}
