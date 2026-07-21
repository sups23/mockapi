<?php

require_once __DIR__ . '/schema.php';

function resource_dir($docRoot, $resource) {
    return $docRoot . '/api/' . $resource;
}

function id_dir($docRoot, $resource) {
    return resource_dir($docRoot, $resource) . '/id';
}

function acquire_lock($resourceDir) {
    if (!is_dir($resourceDir)) {
        if (!mkdir($resourceDir, 0755, true)) {
            return [null, 'Could not create resource directory'];
        }
    }
    $lockFile = $resourceDir . '/.write.lock';
    $handle = fopen($lockFile, 'c+');
    if (!$handle) return [null, 'Could not open lock file'];
    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        return [null, 'Could not acquire write lock'];
    }
    return [$handle, null];
}

function release_lock($handle) {
    if ($handle) {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function write_json_atomic($dir, $filename, $data) {
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true)) {
            return [false, 'Could not create directory'];
        }
    }
    $tmp = tempnam($dir, 'tmp_');
    if ($tmp === false) return [false, 'Could not create temp file'];

    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        unlink($tmp);
        return [false, 'JSON encoding failed'];
    }

    $bytes = file_put_contents($tmp, $json . "\n");
    if ($bytes === false) {
        unlink($tmp);
        return [false, 'Could not write temp file'];
    }

    $target = $dir . '/' . $filename;
    if (!rename($tmp, $target)) {
        unlink($tmp);
        return [false, 'Could not rename temp file to target'];
    }

    return [true, null];
}

function read_json($path) {
    if (!file_exists($path)) return [null, 'File not found'];
    $content = file_get_contents($path);
    if ($content === false) return [null, 'Could not read file'];
    $data = json_decode($content, true);
    if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
        return [null, 'Invalid JSON'];
    }
    return [$data, null];
}

function get_next_id($docRoot, $resource) {
    $listPath = resource_dir($docRoot, $resource) . '/list.json';
    $idDir = id_dir($docRoot, $resource);

    list($listData) = read_json($listPath);
    if (!is_array($listData)) $listData = [];

    $lastId = isset($listData['last_id']) ? intval($listData['last_id']) : 0;
    $nextId = $lastId + 1;

    if (is_dir($idDir)) {
        $existing = glob($idDir . '/*.json');
        if ($existing !== false) {
            foreach ($existing as $f) {
                $num = intval(pathinfo($f, PATHINFO_FILENAME));
                if ($num >= $nextId) $nextId = $num + 1;
            }
        }
    }

    return $nextId;
}

function init_resource($docRoot, $resource) {
    $resDir = resource_dir($docRoot, $resource);
    $idDir = id_dir($docRoot, $resource);
    $seedPath = $resDir . '/seed.json';
    $schemaPath = $resDir . '/schema.json';

    if (!is_dir($idDir)) {
        if (!mkdir($idDir, 0755, true)) {
            return [false, 'Could not create id directory'];
        }
    }

    $existing = glob($idDir . '/*.json');
    if ($existing !== false && count($existing) > 0) {
        return [true, null];
    }

    if (!file_exists($seedPath)) {
        return [true, null];
    }

    list($seeds, $seedErr) = read_json($seedPath);
    if ($seedErr !== null || !is_array($seeds)) {
        return [true, null];
    }

    if (empty($seeds)) {
        return [true, null];
    }

    list($handle, $lockErr) = acquire_lock($resDir);
    if ($lockErr !== null) return [false, $lockErr];

    try {
        $existing = glob($idDir . '/*.json');
        if ($existing !== false && count($existing) > 0) {
            release_lock($handle);
            return [true, null];
        }

        list($schema, $schemaErr) = load_schema($schemaPath);
        if ($schemaErr !== null) {
            release_lock($handle);
            return [false, $schemaErr];
        }

        $ids = [];
        $maxId = 0;
        foreach ($seeds as $i => $seed) {
            if (!is_array($seed)) {
                release_lock($handle);
                return [false, "Seed record $i must be an object"];
            }
            if (!isset($seed['id']) || !is_int($seed['id']) || $seed['id'] < 1) {
                release_lock($handle);
                return [false, "Seed record $i must have a positive integer id"];
            }
            if (in_array($seed['id'], $ids, true)) {
                release_lock($handle);
                return [false, "Seed record $i: duplicate id {$seed['id']}"];
            }
            $ids[] = $seed['id'];
            if ($seed['id'] > $maxId) $maxId = $seed['id'];

            $err = validate_seed_types($seed, $schema);
            if ($err !== null) {
                release_lock($handle);
                return [false, "Seed record $i (id {$seed['id']}): $err"];
            }

            list($ok, $writeErr) = write_json_atomic($idDir, $seed['id'] . '.json', $seed);
            if (!$ok) {
                release_lock($handle);
                return [false, "Failed to write seed record {$seed['id']}: $writeErr"];
            }
        }

        $listPath = $resDir . '/list.json';
        list($listData) = read_json($listPath);
        if (!is_array($listData)) $listData = [];
        $listData['last_id'] = $maxId;

        list($ok, $writeErr) = write_json_atomic($resDir, 'list.json', $listData);
        if (!$ok) {
            release_lock($handle);
            return [false, "Failed to update list.json: $writeErr"];
        }

        release_lock($handle);
        return [true, null];
    } catch (\Throwable $e) {
        release_lock($handle);
        return [false, 'Failed to initialize resource: ' . $e->getMessage()];
    }
}

function validate_seed_types($record, $schema) {
    foreach ($record as $key => $val) {
        if (!isset($schema[$key])) continue;
        $def = $schema[$key];
        $type = $def['type'];
        if ($type === 'number' && !is_numeric($val)) return "'$key' must be a number";
        if ($type === 'boolean' && !is_bool($val)) return "'$key' must be a boolean";
        if ($type === 'array' && !is_array($val)) return "'$key' must be an array";
    }
    return null;
}

function reset_resource($docRoot, $resource) {
    $resDir = resource_dir($docRoot, $resource);
    $idDir = id_dir($docRoot, $resource);
    $seedPath = $resDir . '/seed.json';
    $schemaPath = $resDir . '/schema.json';

    if (!file_exists($seedPath)) {
        return [null, 'No seed.json found for this resource'];
    }

    list($handle, $lockErr) = acquire_lock($resDir);
    if ($lockErr !== null) return [null, $lockErr];

    try {
        if (is_dir($idDir)) {
            $files = glob($idDir . '/*.json');
            if ($files !== false) {
                foreach ($files as $f) {
                    unlink($f);
                }
            }
        } else {
            if (!mkdir($idDir, 0755, true)) {
                release_lock($handle);
                return [null, 'Could not create id directory'];
            }
        }

        list($schema, $schemaErr) = load_schema($schemaPath);
        if ($schemaErr !== null) {
            release_lock($handle);
            return [null, $schemaErr];
        }

        list($seeds, $seedErr) = read_json($seedPath);
        if ($seedErr !== null || !is_array($seeds)) {
            release_lock($handle);
            return [null, 'Invalid seed data'];
        }

        $maxId = 0;
        $count = 0;
        foreach ($seeds as $i => $seed) {
            if (!isset($seed['id']) || !is_int($seed['id']) || $seed['id'] < 1) {
                release_lock($handle);
                return [null, "Seed record $i: must have a positive integer id"];
            }

            $err = validate_seed_types($seed, $schema);
            if ($err !== null) {
                release_lock($handle);
                return [null, "Seed record $i (id {$seed['id']}): $err"];
            }

            if ($seed['id'] > $maxId) $maxId = $seed['id'];

            list($ok, $writeErr) = write_json_atomic($idDir, $seed['id'] . '.json', $seed);
            if (!$ok) {
                release_lock($handle);
                return [null, "Failed to write seed record {$seed['id']}: $writeErr"];
            }
            $count++;
        }

        $listPath = $resDir . '/list.json';
        list($listData) = read_json($listPath);
        if (!is_array($listData)) $listData = [];
        $listData['last_id'] = $maxId;

        list($ok, $writeErr) = write_json_atomic($resDir, 'list.json', $listData);
        if (!$ok) {
            release_lock($handle);
            return [null, "Failed to update list.json: $writeErr"];
        }

        release_lock($handle);
        return [['reset' => true, 'resource' => $resource, 'seeded' => $count, 'last_id' => $maxId], null];
    } catch (\Throwable $e) {
        release_lock($handle);
        return [null, 'Reset failed: ' . $e->getMessage()];
    }
}
