<?php

require_once __DIR__ . '/server/api/route.php';
require_once __DIR__ . '/server/api/schema.php';

$groups = get_route_groups(__DIR__);
$configError = null;

if (isset($groups['error'])) {
    $configError = $groups['error'];
    $groups = [];
}

function explorer_method_class($method) {
    $m = strtolower($method);
    return in_array($m, ['get', 'post', 'put', 'patch', 'delete'], true) ? $m : 'custom';
}

function explorer_param_names($path) {
    preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', $path, $m);
    return $m[1] ?? [];
}

function explorer_summary($docRoot, $route) {
    $op = $route['operation'] ?? 'mock';
    if ($op === 'list') {
        $resource = resource_from_url($route['url'] ?? '');
        $listPath = $resource ? $docRoot . '/api/' . $resource . '/list.json' : '';
        $limit = 10; $fieldCount = 0;
        if ($listPath && file_exists($listPath)) {
            $d = json_decode(file_get_contents($listPath), true);
            if (is_array($d)) {
                $limit = isset($d['_limit']) ? intval($d['_limit']) : 10;
                $fieldCount = isset($d['fields']) ? count($d['fields']) : 0;
            }
        }
        return "limit $limit | $fieldCount fields";
    }
    if ($op === 'reset') {
        $resource = resource_from_url($route['url'] ?? '');
        $scenario = $resource ? active_scenario($docRoot, $resource) : null;
        $seedPath = ($resource && $scenario) ? scenario_dir($docRoot, $resource, $scenario) . '/seed.json' : '';
        $count = 0;
        if ($seedPath && file_exists($seedPath)) {
            $s = json_decode(file_get_contents($seedPath), true);
            if (is_array($s)) $count = count($s);
        }
        return "destructive | $count $scenario records";
    }
    $sf = $route['path'] ?? '';
    if ($sf === '') {
        $resource = resource_from_url($route['url'] ?? '');
        $sf = $resource ? "api/$resource/scenarios/{scenario}/records/{id}.json" : '(derived)';
    }
    return "&rarr; $sf";
}

function explorer_schema_fields($docRoot, $route, $editableOnly = false) {
    $resource = resource_from_url($route['url'] ?? '');
    $path = $route['path'] ?? '';
    if ($resource === null && $path !== '') {
        $parts = explode('/', $path);
        if (count($parts) >= 3) $resource = $parts[1];
    }
    if ($resource === null || $resource === '') return [];
    $schemaPath = $docRoot . '/api/' . $resource . '/schema.json';
    if (!file_exists($schemaPath)) return [];
    $real = realpath($schemaPath);
    if (!$real || !str_starts_with($real, $docRoot . '/api/')) return [];
    $schema = json_decode(file_get_contents($schemaPath), true);
    if (!is_array($schema)) return [];

    $fields = [];
    foreach ($schema as $k => $def) {
        if (!is_array($def)) continue;
        $auto = !empty($def['automatic']);
        $editable = !empty($def['editable']);
        if ($auto) continue;
        if ($editableOnly && !$editable) continue;
        $fields[$k] = $def;
    }
    return $fields;
}

function explorer_default_body_json($schemaFields) {
    $obj = [];
    foreach ($schemaFields as $name => $def) {
        $obj[$name] = array_key_exists('default', $def) ? $def['default'] : null;
    }
    return json_encode($obj, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

function explorer_has_seed($docRoot, $route) {
    $resource = resource_from_url($route['url'] ?? '');
    if (!$resource) return false;
    $scenario = active_scenario($docRoot, $resource);
    return $scenario !== null && file_exists(scenario_dir($docRoot, $resource, $scenario) . '/seed.json');
}

$docRoot = __DIR__;
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Seedbox</title>
<style>
:root { --bg:#f5f7fa; --surface:#fff; --panel:#fbfcfd; --text:#263238; --muted:#687782; --line:#d9e5ea; --blue:#1677ff; --green:#159957; --orange:#c77700; --red:#d64545; --purple:#8957d5; --input-bg:#fff; --hover:#f8fafc; --header-bg:#17212b; --header-text:#fff; --accent:#75b7ff; --resp-bg:#17212b; --resp-text:#d8f8df; }
:root[data-theme="dark"] { --bg:#111827; --surface:#1f2937; --panel:#182230; --text:#f3f4f6; --muted:#9ca3af; --line:#374151; --input-bg:#111827; --hover:#273548; --header-bg:#0b1220; --header-text:#f3f4f6; --accent:#8bc4ff; --resp-bg:#0b1220; --resp-text:#d8f8df; }
* { box-sizing:border-box; } body { margin:0; background:var(--bg); color:var(--text); font:14px -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; }
.topbar { position:sticky; top:0; z-index:10; display:flex; justify-content:space-between; align-items:center; padding:14px 28px; background:var(--header-bg); color:var(--header-text); box-shadow:0 2px 8px #0003; }
.topbar h1 { margin:0; font-size:20px; font-weight:600; } .topbar h1 span { color:var(--accent); } .top-actions { display:flex; align-items:center; gap:14px; } .topbar button { background:var(--header-bg); color:var(--header-text); border:1px solid var(--header-text); opacity:.85; border-radius:5px; padding:6px 12px; cursor:pointer; } .topbar a { color:var(--header-text); opacity:.85; text-decoration:none; font-size:13px; }
.container { max-width:1200px; margin:0 auto; padding:26px 20px 60px; } .intro { color:var(--muted); margin:0 0 22px; }
.toolbar { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:22px; align-items:center; } .toolbar input { flex:1 1 220px; border:1px solid var(--line); border-radius:5px; padding:8px 12px; background:var(--input-bg); color:var(--text); }
.group { margin:22px 0; border:1px solid var(--line); border-radius:8px; background:var(--surface); overflow:hidden; box-shadow:0 1px 2px #00000008; }
.group-head { display:flex; align-items:center; gap:12px; padding:14px 18px; border-bottom:1px solid var(--line); cursor:pointer; } .group-head:hover { background:var(--hover); }
 .group-head h2 { margin:0; font-size:16px; font-weight:600; } .group-head .group-meta { color:var(--muted); font-size:12px; margin-left:auto; display:flex; gap:12px; align-items:center; }
 .scenario-select { border:1px solid var(--line); border-radius:4px; padding:4px 7px; background:var(--input-bg); color:var(--text); font-size:12px; }
.group-chevron { color:var(--muted); transition:transform .15s; font-size:12px; } .group.open .group-chevron { transform:rotate(90deg); }
.group-body { display:none; } .group.open .group-body { display:block; }

.endpoint { border-bottom:1px solid var(--line); }
.endpoint-header { display:flex; align-items:center; gap:12px; padding:12px 18px; cursor:pointer; user-select:none; } .endpoint-header:hover { background:var(--hover); }
.endpoint-path { font-family:ui-monospace,SFMono-Regular,monospace; font-size:13px; font-weight:600; color:var(--text); }
.endpoint-op { font-size:10px; font-weight:600; text-transform:uppercase; color:var(--muted); background:var(--bg); padding:2px 6px; border-radius:3px; }
.endpoint-summary { color:var(--muted); font-size:12px; flex:1; text-align:right; }
.endpoint-arrow { color:var(--muted); font-size:12px; transition:transform .15s; }
.endpoint.open .endpoint-arrow { transform:rotate(90deg); }
.endpoint-body { display:none; padding:4px 18px 18px; background:var(--panel); }
.endpoint.open .endpoint-body { display:block; }

.method { min-width:62px; padding:4px 8px; border-radius:4px; color:#fff; text-align:center; font:bold 11px ui-monospace,monospace; display:inline-block; letter-spacing:.3px; }
.get { background:var(--blue); } .post { background:var(--green); } .put { background:var(--purple); } .patch { background:var(--orange); } .delete { background:var(--red); } .custom { background:#555; }

.endpoint-desc { color:var(--muted); margin:8px 0 12px; font-size:13px; line-height:1.5; }

.form-row { display:flex; align-items:center; gap:8px; margin-bottom:6px; flex-wrap:wrap; }
.form-row input[type="text"] { border:1px solid var(--line); border-radius:5px; padding:7px 10px; background:var(--input-bg); color:var(--text); font:13px inherit; }
.form-row input[type="text"].field-key { flex:1; min-width:140px; }
.form-row input[type="text"].field-val { flex:1; min-width:140px; }
.param-name { font:12px ui-monospace,monospace; color:var(--muted); min-width:50px; }
.param-type { font-size:11px; color:var(--muted); background:var(--bg); padding:1px 6px; border-radius:3px; }

.toggle-filters { color:var(--blue); font-size:12px; cursor:pointer; white-space:nowrap; user-select:none; }
.toggle-filters:hover { text-decoration:underline; }

.filters-note { font-size:11px; color:var(--muted); margin:6px 0 4px; word-break:break-all; }
.filters-note code { color:var(--text); background:var(--bg); padding:1px 4px; border-radius:2px; font-size:10px; }

.dynamic-filters { margin-top:4px; }
.dynamic-filters .form-row { margin-bottom:4px; }

textarea { width:100%; border:1px solid var(--line); border-radius:5px; padding:8px; background:var(--input-bg); color:var(--text); font:12px ui-monospace,monospace; min-height:100px; resize:vertical; }

.btn { border:1px solid var(--line); border-radius:5px; padding:7px 14px; background:var(--surface); color:var(--text); cursor:pointer; font-weight:500; font-size:13px; }
.btn:hover { filter:brightness(.97); }
.btn-try { background:var(--blue); color:#fff; border-color:var(--blue); }
.btn-reset { font-size:12px; padding:5px 10px; }
.btn-small { font-size:11px; padding:3px 8px; }
.btn-danger { background:var(--red); color:#fff; border-color:var(--red); }

.warning { color:var(--red); font-weight:600; margin:8px 0; }

.response-area { display:none; margin-top:14px; border-radius:6px; overflow:hidden; }
.response-area.visible { display:block; }

.response-bar { display:flex; align-items:center; gap:10px; padding:10px 14px; background:var(--hover); border-bottom:1px solid var(--line); flex-wrap:wrap; font-size:12px; }
.response-status { display:inline-block; padding:2px 8px; border-radius:3px; font-weight:700; font-family:ui-monospace,monospace; font-size:13px; }
.response-status.ok { color:var(--green); background:#e6f7ee; }
.response-status.err { color:var(--red); background:#fde8e8; }
.response-url { font-family:ui-monospace,monospace; color:var(--muted); flex:1; word-break:break-all; font-size:12px; }
.response-meta { display:flex; gap:6px; }
.meta-chip { font-size:11px; color:var(--muted); background:var(--bg); padding:2px 8px; border-radius:10px; }

.response-body { padding:12px 14px; background:var(--resp-bg); color:var(--resp-text); }
.response-body pre { margin:0; font:12px ui-monospace,monospace; white-space:pre-wrap; word-break:break-all; max-height:500px; overflow:auto; }

.response-search-wrap { display:flex; align-items:center; gap:6px; padding:6px 14px; background:var(--panel); border-bottom:1px solid var(--line); }
.response-search-wrap input { border:1px solid var(--line); border-radius:4px; padding:5px 8px; background:var(--input-bg); color:var(--text); font:12px monospace; width:200px; }
.search-nav { font-size:11px; color:var(--muted); display:flex; align-items:center; gap:4px; }
.search-nav button { font-size:11px; padding:2px 6px; border:1px solid var(--line); border-radius:3px; background:var(--surface); color:var(--text); cursor:pointer; }
.search-nav button:hover { background:var(--hover); }
.search-count { font-size:11px; color:var(--muted); min-width:70px; text-align:right; }

.highlight-match { background:rgba(255,235,59,0.45); color:inherit; border-radius:2px; }
.highlight-current { background:var(--orange); color:#fff; border-radius:2px; }

.loading { font-size:12px; color:var(--muted); padding:4px 0; }

.error-card { background:#fff3f3; border:1px solid var(--red); border-radius:8px; padding:18px; margin:22px 0; color:var(--red); }

.modal-overlay { display:none; position:fixed; inset:0; background:#00000060; z-index:100; justify-content:center; align-items:flex-start; padding-top:40px; overflow-y:auto; }
.modal-overlay.visible { display:flex; }
.modal { background:var(--surface); border-radius:10px; width:95vw; max-width:700px; box-shadow:0 12px 48px #00000040; max-height:90vh; display:flex; flex-direction:column; }
.modal-header { display:flex; align-items:center; justify-content:space-between; padding:18px 22px; border-bottom:1px solid var(--line); }
.modal-header h2 { margin:0; font-size:18px; } .modal-header button { border:none; background:none; color:var(--muted); font-size:20px; cursor:pointer; padding:4px 8px; border-radius:4px; }
.modal-body { padding:18px 22px; overflow-y:auto; flex:1; }
.modal-body label { display:block; font-weight:600; margin:12px 0 4px; font-size:13px; }
.modal-body label small { font-weight:400; color:var(--muted); }
.modal-body input[type="text"], .modal-body input[type="number"], .modal-body select { border:1px solid var(--line); border-radius:5px; padding:7px 10px; background:var(--input-bg); color:var(--text); font:13px inherit; width:100%; }
.modal-body input[type="checkbox"] { width:auto; margin-right:6px; }
.check-group { display:flex; gap:16px; flex-wrap:wrap; }
.check-group label { display:flex; align-items:center; font-weight:400; margin:0; cursor:pointer; }
.check-group label input { accent-color:var(--blue); }
.schema-row { display:flex; gap:6px; align-items:center; margin-bottom:6px; }
.schema-row input[type="text"] { flex:1; min-width:80px; }
.schema-row select { width:100px; flex:none; }
.schema-row .schema-default { flex:1.2; min-width:60px; }
.modal-footer { display:flex; gap:10px; justify-content:flex-end; padding:14px 22px; border-top:1px solid var(--line); }
.modal-footer button { padding:8px 18px; border-radius:5px; cursor:pointer; font-weight:600; font-size:13px; border:1px solid var(--line); background:var(--surface); color:var(--text); }
.modal-footer .btn-create { background:var(--blue); color:#fff; border-color:var(--blue); }
.modal-footer .btn-create:disabled { opacity:.5; cursor:default; }
.creator-error { background:#fff3f3; color:var(--red); padding:8px 12px; border-radius:5px; margin-top:10px; font-size:13px; display:none; }
.creator-error.visible { display:block; }
.creator-response { margin-top:12px; display:none; }
.creator-response.visible { display:block; }
.creator-response pre { margin:0; font:12px ui-monospace,monospace; white-space:pre-wrap; word-break:break-all; max-height:200px; overflow:auto; background:var(--resp-bg); color:var(--resp-text); padding:10px; border-radius:5px; }

@media (max-width:700px) { .topbar { padding:12px 14px; } .container { padding:16px 8px; } .endpoint-header { flex-wrap:wrap; gap:6px; } .endpoint-summary { width:100%; text-align:left; } .form-row { flex-direction:column; align-items:stretch; } .response-bar { flex-direction:column; align-items:flex-start; } }
</style>
</head>
<body>
<header class="topbar"><h1>Seed<span>box</span></h1><div class="top-actions"><button onclick="openCreator()" title="Create a new CRUD resource with schema and seed data">+ Create Route</button><button id="themeToggle">Dark</button><a href="/">Home</a></div></header>
<main class="container">
<p class="intro">Seedbox is a file-backed API workspace. Add group files under <code>routes/</code> to define endpoints. Each file maps URL patterns to HTTP method configurations.</p>
<div class="toolbar"><input id="resourceFilter" type="search" placeholder="Filter by group, path, or method"><button class="btn" onclick="expandAll()">Expand all</button><button class="btn" onclick="collapseAll()">Collapse all</button></div>
<?php if ($configError): ?>
<div class="error-card"><strong>Configuration error:</strong> <?= htmlspecialchars($configError) ?></div>
<?php elseif (empty($groups)): ?>
<div class="error-card">No route files found in routes/</div>
<?php else: ?>
<?php foreach ($groups as $gi => $group):
    $groupId = htmlspecialchars($group['id'], ENT_QUOTES);
    $groupLabel = htmlspecialchars($group['label'], ENT_QUOTES);
    $groupRoutes = $group['routes'] ?? [];
    $groupScenarios = !empty($group['crud']) ? scenario_names($docRoot, $group['id']) : [];
    $groupActiveScenario = !empty($group['crud']) ? active_scenario($docRoot, $group['id']) : null;
?>
<section class="group" data-resource="group:<?= strtolower($groupId) ?>">
<div class="group-head"><h2><?= $groupLabel ?></h2><div class="group-meta"><span><?= count($groupRoutes) ?> route(s)</span><?php if (!empty($groupScenarios)): ?><select class="scenario-select" aria-label="Active scenario for <?= $groupLabel ?>" onchange="switchScenario('<?= $groupId ?>', this.value)" onclick="event.stopPropagation()"><?php foreach ($groupScenarios as $scenario): ?><option value="<?= htmlspecialchars($scenario, ENT_QUOTES) ?>"<?= $scenario === $groupActiveScenario ? ' selected' : '' ?>><?= htmlspecialchars($scenario) ?></option><?php endforeach; ?></select><?php endif; ?><span class="group-chevron">&#9654;</span></div></div>
<div class="group-body">
<?php foreach ($groupRoutes as $ri => $route):
    $method = strtoupper($route['method'] ?? 'GET');
    $url = $route['url'] ?? '/';
    $status = $route['status'] ?? 200;
    $operation = $route['operation'] ?? 'mock';
    $params = explorer_param_names($url);
    $cls = explorer_method_class($method);
    $uid = $gi . '-' . $ri;
    $summary = explorer_summary($docRoot, $route);
    $filterPath = strtolower(htmlspecialchars($url, ENT_QUOTES));
    $filterMethod = strtolower($method);
    $filterOp = strtolower($operation);

    $editableFields = [];
    if (in_array($operation, ['create', 'patch'])) {
        $editableFields = explorer_schema_fields($docRoot, $route, true);
    }
    $defaultBody = '';
    if ($operation === 'create' && !empty($editableFields)) {
        $defaultBody = explorer_default_body_json($editableFields);
    }
    $hasSeed = ($operation === 'reset') && explorer_has_seed($docRoot, $route);
?>
<div class="endpoint" data-resource="method:<?= $filterMethod ?> path:<?= $filterPath ?> op:<?= $filterOp ?>">
<div class="endpoint-header" onclick="toggleEndpoint(this)">
  <span class="method <?= $cls ?>"><?= htmlspecialchars($method) ?></span>
  <span class="endpoint-op"><?= htmlspecialchars($operation) ?></span>
  <span class="endpoint-path"><?= htmlspecialchars($url) ?></span>
  <span class="endpoint-summary"><?= $summary ?></span>
  <span class="endpoint-arrow">&#9654;</span>
</div>
<div class="endpoint-body">
  <?php if ($operation === 'list'): ?>
  <p class="endpoint-desc">Retrieve a paginated collection. Add query parameters as needed.</p>
  <?php elseif ($operation === 'create'): ?>
  <p class="endpoint-desc">Create a new record. Fields are validated against schema.json. Server-managed fields (id, createdAt, modifiedAt, version) are rejected with 400. Body is pre-filled with schema defaults.</p>
  <?php elseif ($operation === 'patch'): ?>
  <p class="endpoint-desc">Update specific editable fields on an existing record. Server-managed and non-editable fields are rejected.</p>
  <?php elseif ($operation === 'delete'): ?>
  <p class="endpoint-desc">Permanently delete a record. <strong>Requires the current version in the request body.</strong> Get it from a GET read request. Stale version returns 409 Conflict.</p>
  <?php elseif ($operation === 'read'): ?>
  <p class="endpoint-desc">Retrieve a single record by ID. Contains the current version needed for DELETE.</p>
  <?php elseif ($operation === 'reset'): ?>
   <p class="endpoint-desc">Wipe all records in the active scenario and re-seed from that scenario's seed.json. Destructive operation useful for test isolation and CI.</p>
  <?php else: ?>
  <p class="endpoint-desc">Static mock response served from file.</p>
  <?php endif; ?>

  <form class="try-form" id="form-<?= $uid ?>">
  <input type="hidden" name="_uid" value="<?= $uid ?>">
  <input type="hidden" name="_method" value="<?= htmlspecialchars($method) ?>">
  <input type="hidden" name="_path" value="<?= htmlspecialchars($url) ?>">

  <?php if (!empty($params)): ?>
  <?php foreach ($params as $p): ?>
  <?php $ptype = ($p === 'id') ? 'number' : 'string'; ?>
  <div class="form-row"><span class="param-name">{<?= htmlspecialchars($p) ?>}</span><span class="param-type"><?= $ptype ?></span><input type="text" name="param_<?= htmlspecialchars($p) ?>" placeholder="<?= htmlspecialchars($p) ?>"></div>
  <?php endforeach; ?>
  <?php endif; ?>

  <?php if ($operation === 'list'): ?>
  <div class="form-row"><span class="param-name">_page</span><span class="param-type">number</span><input type="text" name="_page" value="1"><button type="button" class="btn btn-reset" onclick="addQueryParam('extra-q-<?= $uid ?>')">+ Add query param</button></div>
  <div class="dynamic-filters" id="extra-q-<?= $uid ?>"></div>
  <?php endif; ?>

  <?php if ($operation === 'create'): ?>
  <?php if (!empty($editableFields)): ?>
  <div class="filters-note">Editable fields: <?php foreach ($editableFields as $f => $def): ?><code><?= htmlspecialchars($f) ?> (<?= htmlspecialchars($def['type']) ?>)</code> <?php endforeach; ?></div>
  <?php endif; ?>
  <textarea name="_body" style="margin-top:8px"><?= htmlspecialchars($defaultBody) ?></textarea>
  <?php endif; ?>

  <?php if ($operation === 'patch'): ?>
  <?php if (!empty($editableFields)): ?>
  <div class="filters-note">Editable fields: <?php foreach ($editableFields as $f => $def): ?><code><?= htmlspecialchars($f) ?> (<?= htmlspecialchars($def['type']) ?>)</code> <?php endforeach; ?></div>
  <div class="form-row">
    <span class="param-name">Field</span>
    <input type="text" id="patch-field-<?= $uid ?>" class="field-key" placeholder="title" oninput="patchFieldChanged('<?= $uid ?>')" style="flex:1">
    <span class="param-name">Value</span>
    <input type="text" id="patch-value-<?= $uid ?>" class="field-val" placeholder="new value" oninput="patchFieldChanged('<?= $uid ?>')" style="flex:1">
  </div>
  <textarea name="_body" id="patch-body-<?= $uid ?>" style="margin-top:8px">{
  "key": null
}</textarea>
  <?php endif; ?>
  <?php endif; ?>

  <?php if ($operation === 'delete'): ?>
  <div class="filters-note">Body must include the current record <code>version</code>. Get it from a GET read request on this endpoint.</div>
  <textarea name="_body" style="margin-top:8px">{
  "version": 1
}</textarea>
  <?php endif; ?>

  <?php if ($operation === 'reset'): ?>
   <p class="warning">This permanently deletes all records in the active scenario and re-seeds from its seed.json. <?php if (!$hasSeed): ?>No seed.json found for this scenario.<?php endif; ?></p>
  <?php endif; ?>

  <?php if ($operation === 'mock' && in_array($method, ['POST', 'PUT', 'PATCH'], true)): ?>
  <textarea name="_body" style="margin-top:8px">{}</textarea>
  <?php endif; ?>

  <?php if (!in_array($operation, ['list', 'create', 'patch', 'delete', 'reset'], true)): ?>
  <div class="dynamic-filters" id="extra-q-<?= $uid ?>"></div>
  <?php endif; ?>

  <div class="dynamic-filters" id="extra-h-<?= $uid ?>"></div>
  <div class="form-row">
    <?php if (!in_array($operation, ['list', 'create', 'patch', 'delete', 'reset'], true)): ?>
    <button type="button" class="btn btn-reset" onclick="addQueryParam('extra-q-<?= $uid ?>')">+ Add query param</button>
    <?php endif; ?>
    <button type="button" class="btn btn-reset" onclick="addHeaderParam('extra-h-<?= $uid ?>')">+ Add request header</button>
  </div>
  </form>

  <div class="form-row" style="margin-top:10px">
    <button type="submit" class="btn <?= ($operation === 'reset') ? 'btn-danger' : 'btn-try' ?>" onclick="tryRequest('form-<?= $uid ?>')"><?= ($operation === 'reset') ? 'Reset' : 'Try it' ?></button>
    <span class="loading" id="loading-<?= $uid ?>" style="display:none">Loading...</span>
  </div>

  <div class="response-area" id="resp-<?= $uid ?>">
    <div class="response-bar" id="resp-bar-<?= $uid ?>"></div>
    <div class="response-search-wrap" id="resp-search-wrap-<?= $uid ?>" style="display:none">
      <input type="text" id="resp-search-<?= $uid ?>" placeholder="Search response..." oninput="searchResponse('<?= $uid ?>')" onkeydown="searchNavigate(event, '<?= $uid ?>')">
      <span class="search-count" id="search-count-<?= $uid ?>"></span>
      <span class="search-nav">
        <button onclick="searchPrev('<?= $uid ?>')" title="Shift+Enter">&#9650; Prev</button>
        <button onclick="searchNext('<?= $uid ?>')" title="Enter">Next &#9660;</button>
      </span>
    </div>
    <div class="response-body" id="resp-body-<?= $uid ?>" style="display:none"><pre></pre></div>
  </div>
</div></div>
<?php endforeach; ?>
<?php if (!empty($group['crud'])): ?>
<div class="form-row" style="margin-top:12px"><button type="button" class="btn btn-reset" onclick="openMockCreator('<?= $groupId ?>')">+ Add Mock Route</button></div>
<?php endif; ?>
</div>
</section>
<?php endforeach; endif; ?>
</main>

<div class="modal-overlay" id="creatorOverlay">
<div class="modal">
  <div class="modal-header">
    <h2>Create Route Resource</h2>
    <button onclick="closeCreator()">&times;</button>
  </div>
  <div class="modal-body">
    <label>Model name <small>(lowercase letters, digits, hyphens)</small></label>
    <input type="text" id="creatorModel" placeholder="e.g. products" oninput="creatorChange()">

    <label>Operations</label>
    <div class="check-group">
      <label><input type="checkbox" id="creatorList" checked onchange="creatorChange()"> List (GET)</label>
      <label><input type="checkbox" id="creatorCreate" checked onchange="creatorChange()"> Create (POST)</label>
      <label><input type="checkbox" id="creatorRead" checked onchange="creatorChange()"> Read (GET /{id})</label>
      <label><input type="checkbox" id="creatorPatch" checked onchange="creatorChange()"> Patch (PATCH /{id})</label>
      <label><input type="checkbox" id="creatorDelete" checked onchange="creatorChange()"> Delete (DELETE /{id})</label>
      <label><input type="checkbox" id="creatorReset" checked onchange="creatorChange()"> Reset (POST /reset)</label>
    </div>

    <label>Page limit</label>
    <input type="number" id="creatorLimit" value="10" min="1" max="100">

    <label>Schema fields</label>
    <div id="creatorSchemaRows"></div>
    <button type="button" class="btn btn-small" onclick="addSchemaRow()" style="margin-top:6px">+ Add field</button>

    <label>Seed data <small>(JSON array of objects with positive integer ids)</small></label>
    <textarea id="creatorSeed" style="min-height:160px" oninput="this.dataset.userEdited='1'" placeholder="Auto-generated from schema fields. Edit to customize.">[{"id": 1, "version": 0}]</textarea>

    <div class="creator-error" id="creatorError"></div>
    <div class="creator-response" id="creatorResponse"><pre></pre></div>
  </div>
  <div class="modal-footer">
    <button onclick="closeCreator()">Cancel</button>
    <button class="btn-create" id="creatorSubmit" onclick="submitCreator()">Create Resource</button>
  </div>
</div>
</div>

<div class="modal-overlay" id="mockCreatorOverlay">
<div class="modal">
  <div class="modal-header">
    <h2>Add Mock Route</h2>
    <button onclick="closeMockCreator()">&times;</button>
  </div>
  <div class="modal-body">
    <label>Path <small id="mockPathHint"></small></label>
    <input type="text" id="mockPath" placeholder="e.g. /api/posts/featured">

    <label>Method</label>
    <select id="mockMethod"><option>GET</option><option>POST</option><option>PUT</option><option>PATCH</option><option>DELETE</option></select>

    <label>Status</label>
    <input type="number" id="mockStatus" value="200" min="100" max="599">

    <label>Response body <small>(valid JSON)</small></label>
    <textarea id="mockResponse" style="min-height:160px">{}</textarea>

    <div class="creator-error" id="mockCreatorError"></div>
    <div class="creator-response" id="mockCreatorResponse"><pre></pre></div>
  </div>
  <div class="modal-footer">
    <button onclick="closeMockCreator()">Cancel</button>
    <button class="btn-create" id="mockCreatorSubmit" onclick="submitMockCreator()">Create Mock Route</button>
  </div>
</div>
</div>

<script>
document.querySelectorAll('.group-head').forEach(function(h) { h.addEventListener('click', function(e) { if (e.target.closest('button')) return; h.parentElement.classList.toggle('open'); }); });
function toggleEndpoint(header) { header.parentElement.classList.toggle('open'); }
document.getElementById('resourceFilter').addEventListener('input', function() {
    var v = this.value.toLowerCase();
    document.querySelectorAll('.group').forEach(function(g) {
        var vis = !v;
        g.querySelectorAll('.endpoint').forEach(function(e) {
            var match = e.dataset.resource.indexOf(v) >= 0;
            e.style.display = match ? '' : 'none';
            if (match) vis = true;
        });
        g.style.display = vis ? '' : 'none';
    });
});
function expandAll() { document.querySelectorAll('.group').forEach(function(g) { g.classList.add('open'); }); document.querySelectorAll('.endpoint').forEach(function(e) { e.classList.add('open'); }); }
function collapseAll() { document.querySelectorAll('.group').forEach(function(g) { g.classList.remove('open'); }); document.querySelectorAll('.endpoint').forEach(function(e) { e.classList.remove('open'); }); }

function patchFieldChanged(uid) {
    var fieldEl = document.getElementById('patch-field-' + uid);
    var valueEl = document.getElementById('patch-value-' + uid);
    var bodyEl = document.getElementById('patch-body-' + uid);
    var field = (fieldEl && fieldEl.value.trim()) ? fieldEl.value.trim() : '';
    var value = (valueEl && valueEl.value.trim()) ? valueEl.value.trim() : 'null';
    if (!field) return;
    var body = '{\n  "' + field + '": ' + (value === 'null' || value === 'true' || value === 'false' || !isNaN(value) ? value : JSON.stringify(value)) + '\n}';
    bodyEl.value = body;
}

function addQueryParam(containerId) {
    var c = document.getElementById(containerId);
    var div = document.createElement('div');
    div.className = 'form-row';
    div.innerHTML = '<input type="text" class="field-key" placeholder="key" style="flex:1"><span>=</span><input type="text" class="field-val" placeholder="value" style="flex:1"><button type="button" class="btn btn-small" onclick="this.parentElement.remove()">X</button>';
    c.appendChild(div);
}

function addHeaderParam(containerId) {
    var c = document.getElementById(containerId);
    var div = document.createElement('div');
    div.className = 'form-row';
    div.innerHTML = '<input type="text" class="field-key" placeholder="Header-Name" style="flex:1"><span>:</span><input type="text" class="field-val" placeholder="value" style="flex:1"><button type="button" class="btn btn-small" onclick="this.parentElement.remove()">X</button>';
    c.appendChild(div);
}

function escHtml(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

var searchState = {};

async function tryRequest(formId) {
    var form = document.getElementById(formId);
    var uid = form.querySelector('[name=_uid]').value;
    var method = form.querySelector('[name=_method]').value;
    var pathTemplate = form.querySelector('[name=_path]').value;

    var pathParams = {};
    form.querySelectorAll('[name^="param_"]').forEach(function(inp) { if (inp.value !== '') pathParams[inp.name.replace('param_','')] = inp.value; });
    var url = pathTemplate;
    for (var k in pathParams) url = url.replace('{' + k + '}', encodeURIComponent(pathParams[k]));
    url = url.replace(/\{[^}]+\}/g, '');

    var qs = new URLSearchParams();
    var pageEl = form.querySelector('[name=_page]');
    if (pageEl && pageEl.value) qs.set('_page', pageEl.value);
    form.querySelectorAll('[id^="extra-q-"] .form-row').forEach(function(row) {
        var k = row.querySelector('.field-key'), v = row.querySelector('.field-val');
        if (k && v && k.value) qs.set(k.value, v.value);
    });

    var qStr = qs.toString();
    if (qStr) url += (url.indexOf('?') >= 0 ? '&' : '?') + qStr;

    var reqHeaders = [];
    form.querySelectorAll('[id^="extra-h-"] .form-row').forEach(function(row) {
        var k = row.querySelector('.field-key'), v = row.querySelector('.field-val');
        if (k && v && k.value) reqHeaders.push([k.value, v.value]);
    });

    var body = undefined;
    var bodyEl = form.querySelector('[name=_body]');
    if (bodyEl) { var raw = bodyEl.value.trim(); if (raw) { try { body = JSON.parse(raw); } catch (e) { body = raw; } } }

    var loadingEl = document.getElementById('loading-' + uid);
    if (loadingEl) loadingEl.style.display = 'inline';

    var opts = { method: method };
    if (reqHeaders.length > 0) {
        var h = {};
        reqHeaders.forEach(function(p) { h[p[0]] = p[1]; });
        opts.headers = h;
    }
    if (body !== undefined) {
        if (!opts.headers) opts.headers = {};
        opts.headers['Content-Type'] = 'application/json';
        opts.body = JSON.stringify(body);
    }

    var respArea = document.getElementById('resp-' + uid);
    var respBody = document.getElementById('resp-body-' + uid);
    var respSearchWrap = document.getElementById('resp-search-wrap-' + uid);
    var respBar = document.getElementById('resp-bar-' + uid);

    respArea.classList.add('visible');
    if (loadingEl) loadingEl.style.display = 'none';

    try {
        var resp = await fetch(url, opts);
        var text = await resp.text();

        var code = resp.status;
        var ok = code >= 200 && code < 300;
        respBar.innerHTML = '<span class="response-status ' + (ok ? 'ok' : 'err') + '">' + code + '</span>' +
            '<span class="response-url">' + escHtml(method) + ' ' + escHtml(url) + '</span>' +
            '<span class="response-meta">' + buildMetaChips(text) + '</span>';

        var formatted;
        try { formatted = JSON.stringify(JSON.parse(text), null, 2); } catch (e) { formatted = text; }
        respBody.querySelector('pre').innerHTML = '';
        respBody.querySelector('pre').textContent = formatted;
        respBody.style.display = 'block';

        respSearchWrap.style.display = 'flex';
        var searchInput = document.getElementById('resp-search-' + uid);
        searchInput.value = '';
        document.getElementById('search-count-' + uid).textContent = '';
        delete searchState[uid];

    } catch (e) {
        respBar.innerHTML = '<span class="response-status err">ERR</span><span class="response-url">' + escHtml(String(e)) + '</span>';
        respBody.querySelector('pre').textContent = String(e);
        respBody.style.display = 'block';
        respSearchWrap.style.display = 'none';
    }
}

function buildMetaChips(text) {
    var chips = '';
    try {
        var d = JSON.parse(text);
        if (Array.isArray(d)) chips += '<span class="meta-chip">array</span><span class="meta-chip">' + d.length + ' items</span>';
        else if (typeof d === 'object' && d !== null) chips += '<span class="meta-chip">object</span>';
    } catch(e) {}
    return chips;
}

function searchResponse(uid) {
    var input = document.getElementById('resp-search-' + uid);
    var pre = document.getElementById('resp-body-' + uid).querySelector('pre');
    var query = input.value;
    var st = searchState[uid] || { matches: [], idx: -1 };

    pre.innerHTML = pre.textContent;

    if (!query || query.length < 1) {
        document.getElementById('search-count-' + uid).textContent = '';
        delete searchState[uid];
        return;
    }

    var text = pre.textContent;
    var escaped = query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    var regex = new RegExp('(' + escaped + ')', 'gi');
    var matches = [];
    var match;
    while ((match = regex.exec(text)) !== null) {
        matches.push(match.index);
        if (regex.lastIndex === match.index) regex.lastIndex++;
    }

    if (matches.length === 0) {
        document.getElementById('search-count-' + uid).textContent = '0 matches';
        delete searchState[uid];
        return;
    }

    var html = '';
    var lastIdx = 0;
    for (var i = 0; i < matches.length; i++) {
        var pos = matches[i];
        html += escHtml(text.substring(lastIdx, pos));
        html += '<mark class="highlight-match" id="sr-' + uid + '-' + i + '">' + escHtml(text.substring(pos, pos + query.length)) + '</mark>';
        lastIdx = pos + query.length;
    }
    html += escHtml(text.substring(lastIdx));
    pre.innerHTML = html;

    st.matches = matches;
    st.queryLen = query.length;
    if (st.idx >= matches.length) st.idx = -1;
    searchState[uid] = st;

    updateSearchNav(uid);
}

function updateSearchNav(uid) {
    var st = searchState[uid];
    var countEl = document.getElementById('search-count-' + uid);
    if (!st || st.matches.length === 0) { countEl.textContent = '0 matches'; return; }
    countEl.textContent = (st.idx >= 0 ? (st.idx + 1) : st.matches.length) + ' / ' + st.matches.length;

    document.querySelectorAll('[id^="sr-' + uid + '-"]').forEach(function(el) { el.className = 'highlight-match'; });
    if (st.idx >= 0) {
        var el = document.getElementById('sr-' + uid + '-' + st.idx);
        if (el) { el.className = 'highlight-current'; el.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
    }
}

function searchNavigate(e, uid) {
    if (e.key === 'Enter') {
        e.preventDefault();
        if (e.shiftKey) searchPrev(uid); else searchNext(uid);
    }
}

function searchNext(uid) {
    var st = searchState[uid];
    if (!st || st.matches.length === 0) return;
    st.idx = (st.idx + 1) % st.matches.length;
    updateSearchNav(uid);
}

function searchPrev(uid) {
    var st = searchState[uid];
    if (!st || st.matches.length === 0) return;
    st.idx = st.idx <= 0 ? st.matches.length - 1 : st.idx - 1;
    updateSearchNav(uid);
}

function openCreator() { document.getElementById('creatorOverlay').classList.add('visible'); initSchemaRows(); }
function closeCreator() { document.getElementById('creatorOverlay').classList.remove('visible'); }
document.getElementById('creatorOverlay').addEventListener('click', function(e) { if (e.target === this) closeCreator(); });

function initSchemaRows() {
    var container = document.getElementById('creatorSchemaRows');
    var seedEl = document.getElementById('creatorSeed');
    seedEl.dataset.userEdited = '';
    seedEl.value = '[{"id": 1, "version": 0}]';
    if (container.children.length > 0) { rebuildSeedPreview(); return; }
    addSchemaRow();
}

function addSchemaRow() {
    var container = document.getElementById('creatorSchemaRows');
    var row = document.createElement('div');
    row.className = 'schema-row';
    row.innerHTML = '<input type="text" placeholder="field name" style="flex:1;min-width:80px" oninput="rebuildSeedPreview()">' +
        '<select onchange="rebuildSeedPreview()"><option>string</option><option>number</option><option>boolean</option><option>array</option><option>datetime</option></select>' +
        '<input type="text" class="schema-default" placeholder="default" oninput="rebuildSeedPreview()">' +
        '<button type="button" class="btn btn-small" onclick="this.parentElement.remove();rebuildSeedPreview()">X</button>';
    container.appendChild(row);
    rebuildSeedPreview();
}

function rebuildSeedPreview() {
    var seedEl = document.getElementById('creatorSeed');
    if (!seedEl.dataset.userEdited) {
        var obj = { id: 1, version: 0 };
        document.querySelectorAll('#creatorSchemaRows .schema-row').forEach(function(row) {
            var inputs = row.querySelectorAll('input');
            var sel = row.querySelector('select');
            var name = inputs[0].value.trim();
            var type = sel.value;
            var def = inputs[1].value.trim();
            if (!name) return;
            if (def !== '') {
                if (type === 'number') { var n = Number(def); obj[name] = isNaN(n) ? 0 : n; }
                else if (type === 'boolean') { obj[name] = def === 'true' || def === '1'; }
                else if (type === 'array') { try { var p = JSON.parse(def); obj[name] = Array.isArray(p) ? p : []; } catch(e) { obj[name] = []; } }
                else { obj[name] = def; }
            } else {
                if (type === 'string') obj[name] = '';
                else if (type === 'number') obj[name] = 0;
                else if (type === 'boolean') obj[name] = false;
                else if (type === 'array') obj[name] = [];
                else obj[name] = null;
            }
        });
        seedEl.value = JSON.stringify([obj], null, 2);
    }
}

function creatorChange() {
    var model = document.getElementById('creatorModel').value.trim();
    var anyChecked = document.getElementById('creatorList').checked || document.getElementById('creatorCreate').checked ||
        document.getElementById('creatorRead').checked || document.getElementById('creatorPatch').checked ||
        document.getElementById('creatorDelete').checked || document.getElementById('creatorReset').checked;
    var valid = model.length > 0 && anyChecked;
    document.getElementById('creatorSubmit').disabled = !valid;
}

var mockResource = '';
function openMockCreator(resource) {
    mockResource = resource;
    document.getElementById('mockPath').value = '/api/' + resource + '/';
    document.getElementById('mockPathHint').textContent = 'Must start with /api/' + resource + '/';
    document.getElementById('mockMethod').value = 'GET';
    document.getElementById('mockStatus').value = '200';
    document.getElementById('mockResponse').value = '{}';
    document.getElementById('mockCreatorError').classList.remove('visible');
    document.getElementById('mockCreatorResponse').classList.remove('visible');
    document.getElementById('mockCreatorSubmit').disabled = false;
    document.getElementById('mockCreatorOverlay').classList.add('visible');
    document.getElementById('mockPath').focus();
}
function closeMockCreator() { document.getElementById('mockCreatorOverlay').classList.remove('visible'); }
document.getElementById('mockCreatorOverlay').addEventListener('click', function(e) { if (e.target === this) closeMockCreator(); });

async function submitMockCreator() {
    var path = document.getElementById('mockPath').value.trim();
    var method = document.getElementById('mockMethod').value;
    var status = parseInt(document.getElementById('mockStatus').value, 10);
    var errEl = document.getElementById('mockCreatorError');
    var respEl = document.getElementById('mockCreatorResponse');
    errEl.classList.remove('visible');
    respEl.classList.remove('visible');
    if (!path.startsWith('/api/' + mockResource + '/')) {
        errEl.textContent = 'Path must start with /api/' + mockResource + '/.';
        errEl.classList.add('visible');
        return;
    }
    if (!Number.isInteger(status) || status < 100 || status > 599) {
        errEl.textContent = 'Status must be between 100 and 599.';
        errEl.classList.add('visible');
        return;
    }
    var response;
    try { response = JSON.parse(document.getElementById('mockResponse').value); } catch (e) {
        errEl.textContent = 'Response body is not valid JSON.';
        errEl.classList.add('visible');
        return;
    }
    document.getElementById('mockCreatorSubmit').disabled = true;
    try {
        var resp = await fetch('/mock-route-config', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ resource: mockResource, path: path, method: method, status: status, response: response })
        });
        var text = await resp.text();
        var data;
        try { data = JSON.parse(text); } catch (e) { data = { error: text }; }
        if (resp.ok && data.created) {
            respEl.querySelector('pre').textContent = JSON.stringify(data, null, 2);
            respEl.classList.add('visible');
            setTimeout(function() { closeMockCreator(); location.reload(); }, 800);
        } else {
            errEl.textContent = data.error || 'Unknown error (HTTP ' + resp.status + ')';
            errEl.classList.add('visible');
            document.getElementById('mockCreatorSubmit').disabled = false;
        }
    } catch (e) {
        errEl.textContent = 'Network error: ' + e.message;
        errEl.classList.add('visible');
        document.getElementById('mockCreatorSubmit').disabled = false;
    }
}

async function submitCreator() {
    var model = document.getElementById('creatorModel').value.trim();
    var errEl = document.getElementById('creatorError');
    var respEl = document.getElementById('creatorResponse');

    errEl.classList.remove('visible');
    errEl.textContent = '';
    respEl.classList.remove('visible');

    if (!/^[a-z][a-z0-9-]*$/.test(model)) {
        errEl.textContent = 'Invalid model name. Use lowercase letters, digits, hyphens. Must start with a letter.';
        errEl.classList.add('visible');
        return;
    }

    var routes = {
        list: document.getElementById('creatorList').checked,
        create: document.getElementById('creatorCreate').checked,
        read: document.getElementById('creatorRead').checked,
        patch: document.getElementById('creatorPatch').checked,
        delete: document.getElementById('creatorDelete').checked,
        reset: document.getElementById('creatorReset').checked
    };
    var anySelected = Object.values(routes).some(function(v) { return v; });
    if (!anySelected) {
        errEl.textContent = 'Select at least one operation.';
        errEl.classList.add('visible');
        return;
    }

    var schema = {};
    var schemaValid = true;
    document.querySelectorAll('#creatorSchemaRows .schema-row').forEach(function(row) {
        var inputs = row.querySelectorAll('input');
        var sel = row.querySelector('select');
        var name = inputs[0].value.trim();
        var type = sel.value;
        var def = inputs[1].value.trim();
        if (!name || !/^[a-zA-Z_][a-zA-Z0-9_]*$/.test(name)) {
            errEl.textContent = 'Schema field "' + (name || '(empty)') + '" is invalid. Use letters, digits, underscores.';
            errEl.classList.add('visible');
            schemaValid = false;
            return;
        }
        var castDefault = null;
        if (def !== '') {
            if (type === 'number') { var n = Number(def); castDefault = isNaN(n) ? 0 : n; }
            else if (type === 'boolean') { castDefault = def === 'true' || def === '1'; }
            else if (type === 'array') { try { castDefault = JSON.parse(def); if (!Array.isArray(castDefault)) { castDefault = []; } } catch(e) { castDefault = []; } }
            else { castDefault = def; }
        } else {
            if (type === 'string') castDefault = '';
            else if (type === 'number') castDefault = 0;
            else if (type === 'boolean') castDefault = false;
            else if (type === 'array') castDefault = [];
            else castDefault = null;
        }
        schema[name] = { type: type, editable: true, default: castDefault };
    });
    if (!schemaValid) return;

    var seed;
    try { seed = JSON.parse(document.getElementById('creatorSeed').value); } catch(e) {
        errEl.textContent = 'Seed data is not valid JSON.';
        errEl.classList.add('visible');
        return;
    }
    if (!Array.isArray(seed)) {
        errEl.textContent = 'Seed data must be a JSON array.';
        errEl.classList.add('visible');
        return;
    }

    var limit = Math.max(1, Math.min(100, parseInt(document.getElementById('creatorLimit').value) || 10));
    var payload = { model: model, routes: routes, schema: schema, limit: limit, seed: seed };

    document.getElementById('creatorSubmit').disabled = true;
    try {
        var resp = await fetch('/routes-config', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        var text = await resp.text();
        var data;
        try { data = JSON.parse(text); } catch(e) { data = { error: text }; }

        if (resp.ok && data.created) {
            respEl.querySelector('pre').textContent = JSON.stringify(data, null, 2);
            respEl.classList.add('visible');
            document.getElementById('creatorSubmit').disabled = true;
            setTimeout(function() { closeCreator(); location.reload(); }, 1200);
        } else {
            errEl.textContent = data.error || 'Unknown error (HTTP ' + resp.status + ')';
            errEl.classList.add('visible');
            document.getElementById('creatorSubmit').disabled = false;
        }
    } catch (e) {
        errEl.textContent = 'Network error: ' + e.message;
        errEl.classList.add('visible');
        document.getElementById('creatorSubmit').disabled = false;
    }
}

async function switchScenario(resource, scenario) {
    try {
        var resp = await fetch('/scenario-config', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ resource: resource, scenario: scenario })
        });
        var text = await resp.text();
        var data;
        try { data = JSON.parse(text); } catch(e) { data = { error: text }; }
        if (!resp.ok || !data.updated) {
            throw new Error(data.error || 'Could not switch scenario');
        }
        location.reload();
    } catch (e) {
        window.alert(e.message);
        location.reload();
    }
}

var themeBtn = document.getElementById('themeToggle'), saved = localStorage.getItem('theme') || 'light';
document.documentElement.dataset.theme = saved;
themeBtn.textContent = saved === 'dark' ? 'Light' : 'Dark';
themeBtn.addEventListener('click', function() {
    var next = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
    document.documentElement.dataset.theme = next;
    localStorage.setItem('theme', next);
    themeBtn.textContent = next === 'dark' ? 'Light' : 'Dark';
});
</script>
</body>
</html>
