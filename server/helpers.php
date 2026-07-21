<?php

function json_header() {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
}

function _parse_path($path) {
    $keys = [];
    $len = strlen($path);
    $i = 0;
    while ($i < $len) {
        if ($path[$i] === '[' && $i + 2 < $len) {
            $j = strpos($path, ']', $i + 1);
            if ($j !== false) {
                $inner = substr($path, $i + 1, $j - $i - 1);
                $inner = trim($inner, "'\"");
                $keys[] = $inner;
                $i = $j + 1;
                if ($i < $len && $path[$i] === '.') $i++;
                continue;
            }
        }
        if (substr($path, $i, 4) === '(k).' || substr($path, $i, 3) === '(k)') {
            $keys[] = '(k)';
            $i += 3;
            if ($i < $len && $path[$i] === '.') $i++;
            continue;
        }
        $j = $i;
        while ($j < $len && $path[$j] !== '.' && $path[$j] !== '[') $j++;
        $key = substr($path, $i, $j - $i);
        if ($key !== '') $keys[] = $key;
        $i = $j;
        if ($i < $len && $path[$i] === '.') $i++;
    }
    return $keys;
}

function get_nested_value($array, $path) {
    $keys = _parse_path($path);
    if (empty($keys)) return null;
    $current = $array;
    foreach ($keys as $key) {
        if (!is_array($current) || !array_key_exists($key, $current)) {
            return null;
        }
        $current = $current[$key];
    }
    return $current;
}

function has_nested_key($array, $path) {
    $keys = _parse_path($path);
    if (empty($keys)) return false;
    $current = $array;
    foreach ($keys as $key) {
        if (!is_array($current) || !array_key_exists($key, $current)) {
            return false;
        }
        $current = $current[$key];
    }
    return true;
}

function get_query_params() {
    $params = [];
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    if ($qs === '') return $params;
    foreach (explode('&', $qs) as $pair) {
        $parts = explode('=', $pair, 2);
        $key = urldecode($parts[0]);
        $val = isset($parts[1]) ? urldecode($parts[1]) : '';
        $params[$key] = $val;
    }
    return $params;
}
