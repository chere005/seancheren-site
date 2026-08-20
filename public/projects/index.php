<?php
// Locate the shared lib/ — local dev (../../lib) or NFSN (/home/protected/lib).
$__libDir = null;
foreach ([__DIR__ . '/../../lib', '/home/protected/lib'] as $__c) {
    if (is_file($__c . '/site.php')) { $__libDir = $__c; break; }
}
require_once $__libDir . '/site.php';

ob_start();
?>
<h1>Projects</h1>

<h2>Public</h2>
<h3>Vibe Coding Apps</h3>
<p>Well, sort of. There's links to the apps I'm vibe coding (it's probably pure slop), but this code will end up on my public github once I wait for a human (me) to review that I'm not posting API keys or passwords.</p>

<?php
// The two icons the project links wear: the GitHub mark, and a T badge for the theme
// picker. Inline SVG in currentColor so they take the link's accent.
$gitIcon = '<svg class="giticon" viewBox="0 0 16 16" width="20" height="20" aria-hidden="true"><path fill="currentColor" d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27s1.36.09 2 .27c1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.01 8.01 0 0 0 16 8c0-4.42-3.58-8-8-8z"/></svg>';
$tIcon = '<svg class="giticon" viewBox="0 0 16 16" width="20" height="20" aria-hidden="true"><rect x="1" y="1" width="14" height="14" rx="3.5" fill="none" stroke="currentColor" stroke-width="1.6"/><path fill="currentColor" d="M4.4 4.4h7.2v1.9H8.9v5.9H7.1V6.3H4.4z"/></svg>';
?>

<h4>Theme Picker</h4>
<p>Apparently getting a functioning user experience often means choosing the right colors (for text readability, disambiguation, etc).. I was trying to visualize palettes, and my good friend claudio went ahead and made this interactive page, which I thought was useful.. Now it's used in a few of my projects, so I thought I'd list it here.</p>
<p class="plinks">
  <a class="gitlink" href="/themepicker/" title="Open the theme picker" aria-label="Open the theme picker"><?= $tIcon ?></a>
  <a class="gitlink" href="https://github.com/chere005/CalMind/tree/main/public/themepicker" title="Theme picker source on GitHub" aria-label="Theme picker source on GitHub"><?= $gitIcon ?></a>
</p>

<h4>CalMind</h4>
<p>Sometimes when I forget to check off a reminder from yesterday, I wish it would still show up on my calendar today.. I also wanted my notes more integrated with my calendar and reminders, as well as a habit tracker that doesn't limit me to 5 days (for the low cost of several dollars per month to upgrade?!).. So my good friend claudio built a web app that is working pretty nicely for me. I've moved my entire calendar and reminders system over, and I am quite happy. It's more important to me that it's custom than that it's generally usable, but since everything is open source, including the start of an iOS and Android app, anybody is free to modify or distribute it however they please.</p>
<p class="plinks">
  <a class="gitlink" href="https://seancheren.com/calmind/" title="Open CalMind" aria-label="Open CalMind"><img class="giticon appicon" src="/calmind/reminders/icon-180.png" alt="" width="20" height="20"></a>
  <a class="gitlink" href="https://github.com/chere005/CalMind" title="CalMind on GitHub" aria-label="CalMind on GitHub"><?= $gitIcon ?></a>
</p>

<h4>AcctMind</h4>
<p>I have never been a fan of various budgeting apps.. I was nearly happy with one I won't name, but I can't simply set the available amount to a value, I have to do some silly math which ends up being way more keystrokes than I'd like. It's rather amusing how simple it should be to build an app with Claudio that completely replaces this $120/yr product for myself. The UX will be minimal, only including features I actually use personally.</p>

<style>
  .plinks { display: flex; align-items: center; gap: 0.9rem; }
  .gitlink { display: inline-flex; align-items: center; justify-content: center; gap: 0.45rem; }
  .giticon { flex: none; display: block; }
  .appicon { border-radius: 5px; }
</style>

<h2>Private</h2>
<h3>Work</h3>
<p>I spend most of my time working remotely at Wolfram Research where I get to work on really cool projects with really cool people.</p>
<h3>Music</h3>
<p>I probably wish this were my focused project, and something I did more publicly, but for now I'm just trying to practice some drums, studio work, composition, etc. Maybe some day I'll finish a real project.</p>
<p>I recently got my hands on an unripe flying microtonal banana.. Learning to play eastern scales in primarily western music has been a beautiful mindfuck.</p>
<h3>Games</h3>
<p>I hope to play Tears of the Kingdom soon. Haven't played MTG much since Phyrexia, but I hope to get back into that and venture into commander some day.. I'm also hoping to spend a bit more time doing part of the making of games; all of the art and storytelling is authored by my best friend and partner. We've also been working through catching the first 150 pokemon in Red and Blue.</p>
<h3>Languages</h3>
<p>Well.. sort of. I'm really interested in writing systems, and have spent more time in the last couple years studying alphabets and glyphs from multiple languages. I have a basic understanding of the modern Latin script (lol), Cyrillic, Farsi, Hiragana and Katakana. I should probably pick a language and become more immersed in its vocabulary.</p>
<?php
site_page('projects', 'Projects', ob_get_clean());
