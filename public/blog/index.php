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


ob_start();
?>
<h1>Blog</h1>

<!-- One hardcoded entry. There is no store, no admin and no feed behind this yet:
     a post is a block of HTML in this file, newest first, and that is the whole
     mechanism until there is enough here to be worth a data layer. -->
<article class="post">
  <h2>Hello, World!</h2>
  <p class="post-date">21 September 2026</p>
  <p>Hello, World!</p>
</article>

<style>
  /* The date sits between the heading and the body, dimmer than either, so the
     entry reads as one block rather than three. */
  .post + .post { margin-top: 2.5rem; border-top: 1px solid var(--surface-2); padding-top: 1.5rem; }
  .post h2 { margin-top: 0; }
  .post-date { margin: 0.2rem 0 0.75rem; color: var(--muted); font-size: 0.85rem; }
</style>
<?php
site_page('blog', 'Blog', ob_get_clean());
