<?php

namespace App\Tui;

/**
 * HTTP client for the ActivityGen REST API (public/api/index.php).
 *
 * The TUI talks to the exact same endpoints as the web frontend so that both
 * interfaces always behave identically.
 */
class ApiClient
{
    private string $baseUrl;

    public function __construct(string $baseUrl)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function getProjects(): array
    {
        return $this->request('GET', '/projects');
    }

    public function addProject(string $name): array
    {
        return $this->request('POST', '/projects', ['name' => $name]);
    }

    public function getActivities(int $projectId): array
    {
        return $this->request('GET', '/activities?' . http_build_query(['project_id' => $projectId]));
    }

    public function getSuggestion(int $projectId): array
    {
        return $this->request('GET', '/activities/suggest?' . http_build_query(['project_id' => $projectId]));
    }

    public function addActivity(int $projectId, string $name, float $priority): array
    {
        return $this->request('POST', '/activities', [
            'name' => $name,
            'priority' => $priority,
            'project_id' => $projectId,
        ]);
    }

    public function deleteActivity(int $projectId, string $name): array
    {
        return $this->request(
            'DELETE',
            '/activities/' . rawurlencode($name) . '?' . http_build_query(['project_id' => $projectId])
        );
    }

    public function adjustPriority(int $projectId, string $name, float $delta): array
    {
        return $this->request('PATCH', '/activities/' . rawurlencode($name) . '/priority', [
            'delta' => $delta,
            'project_id' => $projectId,
        ]);
    }

    public function getSyncStatus(): array
    {
        return $this->request('GET', '/sync/status');
    }

    public function sync(): array
    {
        return $this->request('POST', '/sync');
    }

    private function request(string $method, string $path, ?array $body = null): array
    {
        $options = [
            'http' => [
                'method' => $method,
                'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
                'ignore_errors' => true,
                'timeout' => 15,
            ],
        ];

        if ($body !== null) {
            $options['http']['content'] = json_encode($body);
        }

        $raw = @file_get_contents($this->baseUrl . $path, false, stream_context_create($options));

        if ($raw === false) {
            throw new ApiException("API unreachable at {$this->baseUrl}");
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            throw new ApiException('Invalid API response');
        }

        if (!($decoded['success'] ?? false)) {
            throw new ApiException($decoded['error'] ?? 'Unknown API error');
        }

        return $decoded['data'] ?? [];
    }
}
