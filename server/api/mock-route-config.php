<?php

require_once __DIR__ . '/resource-config.php';
require_once __DIR__ . '/repository.php';
require_once __DIR__ . '/route.php';

function mock_config_error($status, $message) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $message], JSON_PRETTY_PRINT) . "\n";
}

function is_mock_route_path_for_resource($path, $resource) {
    $prefix = '/api/' . $resource;
    if (!is_string($path) || !str_starts_with($path, $prefix . '/')) return false;
    if ($path === $prefix || $path === $prefix . '/reset') return false;
    if (preg_match('#^' . preg_quote($prefix, '#') . '/(?:\d+|\{[A-Za-z_][A-Za-z0-9_]*\})$#', $path)) return false;
    return true;
}

function mock_response_filename($responseDir, $path, $method) {
    $segments = explode('/', trim($path, '/'));
    $name = end($segments);
    $name = trim($name, '{}');
    $name = strtolower(preg_replace('/[^A-Za-z0-9_-]+/', '-', $name));
    $name = trim($name, '-_');
    if ($name === '') $name = 'response';

    $filename = $name . '.json';
    if (!file_exists($responseDir . '/' . $filename)) return $filename;

    $filename = $name . '-' . strtolower($method) . '.json';
    $suffix = 2;
    while (file_exists($responseDir . '/' . $filename)) {
        $filename = $name . '-' . strtolower($method) . '-' . $suffix . '.json';
        $suffix++;
    }
    return $filename;
}

function handle_mock_route_config($docRoot) {
    if (!is_local_request()) {
        mock_config_error(403, 'Mock route creation only available from localhost');
        return;
    }

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'OPTIONS') {
        http_response_code(204);
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Allow: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        return;
    }
    if ($method !== 'POST') {
        header('Allow: POST, OPTIONS');
        mock_config_error(405, 'Method not allowed');
        return;
    }

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        mock_config_error(400, 'Request body must be a JSON object');
        return;
    }

    $resource = $body['resource'] ?? null;
    $path = $body['path'] ?? null;
    $routeMethod = strtoupper($body['method'] ?? '');
    $status = isset($body['status']) ? (int)$body['status'] : 0;
    if (!is_valid_model_name($resource) || !is_crud_resource($docRoot, $resource)) {
        mock_config_error(400, 'Resource does not exist');
        return;
    }
    if (!is_mock_route_path_for_resource($path, $resource) || compile_path($path) === null) {
        mock_config_error(400, 'Path must be a non-CRUD route under /api/' . $resource . '/');
        return;
    }
    if (!preg_match('/^[A-Z]+$/', $routeMethod)) {
        mock_config_error(400, 'Invalid HTTP method');
        return;
    }
    if ($status < 100 || $status > 599) {
        mock_config_error(400, 'Status must be between 100 and 599');
        return;
    }
    if (!array_key_exists('response', $body)) {
        mock_config_error(400, 'Response body is required');
        return;
    }
    $pathErr = validate_path_entry($path, [$routeMethod => ['file' => 'api/' . $resource . '/scenarios/{{activeScenario}}/mocks/placeholder.json', 'status' => $status]]);
    if ($pathErr !== null) {
        mock_config_error(400, $pathErr);
        return;
    }

    $routesDir = $docRoot . '/routes';
    list($lock, $lockErr) = acquire_lock($routesDir);
    if ($lockErr !== null) {
        mock_config_error(500, $lockErr);
        return;
    }

    try {
        $routeFilename = $resource . '.json';
        $routePath = $routesDir . '/' . $routeFilename;
        $routes = [];
        if (file_exists($routePath)) {
            $routes = json_decode(file_get_contents($routePath), true);
            if (!is_array($routes)) {
                mock_config_error(500, 'Existing route configuration is invalid');
                return;
            }
            foreach ($routes as $existingPath => $methods) {
                $err = validate_path_entry($existingPath, $methods);
                if ($err !== null) {
                    mock_config_error(500, 'Existing route configuration is invalid: ' . $err);
                    return;
                }
            }
        }
        if (isset($routes[$path][$routeMethod])) {
            mock_config_error(409, 'A mock route with this path and method already exists');
            return;
        }

        $activeScenario = active_scenario($docRoot, $resource);
        if ($activeScenario === null) {
            mock_config_error(500, 'Active scenario does not exist');
            return;
        }

        $scenarioNames = scenario_names($docRoot, $resource);
        $activeResponseDir = scenario_dir($docRoot, $resource, $activeScenario) . '/mocks';
        $responseFilename = mock_response_filename($activeResponseDir, $path, $routeMethod);
        $writtenResponses = [];
        foreach ($scenarioNames as $scenarioName) {
            $responseDir = scenario_dir($docRoot, $resource, $scenarioName) . '/mocks';
            list($ok, $writeErr) = write_json_atomic($responseDir, $responseFilename, $body['response']);
            if (!$ok) {
                foreach ($writtenResponses as $written) @unlink($written);
                mock_config_error(500, 'Failed to write mock response: ' . $writeErr);
                return;
            }
            $writtenResponses[] = $responseDir . '/' . $responseFilename;
        }

        if (!isset($routes[$path])) $routes[$path] = [];
        $routes[$path][$routeMethod] = [
            'file' => 'api/' . $resource . '/scenarios/{{activeScenario}}/mocks/' . $responseFilename,
            'status' => $status,
        ];
        list($ok, $writeErr) = write_json_atomic($routesDir, $routeFilename, $routes);
        if (!$ok) {
            foreach ($writtenResponses as $written) @unlink($written);
            mock_config_error(500, 'Failed to write mock route: ' . $writeErr);
            return;
        }

        http_response_code(201);
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        echo json_encode([
            'created' => true,
            'resource' => $resource,
            'method' => $routeMethod,
            'path' => $path,
            'status' => $status,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    } finally {
        release_lock($lock);
    }
}
