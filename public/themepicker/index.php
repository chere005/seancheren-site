<?php
// A page served under /test/ (the sandbox mirror) loads lib-test/ instead of lib/,
// isolated in code, config and data. The marketing pages hold no data, so they used
// to keep a plain lib-only preamble; they carry this one since 2026-08-22, when a
// sandbox page turned out to be unable to find a lib at all from one directory down.
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
    if (is_file($__c . '/site.php')) { $__libDir = $__c; break; }
}
require_once $__libDir . '/site.php';


/** HTML-escape. */
function e(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

// Picking a theme is a plain POST→redirect: it sets the sitetheme cookie the public
// pages read (lib/site.php) and nothing else — no account, no app prefs, so the apps
// (CalMind, Chat, the bookshelf) never see it. An unknown name is simply ignored.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'settheme') {
    $t = (string) ($_POST['theme'] ?? '');
    if (isset(THEMES[$t])) {
        // Scope the cookie to this instance, like the session cookies: a theme picked
        // on /test/ must not re-dress production's pages, or the other way.
        $path = '/';
        foreach (['/test/'] as $b) {
            if (strncmp($_SERVER['REQUEST_URI'] ?? '/', $b, strlen($b)) === 0) { $path = rtrim($b, '/') . '/'; }
        }
        setcookie('sitetheme', $t, [
            'expires' => time() + 31536000, 'path' => $path,
            'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Lax',
        ]);
    }
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'), true, 302);
    exit;
}

$current = site_theme();

ob_start();
?>
<h1>Themes</h1>

<style>
  .tp-card { border: 1px solid var(--line); border-radius: 12px; margin: 1rem 0; overflow: hidden; }
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
  .tp-row { display: flex; align-items: center; gap: 0.75rem; padding: 0.6rem 1rem; background: var(--surface); border-top: 1px solid var(--line); }
  .tp-row .name { font-weight: 600; color: var(--text); margin-right: auto; }
  .tp-row form { margin: 0; }
  .tp-use {
    display: inline-flex; align-items: center; justify-content: center;
    border: 1px solid var(--line); background: var(--surface-2); color: var(--text);
    border-radius: 999px; padding: 0.3rem 0.9rem; font-size: 0.9rem; cursor: pointer; font-family: inherit;
  }
  .tp-cur {
    display: inline-flex; align-items: center; justify-content: center;
    background: var(--accent); color: var(--accent-ink); font-weight: 700;
    border-radius: 999px; padding: 0.3rem 0.9rem; font-size: 0.9rem;
  }
</style>

<?php foreach (THEMES as $key => $row):
    $t = theme_vars($key);
    $vars = '';
    foreach ($t['vars'] as $k => $v) { $vars .= "$k: $v; "; }
?>
<div class="tp-card">
  <div class="tp-prev" style="<?= e($vars) ?>" aria-hidden="true">
    <div class="pnav"><span class="on">Home</span><span>Projects</span><span>About</span></div>
    <div class="ph1">Sean Cheren</div>
    <div class="ph2">Public</div>
    <div class="pp">Body text looks like this, with <u>a link</u> in the accent.</div>
    <div class="pmut">and quieter text down here</div>
  </div>
  <div class="tp-row">
    <span class="name"><?= e($row[0]) ?></span>
    <?php if ($key === $current): ?>
      <span class="tp-cur">Current</span>
    <?php else: ?>
      <form method="post">
        <input type="hidden" name="action" value="settheme">
        <input type="hidden" name="theme" value="<?= e($key) ?>">
        <button class="tp-use" type="submit">Use</button>
      </form>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>
<?php
site_page('themepicker', 'Themes', ob_get_clean());
