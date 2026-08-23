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
    ? [__DIR__ . '/../../../lib-dev', '/home/protected/lib-dev']
    : ($__test
        ? [__DIR__ . '/../../../lib-test', '/home/protected/lib-test']
        : [__DIR__ . '/../../lib',         '/home/protected/lib']);
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
