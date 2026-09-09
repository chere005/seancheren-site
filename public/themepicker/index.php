<?php
// A page served under /test/ (the sandbox mirror) loads lib-test/ instead of lib/,
// isolated in code, config and data. The marketing pages hold no data, so they used
// to keep a plain lib-only preamble; they carry this one since 2026-08-22, when a
// sandbox page turned out to be unable to find a lib at all from one directory down.
//
// THREE signals, and all three are needed. __DIR__ with a bare strpos for '/test/'
// missed the instance's OWN top-level page — /home/public/test/index.php sits in
// /home/public/test, with no trailing slash — so the sandbox home silently loaded
// production's lib AND production's data. The host check is what the subdomain
// routing needs: test.seancheren.com/X is rewritten to /test/X internally, so
// REQUEST_URI never says /test/ there either.
$__host   = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
// Which sandbox, if any — '' is production. dev came back on 2026-08-23
// ("deploy a clone from prod to test and dev"), same three signals as test.
$__inst   = '';
foreach (['test', 'dev'] as $__i) {
    if (preg_match('#/' . $__i . '(/|$)#', __DIR__) === 1
        || strncmp($_SERVER['REQUEST_URI'] ?? '', '/' . $__i . '/', strlen($__i) + 2) === 0
        || strncmp($__host, $__i . '.', strlen($__i) + 1) === 0) { $__inst = $__i; break; }
}
$__libDir = null;
$__cands  = $__inst !== ''
    ? [__DIR__ . '/../../../lib-' . $__inst, '/home/protected/lib-' . $__inst]
    : [__DIR__ . '/../../lib',      '/home/protected/lib'];
foreach ($__cands as $__c) {
    if (is_file($__c . '/site.php')) { $__libDir = $__c; break; }
}
require_once $__libDir . '/site.php';


/** HTML-escape. */
function e(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

/**
 * The cookies here are scoped to this instance, like the session cookies: a theme
 * picked on /test/ must not re-dress production's pages, or the other way. Same
 * shape the settheme path has always used.
 */
function tp_cookie_path(): string {
    foreach (['/test/', '/dev/'] as $b) {
        if (strncmp($_SERVER['REQUEST_URI'] ?? '/', $b, strlen($b)) === 0) { return rtrim($b, '/') . '/'; }
    }
    return '/';
}

/** Set (or, with '' + past expiry, clear) one of the theme cookies. */
function tp_set_cookie(string $name, string $value, bool $clear = false): void {
    setcookie($name, $value, [
        'expires'  => $clear ? time() - 3600 : time() + 31536000,
        'path'     => tp_cookie_path(),
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

// The role columns a palette fills, in the order the editor shows them, each with a
// label and a one-line note on what it dresses. The KEYS are the CSS custom
// properties theme_vars() emits, so the picker and the stylesheet cannot drift.
const TP_ROLES = [
    '--bg'          => ['Background',  'the page itself'],
    '--surface'     => ['Surface',     'cards and bars'],
    '--surface-2'   => ['Surface 2',   'raised chips'],
    '--line'        => ['Line',        'borders'],
    '--line-soft'   => ['Line soft',   'faint rules'],
    '--text'        => ['Text',        'body copy'],
    '--text-dim'    => ['Text dim',    'secondary text'],
    '--muted'       => ['Muted',       'captions'],
    '--accent'      => ['Accent',      'links and buttons'],
    '--accent-ink'  => ['Accent ink',  'text on the accent'],
    '--accent-soft' => ['Accent soft', 'accent wash'],
    '--gold'        => ['Gold',        'stars and marks'],
];

// --- Picking a preset: a plain POST→redirect that sets the sitetheme cookie the public
// pages read (lib/site.php) and clears any session-local custom, so choosing a preset
// always drops a custom you were wearing. No account, no app prefs — the apps never see
// it. An unknown name is simply ignored.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'settheme') {
    $t = (string) ($_POST['theme'] ?? '');
    if (isset(THEMES[$t])) {
        tp_set_cookie('sitetheme', $t);
        tp_set_cookie('sitethemevars', '', true);
    }
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'), true, 302);
    exit;
}

// --- Wearing a custom theme, for THIS browser only. The workbench (the list of themes
// you build) lives in the browser's localStorage; applying one posts its twelve colours
// here so the SERVER can validate them and set the cookie every public page reads. The
// validation is the same allow-list site_custom_vars() reads back with: each value is
// admitted only as #rrggbb, an unknown or malformed role falls back to Midnight's, and
// the canonical JSON that reaches the cookie is rebuilt here rather than echoed — so
// nothing a page later drops into a <style> block came unfiltered from the request.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'usecustom') {
    $in    = json_decode((string) ($_POST['vars'] ?? ''), true);
    $clean = [];
    if (is_array($in)) {
        foreach (theme_vars('midnight')['vars'] as $role => $fallback) {
            $v = strtolower((string) ($in[$role] ?? ''));
            $clean[$role] = preg_match('/^#[0-9a-f]{6}$/', $v) === 1 ? $v : $fallback;
        }
    }
    if ($clean) {
        tp_set_cookie('sitetheme', 'custom');
        tp_set_cookie('sitethemevars', json_encode($clean));
    }
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'), true, 302);
    exit;
}

$current  = site_theme();                 // the preset key, or 'midnight' when a custom is worn
$isCustom = site_custom_vars() !== null;  // whether a session-local custom is active right now

// The presets as {key: {--role: hex, …}} for the client — Duplicate copies these, and the
// contrast chips are computed from them in the browser exactly as they are for a custom.
$presets = [];
foreach (THEMES as $key => $row) { $presets[$key] = theme_vars($key)['vars']; }

ob_start();
?>
<h1>Themes</h1>
<p class="tp-intro">
  Pick the palette these public pages wear. Each shows its WCAG contrast — anything under
  <b>4.5:1</b> is flagged as too low for body text. Duplicate one to make it your own, or
  start a new theme; the ones you build stay <b>on this browser only</b> and are gone when
  its storage is cleared — nothing is saved to an account or synced.
</p>

<style>
  .tp-intro { margin: 0 0 1.25rem; }
  .tp-sec { margin: 1.75rem 0 0.6rem; color: var(--accent); font-size: 1.25rem; letter-spacing: -0.01em; }
  .tp-card { border: 1px solid var(--line); border-radius: 12px; margin: 1rem 0; overflow: hidden; }
  .tp-card.on { border-color: var(--accent); }
  /* The preview is a miniature of the site shell wearing that theme's own variables,
     scoped by the inline style on .tp-prev — and inert: look, don't touch. */
  .tp-prev { background: var(--bg); color: var(--text); padding: 0.9rem 1rem 1rem; pointer-events: none; user-select: none; }
  .tp-prev .pnav { display: flex; gap: 0.4rem; padding-bottom: 0.6rem; margin-bottom: 0.7rem; border-bottom: 1px solid var(--line-soft); }
  .tp-prev .pnav span { border: 1px solid var(--line); background: var(--surface); color: var(--text-dim); border-radius: 999px; padding: 0.15rem 0.6rem; font-size: 0.75rem; }
  .tp-prev .pnav span.on { background: var(--accent); border-color: var(--accent); color: var(--accent-ink); font-weight: 700; }
  .tp-prev .ph1 { color: var(--text); font-weight: 700; font-size: 1.1rem; margin: 0 0 0.2rem; }
  .tp-prev .ph2 { color: var(--accent); font-size: 0.9rem; margin: 0.4rem 0 0.1rem; }
  .tp-prev .pp { color: var(--text-dim); font-size: 0.8rem; margin: 0.2rem 0; }
  .tp-prev .pp u { color: var(--accent); text-underline-offset: 2px; }
  .tp-prev .pmut { color: var(--muted); font-size: 0.72rem; margin-top: 0.4rem; }

  /* Contrast chips — the same read akisthemes gives while a palette is built. */
  .tp-ratios { display: flex; flex-wrap: wrap; gap: 0.3rem; padding: 0.7rem 1rem 0; background: var(--surface); border-top: 1px solid var(--line); }
  .tp-ratio { font-size: 0.68rem; padding: 0.2rem 0.5rem; border-radius: 999px; border: 1px solid var(--line); background: var(--surface-2); color: var(--text-dim); }
  .tp-ratio b { color: var(--text); font-weight: 600; }
  .tp-ratio.bad { border-color: #6b2029; background: #2a1116; color: #f5a3ad; }
  .tp-ratio.bad b { color: #ffd7dc; }

  .tp-row { display: flex; align-items: center; gap: 0.5rem; padding: 0.6rem 1rem; background: var(--surface); border-top: 1px solid var(--line); }
  .tp-row .name { font-weight: 600; color: var(--text); margin-right: auto; min-width: 0; }
  .tp-row input.name {
    font: inherit; font-weight: 600; color: var(--text); background: transparent;
    border: 1px solid transparent; border-radius: 8px; padding: 0.2rem 0.4rem; margin-right: auto;
    max-width: 55%; min-width: 0;
  }
  .tp-row input.name:focus { outline: none; border-color: var(--accent); background: var(--bg); }
  .tp-row form { margin: 0; }

  .tp-btn, .tp-cur {
    display: inline-flex; align-items: center; justify-content: center; white-space: nowrap;
    border-radius: 999px; padding: 0.3rem 0.9rem; font-size: 0.9rem; font-family: inherit; cursor: pointer;
  }
  .tp-btn { border: 1px solid var(--line); background: var(--surface-2); color: var(--text); }
  .tp-btn:hover { border-color: var(--accent); }
  .tp-btn.danger.armed { background: #2a1116; border-color: #6b2029; color: #ffd7dc; }
  .tp-cur { background: var(--accent); color: var(--accent-ink); font-weight: 700; cursor: default; }
  .tp-new {
    display: inline-flex; align-items: center; justify-content: center; gap: 0.35rem;
    border: 1px solid var(--accent); background: var(--accent); color: var(--accent-ink); font-weight: 700;
    border-radius: 999px; padding: 0.35rem 1rem; font-size: 0.95rem; font-family: inherit; cursor: pointer;
    margin-top: 0.5rem;
  }
  .tp-new:hover { opacity: 0.85; }

  /* The per-role editor, revealed only while a custom card is being edited. */
  .tp-edit { display: none; padding: 0.5rem 1rem 0.9rem; background: var(--surface); border-top: 1px solid var(--line); }
  .tp-card.editing .tp-edit { display: grid; grid-template-columns: 1fr 1fr; gap: 0.4rem 1rem; }
  @media (max-width: 480px) { .tp-card.editing .tp-edit { grid-template-columns: 1fr; } }
  .tp-role { display: flex; align-items: center; gap: 0.5rem; min-width: 0; }
  .tp-role input[type=color] { width: 30px; height: 30px; padding: 0; border: 1px solid var(--line); border-radius: 6px; background: none; cursor: pointer; flex: 0 0 auto; }
  .tp-role .rl { flex: 1 1 auto; min-width: 0; overflow: hidden; }
  .tp-role .rn { display: block; font-size: 0.8rem; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .tp-role .rj { display: block; font-size: 0.68rem; color: var(--muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .tp-role input[type=text] {
    width: 78px; flex: 0 0 auto; font: inherit; font-size: 0.8rem; color: var(--text);
    background: var(--bg); border: 1px solid var(--line); border-radius: 6px; padding: 0.2rem 0.35rem; spellcheck: false;
  }
  .tp-role input[type=text]:focus { outline: none; border-color: var(--accent); }
</style>

<?php // --- The presets, server-rendered so they show without JavaScript --------------- ?>
<div class="tp-sec">Preset themes</div>
<?php foreach (THEMES as $key => $row):
    $t = theme_vars($key);
    $vars = '';
    foreach ($t['vars'] as $k => $v) { $vars .= "$k: $v; "; }
?>
<div class="tp-card<?= (!$isCustom && $key === $current) ? ' on' : '' ?>" data-vars='<?= e(json_encode($t['vars'])) ?>' data-name="<?= e($row[0]) ?>">
  <div class="tp-prev" style="<?= e($vars) ?>" aria-hidden="true">
    <div class="pnav"><span class="on">Home</span><span>Projects</span><span>About</span></div>
    <div class="ph1">Sean Cheren</div>
    <div class="ph2">Public</div>
    <div class="pp">Body text looks like this, with <u>a link</u> in the accent.</div>
    <div class="pmut">and quieter text down here</div>
  </div>
  <div class="tp-ratios" aria-label="Contrast ratios"></div>
  <div class="tp-row">
    <span class="name"><?= e($row[0]) ?></span>
    <button type="button" class="tp-btn" data-dup>Duplicate</button>
    <?php if (!$isCustom && $key === $current): ?>
      <span class="tp-cur">Current</span>
    <?php else: ?>
      <form method="post">
        <input type="hidden" name="action" value="settheme">
        <input type="hidden" name="theme" value="<?= e($key) ?>">
        <button class="tp-btn" type="submit">Use</button>
      </form>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>

<?php // --- The workbench: custom themes, rendered from localStorage by the script ------- ?>
<div class="tp-sec">Your themes</div>
<div id="tp-cust"></div>
<button type="button" class="tp-new" id="tp-new"><span aria-hidden="true">+</span> New theme</button>

<?php // The one hidden form the script submits to wear a custom (POST→redirect→GET). ?>
<form id="tp-useform" method="post" style="display:none">
  <input type="hidden" name="action" value="usecustom">
  <input type="hidden" name="vars" id="tp-usevars">
</form>

<template id="tp-tmpl">
  <div class="tp-card">
    <div class="tp-prev" aria-hidden="true">
      <div class="pnav"><span class="on">Home</span><span>Projects</span><span>About</span></div>
      <div class="ph1">Sean Cheren</div>
      <div class="ph2">Public</div>
      <div class="pp">Body text looks like this, with <u>a link</u> in the accent.</div>
      <div class="pmut">and quieter text down here</div>
    </div>
    <div class="tp-ratios" aria-label="Contrast ratios"></div>
    <div class="tp-edit"></div>
    <div class="tp-row">
      <input class="name" maxlength="40" aria-label="Theme name" autocomplete="off" spellcheck="false">
      <button type="button" class="tp-btn" data-edit>Edit</button>
      <button type="button" class="tp-btn" data-dup>Duplicate</button>
      <button type="button" class="tp-btn danger" data-del>Delete</button>
      <button type="button" class="tp-btn" data-use>Use</button>
    </div>
  </div>
</template>

<script>
  var ROLES     = <?= json_encode(array_keys(TP_ROLES)) ?>;
  var ROLE_META = <?= json_encode(TP_ROLES) ?>;
  var PRESETS   = <?= json_encode($presets) ?>;
  var ACTIVE    = <?= json_encode($isCustom ? 'custom' : $current) ?>;
  var KEY = 'tp_custom', AKEY = 'tp_active';

  // --- WCAG contrast, the same maths akisthemes judges a palette by ------------------
  function lum(hex) {
    var n = parseInt(hex.slice(1), 16), p = [(n >> 16) & 255, (n >> 8) & 255, n & 255];
    var c = p.map(function (v) { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); });
    return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
  }
  function ratio(a, b) {
    var x = lum(a), y = lum(b);
    return Math.round(((Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05)) * 100) / 100;
  }
  function fillRatios(box, v) {
    var checks = [
      ['text',   v['--text'],       v['--bg']],
      ['muted',  v['--muted'],      v['--bg']],
      ['accent', v['--accent'],     v['--bg']],
      ['gold',   v['--gold'],       v['--bg']],
      ['ink',    v['--accent-ink'], v['--accent']]
    ];
    box.innerHTML = '';
    checks.forEach(function (k) {
      var r = ratio(k[1], k[2]);
      var el = document.createElement('span');
      el.className = 'tp-ratio' + (r < 4.5 ? ' bad' : '');
      el.innerHTML = k[0] + ' <b>' + r.toFixed(2) + ':1</b>';
      el.title = r < 4.5 ? 'Under 4.5:1 — too low for body text' : 'Clears 4.5:1';
      box.appendChild(el);
    });
  }
  function paintPrev(prev, v) {
    prev.setAttribute('style', ROLES.map(function (r) { return r + ':' + v[r]; }).join(';'));
  }

  // --- localStorage: the workbench is per-browser and nothing more -------------------
  function load() { try { return JSON.parse(localStorage.getItem(KEY)) || []; } catch (e) { return []; } }
  function save(list) { try { localStorage.setItem(KEY, JSON.stringify(list)); } catch (e) {} }
  function activeId() { try { return localStorage.getItem(AKEY) || ''; } catch (e) { return ''; } }
  function setActiveId(id) { try { localStorage.setItem(AKEY, id); } catch (e) {} }
  function uid() { return 't' + Date.now().toString(36) + Math.random().toString(36).slice(2, 6); }
  function cleanHex(s) { s = String(s).trim().toLowerCase(); if (s && s[0] !== '#') s = '#' + s; return /^#[0-9a-f]{6}$/.test(s) ? s : null; }

  function normalize(colors) {
    var out = {}, base = PRESETS.midnight;
    ROLES.forEach(function (r) { out[r] = cleanHex(colors && colors[r]) || base[r]; });
    return out;
  }
  function dupName(name) { return (name.slice(0, 30) + ' copy').slice(0, 40); }

  function addTheme(name, colors) {
    var list = load();
    list.push({ id: uid(), name: (name || 'New theme').slice(0, 40), colors: normalize(colors) });
    save(list);
    return list[list.length - 1].id;
  }

  // --- Rendering the workbench -------------------------------------------------------
  var host = document.getElementById('tp-cust');
  var tmpl = document.getElementById('tp-tmpl');

  function render() {
    var list = load(), active = activeId();
    host.innerHTML = '';
    if (!list.length) {
      var empty = document.createElement('p');
      empty.style.color = 'var(--muted)';
      empty.style.margin = '0.5rem 0';
      empty.textContent = 'No themes yet — duplicate a preset above, or start a new one.';
      host.appendChild(empty);
      return;
    }
    list.forEach(function (th) { host.appendChild(card(th, ACTIVE === 'custom' && active === th.id)); });
  }

  function card(th, isCur) {
    var node = tmpl.content.firstElementChild.cloneNode(true);
    if (isCur) { node.classList.add('on'); }
    var prev = node.querySelector('.tp-prev');
    var box  = node.querySelector('.tp-ratios');
    var edit = node.querySelector('.tp-edit');
    var name = node.querySelector('input.name');
    name.value = th.name;

    function repaint() { paintPrev(prev, th.colors); fillRatios(box, th.colors); }
    repaint();

    // The per-role editor.
    ROLES.forEach(function (r) {
      var meta = ROLE_META[r];
      var row  = document.createElement('div'); row.className = 'tp-role';
      var col  = document.createElement('input'); col.type = 'color'; col.value = th.colors[r]; col.setAttribute('aria-label', meta[0]);
      var lab  = document.createElement('div'); lab.className = 'rl';
      lab.innerHTML = '<span class="rn"></span><span class="rj"></span>';
      lab.querySelector('.rn').textContent = meta[0];
      lab.querySelector('.rj').textContent = meta[1];
      var hex  = document.createElement('input'); hex.type = 'text'; hex.value = th.colors[r]; hex.maxLength = 7; hex.spellcheck = false;

      // `input` repaints live; the commit writes localStorage once, not once per pixel.
      col.addEventListener('input', function () { th.colors[r] = col.value; hex.value = col.value; repaint(); });
      col.addEventListener('change', function () { commit(); });
      hex.addEventListener('blur', function () {
        var v = cleanHex(hex.value);
        if (!v) { hex.value = th.colors[r]; return; }
        th.colors[r] = v; col.value = v; hex.value = v; repaint(); commit();
      });
      hex.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter') { ev.preventDefault(); hex.blur(); }
        if (ev.key === 'Escape') { hex.value = th.colors[r]; hex.blur(); }
      });

      row.appendChild(col); row.appendChild(lab); row.appendChild(hex);
      edit.appendChild(row);
    });

    function commit() {
      var list = load();
      for (var i = 0; i < list.length; i++) { if (list[i].id === th.id) { list[i] = th; break; } }
      save(list);
    }

    // Rename.
    var was = th.name;
    name.addEventListener('blur', function () {
      var v = name.value.trim();
      if (v === '') { name.value = was; return; }
      was = v; th.name = v; commit();
    });
    name.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter') { ev.preventDefault(); name.blur(); }
      if (ev.key === 'Escape') { name.value = was; name.blur(); }
    });

    node.querySelector('[data-edit]').addEventListener('click', function () {
      // One card open at a time, so no forgotten field lives further down the page.
      document.querySelectorAll('.tp-card.editing').forEach(function (c) { if (c !== node) c.classList.remove('editing'); });
      node.classList.toggle('editing');
    });
    node.querySelector('[data-dup]').addEventListener('click', function () {
      var id = addTheme(dupName(th.name), th.colors); setEditNext(id); render();
    });
    node.querySelector('[data-use]').addEventListener('click', function () { useCustom(th); });

    // Delete is a two-press gesture — no confirm box, matching the suite. First press
    // arms (reddens), second removes; a click elsewhere disarms.
    var del = node.querySelector('[data-del]');
    del.addEventListener('click', function () {
      if (!del.classList.contains('armed')) { del.classList.add('armed'); return; }
      var list = load().filter(function (x) { return x.id !== th.id; });
      save(list); render();
    });
    node.addEventListener('mouseleave', function () { del.classList.remove('armed'); });

    return node;
  }

  var editNext = '';
  function setEditNext(id) { editNext = id; }

  function useCustom(th) {
    setActiveId(th.id);
    document.getElementById('tp-usevars').value = JSON.stringify(normalize(th.colors));
    document.getElementById('tp-useform').submit();
  }

  // Duplicate straight off a preset card.
  document.querySelectorAll('.tp-card[data-vars]').forEach(function (sec) {
    var v = JSON.parse(sec.getAttribute('data-vars'));
    fillRatios(sec.querySelector('.tp-ratios'), v);
    var dup = sec.querySelector('[data-dup]');
    if (dup) { dup.addEventListener('click', function () { var id = addTheme(dupName(sec.getAttribute('data-name')), v); setEditNext(id); render(); openEdit(id); }); }
  });

  function openEdit(id) {
    var cards = host.querySelectorAll('.tp-card');
    var list  = load();
    var idx   = list.findIndex(function (x) { return x.id === id; });
    if (idx >= 0 && cards[idx]) { cards[idx].classList.add('editing'); cards[idx].scrollIntoView({ block: 'center' }); }
  }

  document.getElementById('tp-new').addEventListener('click', function () {
    var id = addTheme('New theme', PRESETS.midnight); render(); openEdit(id);
  });

  render();
  if (editNext) { openEdit(editNext); editNext = ''; }
</script>
<?php
site_page('themepicker', 'Themes', ob_get_clean());
