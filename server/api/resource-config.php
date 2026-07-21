<?php

require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/repository.php';

function is_local_request() {
    $addr = $_SERVER['REMOTE_ADDR'] ?? '';
    return $addr === '127.0.0.1' || $addr === '::1';
}

function is_valid_model_name($name) {
    return is_string($name) && preg_match('/^[a-z][a-z0-9-]*$/', $name) && strlen($name) <= 64;
}

function is_reserved_model($name) {
    $reserved = ['admin', 'routes-config', 'api-test', 'health', 'mocks'];
    return in_array($name, $reserved, true);
}

function resource_exists($docRoot, $model) {
    return file_exists($docRoot . '/routes/' . $model . '.json')
        || is_dir($docRoot . '/api/' . $model);
}

function build_route_file($model, $operations) {
    $routes = [];

    $hasListOrCreate = !empty($operations['list']) || !empty($operations['create']);
    if ($hasListOrCreate) {
        $entry = [];
        if (!empty($operations['list'])) {
            $entry['GET'] = ['operation' => 'list', 'status' => 200];
        }
        if (!empty($operations['create'])) {
            $entry['POST'] = ['operation' => 'create', 'status' => 201];
        }
        if (!empty($entry)) {
            $routes['/api/' . $model] = $entry;
        }
    }

    $hasDetail = !empty($operations['read']) || !empty($operations['patch']) || !empty($operations['delete']);
    if ($hasDetail) {
        $entry = [];
        if (!empty($operations['read'])) {
            $entry['GET'] = ['operation' => 'read', 'status' => 200];
        }
        if (!empty($operations['patch'])) {
            $entry['PATCH'] = ['operation' => 'patch', 'status' => 200];
        }
        if (!empty($operations['delete'])) {
            $entry['DELETE'] = ['operation' => 'delete', 'status' => 200];
        }
        if (!empty($entry)) {
            $routes['/api/' . $model . '/{id}'] = $entry;
        }
    }

    if (!empty($operations['reset'])) {
        $routes['/api/' . $model . '/reset'] = [
            'POST' => ['operation' => 'reset', 'status' => 200],
        ];
    }

    return $routes;
}

function build_complete_schema($userFields) {
    $schema = [];
    $schema['id'] = ['type' => 'number', 'automatic' => true];
    foreach ($userFields as $name => $def) {
        $schema[$name] = $def;
    }
    $schema['createdAt'] = ['type' => 'datetime', 'automatic' => true];
    $schema['modifiedAt'] = ['type' => 'datetime', 'automatic' => true];
    $schema['version'] = ['type' => 'number', 'automatic' => true];
    return $schema;
}

function build_list_fields($userFields) {
    $fields = ['id'];
    foreach ($userFields as $name => $def) {
        $fields[] = $name;
    }
    $fields[] = 'version';
    return $fields;
}

function handle_resource_config($docRoot) {
    if (!is_local_request()) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Route creation only available from localhost'], JSON_PRETTY_PRINT) . "\n";
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
        http_response_code(405);
        header('Content-Type: application/json; charset=utf-8');
        header('Allow: POST, OPTIONS');
        echo json_encode(['error' => 'Method not allowed'], JSON_PRETTY_PRINT) . "\n";
        return;
    }

    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Request body must be a JSON object'], JSON_PRETTY_PRINT) . "\n";
        return;
    }

    $model = $body['model'] ?? null;
    if (!is_valid_model_name($model)) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Invalid model name. Use lowercase letters, digits, and hyphens (max 64 chars).'], JSON_PRETTY_PRINT) . "\n";
        return;
    }
    if (is_reserved_model($model)) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => "Model name '$model' is reserved."], JSON_PRETTY_PRINT) . "\n";
        return;
    }
    if (resource_exists($docRoot, $model)) {
        http_response_code(409);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => "Resource '$model' already exists."], JSON_PRETTY_PRINT) . "\n";
        return;
    }

    $operations = $body['routes'] ?? [];
    if (!is_array($operations)) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'routes must be an object with boolean values'], JSON_PRETTY_PRINT) . "\n";
        return;
    }
    $validOps = ['list', 'create', 'read', 'patch', 'delete', 'reset'];
    $selectedOps = [];
    foreach ($operations as $op => $enabled) {
        if (!in_array($op, $validOps, true)) continue;
        if ($enabled) $selectedOps[$op] = true;
    }
    if (empty($selectedOps)) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'At least one operation must be selected.'], JSON_PRETTY_PRINT) . "\n";
        return;
    }

    $userSchema = $body['schema'] ?? [];
    if (!is_array($userSchema)) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'schema must be an object'], JSON_PRETTY_PRINT) . "\n";
        return;
    }

    $err = validate_schema($userSchema);
    if ($err !== null) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => "Schema validation: $err"], JSON_PRETTY_PRINT) . "\n";
        return;
    }

    foreach ($userSchema as $name => $def) {
        if (empty($def['editable']) || !empty($def['automatic'])) {
            http_response_code(400);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => "Schema field '$name' must be editable and not automatic"], JSON_PRETTY_PRINT) . "\n";
            return;
        }
    }

    $limit = isset($body['limit']) ? max(1, min(100, intval($body['limit']))) : 10;

    $seed = $body['seed'] ?? [];
    if (!is_array($seed)) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'seed must be an array'], JSON_PRETTY_PRINT) . "\n";
        return;
    }

    $completeSchema = build_complete_schema($userSchema);
    $listFields = build_list_fields($userSchema);
    $routeConfig = build_route_file($model, $selectedOps);

    $ids = [];
    foreach ($seed as $i => $record) {
        if (!is_array($record)) {
            http_response_code(400);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => "Seed record $i must be an object"], JSON_PRETTY_PRINT) . "\n";
            return;
        }
        if (!isset($record['id']) || !is_int($record['id']) || $record['id'] < 1) {
            http_response_code(400);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => "Seed record $i must have a positive integer id"], JSON_PRETTY_PRINT) . "\n";
            return;
        }
        if (in_array($record['id'], $ids, true)) {
            http_response_code(400);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => "Seed record $i: duplicate id {$record['id']}"], JSON_PRETTY_PRINT) . "\n";
            return;
        }
        $ids[] = $record['id'];
        $typeErr = validate_seed_types($record, $completeSchema);
        if ($typeErr !== null) {
            http_response_code(400);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => "Seed record $i (id {$record['id']}): $typeErr"], JSON_PRETTY_PRINT) . "\n";
            return;
        }
    }

    $maxId = !empty($ids) ? max($ids) : 0;

    $touched = [];

    try {
        $resDir = $docRoot . '/api/' . $model;
        $idDir = $resDir . '/id';
        $routesDir = $docRoot . '/routes';

        if (!is_dir($routesDir)) {
            if (!mkdir($routesDir, 0755, true)) {
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['error' => 'Could not create routes directory'], JSON_PRETTY_PRINT) . "\n";
                return;
            }
        }

        if (!is_dir($resDir)) {
            if (!mkdir($resDir, 0755, true)) {
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['error' => 'Could not create resource directory'], JSON_PRETTY_PRINT) . "\n";
                return;
            }
            $touched[] = $resDir;
        }

        if (!is_dir($idDir)) {
            if (!mkdir($idDir, 0755, true)) {
                cleanup_touched($touched);
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['error' => 'Could not create id directory'], JSON_PRETTY_PRINT) . "\n";
                return;
            }
            $touched[] = $idDir;
        }

        $routePath = $routesDir . '/' . $model . '.json';
        list($ok, $writeErr) = write_json_atomic($routesDir, $model . '.json', $routeConfig);
        if (!$ok) {
            cleanup_touched($touched);
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Failed to write route file: ' . $writeErr], JSON_PRETTY_PRINT) . "\n";
            return;
        }
        $touched[] = $routePath;

        list($ok, $writeErr) = write_json_atomic($resDir, 'schema.json', $completeSchema);
        if (!$ok) {
            cleanup_touched($touched);
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Failed to write schema: ' . $writeErr], JSON_PRETTY_PRINT) . "\n";
            return;
        }

        list($ok, $writeErr) = write_json_atomic($resDir, 'list.json', [
            'fields' => $listFields,
            '_limit' => $limit,
            'last_id' => $maxId,
        ]);
        if (!$ok) {
            cleanup_touched($touched);
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Failed to write list.json: ' . $writeErr], JSON_PRETTY_PRINT) . "\n";
            return;
        }

        foreach ($seed as $record) {
            list($ok, $writeErr) = write_json_atomic($idDir, $record['id'] . '.json', $record);
            if (!$ok) {
                cleanup_touched($touched);
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['error' => "Failed to write seed record {$record['id']}: " . $writeErr], JSON_PRETTY_PRINT) . "\n";
                return;
            }
        }

        list($ok, $writeErr) = write_json_atomic($resDir, 'seed.json', $seed);
        if (!$ok) {
            cleanup_touched($touched);
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Failed to write seed.json: ' . $writeErr], JSON_PRETTY_PRINT) . "\n";
            return;
        }

        $generatedOps = [];
        foreach ($validOps as $op) {
            if (!empty($selectedOps[$op])) {
                $generatedOps[] = $op;
            }
        }

        http_response_code(201);
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        echo json_encode([
            'created' => true,
            'resource' => $model,
            'routes' => $generatedOps,
            'limit' => $limit,
            'fields' => count($userSchema),
            'seeded' => count($seed),
            'last_id' => $maxId,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    } catch (\Throwable $e) {
        cleanup_touched($touched);
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Failed to create resource: ' . $e->getMessage()], JSON_PRETTY_PRINT) . "\n";
    }
}

function cleanup_touched($paths) {
    foreach ($paths as $path) {
        if (is_file($path)) {
            @unlink($path);
        } elseif (is_dir($path)) {
            array_map('unlink', glob($path . '/*') ?: []);
            @rmdir($path);
        }
    }
}
