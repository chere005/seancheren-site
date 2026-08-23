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
$__test   = preg_match('#/test(/|$)#', __DIR__) === 1
         || strncmp($_SERVER['REQUEST_URI'] ?? '', '/test/', 6) === 0
         || strncmp($__host, 'test.', 5) === 0;
$__libDir = null;
$__cands  = $__test
    ? [__DIR__ . '/../../../lib-test', '/home/protected/lib-test']
    : [__DIR__ . '/../../lib',      '/home/protected/lib'];
foreach ($__cands as $__c) {
    if (is_file($__c . '/site.php')) { $__libDir = $__c; break; }
}
require_once $__libDir . '/site.php';


ob_start();
?>
<h1>About</h1>
<p>I'm Sean Cheren, I work on software projects and play music and games in my spare time..</p>
<p>Some current favorites that come to mind:</p>

<h3>Music</h3>
<div class="lists-col">
<ul>
  <li>King Gizzard and the Lizard Wizard</li>
  <li>Mars Volta</li>
  <li>Dream Theater</li>
  <li>Led Zeppelin</li>
  <li>Amon Amarth</li>
  <li>Umphrey's McGee</li>
  <li>Pink Floyd</li>
  <li>きかがくもよ</li>
</ul>
</div>

<h3>Games</h3>
<div class="lists-col">
<ul>
  <li>Outer Wilds</li>
  <li>Dark Souls (I)</li>
  <li>Sekiro</li>
  <li>Demon's Souls</li>
  <li>Morrowind</li>
  <li>Oblivion</li>
  <li>The Legend of Zelda</li>
  <li>Breath of the Wild</li>
  <li>Metal Gear Solid: Tactical Espionage</li>
  <li>Metal Gear Solid: Sons of Liberty</li>
  <li>Returnal</li>
  <li>Inscryption</li>
  <li>Slay the Spire</li>
  <li>Star Realms</li>
</ul>
</div>
<?php
site_page('about', 'About', ob_get_clean());
