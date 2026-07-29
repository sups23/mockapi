<?php

function strip_hidden_fields(&$item, $listData) {
    $hidden = $listData['_hidden'] ?? [];
    if (!is_array($item)) return;
    foreach ($hidden as $field) {
        unset($item[$field]);
    }
}

function matches_filters($item, $filters, $schema) {
    $andFilters = $filters['and'] ?? [];
    $orFilters  = $filters['or']  ?? [];
    if ($andFilters === [] && $orFilters === []) {
        $andFilters = $filters;
    }
    foreach ($andFilters as $key => $val) {
        if (!match_filter($item, $key, $val, $schema)) return false;
    }
    if ($orFilters === []) return true;
    foreach ($orFilters as $key => $val) {
        if (match_filter($item, $key, $val, $schema)) return true;
    }
    return false;
}

function parse_filter_key($key) {
    $op = '';
    $field = $key;
    $suffixes = ['__GTE', '__LTE', '__NE', '__SEARCH'];
    foreach ($suffixes as $suffix) {
        if (substr($key, -strlen($suffix)) === $suffix) {
            $op = substr($suffix, 2);
            $field = substr($key, 0, -strlen($suffix));
            break;
        }
    }
    return [$field, $op];
}

function validate_filter_field($schema, $field, $op, $val) {
    if ($schema === null) return null;

    $topField = explode('.', $field)[0];
    if (!isset($schema[$topField])) {
        return "Unknown filter field '$topField'";
    }

    $fieldType = $schema[$topField]['type'] ?? null;

    if ($op === 'GTE' || $op === 'LTE') {
        if ($fieldType !== 'number' && $fieldType !== 'datetime') {
            return "Range filter '$op' is not supported on field '$topField' of type '$fieldType'";
        }
    }

    if ($op === 'SEARCH') {
        if ($fieldType !== 'string' && $fieldType !== 'array') {
            return "Search filter is not supported on field '$topField' of type '$fieldType'";
        }
    }

    return null;
}

function match_filter($item, $key, $val, $schema) {
    list($field, $op) = parse_filter_key($key);

    $filterErr = validate_filter_field($schema, $field, $op, $val);
    if ($filterErr !== null) return false;

    if (!has_nested_key($item, $field)) return false;
    $itemVal = get_nested_value($item, $field);
    if ($itemVal === null) return false;

    if ($op === '' && is_string($val) && strpos($val, '===') === 0) {
        $otherField = substr($val, 3);
        $otherVal = get_nested_value($item, $otherField);
        return (string)$itemVal === (string)$otherVal;
    }

    if (is_array($itemVal)) {
        $needles = is_array($val) ? $val : explode(',', (string)$val);
        $needles = array_map('trim', $needles);
        foreach ($itemVal as $iv) {
            foreach ($needles as $needle) {
                if ($op === 'SEARCH') {
                    if (is_string($iv) && stripos($iv, $needle) !== false) return true;
                    if (!is_string($iv) && (string)$iv === (string)$needle) return true;
                } else {
                    if ((string)$iv === (string)$needle) return true;
                }
            }
        }
        return false;
    }

    $needles = explode(',', (string)$val);
    $needles = array_map('trim', $needles);

    if ($op === 'GTE' || $op === 'LTE') {
        if (!is_numeric($itemVal)) {
            return false;
        }
        $cmp = floatval($itemVal);
        $target = floatval($val);
        if ($op === 'GTE') return $cmp >= $target;
        if ($op === 'LTE') return $cmp <= $target;
    }

    foreach ($needles as $needle) {
        if ($op === 'SEARCH') {
            if (is_string($itemVal) && stripos($itemVal, $needle) !== false) return true;
            if (!is_string($itemVal) && (string)$itemVal === (string)$needle) return true;
        } elseif ($op === 'NE') {
            if ((string)$itemVal === (string)$needle) return false;
        } else {
            if (is_string($itemVal) && stripos($itemVal, $needle) !== false) return true;
            if (!is_string($itemVal) && (string)$itemVal === (string)$needle) return true;
        }
    }
    return false;
}

function parse_sort($sortParam) {
    if (empty($sortParam)) return [];
    $parts = explode(';', $sortParam);
    $sorts = [];
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '') continue;
        $colonPos = strrpos($part, ':');
        if ($colonPos === false) continue;
        $field = substr($part, 0, $colonPos);
        $order = strtoupper(substr($part, $colonPos + 1));
        if (!in_array($order, ['ASC', 'DESC'], true)) continue;
        $sorts[] = ['field' => $field, 'order' => $order];
    }
    return $sorts;
}

function sort_items(&$items, $sorts) {
    if (empty($sorts) || empty($items)) return;
    usort($items, function($a, $b) use ($sorts) {
        foreach ($sorts as $sort) {
            $field = $sort['field'];
            $order = $sort['order'];
            $va = get_nested_value($a, $field);
            $vb = get_nested_value($b, $field);
            if ($va === $vb) continue;
            if ($va === null) return 1;
            if ($vb === null) return -1;
            if (is_numeric($va) && is_numeric($vb)) {
                $cmp = floatval($va) <=> floatval($vb);
            } else {
                $cmp = strcasecmp((string)$va, (string)$vb);
            }
            return $order === 'ASC' ? $cmp : -$cmp;
        }
        return 0;
    });
}

function serve_list_json($listPath, $schema = null, $recordsDir = null) {
    $data = json_decode(file_get_contents($listPath), true);
    if (!is_array($data) || !isset($data['fields']) || !is_array($data['fields'])) {
        send_route_response(500, ['error' => 'List configuration must have a fields array']);
        return;
    }

    $fields = $data['fields'];
    $recordsDir = $recordsDir ?? dirname($listPath) . '/scenarios/default/records';

    $limit = isset($data['_limit']) ? max(1, intval($data['_limit'])) : 10;

    $params = get_query_params();
    $page = isset($params['_page']) ? max(1, intval($params['_page'])) : 1;

    $reserved = ['_page', '_sort', 'sortBy'];
    $filters = [];
    foreach ($params as $k => $v) {
        if (!in_array($k, $reserved) && $v !== '' && substr($k, -2) !== '__') {
            list($baseField, $op) = parse_filter_key($k);

            $filterErr = validate_filter_field($schema, $baseField, $op, $v);
            if ($filterErr !== null) {
                send_route_response(400, ['error' => "Invalid filter: $filterErr"]);
                return;
            }

            $filters[$k] = $v;
        }
    }

    $sorts = parse_sort($params['_sort'] ?? '');
    $sortBy = $params['sortBy'] ?? '';
    if ($sortBy !== '' && empty($sorts)) {
        $sorts = [['field' => $sortBy, 'order' => 'ASC']];
    }

    foreach ($sorts as $sort) {
        $topField = explode('.', $sort['field'])[0];
        if ($schema !== null && !isset($schema[$topField]) && !in_array($topField, ['id', 'version', 'createdAt', 'modifiedAt'])) {
            send_route_response(400, ['error' => "Invalid sort field '$topField'"]);
            return;
        }
    }

    $defaultFilters = $data['defaultFilters'] ?? [];
    if (is_array($defaultFilters)) {
        foreach ($defaultFilters as $k => $v) {
            if (!array_key_exists($k, $filters)) {
                $filters[$k] = $v;
            }
        }
        if (($filters['and'] ?? []) === [] && ($filters['or'] ?? []) === []) {
            unset($filters['and'], $filters['or']);
        }
    }

    $items = [];
    if (is_dir($recordsDir)) {
        $jsonFiles = glob($recordsDir . '/*.json');
        if ($jsonFiles !== false) {
            usort($jsonFiles, function($a, $b) {
                return intval(pathinfo($b, PATHINFO_FILENAME)) - intval(pathinfo($a, PATHINFO_FILENAME));
            });
            foreach ($jsonFiles as $file) {
                $item = json_decode(file_get_contents($file), true);
                if (!is_array($item)) continue;
                if ($filters && !matches_filters($item, $filters, $schema)) continue;

                strip_hidden_fields($item, $data);

                $filtered = [];
                foreach ($fields as $field) {
                    $val = get_nested_value($item, $field);
                    if ($val !== null) {
                        $keys = explode('.', $field);
                        if (count($keys) === 1) {
                            $filtered[$field] = $val;
                        } else {
                            $ptr = &$filtered;
                            foreach ($keys as $i => $k) {
                                if (!isset($ptr[$k])) $ptr[$k] = ($i === count($keys) - 1) ? $val : [];
                                $ptr = &$ptr[$k];
                            }
                        }
                    }
                }
                $items[] = $filtered;
            }
        }
    }

    sort_items($items, $sorts);
    $offset = ($page - 1) * $limit;
    $items = array_slice($items, $offset, $limit);

    send_route_response(200, $items);
}
