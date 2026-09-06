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
    ? [__DIR__ . '/../../lib-' . $__inst, '/home/protected/lib-' . $__inst]
    : [__DIR__ . '/../lib',      '/home/protected/lib'];
foreach ($__cands as $__c) {
    if (is_file($__c . '/site.php')) { $__libDir = $__c; break; }
}
require_once $__libDir . '/site.php';


ob_start();
?>
<h1>Hello!</h1>
<p>Thanks to my good friend claudio (I really just like the nickname, I'm still rather agnostic to models and more concerned with agent harnesses, but I digress), apparently spinning up web applications and apps is incredibly trivial to vibe code slop that somehow seems to work in testing!</p>
<p>Check out the <a href="/projects/">projects page</a> to see what I felt like posting that I'm clawing at time to work on..</p>
<p>And.. if you poke around, you might find some demo projects sitting at places like my url/chat.</p>
<p>Try not to blast through my (extremely low) server budget :)</p>
<div class="sig">
  <div>&mdash;S</div>
  <div class="date">July 26, 2026</div>
</div>
<?php
site_page('', 'Home', ob_get_clean());
