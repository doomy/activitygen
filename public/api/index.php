<?php

require __DIR__ . '/../../vendor/autoload.php';

use App\ConnectionManager;
use App\Service\ActivityService;
use App\Sync\SyncManager;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Slim\Exception\HttpNotFoundException;

// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Initialize connection manager
$connectionManager = new ConnectionManager();
$dataSource = $connectionManager->getDataSource();
$activityService = new ActivityService($dataSource);

// Create Slim app
$app = AppFactory::create();
$app->setBasePath('/api');
$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();

// Error handling
$errorMiddleware = $app->addErrorMiddleware(true, true, true);

// Helper function for JSON responses
function jsonResponse(Response $response, array $data, int $status = 200): Response
{
    $response->getBody()->write(json_encode($data));
    return $response
        ->withHeader('Content-Type', 'application/json')
        ->withStatus($status);
}

// Helper to read and validate the project_id query/body parameter
function requireProjectId(array $params): ?int
{
    if (!isset($params['project_id']) || trim((string)$params['project_id']) === '') {
        return null;
    }

    return (int)$params['project_id'];
}

// GET /projects - List all projects
$app->get('/projects', function (Request $request, Response $response) use ($activityService) {
    try {
        $projects = $activityService->getProjects();
        return jsonResponse($response, [
            'success' => true,
            'data' => $projects,
        ]);
    } catch (\Exception $e) {
        return jsonResponse($response, [
            'success' => false,
            'error' => $e->getMessage(),
        ], 500);
    }
});

// POST /projects - Add a new project (requires an online remote connection)
$app->post('/projects', function (Request $request, Response $response) use ($connectionManager) {
    try {
        $body = $request->getParsedBody();
        $name = $body['name'] ?? null;

        if (!$name || trim($name) === '') {
            return jsonResponse($response, [
                'success' => false,
                'error' => 'Project name is required',
            ], 400);
        }

        $remoteDataSource = $connectionManager->getRemoteDataSource();
        if (!$connectionManager->isOnline() || !$remoteDataSource) {
            return jsonResponse($response, [
                'success' => false,
                'error' => 'Cannot add a project while offline',
            ], 400);
        }

        $project = $remoteDataSource->addProject(trim($name));

        return jsonResponse($response, [
            'success' => true,
            'data' => ActivityService::describeProject($project),
        ], 201);
    } catch (\Exception $e) {
        return jsonResponse($response, [
            'success' => false,
            'error' => $e->getMessage(),
        ], 500);
    }
});

// DELETE /projects/{id} - Delete (archive) a project
// The project and its activities are kept in the database behind an `archived`
// flag, so the delete is reversible. Requires an online remote connection,
// mirroring POST /projects.
$app->delete('/projects/{id:[0-9]+}', function (Request $request, Response $response, array $args) use ($connectionManager) {
    try {
        $projectId = (int)$args['id'];

        if (!ActivityService::isProjectDeletable($projectId)) {
            return jsonResponse($response, [
                'success' => false,
                'error' => 'The default project cannot be deleted',
            ], 400);
        }

        $remoteDataSource = $connectionManager->getRemoteDataSource();
        if (!$connectionManager->isOnline() || !$remoteDataSource) {
            return jsonResponse($response, [
                'success' => false,
                'error' => 'Cannot delete a project while offline',
            ], 400);
        }

        $project = $remoteDataSource->getProjectById($projectId);
        if (!$project || $project['archived']) {
            return jsonResponse($response, [
                'success' => false,
                'error' => 'Project not found',
            ], 404);
        }

        $remoteDataSource->archiveProject($projectId);

        return jsonResponse($response, [
            'success' => true,
            'data' => [
                'deleted' => $project['name'],
                'archived' => true,
            ],
        ]);
    } catch (\Exception $e) {
        return jsonResponse($response, [
            'success' => false,
            'error' => $e->getMessage(),
        ], 500);
    }
});

// GET /activities - List the activities in a project, optionally fuzzy-filtered
// by `q`. With a query the results come back best match first, each with the
// character offsets that matched, so both frontends rank and highlight alike.
$app->get('/activities', function (Request $request, Response $response) use ($activityService) {
    try {
        $queryParams = $request->getQueryParams();
        $projectId = requireProjectId($queryParams);
        if ($projectId === null) {
            return jsonResponse($response, [
                'success' => false,
                'error' => 'project_id is required',
            ], 400);
        }

        $activities = $activityService->searchActivities($projectId, (string)($queryParams['q'] ?? ''));
        return jsonResponse($response, [
            'success' => true,
            'data' => $activities,
        ]);
    } catch (\Exception $e) {
        return jsonResponse($response, [
            'success' => false,
            'error' => $e->getMessage(),
        ], 500);
    }
});

// GET /activities/suggest - Get a random activity suggestion from a project
$app->get('/activities/suggest', function (Request $request, Response $response) use ($activityService) {
    try {
        $projectId = requireProjectId($request->getQueryParams());
        if ($projectId === null) {
            return jsonResponse($response, [
                'success' => false,
                'error' => 'project_id is required',
            ], 400);
        }

        $suggestion = $activityService->getRandomSuggestion($projectId);

        if (!$suggestion) {
            return jsonResponse($response, [
                'success' => false,
                'error' => 'No activities available',
            ], 404);
        }

        return jsonResponse($response, [
            'success' => true,
            'data' => $suggestion,
        ]);
    } catch (\Exception $e) {
        return jsonResponse($response, [
            'success' => false,
            'error' => $e->getMessage(),
        ], 500);
    }
});

// POST /activities - Add a new activity to a project
$app->post('/activities', function (Request $request, Response $response) use ($activityService) {
    try {
        $body = $request->getParsedBody();
        $projectId = requireProjectId($body ?? []);
        $name = $body['name'] ?? null;
        $priority = isset($body['priority']) ? (float)$body['priority'] : 1.0;

        if ($projectId === null) {
            return jsonResponse($response, [
                'success' => false,
                'error' => 'project_id is required',
            ], 400);
        }

        if (!$name || trim($name) === '') {
            return jsonResponse($response, [
                'success' => false,
                'error' => 'Activity name is required',
            ], 400);
        }

        $activityService->addActivity($projectId, trim($name), $priority);

        return jsonResponse($response, [
            'success' => true,
            'data' => [
                'activity' => trim($name),
                'priority' => $priority,
            ],
        ], 201);
    } catch (\Exception $e) {
        return jsonResponse($response, [
            'success' => false,
            'error' => $e->getMessage(),
        ], 500);
    }
});

// DELETE /activities/{name} - Delete an activity from a project
$app->delete('/activities/{name:.+}', function (Request $request, Response $response, array $args) use ($activityService) {
    try {
        $projectId = requireProjectId($request->getQueryParams());
        if ($projectId === null) {
            return jsonResponse($response, [
                'success' => false,
                'error' => 'project_id is required',
            ], 400);
        }

        $name = rawurldecode($args['name']);
        $deleted = $activityService->deleteActivity($projectId, $name);

        if (!$deleted) {
            return jsonResponse($response, [
                'success' => false,
                'error' => 'Activity not found',
            ], 404);
        }

        return jsonResponse($response, [
            'success' => true,
            'data' => ['deleted' => $name],
        ]);
    } catch (\Exception $e) {
        return jsonResponse($response, [
            'success' => false,
            'error' => $e->getMessage(),
        ], 500);
    }
});

// PATCH /activities/{name}/priority - Update activity priority within a project
$app->patch('/activities/{name:.+}/priority', function (Request $request, Response $response, array $args) use ($activityService) {
    try {
        $body = $request->getParsedBody();
        $projectId = requireProjectId($body ?? []);
        $delta = isset($body['delta']) ? (float)$body['delta'] : null;

        if ($projectId === null) {
            return jsonResponse($response, [
                'success' => false,
                'error' => 'project_id is required',
            ], 400);
        }

        if ($delta === null) {
            return jsonResponse($response, [
                'success' => false,
                'error' => 'Delta is required',
            ], 400);
        }

        $name = rawurldecode($args['name']);
        $newPriority = $activityService->adjustPriority($projectId, $name, $delta);

        return jsonResponse($response, [
            'success' => true,
            'data' => [
                'activity' => $name,
                'priority' => $newPriority,
            ],
        ]);
    } catch (\Exception $e) {
        return jsonResponse($response, [
            'success' => false,
            'error' => $e->getMessage(),
        ], 500);
    }
});

// GET /sync/status - Get synchronization status
$app->get('/sync/status', function (Request $request, Response $response) use ($connectionManager) {
    try {
        $isOnline = $connectionManager->isOnline();
        $status = $connectionManager->getConnectionStatus();
        $pendingCount = 0;

        if (!$isOnline) {
            $localDataSource = $connectionManager->getLocalDataSource();
            if ($localDataSource->hasPendingSync()) {
                $pendingCount = count($localDataSource->getSyncQueue());
            }
        }

        return jsonResponse($response, [
            'success' => true,
            'data' => [
                'online' => $isOnline,
                'status' => $status,
                'pendingOperations' => $pendingCount,
            ],
        ]);
    } catch (\Exception $e) {
        return jsonResponse($response, [
            'success' => false,
            'error' => $e->getMessage(),
        ], 500);
    }
});

// POST /sync - Manually trigger synchronization
$app->post('/sync', function (Request $request, Response $response) use ($connectionManager) {
    try {
        if (!$connectionManager->isOnline()) {
            return jsonResponse($response, [
                'success' => false,
                'error' => 'Cannot sync while offline',
            ], 400);
        }

        $localDataSource = $connectionManager->getLocalDataSource();
        $remoteDataSource = $connectionManager->getRemoteDataSource();

        if (!$remoteDataSource) {
            return jsonResponse($response, [
                'success' => false,
                'error' => 'Remote connection unavailable',
            ], 500);
        }

        $syncManager = new SyncManager($remoteDataSource, $localDataSource);
        $result = $syncManager->fullSync();

        return jsonResponse($response, [
            'success' => true,
            'data' => $result,
        ]);
    } catch (\Exception $e) {
        return jsonResponse($response, [
            'success' => false,
            'error' => $e->getMessage(),
        ], 500);
    }
});

$app->run();
