<?php

require_once __DIR__ . '/server/helpers.php';
require_once __DIR__ . '/server/api/schema.php';
require_once __DIR__ . '/server/api/repository.php';
require_once __DIR__ . '/server/api/list.php';
require_once __DIR__ . '/server/api/route.php';
require_once __DIR__ . '/server/api/resource-config.php';

function dispatch($docRoot) {
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

    if ($uri === '/api-test' || $uri === '/' || $uri === '') {
        require $docRoot . '/api-test.php';
        return;
    }

    if ($uri === '/routes-config') {
        handle_resource_config($docRoot);
        return;
    }

    if ($uri === '/todo' || $uri === '/todo/' || $uri === '/todo/index.php') {
        require $docRoot . '/public/todo/index.php';
        return;
    }

    json_header();
    handle_api_route($docRoot, $uri);
}

if (php_sapi_name() === 'cli-server') {
    $scriptFile = realpath($_SERVER['SCRIPT_FILENAME'] ?? '');
    if ($scriptFile === realpath(__FILE__)) {
        dispatch(__DIR__);
    } else {
        return false;
    }
}
