<?php
// A page served under /test/ (the sandbox mirror) loads lib-test/ instead of lib/, and one
// served under /dev/ (a second, fixed sandbox slot) loads lib-dev/ — each mirror
// isolated in code, config and data. The marketing pages hold no data, so they used to
// keep a plain lib-only preamble; they carry this one since 2026-08-22, when site_nav()
// started building its links through suite_base(). Without it a sandbox page could not
// know its own base — and, worse, could not find a lib at all from one directory down.
$__test   = strpos(__DIR__, '/test/') !== false
         || strncmp($_SERVER['REQUEST_URI'] ?? '', '/test/', 6) === 0;
$__dev    = strpos(__DIR__, '/dev/') !== false
         || strncmp($_SERVER['REQUEST_URI'] ?? '', '/dev/', 5) === 0;
$__libDir = null;
$__cands  = $__dev
    ? [__DIR__ . '/../../lib-dev', '/home/protected/lib-dev']
    : ($__test
        ? [__DIR__ . '/../../lib-test', '/home/protected/lib-test']
        : [__DIR__ . '/../lib',         '/home/protected/lib']);
foreach ($__cands as $__c) {
    if (is_file($__c . '/site.php')) { $__libDir = $__c; break; }
}
require_once $__libDir . '/site.php';


ob_start();
?>
<h1>Hello!</h1>
<p>Thanks to my good friend claudio (I really just like the nickname, I'm still rather agnostic to models and more concerned with agent harnesses, but I digress), apparently spinning up web applications and apps is incredibly trivial to vibe code slop that somehow seems to work in testing!</p>
<p>Check out the <a href="/projects/">projects page</a> to see what I felt like posting that I'm clawing at time to work on..</p>
<p>And.. if you poke around, you might find some demo projects sitting at places like my url/chat or url/reminders.</p>
<p>Try not to blast through my (extremely low) server budget :)</p>
<div class="sig">
  <div>&mdash;S</div>
  <div class="date">July 26, 2026</div>
</div>
<?php
site_page('', 'Home', ob_get_clean());
