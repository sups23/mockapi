<?php

require_once __DIR__ . '/server/helpers.php';
require_once __DIR__ . '/server/api/schema.php';
require_once __DIR__ . '/server/api/repository.php';
require_once __DIR__ . '/server/api/list.php';
require_once __DIR__ . '/server/api/route.php';
require_once __DIR__ . '/server/api/resource-config.php';
require_once __DIR__ . '/server/api/mock-route-config.php';

function dispatch($docRoot) {
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

    if ($uri === '/seedbox' || $uri === '/' || $uri === '') {
        require $docRoot . '/seedbox.php';
        return;
    }

    if ($uri === '/routes-config') {
        handle_resource_config($docRoot);
        return;
    }

    if ($uri === '/mock-route-config') {
        handle_mock_route_config($docRoot);
        return;
    }

    if (!str_starts_with($uri, '/api/')) {
        $public = $docRoot . '/public' . $uri;
        if (is_file($public)) {
            header('Content-Type: ' . mime_type($public) . '; charset=utf-8');
            readfile($public);
            return;
        }
        if (is_dir($public) && is_file($public . '/index.html')) {
            header('Content-Type: text/html; charset=utf-8');
            readfile($public . '/index.html');
            return;
        }
        route_error(404, 'Not found');
        return;
    }

    json_header();
    handle_api_route($docRoot, $uri);
}

function mime_type($path) {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $map = [
        'html' => 'text/html', 'css' => 'text/css', 'js' => 'application/javascript',
        'json' => 'application/json', 'png' => 'image/png', 'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon', 'woff2' => 'font/woff2',
    ];
    return $map[$ext] ?? 'application/octet-stream';
}

if (php_sapi_name() === 'cli-server') {
    $scriptFile = realpath($_SERVER['SCRIPT_FILENAME'] ?? '');
    if ($scriptFile === realpath(__FILE__)) {
        dispatch(__DIR__);
    } else {
        return false;
    }
}
