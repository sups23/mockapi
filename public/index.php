<?php

$docRoot = dirname(__DIR__);

require_once $docRoot . '/server/helpers.php';
require_once $docRoot . '/server/api/schema.php';
require_once $docRoot . '/server/api/repository.php';
require_once $docRoot . '/server/api/list.php';
require_once $docRoot . '/server/api/route.php';
require_once $docRoot . '/server/api/resource-config.php';
require_once $docRoot . '/server/api/mock-route-config.php';
require_once $docRoot . '/server/api/scenario-config.php';
require_once $docRoot . '/router.php';

dispatch($docRoot);
