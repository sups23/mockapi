<?php

require_once __DIR__ . '/schema.php';

function resource_dir($docRoot, $resource) {
    return $docRoot . '/api/' . $resource;
}

function scenarios_dir($docRoot, $resource) {
    return resource_dir($docRoot, $resource) . '/scenarios';
}

function scenario_dir($docRoot, $resource, $scenario) {
    return scenarios_dir($docRoot, $resource) . '/' . $scenario;
}

function scenario_records_dir($docRoot, $resource, $scenario) {
    return scenario_dir($docRoot, $resource, $scenario) . '/records';
}

function is_valid_scenario_name($scenario) {
    return is_string($scenario) && preg_match('/^[a-z][a-z0-9-]*$/', $scenario) && strlen($scenario) <= 64;
}

function resource_config($docRoot, $resource) {
    list($data, $err) = read_json(resource_dir($docRoot, $resource) . '/list.json');
    return $err === null && is_array($data) ? $data : [];
}

function scenario_names($docRoot, $resource) {
    $dir = scenarios_dir($docRoot, $resource);
    if (!is_dir($dir)) return [];

    $names = [];
    foreach (glob($dir . '/*', GLOB_ONLYDIR) ?: [] as $path) {
        $name = basename($path);
        if (is_valid_scenario_name($name)) $names[] = $name;
    }
    sort($names, SORT_STRING);
    return $names;
}

function active_scenario($docRoot, $resource) {
    $config = resource_config($docRoot, $resource);
    $active = $config['activeScenario'] ?? 'default';
    if (!is_valid_scenario_name($active)) return null;
    return in_array($active, scenario_names($docRoot, $resource), true) ? $active : null;
}

function active_records_dir($docRoot, $resource) {
    $scenario = active_scenario($docRoot, $resource);
    return $scenario === null ? null : scenario_records_dir($docRoot, $resource, $scenario);
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

function get_next_id($docRoot, $resource, $scenario) {
    $recordsDir = scenario_records_dir($docRoot, $resource, $scenario);

    $nextId = 1;

    if (is_dir($recordsDir)) {
        $existing = glob($recordsDir . '/*.json');
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
    $config = resource_config($docRoot, $resource);
    $active = $config['activeScenario'] ?? 'default';
    $scenarios = scenario_names($docRoot, $resource);
    if (!is_valid_scenario_name($active) || !in_array($active, $scenarios, true)) {
        return [false, "Active scenario '$active' does not exist for resource '$resource'"];
    }

    $recordsDir = scenario_records_dir($docRoot, $resource, $active);
    $seedPath = scenario_dir($docRoot, $resource, $active) . '/seed.json';
    $schemaPath = $resDir . '/schema.json';

    if (!is_dir($recordsDir)) {
        if (!mkdir($recordsDir, 0755, true)) {
            return [false, 'Could not create scenario records directory'];
        }
    }

    $existing = glob($recordsDir . '/*.json');
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
        $existing = glob($recordsDir . '/*.json');
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
            $err = validate_seed_types($seed, $schema);
            if ($err !== null) {
                release_lock($handle);
                return [false, "Seed record $i (id {$seed['id']}): $err"];
            }

            list($ok, $writeErr) = write_json_atomic($recordsDir, $seed['id'] . '.json', $seed);
            if (!$ok) {
                release_lock($handle);
                return [false, "Failed to write seed record {$seed['id']}: $writeErr"];
            }
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
    $scenario = active_scenario($docRoot, $resource);
    if ($scenario === null) return [null, 'Active scenario does not exist'];
    $recordsDir = scenario_records_dir($docRoot, $resource, $scenario);
    $seedPath = scenario_dir($docRoot, $resource, $scenario) . '/seed.json';
    $schemaPath = $resDir . '/schema.json';

    if (!file_exists($seedPath)) {
        return [null, 'No seed.json found for this resource'];
    }

    list($handle, $lockErr) = acquire_lock($resDir);
    if ($lockErr !== null) return [null, $lockErr];

    try {
        if (is_dir($recordsDir)) {
            $files = glob($recordsDir . '/*.json');
            if ($files !== false) {
                foreach ($files as $f) {
                    unlink($f);
                }
            }
        } else {
            if (!mkdir($recordsDir, 0755, true)) {
                release_lock($handle);
                return [null, 'Could not create scenario records directory'];
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

            list($ok, $writeErr) = write_json_atomic($recordsDir, $seed['id'] . '.json', $seed);
            if (!$ok) {
                release_lock($handle);
                return [null, "Failed to write seed record {$seed['id']}: $writeErr"];
            }
            $count++;
        }

        release_lock($handle);
        return [['reset' => true, 'resource' => $resource, 'scenario' => $scenario, 'seeded' => $count], null];
    } catch (\Throwable $e) {
        release_lock($handle);
        return [null, 'Reset failed: ' . $e->getMessage()];
    }
}
