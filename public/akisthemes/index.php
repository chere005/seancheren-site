<?php
// A page served under /test/ (the sandbox mirror) loads lib-test/ instead of lib/,
// isolated in code, config and data. Links stay root-relative — the sandbox is a
// subdomain and .htaccess maps test.seancheren.com/X to /test/X — so nothing here
// prefixes a href. Keep this preamble identical when adding a page.
//
// There was a /dev/ slot too, a second fixed sandbox. Sean, 2026-08-23: it
// "shouldn't even exist anymore". It held no data — data-dev was never created —
// so it went whole, code and all.
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
    if (is_file($__c . '/auth.php')) { $__libDir = $__c; break; }
}
require_once $__libDir . '/auth.php';
require_once $__libDir . '/chrome.php';
require_login('Themes');

/**
 * A workbench for building colour palettes. It is **not** wired to Aki's Bookshelf: the
 * eight themes below are copied in as a starting point, and editing one here changes
 * nothing in that app. The bookshelf keeps its own BOOK_THEMES; if a palette designed
 * here is wanted there, its values get carried across by hand, on purpose — so playing
 * with colours can never repaint an app someone is using.
 *
 * Palettes are per-user (palettes-<user>.json) and each is just a name plus one colour
 * per role. The roles are the bookshelf's, because they are a complete set for dressing
 * a page — a background, three greys of separation, three weights of text, an accent
 * with its own ink, and a highlight — not because anything reads them from here.
 */
$cfg  = app_config();
$me   = current_user() ?? '';
$file = user_data_file($cfg['data_dir'], 'palettes');

if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
$csrf = htmlspecialchars($_SESSION['csrf'], ENT_QUOTES);

function e(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES); }

/** The roles a palette fills, in the order they are shown, with what each one is for. */
const ROLES = [
    '--bg'           => ['Page',          'the page behind everything'],
    '--surface'      => ['Surface',       'cards and raised panels'],
    '--surface-2'    => ['Surface 2',     'inputs, hover, a row being dragged'],
    '--line'         => ['Line',          'borders and rules'],
    '--line-soft'    => ['Line soft',     'the faintest rule'],
    '--text'         => ['Text',          'body text'],
    '--text-dim'     => ['Text dim',      'secondary text on a control'],
    '--muted'        => ['Muted',         'hints, counts, bylines'],
    '--accent'       => ['Accent',        'primary buttons and active state'],
    '--accent-ink'   => ['Accent ink',    'text ON the accent'],
    '--accent-soft'  => ['Accent soft',   'the tinted panel behind an accent control'],
    '--gold'         => ['Highlight',     'stars, headings — a second accent'],
];

/**
 * The starting set: Aki's Bookshelf's eight, copied. A copy and not a reference, so an
 * edit here cannot reach that app. Only used to seed an empty file.
 *
 * The first column is the name they came in under. Palettes here are *numbered* rather
 * than named — this is a workbench, and a palette is a thing you are still deciding
 * about, so a descriptive name is something you give it once it has earned one. The
 * originals' names are kept only so an already-seeded file can be renumbered (below).
 */
const STARTERS = [
    ['Midnight',      '#111111', '#1a1a1a', '#2a2a2a', '#333333', '#262626', '#eeeeee', '#cccccc', '#888888', '#34d399', '#06251b', '#14332a', '#f0b429'],
    ['Sage & Cream',  '#fefae0', '#faedcd', '#e9edc9', '#ccd5ae', '#e4e7c9', '#3f3a2e', '#5c5545', '#776e56', '#96632f', '#fefae0', '#efe2c2', '#8a5a12'],
    ['Blossom',       '#fdf4f9', '#ffe9f2', '#ffc8dd', '#cdb4db', '#bde0fe', '#3f2e47', '#6a5273', '#7d6486', '#7b4e96', '#fff5fa', '#f0e2f6', '#8a5a12'],
    ['Dusk',          '#22223b', '#2e2e4d', '#4a4e69', '#4a4e69', '#34345a', '#f2e9e4', '#c9ada7', '#9a8c98', '#c9ada7', '#22223b', '#33324f', '#e0b877'],
    ['Neon',          '#12101a', '#1c1830', '#2a2444', '#3a3160', '#241f3c', '#f5f0ff', '#c9bee6', '#9086b0', '#00f5d4', '#072b25', '#10302b', '#fee440'],
    ['Plum & Mint',   '#2a1327', '#3a1b35', '#4e2a47', '#6b3f60', '#43203d', '#efe4ec', '#b5d8cc', '#a086a6', '#72e1d1', '#10302b', '#1d3b36', '#f0b429'],
    ['Forest',        '#040303', '#16201d', '#3a4e48', '#3a4e48', '#263230', '#e4ddd6', '#beb0a7', '#6a7b76', '#8b9d83', '#0a0f0d', '#1c2a25', '#c9a227'],
    ['Olive & Slate', '#241e2d', '#332a3e', '#443850', '#564a62', '#3b3247', '#eaf0ce', '#c0c5c1', '#848b98', '#bbbe64', '#241e2d', '#3a3448', '#d8c46a'],
];

/** A #rrggbb, or null. Everything stored goes through this — the values reach a style. */
function clean_hex(string $s): ?string
{
    $s = strtolower(trim($s));
    return preg_match('/^#[0-9a-f]{6}$/', $s) ? $s : null;
}

/** A palette row with every role filled, so the page never reads a missing key. */
function palette_clean(array $p): array
{
    $out = ['id'    => (string) ($p['id'] ?? bin2hex(random_bytes(6))),
            'name'  => mb_substr(trim((string) ($p['name'] ?? 'Untitled')), 0, 40) ?: 'Untitled',
            'colors' => []];
    $i = 0;
    foreach (ROLES as $role => $_) {
        $c = clean_hex((string) ($p['colors'][$role] ?? ''));
        // A missing role falls back to the starter's, so a hand-edited file still renders.
        $out['colors'][$role] = $c ?? (STARTERS[0][$i + 1] ?? '#888888');
        $i++;
    }
    return $out;
}

/** "Theme 4" — the next free number, so two palettes never carry the same one. */
function palette_next_name(array $list): string
{
    $used = [];
    foreach ($list as $p) {
        if (preg_match('/^Theme (\d+)$/', (string) ($p['name'] ?? ''), $m)) { $used[] = (int) $m[1]; }
    }
    $n = 1;
    while (in_array($n, $used, true)) { $n++; }
    return 'Theme ' . $n;
}

/** Load, seeding the eight starters the first time so the page opens with something. */
function palettes_load(string $file): array
{
    $raw = store_read($file);
    if (!$raw) {
        $raw = [];
        foreach (STARTERS as $n => $s) {
            $row = ['id' => bin2hex(random_bytes(6)), 'name' => 'Theme ' . ($n + 1), 'colors' => []];
            $i = 1;
            foreach (ROLES as $role => $_) { $row['colors'][$role] = $s[$i]; $i++; }
            $raw[] = $row;
        }
        store_write($file, $raw);
    } else {
        // A file seeded before palettes were numbered still carries the bookshelf's names.
        // Renumber those, but *only* where the name is still exactly the one it was seeded
        // with — anything renamed by hand is someone's decision and is left alone.
        $legacy = [];
        foreach (STARTERS as $n => $s) { $legacy[$s[0]] = 'Theme ' . ($n + 1); }
        $changed = false;
        foreach ($raw as $k => $p) {
            $was = (string) ($p['name'] ?? '');
            if (isset($legacy[$was])) { $raw[$k]['name'] = $legacy[$was]; $changed = true; }
        }
        if ($changed) { store_write($file, $raw); }
    }
    return array_map('palette_clean', array_values($raw));
}

function palettes_save(string $file, array $list): void
{
    store_write($file, array_values(array_map('palette_clean', $list)));
}

$isAjax = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';

// --- Mutations (POST -> redirect -> GET, or JSON for the background ones) ---
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400); exit('Bad request (invalid CSRF token).');
    }
    $action = (string) ($_POST['action'] ?? '');
    $list   = palettes_load($file);
    $id     = (string) ($_POST['id'] ?? '');
    $idx    = null;
    foreach ($list as $k => $p) { if ($p['id'] === $id) { $idx = $k; break; } }

    if ($action === 'add') {
        // Either a copy of an existing palette or a fresh one off the first starter, so a
        // new row is never a blank grid you have to fill in twelve times to see anything.
        $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 40);
        $new  = ['id' => bin2hex(random_bytes(6)),
                 'name' => $name !== '' ? $name : palette_next_name($list), 'colors' => []];
        if ($idx !== null) {
            // A duplicate keeps the colours and says so in the name, so the copy and the
            // original are told apart at a glance rather than by position.
            $new['colors'] = $list[$idx]['colors'];
            if ($name === '') { $new['name'] = mb_substr($list[$idx]['name'] . ' (New)', 0, 40); }
        } else {
            $i = 1;
            foreach (ROLES as $role => $_) { $new['colors'][$role] = STARTERS[0][$i]; $i++; }
        }
        array_unshift($list, $new);          // new things land at the top, as everywhere here
        palettes_save($file, $list);
        header('Location: ' . _self_path() . '#p-' . $new['id']);
        exit;
    }
    if ($action === 'delete' && $idx !== null) {
        // Two-press, like every delete in the suite: a stale page can't destroy on one tap.
        if (empty($_POST['confirm'])) { header('Location: ' . _self_path()); exit; }
        array_splice($list, $idx, 1);
        palettes_save($file, $list);
        header('Location: ' . _self_path());
        exit;
    }
    // The two below are what the editor actually uses, and both answer in place so the
    // palette you are working on doesn't scroll away underneath you.
    if ($action === 'set_color' && $idx !== null) {
        $role = (string) ($_POST['role'] ?? '');
        $hex  = clean_hex((string) ($_POST['hex'] ?? ''));
        // Both have to be good, and the answer has to say which failed — reporting ok for
        // a role that was never stored would have the page show a colour the file doesn't
        // have, and the next load would silently undo it.
        $ok   = isset(ROLES[$role]) && $hex !== null;
        if ($ok) {
            $list[$idx]['colors'][$role] = $hex;
            palettes_save($file, $list);
        }
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => $ok, 'hex' => $ok ? $hex : null]);
            exit;
        }
    }
    if ($action === 'rename' && $idx !== null) {
        $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 40);
        if ($name !== '') { $list[$idx]['name'] = $name; palettes_save($file, $list); }
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => $name !== '', 'name' => $list[$idx]['name']]);
            exit;
        }
    }
    header('Location: ' . _self_path());
    exit;
}

$palettes = palettes_load($file);
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title>Themes</title>
  <?php // theme_bg(), not a literal: this page paints itself from the user's theme, and a
        // pinned #111 left the status bar black behind a cream page. It went unnoticed
        // while the suite's own pages carried the same meta correctly. ?>
  <meta name="theme-color" content="<?= e(theme_bg()) ?>">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black">
  <meta name="apple-mobile-web-app-title" content="Themes">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: system-ui, sans-serif; background: #111; color: #eee;
           min-height: 100vh; padding: 1.5rem 1rem calc(2rem + env(safe-area-inset-bottom, 0px)); }
    .wrap { max-width: 860px; margin: 0 auto; }
    /* Same top bar as the rest of the suite: one 32px row, rule under it. */
    header { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem;
             margin-bottom: 0.5rem; padding-bottom: 0.7rem; border-bottom: 1px solid #262626; }
    .hleft { display: flex; align-items: center; gap: 0.75rem; min-width: 0; }
    .hleft h1 { font-size: 1.35rem; }
    .backbtn {
      width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center;
      border: 1px solid #333; border-radius: 999px; background: #1a1a1a; color: #ccc;
      font-size: 1.35rem; line-height: 1; text-decoration: none; flex: 0 0 auto;
      font-family: inherit; cursor: pointer; padding: 0;
    }
    .backbtn:hover { border-color: #888; color: #fff; }
    .backbtn.exitedit { display: none; background: #000; border-color: #444; color: #eee; font-size: 1.2rem; }
    body.editing .backbtn.exitedit { display: inline-flex; }
    body.editing .backbtn.goback { display: none; }
    .hright { display: flex; align-items: center; gap: 0.75rem; flex: 0 0 auto; }
    .hedit { height: 32px; display: inline-flex; align-items: center; justify-content: center;
             padding: 0 0.6rem; background: none; border: 1px solid #333; border-radius: 999px;
             color: #ccc; font-size: 0.95rem; font-family: inherit; line-height: 1; cursor: pointer; }
    .hedit:hover { border-color: #888; color: #fff; }
    body.editing .hedit { background: var(--accent); border-color: var(--accent);
                          color: var(--accent-ink); font-weight: 700; }
    .who { height: 32px; display: inline-flex; align-items: center; justify-content: center;
           padding: 0 0.8rem; border: 1px solid #2a4a3d; border-radius: 999px;
           background: none; color: var(--accent); font-size: 0.85rem; }
    .lede { color: #888; font-size: 0.85rem; margin: 0.6rem 0 1.1rem; line-height: 1.5; }
    .lede b { color: #bbb; font-weight: 600; }

    .toolbar { display: flex; align-items: center; gap: 0.6rem; height: 32px; margin-bottom: 1.2rem; }
    .btn { height: 32px; display: inline-flex; align-items: center; justify-content: center;
           padding: 0 0.9rem; border-radius: 999px; font-size: 0.9rem; font-family: inherit;
           border: 1px solid #333; background: none; color: #ccc; cursor: pointer; }
    .btn:hover { border-color: #888; color: #fff; }
    .btn.primary { background: var(--accent); color: var(--accent-ink); border-color: var(--accent); font-weight: 700; }

    .pal { border: 1px solid #262626; border-radius: 12px; margin-bottom: 1.1rem; overflow: hidden; }
    .palhead { display: flex; align-items: center; gap: 0.5rem; padding: 0.6rem 0.75rem;
               background: #161616; border-bottom: 1px solid #262626; }
    /* The name reads as plain text until that palette's pencil is on, then it is a field.
       Same element either way, so turning editing on shifts nothing. Editing is per
       palette rather than a mode for the whole page: only one is ever open, so the field
       you are typing in is never in doubt. */
    .palname { flex: 1 1 auto; min-width: 0; background: none; border: 1px solid transparent;
               border-radius: 6px; color: #eee; font: 600 1rem inherit; padding: 0.2rem 0.4rem; }
    .pal:not(.editing) .palname { pointer-events: none; }
    .pal.editing .palname { border-color: #333; }
    .palname:focus { outline: none; border-color: var(--accent); background: #1a1a1a; }
    /* The pencil lights up for whichever palette is open. Delete is always shown — this
       app holds nothing you would mind losing, and the two-press confirm is the guard. */
    .pal.editing .paledit { background: var(--accent); border-color: var(--accent);
                            color: var(--accent-ink); }
    /* Colours are only changeable on the palette that is open. Outside it the swatches
       are still shown at full strength — they are the point of the page — but they are
       inert, so a stray tap on a colour can't quietly repaint a palette you were only
       looking at. pointer-events rather than `disabled`, which would grey them out. */
    .pal:not(.editing) .role input { pointer-events: none; }
    .pal:not(.editing) .role input[type=color] { cursor: default; }
    .pal.editing { border-color: var(--accent); }
    .palact { width: 28px; height: 28px; display: inline-flex; align-items: center;
              justify-content: center; border: 1px solid #333; border-radius: 999px;
              background: none; color: #999; font-size: 1rem; line-height: 1; cursor: pointer; flex: 0 0 auto; }
    .palact:hover { border-color: #888; color: #fff; }

    /* The strip is the palette read at a glance; the grid is where it is edited. */
    .strip { display: flex; height: 34px; }
    .strip i { flex: 1; display: block; }
    .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(225px, 1fr));
            gap: 0.5rem 0.75rem; padding: 0.75rem; }
    .role { display: flex; align-items: center; gap: 0.55rem; min-width: 0; }
    .role input[type=color] { width: 30px; height: 30px; padding: 0; border: 1px solid #333;
                              border-radius: 6px; background: none; cursor: pointer; flex: 0 0 auto; }
    /* These two must be blocks: text-overflow does nothing on an inline span, so the
       longer descriptions ran on underneath the hex field instead of ellipsing. */
    .role .rl { flex: 1 1 auto; min-width: 0; overflow: hidden; }
    .role .rn, .role .rj { display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .role .rn { font-size: 0.8rem; color: #ddd; }
    .role .rj { font-size: 0.68rem; color: #666; }
    .role input[type=text] { width: 74px; background: #1a1a1a; border: 1px solid #333;
                             border-radius: 6px; color: #ccc; font: 0.72rem ui-monospace, monospace;
                             padding: 0.25rem 0.3rem; text-align: center; flex: 0 0 auto; }
    .role input[type=text]:focus { outline: none; border-color: var(--accent); }

    /* A palette is only real once you see it dressing something. */
    .prev { padding: 0 0.75rem 0.75rem; }
    .prevcard { border-radius: 10px; border: 1px solid; padding: 0.8rem; }
    .prevcard h4 { font-size: 0.95rem; margin-bottom: 0.15rem; }
    .prevcard .by { font-size: 0.78rem; margin-bottom: 0.5rem; }
    .prevcard .stars { font-size: 0.95rem; letter-spacing: 1px; }
    .prevrow { display: flex; align-items: center; gap: 0.5rem; margin-top: 0.6rem; flex-wrap: wrap; }
    .prevbtn { padding: 0.3rem 0.8rem; border-radius: 999px; font-size: 0.8rem; font-weight: 700; border: none; }
    .prevtag { padding: 0.25rem 0.6rem; border-radius: 999px; font-size: 0.75rem; border: 1px solid; }

    /* Contrast is the thing that actually decides whether a palette is usable. */
    .ratios { display: flex; flex-wrap: wrap; gap: 0.3rem; padding: 0 0.75rem 0.8rem; }
    .ratio { font-size: 0.68rem; padding: 0.2rem 0.5rem; border-radius: 999px;
             border: 1px solid #2a2a2a; background: #161616; color: #999; }
    .ratio b { color: #ddd; font-weight: 600; }
    .ratio.bad { border-color: #6b2029; background: #2a1116; color: #f5a3ad; }
    .ratio.bad b { color: #ffd7dc; }

    .addrow { display: none; align-items: center; gap: 0.5rem; margin-bottom: 1.1rem; }
    .addrow.on { display: flex; }
    .addrow input { flex: 1 1 auto; min-width: 0; background: #1a1a1a; border: 1px solid #333;
                    border-radius: 999px; color: #eee; font-size: 16px; padding: 0.4rem 0.9rem; }
    .addrow input:focus { outline: none; border-color: var(--accent); }
    .empty { color: #666; font-size: 0.9rem; padding: 2rem 0; text-align: center; }
<?php // Both of these return bare CSS, so they belong INSIDE the block — emitted after
      // </style> they render as text at the top of the page. ?>
<?= theme_css() ?>
<?= confirm_delete_styles() ?>
  </style>
</head>
<body>
<div class="wrap">
  <header>
    <div class="hleft">
      <?php // Two buttons in one slot, as everywhere in the suite: "<" normally, and a
            // black × that leaves edit mode while editing. CSS swaps them, so nothing
            // beside them shifts. ?>
      <button type="button" class="backbtn goback" onclick="history.back()" title="Back" aria-label="Back">&lsaquo;</button>
      <button type="button" class="backbtn exitedit" id="exitEditBtn" title="Done editing" aria-label="Leave edit mode">&times;</button>
      <h1>Themes</h1>
    </div>
    <?php // No page-wide Edit button: editing belongs to one palette at a time, so the
          // pencil lives on the palette. The x above still closes whichever is open. ?>
    <div class="hright">
      <span class="who"><?= e($me) ?></span>
    </div>
  </header>

  <p class="lede">
    A workbench for building colour palettes. It opens with the eight from Aki's Bookshelf
    as a starting point — but this is a <b>separate app</b>, and changing a colour here does
    not touch that one. Each swatch saves as you pick it; the numbers under a palette are
    contrast ratios against its own page colour, and anything under <b>4.5:1</b> is flagged.
  </p>

  <div class="toolbar">
    <button type="button" class="btn primary" id="newBtn">+ Palette</button>
    <span style="color:#666;font-size:0.8rem"><?= count($palettes) ?> palette<?= count($palettes) === 1 ? '' : 's' ?></span>
  </div>

  <form class="addrow" id="addRow" method="post">
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <input type="hidden" name="action" value="add">
    <input type="text" name="name" id="addName" placeholder="Name the palette…" maxlength="40" autocomplete="off">
    <button type="submit" class="btn primary">Add</button>
    <button type="button" class="btn" id="addCancel">Cancel</button>
  </form>

  <?php if (!$palettes): ?>
    <p class="empty">No palettes yet.</p>
  <?php endif; ?>

  <?php foreach ($palettes as $p): $c = $p['colors']; ?>
    <section class="pal" id="p-<?= e($p['id']) ?>" data-id="<?= e($p['id']) ?>">
      <div class="palhead">
        <input class="palname" value="<?= e($p['name']) ?>" maxlength="40" aria-label="Palette name" readonly>
        <?php // Edit, then duplicate, then delete. Edit is per-palette — only one is ever
              // open, so the field you are typing in is never ambiguous. ?>
        <button type="button" class="palact paledit" title="Rename" aria-label="Rename">&#9998;&#65038;</button>
        <form method="post" style="display:inline-flex">
          <input type="hidden" name="csrf" value="<?= $csrf ?>">
          <input type="hidden" name="action" value="add">
          <input type="hidden" name="id" value="<?= e($p['id']) ?>">
          <button type="submit" class="palact" title="Duplicate" aria-label="Duplicate">&#10697;</button>
        </form>
        <form method="post" style="display:inline-flex">
          <input type="hidden" name="csrf" value="<?= $csrf ?>">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= e($p['id']) ?>">
          <button type="submit" class="palact paldel needs-confirm" title="Delete" aria-label="Delete">&times;</button>
        </form>
      </div>

      <div class="strip">
        <?php foreach (ROLES as $role => $meta): ?>
          <i data-strip="<?= e($role) ?>" style="background:<?= e($c[$role]) ?>" title="<?= e($meta[0]) ?> <?= e($c[$role]) ?>"></i>
        <?php endforeach; ?>
      </div>

      <div class="grid">
        <?php foreach (ROLES as $role => $meta): ?>
          <div class="role">
            <input type="color" value="<?= e($c[$role]) ?>" data-role="<?= e($role) ?>" aria-label="<?= e($meta[0]) ?>">
            <span class="rl">
              <span class="rn"><?= e($meta[0]) ?></span>
              <span class="rj" title="<?= e($meta[1]) ?>"><?= e($meta[1]) ?></span>
            </span>
            <input type="text" value="<?= e($c[$role]) ?>" data-hex="<?= e($role) ?>" maxlength="7" spellcheck="false">
          </div>
        <?php endforeach; ?>
      </div>

      <div class="prev">
        <div class="prevcard">
          <h4>The Left Hand of Darkness</h4>
          <div class="by">Ursula K. Le Guin</div>
          <div class="stars"><span class="son">&#9733;&#9733;&#9733;&#9733;</span><span class="soff">&#9733;</span></div>
          <div class="prevrow">
            <button type="button" class="prevbtn">Add book</button>
            <span class="prevtag">Want to read</span>
            <span class="prevmuted" style="font-size:0.75rem">312 pages</span>
          </div>
        </div>
      </div>

      <div class="ratios"></div>
    </section>
  <?php endforeach; ?>
</div>

<?= confirm_delete_script() ?>
<script>
  var CSRF  = <?= json_encode($_SESSION['csrf'] ?? '') ?>;
  var ROLES = <?= json_encode(array_keys(ROLES)) ?>;

  // --- WCAG contrast, so a palette can be judged while it is being made ---------------
  function lum(hex) {
    var n = parseInt(hex.slice(1), 16), p = [(n >> 16) & 255, (n >> 8) & 255, n & 255];
    var c = p.map(function (v) { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); });
    return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
  }
  function ratio(a, b) {
    var x = lum(a), y = lum(b);
    return Math.round(((Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05)) * 100) / 100;
  }

  function colorsOf(sec) {
    var out = {};
    ROLES.forEach(function (r) {
      var i = sec.querySelector('input[type=color][data-role="' + r + '"]');
      if (i) { out[r] = i.value; }
    });
    return out;
  }

  // Repaint the strip, the preview card and the contrast chips from the current values.
  // Everything downstream of a colour change goes through here, so the three can't
  // disagree about what the palette currently is.
  function refresh(sec) {
    var c = colorsOf(sec);
    ROLES.forEach(function (r) {
      var s = sec.querySelector('[data-strip="' + r + '"]');
      if (s) { s.style.background = c[r]; s.title = s.title.replace(/#[0-9a-f]{6}$/i, c[r]); }
    });
    var card = sec.querySelector('.prevcard');
    if (card) {
      card.style.background  = c['--surface'];
      card.style.borderColor = c['--line'];
      card.querySelector('h4').style.color = c['--text'];
      card.querySelector('.by').style.color = c['--muted'];
      card.querySelector('.son').style.color = c['--gold'];
      card.querySelector('.soff').style.color = c['--line'];
      var b = card.querySelector('.prevbtn');
      b.style.background = c['--accent']; b.style.color = c['--accent-ink'];
      var t = card.querySelector('.prevtag');
      t.style.background = c['--accent-soft']; t.style.color = c['--accent'];
      t.style.borderColor = c['--line'];
      card.querySelector('.prevmuted').style.color = c['--muted'];
    }
    var prev = sec.querySelector('.prev');
    if (prev) { prev.style.background = c['--bg']; }

    var checks = [
      ['text',   c['--text'],       c['--bg']],
      ['muted',  c['--muted'],      c['--bg']],
      ['accent', c['--accent'],     c['--bg']],
      ['gold',   c['--gold'],       c['--bg']],
      ['ink',    c['--accent-ink'], c['--accent']]
    ];
    var box = sec.querySelector('.ratios');
    box.innerHTML = '';
    checks.forEach(function (k) {
      var v = ratio(k[1], k[2]);
      var el = document.createElement('span');
      el.className = 'ratio' + (v < 4.5 ? ' bad' : '');
      el.innerHTML = k[0] + ' <b>' + v.toFixed(2) + ':1</b>';
      el.title = v < 4.5 ? 'Under 4.5:1 — too low for body text' : 'Clears 4.5:1';
      box.appendChild(el);
    });
  }

  function post(body) {
    return fetch('', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' },
                       body: new URLSearchParams(body) });
  }

  document.querySelectorAll('.pal').forEach(function (sec) {
    var id = sec.dataset.id;
    refresh(sec);

    sec.querySelectorAll('input[type=color]').forEach(function (inp) {
      var role = inp.dataset.role;
      var hexF = sec.querySelector('input[type=text][data-hex="' + role + '"]');
      // `input` repaints as the picker is dragged; `change` is the commit, so the file is
      // written once per pick rather than once per pixel of the colour wheel.
      inp.addEventListener('input', function () {
        if (hexF) { hexF.value = inp.value; }
        refresh(sec);
      });
      inp.addEventListener('change', function () {
        post({ csrf: CSRF, action: 'set_color', id: id, role: role, hex: inp.value });
      });
      if (hexF) {
        var commit = function () {
          var v = hexF.value.trim().toLowerCase();
          if (v && v[0] !== '#') { v = '#' + v; }
          if (!/^#[0-9a-f]{6}$/.test(v)) { hexF.value = inp.value; return; }   // put it back
          hexF.value = v; inp.value = v;
          refresh(sec);
          post({ csrf: CSRF, action: 'set_color', id: id, role: role, hex: v });
        };
        hexF.addEventListener('blur', commit);
        hexF.addEventListener('keydown', function (ev) {
          if (ev.key === 'Enter') { ev.preventDefault(); hexF.blur(); }
          if (ev.key === 'Escape') { hexF.value = inp.value; hexF.blur(); }
        });
      }
    });

    var name = sec.querySelector('.palname');
    var was  = name.value;
    var save = function () {
      var v = name.value.trim();
      if (v === '' ) { name.value = was; return; }
      if (v === was) { return; }
      was = v;
      post({ csrf: CSRF, action: 'rename', id: id, name: v });
    };
    name.addEventListener('blur', save);
    name.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter') { ev.preventDefault(); name.blur(); }
      if (ev.key === 'Escape') { name.value = was; name.blur(); }
    });
  });

  var row = document.getElementById('addRow');
  document.getElementById('newBtn').addEventListener('click', function () {
    row.classList.add('on');
    document.getElementById('addName').focus();
  });
  document.getElementById('addCancel').addEventListener('click', function () { row.classList.remove('on'); });

  // --- Editing a name ---------------------------------------------------------------
  // One palette at a time: opening one closes whatever was open, so there is never a
  // second live field further down the page that you have forgotten about. Never
  // persisted — the page always opens with nothing being edited.
  // Keyboard focus has to be taken away too, or a swatch reached by Tab would still open
  // its picker on a palette that is supposed to be inert.
  function setInteractive(sec, on) {
    sec.querySelectorAll('.role input').forEach(function (i) {
      i.tabIndex = on ? 0 : -1;
      if (i.type === 'text') { i.readOnly = !on; }
    });
  }
  function closeEditing() {
    document.querySelectorAll('.pal.editing').forEach(function (s) {
      s.classList.remove('editing');
      setInteractive(s, false);
      var n = s.querySelector('.palname');
      if (n) { n.readOnly = true; }
    });
    // Dropping focus is what actually dismisses an open native colour picker.
    if (document.activeElement && document.activeElement.blur) { document.activeElement.blur(); }
    document.body.classList.remove('editing');
  }
  function openEditing(sec) {
    closeEditing();
    sec.classList.add('editing');
    document.body.classList.add('editing');   // the back button becomes the x that closes it
    setInteractive(sec, true);
    var n = sec.querySelector('.palname');
    if (n) { n.readOnly = false; n.focus(); n.select(); }
  }
  // Everything starts inert; only the palette you open becomes editable.
  document.querySelectorAll('.pal').forEach(function (s) { setInteractive(s, false); });
  document.querySelectorAll('.pal').forEach(function (sec) {
    var pen = sec.querySelector('.paledit');
    if (!pen) { return; }
    pen.addEventListener('click', function () {
      if (sec.classList.contains('editing')) { closeEditing(); } else { openEditing(sec); }
    });
  });
  document.getElementById('exitEditBtn').addEventListener('click', closeEditing);
  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape') { closeEditing(); }
  });
  // Clicking anywhere outside the open palette closes it — which also drops focus, and
  // that is what dismisses the colour picker. Bubble phase, so a click on another
  // palette's pencil has already opened that one by the time this runs and is left be.
  document.addEventListener('click', function (ev) {
    var open = document.querySelector('.pal.editing');
    if (open && !open.contains(ev.target)) { closeEditing(); }
  });
</script>
</body>
</html>
