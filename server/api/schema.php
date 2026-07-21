<?php

function load_schema($schemaPath) {
    if (!file_exists($schemaPath)) {
        return [null, 'Schema not found'];
    }
    $schema = json_decode(file_get_contents($schemaPath), true);
    if (!is_array($schema)) {
        return [null, 'Invalid schema JSON'];
    }
    $err = validate_schema($schema);
    if ($err !== null) {
        return [null, $err];
    }
    return [$schema, null];
}

function validate_schema($schema) {
    $validTypes = ['string', 'number', 'boolean', 'array', 'datetime'];
    $allowedKeys = ['type', 'editable', 'automatic', 'default', 'required'];

    foreach ($schema as $field => $def) {
        if (!is_string($field) || $field === '') {
            return "Schema field key must be a non-empty string";
        }
        if (!is_array($def)) {
            return "Schema field '$field' must be an object";
        }
        $type = $def['type'] ?? null;
        if (!is_string($type) || !in_array($type, $validTypes, true)) {
            return "Schema field '$field': type must be one of " . implode(', ', $validTypes);
        }
        foreach ($def as $key => $_) {
            if (!in_array($key, $allowedKeys, true)) {
                return "Schema field '$field': unknown property '$key'";
            }
        }
        if (!empty($def['default'])) {
            $defaultErr = validate_default_value($def['default'], $def['type']);
            if ($defaultErr !== null) {
                return "Schema field '$field': $defaultErr";
            }
        }
    }
    return null;
}

function validate_default_value($val, $type) {
    switch ($type) {
        case 'string':
            if (!is_string($val)) return "default must be a string";
            break;
        case 'number':
            if (!is_int($val) && !is_float($val)) return "default must be a number";
            break;
        case 'boolean':
            if (!is_bool($val)) return "default must be a boolean";
            break;
        case 'array':
            if (!is_array($val)) return "default must be an array";
            break;
        case 'datetime':
            if (!is_string($val) && $val !== null) return "default must be a string or null";
            break;
    }
    return null;
}

function cast_value($val, $schemaDef) {
    $type = $schemaDef['type'];

    if ($type === 'number') {
        if (!is_numeric($val)) return [null, 'expects a number'];
        return [strpos((string)$val, '.') !== false ? floatval($val) : intval($val), null];
    }
    if ($type === 'boolean') {
        if ($val === true || $val === '1' || $val === 1 || $val === 'true') return [true, null];
        if ($val === false || $val === '0' || $val === 0 || $val === 'false') return [false, null];
        return [null, 'expects a boolean'];
    }
    if ($type === 'array') {
        if (!is_array($val)) return [null, 'expects an array'];
        return [$val, null];
    }
    if (is_array($val)) return [null, 'expects a string'];
    return [(string)$val, null];
}

function get_editable_fields($schema) {
    $fields = [];
    foreach ($schema as $name => $def) {
        if (!empty($def['editable']) && empty($def['automatic'])) {
            $fields[$name] = $def;
        }
    }
    return $fields;
}

function get_default_record($schema) {
    $record = [];
    foreach ($schema as $name => $def) {
        if (!empty($def['editable']) && empty($def['automatic'])) {
            $record[$name] = array_key_exists('default', $def) ? $def['default'] : default_for_type($def['type']);
        }
    }
    return $record;
}

function default_for_type($type) {
    switch ($type) {
        case 'string': return '';
        case 'number': return 0;
        case 'boolean': return false;
        case 'array': return [];
        case 'datetime': return null;
        default: return null;
    }
}

function get_field_type($schema, $field) {
    if (!isset($schema[$field])) return null;
    return $schema[$field]['type'] ?? null;
}

function is_editable_field($schema, $field) {
    if (!isset($schema[$field])) return false;
    $def = $schema[$field];
    return !empty($def['editable']) && empty($def['automatic']);
}
