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

// GET /activities - List all activities
$app->get('/activities', function (Request $request, Response $response) use ($activityService) {
    try {
        $activities = $activityService->getAllActivities();
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

// GET /activities/suggest - Get a random activity suggestion
$app->get('/activities/suggest', function (Request $request, Response $response) use ($activityService) {
    try {
        $suggestion = $activityService->getRandomSuggestion();
        
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

// POST /activities - Add a new activity
$app->post('/activities', function (Request $request, Response $response) use ($activityService) {
    try {
        $body = $request->getParsedBody();
        $name = $body['name'] ?? null;
        $priority = isset($body['priority']) ? (float)$body['priority'] : 1.0;

        if (!$name || trim($name) === '') {
            return jsonResponse($response, [
                'success' => false,
                'error' => 'Activity name is required',
            ], 400);
        }

        $activityService->addActivity(trim($name), $priority);

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

// DELETE /activities/{name} - Delete an activity
$app->delete('/activities/{name:.+}', function (Request $request, Response $response, array $args) use ($activityService) {
    try {
        $name = rawurldecode($args['name']);
        $deleted = $activityService->deleteActivity($name);

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

// PATCH /activities/{name}/priority - Update activity priority
$app->patch('/activities/{name:.+}/priority', function (Request $request, Response $response, array $args) use ($activityService) {
    try {
        $name = urldecode($args['name']);
        $body = $request->getParsedBody();
        $delta = isset($body['delta']) ? (float)$body['delta'] : null;

        if ($delta === null) {
            return jsonResponse($response, [
                'success' => false,
                'error' => 'Delta is required',
            ], 400);
        }

        $newPriority = $activityService->adjustPriority($name, $delta);

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
