<?php

require_once __DIR__ . '/resource-config.php';
require_once __DIR__ . '/repository.php';

function scenario_config_error($status, $message) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $message], JSON_PRETTY_PRINT) . "\n";
}

function handle_scenario_config($docRoot) {
    if (!is_local_request()) {
        scenario_config_error(403, 'Scenario selection is only available from localhost');
        return;
    }

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'OPTIONS') {
        http_response_code(204);
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Allow: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        return;
    }

    $raw = file_get_contents('php://input');
    $body = $raw === '' || $raw === false ? [] : json_decode($raw, true);
    if ($body === null && $raw !== '' && $raw !== false) {
        scenario_config_error(400, 'Request body must be valid JSON');
        return;
    }

    $resource = is_array($body) ? ($body['resource'] ?? null) : null;
    if ($method === 'GET') {
        $resource = $_GET['resource'] ?? $resource;
    }
    if (!is_valid_model_name($resource) || !is_crud_resource($docRoot, $resource)) {
        scenario_config_error(400, 'Resource does not exist');
        return;
    }

    $names = scenario_names($docRoot, $resource);
    $active = active_scenario($docRoot, $resource);

    if ($method === 'GET') {
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        echo json_encode([
            'resource' => $resource,
            'activeScenario' => $active,
            'scenarios' => $names,
        ], JSON_PRETTY_PRINT) . "\n";
        return;
    }

    if ($method !== 'POST') {
        header('Allow: GET, POST, OPTIONS');
        scenario_config_error(405, 'Method not allowed');
        return;
    }

    $scenario = $body['scenario'] ?? null;
    if (!is_valid_scenario_name($scenario)) {
        scenario_config_error(400, 'Invalid scenario name. Use lowercase letters, digits, and hyphens.');
        return;
    }
    if (!in_array($scenario, $names, true)) {
        scenario_config_error(404, "Scenario '$scenario' does not exist for resource '$resource'");
        return;
    }

    $resourceDir = resource_dir($docRoot, $resource);
    list($lock, $lockErr) = acquire_lock($resourceDir);
    if ($lockErr !== null) {
        scenario_config_error(500, $lockErr);
        return;
    }

    try {
        $listPath = $resourceDir . '/list.json';
        list($config, $readErr) = read_json($listPath);
        if ($readErr !== null || !is_array($config)) {
            scenario_config_error(500, 'Invalid list configuration');
            return;
        }

        $config['activeScenario'] = $scenario;
        list($ok, $writeErr) = write_json_atomic($resourceDir, 'list.json', $config);
        if (!$ok) {
            scenario_config_error(500, 'Failed to update active scenario: ' . $writeErr);
            return;
        }

        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        echo json_encode([
            'updated' => true,
            'resource' => $resource,
            'activeScenario' => $scenario,
            'scenarios' => $names,
        ], JSON_PRETTY_PRINT) . "\n";
    } finally {
        release_lock($lock);
    }
}
