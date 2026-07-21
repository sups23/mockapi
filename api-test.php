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
        $seedPath = $resource ? $docRoot . '/api/' . $resource . '/seed.json' : '';
        $count = 0;
        if ($seedPath && file_exists($seedPath)) {
            $s = json_decode(file_get_contents($seedPath), true);
            if (is_array($s)) $count = count($s);
        }
        return "destructive | $count seed records";
    }
    $sf = $route['path'] ?? '';
    if ($sf === '') {
        $resource = resource_from_url($route['url'] ?? '');
        $sf = $resource ? "api/$resource/id/{id}.json" : '(derived)';
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
    return file_exists($docRoot . '/api/' . $resource . '/seed.json');
}

$docRoot = __DIR__;
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>JSON API Explorer</title>
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

@media (max-width:700px) { .topbar { padding:12px 14px; } .container { padding:16px 8px; } .endpoint-header { flex-wrap:wrap; gap:6px; } .endpoint-summary { width:100%; text-align:left; } .form-row { flex-direction:column; align-items:stretch; } .response-bar { flex-direction:column; align-items:flex-start; } }
</style>
</head>
<body>
<header class="topbar"><h1>JSON <span>API Explorer</span></h1><div class="top-actions"><button id="themeToggle">Dark</button><a href="/">Home</a></div></header>
<main class="container">
<p class="intro">Keyed route registry explorer. Add group files under <code>routes/</code> to define endpoints. Each file maps URL patterns to HTTP method configurations.</p>
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
?>
<section class="group" data-resource="group:<?= strtolower($groupId) ?>">
<div class="group-head"><h2><?= $groupLabel ?></h2><div class="group-meta"><span><?= count($groupRoutes) ?> route(s)</span><span class="group-chevron">&#9654;</span></div></div>
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

    $allFields = [];
    $editableFields = [];
    if (in_array($operation, ['list', 'create', 'patch'])) {
        $allFields = explorer_schema_fields($docRoot, $route, false);
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
  <p class="endpoint-desc">Paginated list. Supports filters, search, range queries, and CSV array matching. Unknown filter fields return 400. Range operators only work on number/datetime fields.</p>
  <?php elseif ($operation === 'create'): ?>
  <p class="endpoint-desc">Create a new record. Fields are validated against schema.json. Server-managed fields (id, createdAt, modifiedAt, version) are rejected with 400. Body is pre-filled with schema defaults.</p>
  <?php elseif ($operation === 'patch'): ?>
  <p class="endpoint-desc">Update specific editable fields on an existing record. Server-managed and non-editable fields are rejected.</p>
  <?php elseif ($operation === 'delete'): ?>
  <p class="endpoint-desc">Permanently delete a record. <strong>Requires the current version in the request body.</strong> Get it from a GET read request. Stale version returns 409 Conflict.</p>
  <?php elseif ($operation === 'read'): ?>
  <p class="endpoint-desc">Retrieve a single record by ID. Contains the current version needed for DELETE.</p>
  <?php elseif ($operation === 'reset'): ?>
  <p class="endpoint-desc">Wipe all runtime records and re-seed from seed.json. Destructive operation useful for test isolation and CI.</p>
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
  <div class="form-row"><span class="param-name">_page</span><span class="param-type">number</span><input type="text" name="_page" value="1"><span class="param-name">_sort</span><span class="param-type">string</span><input type="text" name="_sort" placeholder="field:ASC;field:DESC"><span class="toggle-filters" onclick="toggleExtra(this)">+ filters</span></div>
  <div class="extra-params" style="display:none">
    <?php if (!empty($allFields)): ?>
    <div class="filters-note">Fields: <?php foreach ($allFields as $f => $def): ?><code><?= htmlspecialchars($f) ?> (<?= htmlspecialchars($def['type']) ?>)</code> <?php endforeach; ?></div>
    <?php endif; ?>
    <div class="filters-note">Filters: <code>field=value</code> | Range: <code>field__LTE=10</code> <code>field__GTE=5</code> (numbers only) | Search: <code>field__SEARCH=text</code> (strings/arrays) | NE: <code>field__NE=val</code></div>
    <div class="form-row">
      <input type="text" class="field-key" placeholder="field or field__LTE / field__SEARCH" style="flex:1">
      <span>=</span>
      <input type="text" class="field-val" placeholder="value" style="flex:1">
      <button type="button" class="btn btn-reset" onclick="addFilter(this)">+ Add</button>
    </div>
    <div class="dynamic-filters"></div>
  </div>
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
  <p class="warning">This permanently deletes all runtime records and re-seeds from seed.json. <?php if (!$hasSeed): ?>No seed.json found for this resource.<?php endif; ?></p>
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
</div>
</section>
<?php endforeach; endif; ?>
</main>
<script>
document.querySelectorAll('.group-head').forEach(function(h) { h.addEventListener('click', function(e) { if (e.target.closest('button')) return; h.parentElement.classList.toggle('open'); }); });
function toggleEndpoint(header) { header.parentElement.classList.toggle('open'); }
function toggleExtra(el) {
    var extra = el.closest('.form-row').nextElementSibling;
    if (!extra || !extra.classList.contains('extra-params')) extra = el.closest('.form-row').parentElement.querySelector('.extra-params');
    if (extra) { var v = extra.style.display === 'block'; extra.style.display = v ? 'none' : 'block'; el.textContent = v ? '+ filters' : '- filters'; }
}

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

function addFilter(btn) {
    var row = btn.closest('.form-row');
    var keyInput = row.querySelector('.field-key');
    var valInput = row.querySelector('.field-val');
    var k = keyInput.value.trim(), v = valInput.value.trim();
    if (!k) return;
    var container = row.parentElement.querySelector('.dynamic-filters');
    var div = document.createElement('div');
    div.className = 'form-row';
    div.innerHTML = '<code style="font-size:11px;min-width:80px;word-break:break-all">' + escHtml(k) + '</code><span>=</span><code style="font-size:11px;flex:1;word-break:break-all">' + escHtml(v) + '</code><button type="button" class="btn btn-small" onclick="this.parentElement.remove()">X</button>';
    div.dataset.key = k; div.dataset.val = v;
    container.appendChild(div);
    keyInput.value = ''; valInput.value = '';
}

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
    var sortEl = form.querySelector('[name=_sort]');
    if (sortEl && sortEl.value) qs.set('_sort', sortEl.value);

    form.querySelectorAll('.dynamic-filters .form-row').forEach(function(row) {
        if (row.dataset.key) qs.set(row.dataset.key, row.dataset.val || '');
    });
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
