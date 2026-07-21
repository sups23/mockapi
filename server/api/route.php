<?php

require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/repository.php';
require_once __DIR__ . '/../../server/helpers.php';
require_once __DIR__ . '/list.php';

function load_route_file($docRoot, $resource) {
    $path = $docRoot . '/routes/' . $resource . '.json';
    if (!file_exists($path)) {
        return [null, null];
    }

    $content = file_get_contents($path);
    $decoded = json_decode($content, true);
    if (!is_array($decoded)) {
        return [null, 'Invalid route file JSON'];
    }

    $routes = [];
    foreach ($decoded as $urlPattern => $methods) {
        if (!is_array($methods)) {
            return [null, "Route $urlPattern: methods must be an object"];
        }

        $compiled = compile_path($urlPattern);
        if ($compiled === null) {
            return [null, "Invalid URL pattern: $urlPattern"];
        }

        $err = validate_path_entry($urlPattern, $methods);
        if ($err !== null) return [null, $err];

        $isStatic = !str_contains($urlPattern, '{');

        foreach ($methods as $method => $config) {
            $operation = $config['operation'];
            $status = isset($config['status']) ? (int)$config['status'] : 200;
            $explicitPath = $config['path'] ?? null;

            $jsonPath = resolve_operation_path($operation, $urlPattern, $explicitPath);
            if ($jsonPath === false) {
                return [null, "Route $method $urlPattern: could not resolve data path"];
            }

            $routes[] = [
                'method' => strtoupper($method),
                'url' => $urlPattern,
                'regex' => $compiled['regex'],
                'params' => $compiled['params'],
                'isStatic' => $isStatic,
                'status' => $status,
                'operation' => $operation,
                'jsonPath' => $jsonPath,
                'headers' => $config['headers'] ?? [],
            ];
        }
    }

    return [$routes, null];
}

function validate_path_entry($url, $methods) {
    if (!is_string($url) || $url === '' || $url[0] !== '/') {
        return "Route key must be a path starting with /";
    }
    if (preg_match('/[?#]/', $url)) {
        return "Route URL must not contain query string or fragment";
    }
    if (!str_starts_with($url, '/api/')) {
        return "Route URL must start with /api/";
    }

    foreach ($methods as $method => $config) {
        if (!is_string($method) || !preg_match('/^[A-Z]+$/i', $method)) {
            return "Invalid HTTP method: $method";
        }
        if (!is_array($config)) {
            return "Route $url $method: config must be an object";
        }

        $operation = $config['operation'] ?? null;
        if (!is_string($operation)) {
            return "Route $url $method: operation is required";
        }

        $validOps = ['create', 'read', 'list', 'patch', 'delete', 'mock', 'reset'];
        if (!in_array($operation, $validOps, true)) {
            return "Route $url $method: invalid operation '$operation'. Must be: " . implode(', ', $validOps);
        }

        $methodUpper = strtoupper($method);
        $comboMap = [
            'list' => 'GET', 'create' => 'POST', 'read' => 'GET',
            'patch' => 'PATCH', 'delete' => 'DELETE', 'reset' => 'POST',
        ];
        if (isset($comboMap[$operation]) && $methodUpper !== $comboMap[$operation]) {
            return "Route $url $method: operation '$operation' requires method " . $comboMap[$operation];
        }

        if ($operation === 'mock' && empty($config['path'])) {
            return "Route $url $method: mock operation requires a path";
        }

        if (isset($config['status'])) {
            $s = (int)$config['status'];
            if ($s < 100 || $s > 599) {
                return "Route $url $method: status must be 100-599";
            }
        }

        if (isset($config['path'])) {
            if (!is_string($config['path'])) {
                return "Route $url $method: path must be a string";
            }
            if (!str_starts_with($config['path'], 'api/')) {
                return "Route $url $method: path must start with api/";
            }
        }

        if (isset($config['headers'])) {
            if (!is_array($config['headers'])) {
                return "Route $url $method: headers must be an object";
            }
            $reserved = ['content-type', 'access-control-allow-origin', 'access-control-allow-methods',
                'access-control-allow-headers', 'allow', 'content-length', 'transfer-encoding', 'connection'];
            foreach ($config['headers'] as $k => $v) {
                if (!is_string($k) || !is_string($v)) {
                    return "Route $url $method: header keys and values must be strings";
                }
                if (in_array(strtolower($k), $reserved, true)) {
                    return "Route $url $method: header '$k' is reserved";
                }
            }
        }
    }

    return null;
}

function resolve_operation_path($operation, $urlPattern, $explicitPath) {
    if ($operation === 'mock') {
        return $explicitPath;
    }
    if ($operation === 'reset') {
        return null;
    }

    $resource = resource_from_url($urlPattern);
    if ($resource === null) return false;

    if ($operation === 'list' || $operation === 'create') {
        return 'api/' . $resource . '/list.json';
    }

    if ($explicitPath !== null) return $explicitPath;
    return 'api/' . $resource . '/id/{id}.json';
}

function compile_path($pattern) {
    $paramNames = [];
    $regex = '#^';
    $offset = 0;
    while (preg_match('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', $pattern, $m, PREG_OFFSET_CAPTURE, $offset)) {
        $name = $m[1][0];
        $pos = $m[0][1];
        $before = substr($pattern, $offset, $pos - $offset);
        $regex .= preg_quote($before, '#');
        $regex .= '([^/]+)';
        $paramNames[] = $name;
        $offset = $pos + strlen($m[0][0]);
    }
    $regex .= preg_quote(substr($pattern, $offset), '#');
    $regex .= '$#';
    return ['regex' => $regex, 'params' => $paramNames];
}

function match_route_path($route, $uri) {
    if (preg_match($route['regex'], $uri, $matches)) {
        $params = [];
        foreach ($route['params'] as $i => $name) {
            $value = urldecode($matches[$i + 1]);
            if ($value === '' || $value === '.' || $value === '..' || str_contains($value, "\0")) {
                return null;
            }
            if (str_contains($value, '/') || str_contains($value, '\\')) {
                return null;
            }
            $params[$name] = $value;
        }
        return $params;
    }
    return null;
}

function match_route($routes, $uri, $method) {
    foreach ($routes as $route) {
        if ($route['isStatic'] && $route['url'] === $uri && $route['method'] === $method) {
            return [$route, []];
        }
    }
    foreach ($routes as $route) {
        if (!$route['isStatic']) {
            $params = match_route_path($route, $uri);
            if ($params !== null && $route['method'] === $method) {
                return [$route, $params];
            }
        }
    }
    return [null, null];
}

function get_path_methods($routes, $uri) {
    $methods = [];
    foreach ($routes as $route) {
        if ($route['isStatic'] && $route['url'] === $uri) {
            $methods[] = $route['method'];
        }
    }
    if (!empty($methods)) {
        return array_values(array_unique($methods));
    }
    foreach ($routes as $route) {
        if (!$route['isStatic'] && match_route_path($route, $uri) !== null) {
            $methods[] = $route['method'];
        }
    }
    return array_values(array_unique($methods));
}

function resource_from_url($url) {
    if (preg_match('#^/api/([^{/]+)#', $url, $m)) {
        return $m[1];
    }
    return null;
}

function resolve_route_file($docRoot, $pattern, $params) {
    $file = $pattern;
    foreach ($params as $name => $value) {
        $file = str_replace('{' . $name . '}', $value, $file);
    }
    $safe = safe_path($file);
    if ($safe === null) return [null, 'Path contains invalid characters'];
    $full = $docRoot . '/' . $safe;
    $real = realpath($full);
    if ($real === false) return [null, 'File not found'];
    if (!str_starts_with($real . '/', $docRoot . '/api/')) return [null, 'Path traversal denied'];
    return [$real, null];
}

function safe_path($path) {
    $path = str_replace("\0", '', $path);
    $parts = explode('/', $path);
    $filtered = [];
    foreach ($parts as $part) {
        if ($part === '' || $part === '.') continue;
        if ($part === '..') return null;
        $filtered[] = $part;
    }
    return implode('/', $filtered);
}

function send_route_response($status, $body, $headers = []) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    foreach ($headers as $k => $v) {
        header("$k: $v");
    }
    echo json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
}

function route_error($status, $message, $headers = []) {
    send_route_response($status, ['error' => $message], $headers);
}

function handle_api_route($docRoot, $uri) {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    $resource = resource_from_url($uri);
    if ($resource === null) {
        route_error(404, 'Not found');
        return;
    }

    list($routes, $routeErr) = load_route_file($docRoot, $resource);
    if ($routeErr !== null) {
        route_error(500, 'Route configuration error: ' . $routeErr);
        return;
    }
    if ($routes === null) {
        route_error(404, 'Not found');
        return;
    }

    if ($method === 'OPTIONS') {
        handle_options($uri, $routes);
        return;
    }

    $pathMethods = get_path_methods($routes, $uri);
    if (empty($pathMethods)) {
        route_error(404, 'Not found');
        return;
    }

    if (!in_array($method, $pathMethods, true)) {
        $allow = implode(', ', array_merge($pathMethods, ['OPTIONS']));
        route_error(405, 'Method not allowed', ['Allow' => $allow]);
        return;
    }

    list($route, $params) = match_route($routes, $uri, $method);
    if ($route === null) {
        route_error(500, 'Internal routing error');
        return;
    }

    $res = resource_from_url($uri);
    if ($res !== null) {
        @init_resource($docRoot, $res);
    }

    switch ($route['operation']) {
        case 'read':
        case 'mock':
            serve_data_file_route($docRoot, $route, $params);
            break;
        case 'list':
            serve_list_route($docRoot, $route);
            break;
        case 'create':
            handle_create_route($docRoot, $route);
            break;
        case 'patch':
            handle_patch_route($docRoot, $route, $params);
            break;
        case 'delete':
            handle_delete_route($docRoot, $route, $params);
            break;
        case 'reset':
            handle_reset_route($docRoot, $route);
            break;
        default:
            route_error(500, 'Unknown operation');
    }
}

function handle_options($uri, $routes) {
    $pathMethods = get_path_methods($routes, $uri);
    if (empty($pathMethods)) {
        route_error(404, 'Not found');
        return;
    }

    $allow = implode(', ', array_merge($pathMethods, ['OPTIONS']));
    http_response_code(204);
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: ' . $allow);
    header('Allow: ' . $allow);

    $reqHeaders = $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'] ?? '';
    if ($reqHeaders !== '') {
        $names = array_map('trim', explode(',', $reqHeaders));
        $valid = array_filter($names, function($h) {
            return preg_match('/^[A-Za-z0-9!#$%&\'*+\-.^_`|~]+$/', $h);
        });
        if (!empty($valid)) {
            header('Access-Control-Allow-Headers: ' . implode(', ', $valid));
        }
    }
    header('Access-Control-Allow-Headers: Content-Type', false);
}

function serve_data_file_route($docRoot, $route, $params) {
    list($filePath, $err) = resolve_route_file($docRoot, $route['jsonPath'], $params);
    if ($err !== null) {
        route_error(404, 'File not found');
        return;
    }

    list($data, $dataErr) = read_json($filePath);
    if ($dataErr !== null) {
        route_error(500, 'Invalid JSON in response file');
        return;
    }

    send_route_response($route['status'], $data, $route['headers']);
}

function serve_list_route($docRoot, $route) {
    $resource = resource_from_url($route['url']);
    if ($resource === null) {
        route_error(500, 'Could not determine resource');
        return;
    }

    $listPath = $docRoot . '/' . safe_path($route['jsonPath']);
    $listPath = realpath($listPath);
    if ($listPath === false || !str_starts_with($listPath . '/', $docRoot . '/api/')) {
        route_error(404, 'List configuration file not found');
        return;
    }

    $schemaPath = $docRoot . '/api/' . $resource . '/schema.json';
    list($schema) = load_schema($schemaPath);

    serve_list_json($listPath, $schema);
}

function parse_body_json() {
    $ct = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
    if ($ct !== '' && stripos($ct, 'application/json') === false) {
        return [null, 'Request body must be JSON'];
    }

    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) {
        return [null, 'Request body is required'];
    }

    $body = json_decode($raw, true);
    if (!is_array($body)) {
        return [null, 'Request body must be a JSON object'];
    }

    return [$body, null];
}

function is_server_managed($field) {
    $managed = ['id', 'createdAt', 'modifiedAt', 'version'];
    return in_array($field, $managed, true);
}

function validate_create_body($body, $schema) {
    $errors = [];
    $fields = [];

    foreach ($body as $key => $val) {
        if (is_server_managed($key)) {
            $errors[] = "Field '$key' is server-managed and cannot be set";
            continue;
        }
        if (!isset($schema[$key])) {
            $errors[] = "Unknown field '$key'";
            continue;
        }
        $def = $schema[$key];
        if (!empty($def['automatic'])) {
            $errors[] = "Field '$key' is automatic and cannot be set";
            continue;
        }
        if (empty($def['editable'])) {
            $errors[] = "Field '$key' is not editable";
            continue;
        }

        list($cast, $err) = cast_value($val, $def);
        if ($err !== null) {
            $errors[] = "Field '$key': $err";
        } else {
            $fields[$key] = $cast;
        }
    }

    if (!empty($errors)) {
        return [null, implode('; ', $errors)];
    }
    return [$fields, null];
}

function validate_patch_body($body, $schema) {
    $errors = [];
    $fields = [];

    foreach ($body as $key => $val) {
        if (is_server_managed($key)) {
            $errors[] = "Field '$key' is server-managed and cannot be set";
            continue;
        }
        if (!isset($schema[$key])) {
            $errors[] = "Unknown field '$key'";
            continue;
        }
        $def = $schema[$key];
        if (!empty($def['automatic'])) {
            $errors[] = "Field '$key' is automatic and cannot be set";
            continue;
        }
        if (empty($def['editable'])) {
            $errors[] = "Field '$key' is not editable";
            continue;
        }

        list($cast, $err) = cast_value($val, $def);
        if ($err !== null) {
            $errors[] = "Field '$key': $err";
        } else {
            $fields[$key] = $cast;
        }
    }

    if (!empty($errors)) {
        return [null, implode('; ', $errors)];
    }
    return [$fields, null];
}

function handle_create_route($docRoot, $route) {
    $resource = resource_from_url($route['url']);
    if ($resource === null) {
        route_error(500, 'Could not determine resource');
        return;
    }

    $resDir = resource_dir($docRoot, $resource);
    $schemaPath = $resDir . '/schema.json';
    $listPath = $resDir . '/list.json';

    list($schema, $schemaErr) = load_schema($schemaPath);
    if ($schemaErr !== null) {
        route_error(500, 'Schema: ' . $schemaErr);
        return;
    }

    list($body, $bodyErr) = parse_body_json();
    if ($bodyErr !== null) {
        route_error(400, $bodyErr);
        return;
    }

    list($fields, $fieldErr) = validate_create_body($body, $schema);
    if ($fieldErr !== null) {
        route_error(400, $fieldErr);
        return;
    }

    if (!is_dir($resDir)) {
        if (!mkdir($resDir, 0755, true)) {
            route_error(500, 'Could not create resource directory');
            return;
        }
    }

    if (!file_exists($listPath)) {
        $schemaFieldKeys = array_keys($schema);
        $ok = write_json_atomic($resDir, 'list.json', ['fields' => $schemaFieldKeys, 'last_id' => 0]);
        if (!$ok[0]) {
            route_error(500, 'Could not initialize list.json');
            return;
        }
    }

    list($handle, $lockErr) = acquire_lock($resDir);
    if ($lockErr !== null) {
        route_error(500, $lockErr);
        return;
    }

    try {
        $idDir = id_dir($docRoot, $resource);
        if (!is_dir($idDir)) {
            if (!mkdir($idDir, 0755, true)) {
                release_lock($handle);
                route_error(500, 'Could not create id directory');
                return;
            }
        }

        list($listData) = read_json($listPath);
        if (!is_array($listData)) $listData = [];

        $nextId = get_next_id($docRoot, $resource);
        $now = date('Y-m-d\TH:i:s.v\Z');

        $item = [
            'id' => $nextId,
            'version' => 1,
            'createdAt' => $now,
            'modifiedAt' => $now,
        ];

        foreach ($schema as $key => $def) {
            if (!empty($def['editable']) && empty($def['automatic'])) {
                $item[$key] = array_key_exists($key, $fields)
                    ? $fields[$key]
                    : ($def['default'] ?? default_for_type($def['type']));
            }
        }

        list($ok, $writeErr) = write_json_atomic($idDir, $nextId . '.json', $item);
        if (!$ok) {
            release_lock($handle);
            route_error(500, 'Failed to write record: ' . $writeErr);
            return;
        }

        $listData['last_id'] = $nextId;
        list($ok, $writeErr) = write_json_atomic($resDir, 'list.json', $listData);
        if (!$ok) {
            release_lock($handle);
            route_error(500, 'Record created but failed to update list.json: ' . $writeErr);
            return;
        }

        release_lock($handle);
        send_route_response($route['status'], $item, $route['headers']);
    } catch (\Throwable $e) {
        release_lock($handle);
        route_error(500, 'Failed to create record');
    }
}

function handle_patch_route($docRoot, $route, $params) {
    list($recordPath, $fileErr) = resolve_route_file($docRoot, $route['jsonPath'], $params);
    if ($fileErr !== null || !file_exists($recordPath)) {
        route_error(404, 'Record not found');
        return;
    }

    $resource = resource_from_url($route['url']);
    if ($resource === null) {
        route_error(500, 'Could not determine resource');
        return;
    }

    $schemaPath = resource_dir($docRoot, $resource) . '/schema.json';
    list($schema, $schemaErr) = load_schema($schemaPath);
    if ($schemaErr !== null) {
        route_error(500, 'Schema: ' . $schemaErr);
        return;
    }

    list($body, $bodyErr) = parse_body_json();
    if ($bodyErr !== null) {
        route_error(400, $bodyErr);
        return;
    }

    list($fields, $fieldErr) = validate_patch_body($body, $schema);
    if ($fieldErr !== null) {
        route_error(400, $fieldErr);
        return;
    }

    if (empty($fields)) {
        route_error(400, 'No valid editable fields provided');
        return;
    }

    $resDir = resource_dir($docRoot, $resource);

    list($handle, $lockErr) = acquire_lock($resDir);
    if ($lockErr !== null) {
        route_error(500, $lockErr);
        return;
    }

    try {
        if (!file_exists($recordPath)) {
            release_lock($handle);
            route_error(404, 'Record not found');
            return;
        }

        list($item, $readErr) = read_json($recordPath);
        if ($readErr !== null || !is_array($item)) {
            release_lock($handle);
            route_error(500, 'Invalid record data');
            return;
        }

        foreach ($fields as $key => $val) {
            $item[$key] = $val;
        }

        $item['version'] = intval($item['version'] ?? 0) + 1;
        $item['modifiedAt'] = date('Y-m-d\TH:i:s.v\Z');

        $dir = dirname($recordPath);
        $filename = basename($recordPath);
        list($ok, $writeErr) = write_json_atomic($dir, $filename, $item);
        if (!$ok) {
            release_lock($handle);
            route_error(500, 'Failed to update record: ' . $writeErr);
            return;
        }

        release_lock($handle);
        send_route_response($route['status'], $item, $route['headers']);
    } catch (\Throwable $e) {
        release_lock($handle);
        route_error(500, 'Failed to update record');
    }
}

function handle_delete_route($docRoot, $route, $params) {
    list($recordPath, $fileErr) = resolve_route_file($docRoot, $route['jsonPath'], $params);
    if ($fileErr !== null || !file_exists($recordPath)) {
        route_error(404, 'Record not found');
        return;
    }

    list($body, $bodyErr) = parse_body_json();
    if ($bodyErr !== null) {
        route_error(400, $bodyErr);
        return;
    }

    if (!isset($body['version']) || (!is_int($body['version']) && !ctype_digit((string)$body['version']))) {
        route_error(400, 'Request body must include the current version: {"version": <number>}. Get it from a GET read request.');
        return;
    }
    $clientVersion = (int)$body['version'];

    $resource = resource_from_url($route['url']);
    if ($resource === null) {
        route_error(500, 'Could not determine resource');
        return;
    }
    $resDir = resource_dir($docRoot, $resource);

    list($handle, $lockErr) = acquire_lock($resDir);
    if ($lockErr !== null) {
        route_error(500, $lockErr);
        return;
    }

    try {
        if (!file_exists($recordPath)) {
            release_lock($handle);
            route_error(404, 'Record not found');
            return;
        }

        list($item, $readErr) = read_json($recordPath);
        if ($readErr !== null || !is_array($item)) {
            release_lock($handle);
            route_error(500, 'Invalid record data');
            return;
        }

        $storedVersion = intval($item['version'] ?? 0);
        if ($clientVersion !== $storedVersion) {
            release_lock($handle);
            route_error(409, 'Version conflict: record has version ' . $storedVersion . ', you sent ' . $clientVersion);
            return;
        }

        if (!unlink($recordPath)) {
            release_lock($handle);
            route_error(500, 'Failed to delete record');
            return;
        }

        release_lock($handle);
        send_route_response($route['status'], [
            'deleted' => true,
            'id' => $item['id'] ?? ($params['id'] ?? null),
        ], $route['headers']);
    } catch (\Throwable $e) {
        release_lock($handle);
        route_error(500, 'Failed to delete record');
    }
}

function handle_reset_route($docRoot, $route) {
    $resource = resource_from_url($route['url']);
    if ($resource === null) {
        route_error(500, 'Could not determine resource');
        return;
    }

    list($result, $err) = reset_resource($docRoot, $resource);
    if ($err !== null) {
        $code = str_contains($err, 'No seed.json') ? 400 : 500;
        route_error($code, $err);
        return;
    }

    send_route_response($route['status'], $result, $route['headers']);
}

function get_route_groups($docRoot) {
    $dir = $docRoot . '/routes';
    if (!is_dir($dir)) {
        return ['error' => 'routes/ directory not found'];
    }

    $files = glob($dir . '/*.json');
    if ($files === false || count($files) === 0) {
        return ['error' => 'No route files found in routes/'];
    }
    sort($files);

    $groups = [];

    foreach ($files as $file) {
        $filename = basename($file);
        $groupId = pathinfo($filename, PATHINFO_FILENAME);

        $content = file_get_contents($file);
        $decoded = json_decode($content, true);
        if (!is_array($decoded)) continue;

        $routes = [];
        foreach ($decoded as $urlPattern => $methods) {
            if (!is_array($methods)) continue;
            foreach ($methods as $method => $config) {
                if (!is_array($config)) continue;
                $routes[] = [
                    'method' => strtoupper($method),
                    'url' => $urlPattern,
                    'status' => (int)($config['status'] ?? 200),
                    'operation' => $config['operation'] ?? 'mock',
                    'path' => $config['path'] ?? null,
                    'headers' => $config['headers'] ?? [],
                ];
            }
        }

        $groups[] = [
            'id' => $groupId,
            'label' => ucwords(str_replace(['-', '_'], ' ', $groupId)),
            'file' => 'routes/' . $filename,
            'routes' => $routes,
        ];
    }

    return $groups;
}
