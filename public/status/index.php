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
$__test   = preg_match('#/test(/|$)#', __DIR__) === 1
         || strncmp($_SERVER['REQUEST_URI'] ?? '', '/test/', 6) === 0
         || strncmp($__host, 'test.', 5) === 0;
$__libDir = null;
$__cands  = $__test
    ? [__DIR__ . '/../../../lib-test', '/home/protected/lib-test']
    : [__DIR__ . '/../../lib',      '/home/protected/lib'];
foreach ($__cands as $__c) {
    if (is_file($__c . '/auth.php')) { $__libDir = $__c; break; }
}
require_once $__libDir . '/auth.php';
require_login('Status');
// Standalone status page — sean only. The site login session is shared, so we
// don't destroy it; we just refuse others and offer a log out, same pattern
// as Aki's Bookshelf.
if (current_user() !== 'sean') {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>Status</title>'
       . '<body style="font-family:system-ui,sans-serif;background:#111;color:#eee;display:flex;min-height:100vh;align-items:center;justify-content:center;text-align:center;padding:2rem;margin:0">'
       . '<div><p style="font-size:1.15rem;margin:0 0 1rem">This page is sean\'s.</p>'
       . '<p style="margin:0"><a href="?logout" style="color:#5fb6ac">Log out</a> and sign in as sean.</p></div></body>';
    exit;
}

function e(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES); }

/**
 * EVERY TIME ON THIS PAGE, in 12-hour Central — Sean, 2026-08-23. Set here
 * rather than trusted from the environment: the server keeps UTC, so a bare
 * date() call reads five or six hours ahead depending on the month, and the
 * one page whose whole job is "when did this happen" cannot afford that.
 *
 * Takes an epoch OR a string somebody already formatted (the history file
 * carries strings written by report-status.sh, some of them from before this
 * existed). A string that cannot be parsed comes back untouched — showing the
 * original beats showing 1 Jan 1970.
 */
date_default_timezone_set('America/Chicago');
function ct($when, string $fmt = 'g:i:s a'): string
{
    if ($when === null || $when === '') { return ''; }
    $ts = is_numeric($when) ? (int) $when : strtotime((string) $when);
    if ($ts === false || $ts <= 0) { return (string) $when; }
    return date($fmt, $ts);
}
/** A date and a time, for something that may not be today. */
function ctFull($when): string { return ct($when, 'M j, g:i a'); }

// ------------------------------------------------------------- dtp/tdtp history
// Written by CoreMind's bin/dtp.sh (report_status()) at the start and end of
// every dtp/tdtp run, pushed here over the same SSH deploy credentials used
// for regular deploys. Kept OUTSIDE public/ so it's never web-exposed on its
// own — this page is the only reader. Last 5 runs only; dtp.sh trims the rest.
// Absolute on the server, with a local fallback beside the repo's own data/
// dir — without it this page could never be looked at anywhere but production,
// which is the one place a rendering bug is expensive to find.
$historyPath = '/home/protected/status/history.json';
if (!is_file($historyPath)) {
    $local = __DIR__ . '/../../data/status-history.json';
    if (is_file($local)) { $historyPath = $local; }
}
$history = [];
if (is_file($historyPath)) {
    $raw = json_decode((string) file_get_contents($historyPath), true);
    if (is_array($raw)) { $history = $raw; }
}
// Newest first for display.
usort($history, fn($a, $b) => strcmp($b['started_at'] ?? '', $a['started_at'] ?? ''));
$latest = $history[0] ?? null;
$isRunning = $latest && ($latest['status'] ?? '') === 'running';

/**
 * WHICH REPOS ARE MID-RELEASE. Sean, 2026-08-23: the purple "should be shown
 * for every single entry in the current page when a tdtp starts until it turns
 * back to green or orange etc" — and "i don't want 'A release is going out
 * right now', i want that shown for every item in the table".
 *
 * A sentence in the header is a thing you read once; a table that goes purple
 * is a thing you watch. So the running state lives in the cells.
 *
 * Read from the RUN'S OWN PLAN rather than painting everything: dtp.sh records
 * the resolved target ("core CalMind ChefMind AcctMind"), so a run that is not
 * touching MyCalMind does not claim to be. During `tdtp all` that is every
 * repo, which is the case this was asked for.
 */
function running_repos(?array $latest): array
{
    if (!$latest || ($latest['status'] ?? '') !== 'running') { return []; }
    $out = [];
    foreach (preg_split('/\s+/', trim((string) ($latest['target'] ?? ''))) as $t) {
        if ($t !== '') { $out[$t === 'core' ? 'CoreMind' : $t] = true; }
    }
    return $out;
}

// severity: 0 = live/good, 1 = done/deliberate, 2 = partial/small issue, 3 = crit/needs attention
function severity_chip_class(int $sev): string
{
    return match ($sev) {
        0 => 'live',
        1 => 'done',
        2 => 'partial',
        default => 'crit',
    };
}

require_once $__libDir . '/statuscheck.php';

// ------------------------------------------------------------- hits
// The hit log is written by lib/hitlog.php, which every page on this host
// inherits — see that file's header for why it is separate from usage.log.
require_once $__libDir . '/hitlog.php';
$hitWindows = ['the last hour' => 3600, 'the last 12 hours' => 12 * 3600, 'the last 3 days' => 3 * 86400];
$hits = hit_counts($hitWindows);

// ------------------------------------------------------------- the headline
/**
 * WHAT THE PAGE IS FOR, SAID IN ONE LINE.
 *
 * Sean, 2026-08-23, of the old headline ("Five repos, five platforms, two ways
 * of syncing"): "is fluff, you can do better than that". He is right — it
 * described the architecture, which does not change, on a page whose whole job
 * is to say what changed. Somebody opening this wants one answer: is anything
 * wrong, and is it wrong in a way that needs them.
 *
 * So the headline is COMPUTED from the same two sources the tables below
 * render — the live checks and the platform matrix — and it can only ever say
 * "everything is fine" when both of those agree that it is. A deliberate
 * choice (severity 1: MyCalMind staying off the phone) is not a problem and is
 * not counted as one; a known small issue (2) is mentioned but does not raise
 * the alarm; anything down, any broken sign-in, and any severity 3 does.
 *
 * The one rule this must never break: it may not read better than the truth.
 * A green headline over a red table is worse than no headline.
 */
function status_headline(array $repos, array $endpoints, array $results, bool $isRunning): array
{
    $down = [];      // endpoints that did not answer
    $broken = [];    // endpoints whose sign-in failed
    foreach ($endpoints as $group => $domains) {
        foreach ($domains as $domain => $list) {
            foreach ($list as $ep) {
                $r = $results[$group][$ep['url']] ?? null;
                if (!$r || empty($r['ok'])) { $down[] = $ep['label'] . ' on ' . $domain; continue; }
                if (($r['login']['state'] ?? '') === 'failed') { $broken[] = $ep['label'] . ' on ' . $domain; }
            }
        }
    }
    $rough = [];     // recorded severity 2 — a known small issue
    $bad = [];       // recorded severity 3 — wants attention
    foreach ($repos as $repo) {
        foreach ($repo['plat'] as $plat => $cell) {
            if (($cell[0] ?? null) === 2) { $rough[] = $repo['name'] . ' on ' . $plat; }
            if (($cell[0] ?? null) === 3) { $bad[] = $repo['name'] . ' on ' . $plat; }
        }
    }
    $urgent = array_merge($down, $broken, $bad);
    $n = count($urgent);

    if ($n > 0) {
        // NAME IT when there is one. "1 thing needs you" makes a person hunt
        // for a fact this line already had.
        $title = $n === 1 ? ucfirst($urgent[0]) . ' needs you' : $n . ' things need you';
        $bits = [];
        if ($down)   { $bits[] = count($down) . ' not answering'; }
        if ($broken) { $bits[] = count($broken) . ' up but nobody can sign in'; }
        if ($bad)    { $bits[] = count($bad) . ' flagged in the matrix'; }
        return [$title, implode(' &middot; ', $bits) . '.', 'crit'];
    }
    if ($rough) {
        $title = count($rough) === 1 ? 'Up, with one rough edge' : 'Up, with ' . count($rough) . ' rough edges';
        return [$title, ucfirst($rough[0]) . (count($rough) > 1 ? ', and ' . (count($rough) - 1) . ' more' : ''), 'partial'];
    }
    return ['Everything is up', '', 'live'];
}
[$hlTitle, $hlDek, $hlKind] = status_headline($repos, $endpoints, $results, (bool) $isRunning);
$RUNNING = running_repos($latest);

/** A cell's chip class — purple for the whole of a repo that is mid-release. */
function cell_chip(?int $sev, string $repo, array $running): string
{
    if (isset($running[$repo])) { return 'running'; }
    return $sev === null ? 'none' : severity_chip_class($sev);
}

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Mind-Suite Status</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;650&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">

<style>
  :root {
    --bg: #f5f4f1;
    --surface: #ffffff;
    --surface-alt: #ece9e3;
    --line: #ddd9d0;
    --ink: #1c1b18;
    --ink-soft: #6b675e;
    --ink-faint: #9a968b;

    --accent: #2f6e68;
    --accent-ink: #ffffff;
    --accent-soft: #dcedea;
    --accent-soft-ink: #1d4a45;

    --live: #1f8a4c;
    --live-bg: #e4f5ea;
    --done: #2c6fd1;
    --done-bg: #e6eefb;
    --partial: #a86a15;
    --partial-bg: #fbeeda;
    --crit: #c22f2f;
    --crit-bg: #fbe6e6;
    --running: #7a3fc2;
    --running-bg: #ede3fa;
    --none: #9c978d;
    --none-bg: #f0eee9;

    --font-sans: "IBM Plex Sans", "Segoe UI", system-ui, sans-serif;
    --font-mono: "IBM Plex Mono", ui-monospace, "SF Mono", Menlo, monospace;
  }

  @media (prefers-color-scheme: dark) {
    :root:not([data-theme="light"]) {
      --bg: #15181a;
      --surface: #1d2124;
      --surface-alt: #23282b;
      --line: #32383c;
      --ink: #ece9e2;
      --ink-soft: #a9a59a;
      --ink-faint: #726e64;

      --accent: #5fb6ac;
      --accent-ink: #0d1f1d;
      --accent-soft: #1e3634;
      --accent-soft-ink: #b7ded8;

      --live: #4fd183;
      --live-bg: #16311f;
      --done: #77aef2;
      --done-bg: #17263a;
      --partial: #e8a94b;
      --partial-bg: #3a2c15;
      --crit: #f0605c;
      --crit-bg: #3a1a1a;
      --running: #b58ae8;
      --running-bg: #2c2138;
      --none: #6f6b62;
      --none-bg: #23262a;
    }
  }

  * { box-sizing: border-box; }

  body {
    margin: 0;
    background: var(--bg);
    color: var(--ink);
    font-family: var(--font-sans);
    -webkit-font-smoothing: antialiased;
  }

  .page {
    max-width: 1640px;
    margin: 0 auto;
    /* 20px of top padding, not 56 — the eyebrow row IS the top of the page now
       and there is nothing above it to make room for. */
    padding: 20px 32px 72px;
    display: flex;
    flex-direction: column;
    gap: 32px;
  }

  header { display: flex; flex-direction: column; gap: 14px; }

  /* The eyebrow and Log out share one line. They were two stacked rows — a
     right-aligned bar of its own above a left-aligned eyebrow — which left a
     band of empty page between them and put the only control on the page as
     far from everything else as it could get. */
  .eyebrow-row {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 16px;
  }
  .eyebrow-row a { color: var(--ink-faint); text-decoration: none; font-size: 0.8rem; }
  .eyebrow-row { }
  .live-stamp { margin-left: auto; margin-right: 14px; font-family: var(--font-mono); font-size: 0.7rem; color: var(--ink-faint); }
  .eyebrow-row a:hover { color: var(--ink-soft); text-decoration: underline; }

  .eyebrow {
    font-family: var(--font-mono);
    font-size: 0.78rem;
    font-weight: 500;
    letter-spacing: 0.14em;
    text-transform: uppercase;
    color: var(--accent);
  }

  /* The headline wears its own state — a dot in the same colours the chips
     use, so the page's one-line answer and its tables cannot disagree. */
  h1 .hl-dot {
    display: inline-block; width: 11px; height: 11px; border-radius: 50%;
    margin-right: 12px; vertical-align: 0.08em; flex: none;
  }
  h1.hl-live .hl-dot    { background: var(--live); }
  h1.hl-partial .hl-dot { background: var(--partial); }
  h1.hl-crit .hl-dot    { background: var(--crit); }
  h1.hl-running .hl-dot { background: var(--running); animation: pulse 1.4s ease-in-out infinite; }
  h1.hl-crit { color: var(--crit); }

  h1 {
    margin: 0;
    font-size: clamp(1.9rem, 3vw, 2.5rem);
    font-weight: 650;
    letter-spacing: -0.01em;
    text-wrap: balance;
  }

  .triggered { margin-top: 6px; font-size: 0.95rem; color: var(--ink-soft); }
  .triggered strong { color: var(--ink); font-weight: 600; }

  .dek { margin: 0; max-width: 62ch; font-size: 1.02rem; line-height: 1.55; color: var(--ink-soft); }
  .dek strong { color: var(--ink); font-weight: 600; }

  /* ---------- tabs ---------- */

  .tabs {
    display: flex;
    gap: 4px;
    border-bottom: 1px solid var(--line);
  }
  .tab-btn {
    font-family: var(--font-sans);
    font-size: 0.88rem;
    font-weight: 600;
    color: var(--ink-soft);
    background: none;
    border: none;
    border-bottom: 2px solid transparent;
    padding: 10px 4px;
    margin-bottom: -1px;
    cursor: pointer;
  }
  .tab-btn:hover { color: var(--ink); }
  .tab-btn.active { color: var(--accent); border-bottom-color: var(--accent); }
  .tab-panel { display: none; flex-direction: column; gap: 32px; }
  .tab-panel.active { display: flex; }

  /* ---------- KPI strip ---------- */

  .kpis {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 1px;
    background: var(--line);
    border: 1px solid var(--line);
    border-radius: 10px;
    overflow: hidden;
  }
  .kpi { background: var(--surface); padding: 16px 18px; display: flex; flex-direction: column; gap: 4px; }
  .kpi .n { font-family: var(--font-mono); font-variant-numeric: tabular-nums; font-size: 1.7rem; font-weight: 600; letter-spacing: -0.01em; }
  .kpi .l { font-size: 0.78rem; color: var(--ink-soft); line-height: 1.35; }

  /* ---------- table ---------- */

  .table-card { background: var(--surface); border: 1px solid var(--line); border-radius: 12px; overflow: hidden; }
  /* Sean, 2026-08-23: "there's no padding on the left or right of the page".
     The first attempt at this made it WORSE — a max(12px, …) override replaced
     the 32px the desktop rule already had, so every width got 12. The floor
     belongs in the media query, where the small screen is; here it only ever
     widens. */
  /* Side padding, third attempt and this time measured. A fixed 32px reads as
     nothing on a wide window, and the first fix made it WORSE by replacing the
     32 with a 12. clamp scales with the viewport: 16px on a phone, 56px on a
     desktop, and never less than the safe-area inset on a notched screen. */
  .page {
    padding-left: max(clamp(16px, 4vw, 56px), env(safe-area-inset-left));
    padding-right: max(clamp(16px, 4vw, 56px), env(safe-area-inset-right));
  }
  .table-scroll { overflow-x: auto; }

  table { border-collapse: collapse; table-layout: fixed; width: 100%; min-width: 1180px; }

  col.repo { width: 170px; }
  col.web  { width: 215px; }
  /* Was 420px, when this column held a paragraph each. It holds one line now. */
  col.sync { width: 300px; }
  col.plat { width: 150px; }

  thead th {
    position: sticky; top: 0; background: var(--surface-alt); text-align: left;
    font-family: var(--font-mono); font-size: 0.72rem; font-weight: 500;
    letter-spacing: 0.08em; text-transform: uppercase; color: var(--ink-soft);
    padding: 12px 14px; border-bottom: 1px solid var(--line); white-space: nowrap;
  }
  tbody td { padding: 14px; border-bottom: 1px solid var(--line); vertical-align: top; font-size: 0.87rem; line-height: 1.5; }
  tbody tr:last-child td { border-bottom: none; }
  tbody tr:hover td { background: var(--surface-alt); }

  .repo-name { font-weight: 650; font-size: 0.95rem; letter-spacing: -0.005em; }
  .repo-tag { display: block; margin-top: 2px; font-family: var(--font-mono); font-size: 0.72rem; color: var(--ink-faint); }
  .running-tag { color: var(--running); animation: pulse 1.4s ease-in-out infinite; }

  .prose { color: var(--ink-soft); }
  .prose strong { color: var(--ink); font-weight: 600; }
  .prose code { font-family: var(--font-mono); font-size: 0.82em; background: var(--surface-alt); padding: 0.1em 0.35em; border-radius: 4px; color: var(--ink); }

  /* status chips */

  .chip {
    display: inline-flex; align-items: center; gap: 6px; padding: 4px 9px 4px 7px;
    border-radius: 999px; font-size: 0.76rem; font-weight: 500; white-space: normal;
    line-height: 1.4; max-width: 100%;
  }
  .chip::before { content: ""; width: 7px; height: 7px; border-radius: 50%; flex: none; }

  .chip.live { background: var(--live-bg); color: var(--live); }
  .chip.live::before { background: var(--live); }
  .chip.done { background: var(--done-bg); color: var(--done); }
  .chip.done::before { background: transparent; border: 1.5px solid var(--done); }
  .chip.partial { background: var(--partial-bg); color: var(--partial); }
  .chip.partial::before { background: transparent; border: 1.5px solid var(--partial); }
  .chip.crit { background: var(--crit-bg); color: var(--crit); }
  .chip.crit::before { background: var(--crit); }
  .chip.running { background: var(--running-bg); color: var(--running); }
  .chip.running::before { background: var(--running); animation: pulse 1.4s ease-in-out infinite; }
  @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.35; } }
  .chip.none { background: var(--none-bg); color: var(--none); }
  .chip.none::before { background: var(--none); }
  .chip.legacy { background: var(--accent-soft); color: var(--accent-soft-ink); }
  .chip.legacy::before { background: transparent; border: 1.5px solid var(--accent-soft-ink); border-radius: 2px; }

  tbody tr.outside td { background: var(--surface-alt); }
  tbody tr.outside:hover td { background: var(--surface-alt); }
  tbody tr.outside .repo-name { color: var(--ink-soft); }

  .cell-note { display: block; margin-top: 5px; font-size: 0.74rem; color: var(--ink-faint); line-height: 1.45; }
  .cell-note br { content: ""; display: block; margin-top: 1px; }

  /* The legend used to reuse .chip with an &nbsp; inside, which made every
     swatch a different width: a chip is a pill sized by its own text, and
     seven pills holding one space each came out seven different sizes. The
     swatch is its own fixed-size element now — same 10px marker the chips
     draw, nothing sizing it but the CSS. */
  .domain-head {
    font-family: var(--font-mono); font-size: 0.74rem; font-weight: 500;
    letter-spacing: 0.06em; color: var(--ink-soft);
    margin: 18px 0 2px; padding-bottom: 6px; border-bottom: 1px solid var(--line);
  }
  .endpoint-group > .domain-head:first-of-type { margin-top: 8px; }
  .domain-note { color: var(--ink-faint); letter-spacing: 0.04em; text-transform: uppercase; font-size: 0.66rem; }

  .legend { display: flex; flex-wrap: wrap; gap: 10px 22px; padding: 16px 18px; background: var(--surface-alt); border-top: 1px solid var(--line); font-size: 0.8rem; color: var(--ink-soft); }
  .legend-item { display: flex; align-items: center; gap: 8px; }
  .swatch { width: 10px; height: 10px; border-radius: 50%; flex: none; box-sizing: border-box; }
  .swatch.live    { background: var(--live); }
  .swatch.done    { background: transparent; border: 1.5px solid var(--done); }
  .swatch.partial { background: transparent; border: 1.5px solid var(--partial); }
  .swatch.crit    { background: var(--crit); }
  /* No pulse in the LEGEND. A key is a reference, not a live indicator, and a
     flashing swatch beside static text reads as something needing attention. */
  .swatch.running { background: var(--running); }
  .swatch.none    { background: var(--none); }
  .swatch.legacy  { background: transparent; border: 1.5px solid var(--accent-soft-ink); border-radius: 2px; }

  footer { display: flex; flex-direction: column; gap: 6px; font-size: 0.84rem; color: var(--ink-soft); border-top: 1px solid var(--line); padding-top: 20px; }
  footer strong { color: var(--ink); font-weight: 600; }

  /* ---------- history tab ---------- */

  .run-buttons { display: flex; flex-wrap: wrap; gap: 10px; }
  .run-btn {
    font-family: var(--font-mono); font-size: 0.78rem; text-align: left;
    background: var(--surface); border: 1px solid var(--line); border-radius: 10px;
    padding: 10px 14px; cursor: pointer; color: var(--ink); display: flex; flex-direction: column; gap: 4px;
    min-width: 160px;
  }
  .run-btn:hover { border-color: var(--accent); }
  .run-btn.selected { border-color: var(--accent); background: var(--accent-soft); }
  .run-btn .t { font-weight: 600; }
  .run-btn .k { color: var(--ink-faint); font-size: 0.7rem; }

  .graph-card { background: var(--surface); border: 1px solid var(--line); border-radius: 12px; padding: 24px; }
  .graph-empty { color: var(--ink-faint); font-size: 0.88rem; padding: 40px 0; text-align: center; }
  /* An empty state is one line, not a 160px card pretending something is
     there. */
  .none-yet { margin: 0; color: var(--ink-faint); font-size: 0.85rem; }


  /* ---------- live status tab ---------- */

  .endpoint-group { background: var(--surface); border: 1px solid var(--line); border-radius: 12px; overflow: hidden; }
  .endpoint-group h2 {
    margin: 0; padding: 14px 18px; font-size: 0.95rem; font-weight: 650;
    background: var(--surface-alt); border-bottom: 1px solid var(--line);
  }
  /* Two shapes, because the two subsections answer different questions: a
     gated row has a sign-in and a scope, a public one has neither and used to
     carry two columns of "n/a" to prove it. */
  .endpoint-row {
    display: grid; grid-template-columns: 1.6fr 80px 108px 116px 150px; gap: 14px;
    padding: 14px 18px; border-bottom: 1px solid var(--line); align-items: start; font-size: 0.85rem;
  }
  /* The public rows keep the SAME tracks and simply leave two of them empty,
     so Endpoint, Status and Response line up down the whole domain instead of
     the two subsections looking like two unrelated tables. */
  .sec-open .endpoint-row > [data-col="2"],
  .sec-open .endpoint-ms { grid-column: 4; }
  .endpoint-row:last-child { border-bottom: none; }
  .sec + .sec { margin-top: 4px; }
  .sec-head {
    font-family: var(--font-mono); font-size: 0.66rem; letter-spacing: 0.09em;
    text-transform: uppercase; color: var(--ink-faint);
    padding: 12px 18px 0;
  }
  .endpoint-head {
    font-family: var(--font-mono); font-size: 0.68rem; letter-spacing: 0.08em;
    text-transform: uppercase; color: var(--ink-faint); padding-top: 8px; padding-bottom: 8px;
    background: var(--surface-alt);
  }
  .endpoint-head [data-col]:hover { color: var(--ink); }
  .endpoint-head [data-col].sorted { color: var(--accent); }
  .endpoint-head [data-col]::after { content: " \2195"; opacity: 0.35; }

  .recheck {
    margin-left: 10px; font-size: 0.8rem; color: var(--accent); text-decoration: none;
    border: 1px solid var(--line); border-radius: 999px; padding: 3px 10px;
  }
  .recheck:hover { border-color: var(--accent); }

  .scope-chip {
    display: inline-block; font-family: var(--font-mono); font-size: 0.7rem; line-height: 1.35;
    color: var(--ink-soft); background: var(--surface-alt);
    border: 1px solid var(--line); border-radius: 6px; padding: 3px 7px;
  }

  /* ---------- group + graph heads ---------- */
  .group-head { padding: 16px 18px 12px; border-bottom: 1px solid var(--line); background: var(--surface-alt); }
  .group-head h2 { margin: 0; font-size: 1rem; font-weight: 650; }
  .group-head p { margin: 4px 0 0; font-size: 0.82rem; color: var(--ink-soft); }

  .graph-head { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 10px; flex-wrap: wrap; }
  .graph-head h2 { margin: 0; font-size: 0.95rem; font-weight: 650; display: flex; align-items: baseline; gap: 9px; }
  .graph-group {
    font-family: var(--font-mono); font-size: 0.66rem; font-weight: 400;
    letter-spacing: 0.07em; text-transform: uppercase; color: var(--ink-faint);
  }

  /* The pane picker sits above the stack it controls, and says how many panes
     there are so an all-unticked list is obviously a choice, not a break. */
  .pane-controls { display: flex; align-items: center; gap: 12px; }
  .pane-count { font-family: var(--font-mono); font-size: 0.72rem; color: var(--ink-faint); }
  .pick-head {
    font-family: var(--font-mono); font-size: 0.62rem; letter-spacing: 0.09em;
    text-transform: uppercase; color: var(--ink-faint); padding: 8px 8px 3px;
  }
  .repo-pick-menu .pick-head:first-child { padding-top: 2px; }
  /* The chart's own key: colour identifies a SET of platforms moving as one,
     so it has to be spelled out per chart rather than fixed in a legend. */
  /* ---------- usage tab ---------- */

  .usage-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
  .usage-table th, .usage-table td { padding: 11px 18px; border-bottom: 1px solid var(--line); text-align: left; }
  .usage-table tr:last-child td { border-bottom: none; }
  .usage-table thead th {
    font-family: var(--font-mono); font-size: 0.66rem; letter-spacing: 0.08em;
    text-transform: uppercase; color: var(--ink-faint); font-weight: 500; background: var(--surface-alt);
  }
  /* Counts are read down a column and compared, so they are tabular and right
     aligned — a ragged left edge makes 9 and 1,204 look the same length. */
  .usage-table .num { text-align: right; font-family: var(--font-mono); font-variant-numeric: tabular-nums; }
  .usage-table .zero { color: var(--ink-faint); }
  .usage-table .soft { color: var(--ink-faint); font-size: 0.78rem; }
  .usage-dot { display: inline-block; width: 9px; height: 9px; border-radius: 50%; margin-right: 9px; flex: none; }
  .usage-none { padding: 20px 18px; color: var(--ink-faint); font-size: 0.85rem; }

  .win-tabs { display: flex; gap: 6px; flex-wrap: wrap; }
  .win-tab {
    font: inherit; font-size: 0.76rem; cursor: pointer; color: var(--ink-faint);
    background: transparent; border: 1px solid var(--line); border-radius: 999px; padding: 4px 11px;
  }
  .win-tab:hover { color: var(--ink-soft); border-color: var(--ink-soft); }
  .win-tab.on { background: var(--accent-soft); border-color: var(--accent); color: var(--accent); font-weight: 600; }
  .gk-lane { color: var(--ink-faint); font-family: var(--font-mono); font-size: 0.68rem; }

  .graph-key { display: flex; flex-wrap: wrap; gap: 6px 16px; margin-top: 10px; }
  .gk { display: inline-flex; align-items: center; gap: 7px; font-size: 0.76rem; color: var(--ink-soft); }
  .gk i { width: 14px; height: 3px; border-radius: 2px; flex: none; }

  .graph-axis {
    display: flex; justify-content: space-between; margin-top: 4px;
    font-family: var(--font-mono); font-size: 0.72rem; color: var(--ink-faint);
  }

  /* The repo picker: a <details>, so it needs no JS to open and cannot get
     stuck open behind a failed script. */
  .repo-pick { position: relative; }
  .repo-pick > summary {
    list-style: none; cursor: pointer; font-size: 0.8rem; color: var(--ink-soft);
    border: 1px solid var(--line); border-radius: 999px; padding: 5px 12px; background: var(--surface-alt);
  }
  .repo-pick > summary::-webkit-details-marker { display: none; }
  .repo-pick-menu {
    position: absolute; right: 0; top: calc(100% + 6px); z-index: 20; min-width: 210px;
    background: var(--surface); border: 1px solid var(--line); border-radius: 10px;
    padding: 8px; display: flex; flex-direction: column; gap: 2px;
    box-shadow: 0 12px 28px rgba(0, 0, 0, 0.45);
  }
  .repo-pick-menu label {
    display: flex; align-items: center; gap: 8px; padding: 6px 8px;
    border-radius: 6px; font-size: 0.85rem; cursor: pointer;
  }
  .repo-pick-menu label:hover { background: var(--surface-alt); }
  /* The pane picker opens leftwards — it sits at the left edge, and the
     shared rule anchors to the right for the per-pane platform menus. */
  #pane-pick .repo-pick-menu { right: auto; left: 0; max-height: 62vh; overflow-y: auto; }
  .repo-pick-menu .dot { width: 10px; height: 3px; border-radius: 2px; flex: none; }
  .endpoint-url { font-family: var(--font-mono); font-size: 0.78rem; color: var(--ink-faint); word-break: break-all; }
  .endpoint-auth { color: var(--ink-soft); line-height: 1.4; }
  .endpoint-ms { font-family: var(--font-mono); color: var(--ink-faint); font-size: 0.78rem; }

  @media (max-width: 640px) {
    .page { padding-top: 16px; padding-bottom: 56px; }
    .endpoint-row { grid-template-columns: 1fr; gap: 6px; }
    .endpoint-head { display: none; }
    .graph-head { flex-direction: column; align-items: flex-start; }
  }
</style>
</head>
<body>

<script>
  // 12-hour Central everywhere on this page, in one place.
  const fmtT = (ts) => new Date(ts * 1000).toLocaleTimeString('en-US',
    { timeZone: 'America/Chicago', hour: 'numeric', minute: '2-digit' });
  const fmtF = (ts) => new Date(ts * 1000).toLocaleString('en-US',
    { timeZone: 'America/Chicago', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
</script>

<div class="page">

  <header>
    <div class="eyebrow-row">
      <div class="eyebrow">Mind-Suite &middot; deploy &amp; sync status</div>
      <span class="live-stamp" id="live-stamp">live</span>
      <a href="?logout">Log out</a>
    </div>
    <?php // The release is its own line and reads bigger than the label above
          // it — it is the fact, not the page's name. ?>
    <div class="triggered"><?= $latest
      ? 'Last triggered <strong>' . e(ctFull($latest['started_at'] ?? '')) . '</strong> &middot; ' . e($latest['target'] ?? '?')
      : 'No release recorded yet' ?></div>
    <?php // No headline. "Everything is up" with a dot beside it said nothing
          // the table below does not say better, and it said it in the one
          // place a person looks first. The dek survives only for the case
          // where something IS wrong, where naming it is the whole point. ?>
  </header>

  <div class="tabs">
    <button class="tab-btn active" data-tab="current">Current</button>
    <button class="tab-btn" data-tab="history">History</button>
    <button class="tab-btn" data-tab="live">Live Status</button>
    <button class="tab-btn" data-tab="usage">Usage</button>
  </div>

  <!-- ============================================================ CURRENT -->
  <div class="tab-panel active" id="tab-current">

  <div class="kpis">
    <div class="kpi"><span class="n">5</span><span class="l">Mind-suite repos &middot; 2 developer &middot; 2 website</span></div>
    <div class="kpi"><span class="n">3</span><span class="l">apps syncing through a server</span></div>
    <div class="kpi"><span class="n">1</span><span class="l">app syncing local-only, via Bonjour</span></div>
    <div class="kpi"><span class="n">4 / 4</span><span class="l">apps building &amp; running on Android</span></div>
    <div class="kpi"><span class="n">3 / 3</span><span class="l">phone slots spent (free-tier cap)</span></div>
  </div>

  <?php
  // ONE TABLE PER CATEGORY, all from $repos. Sean, 2026-08-22: "group
  // MindSuite, Developer (AgentSuite/LocalLLM), and website repos".
  foreach ($REPO_GROUPS as $gkey => [$gname, $gdek]):
    $rows = array_values(array_filter($repos, fn($r) => $r['group'] === $gkey));
    if (!$rows) { continue; }
  ?>
  <div class="table-card">
    <div class="group-head">
      <h2><?= e($gname) ?></h2>
      <p><?= e($gdek) ?></p>
    </div>
    <div class="table-scroll">
      <table>
        <colgroup>
          <col class="repo"><col class="web"><col class="sync">
          <col class="plat"><col class="plat"><col class="plat"><col class="plat"><col class="plat">
        </colgroup>
        <thead>
          <tr>
            <th>Repo</th><th>Web / server</th><th>Sync &amp; sharing mechanism</th>
            <th>macOS</th><th>Windows</th><th>iOS</th><th>watchOS</th><th>Android</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr data-live="repo:<?= e($r['name']) ?>"<?= $gkey === 'mindsuite' ? '' : ' class="outside"' ?>>
              <td>
                <span class="repo-name"><?= e($r['name']) ?></span>
                <span class="repo-tag"><?= isset($RUNNING[$r['name']])
                  ? '<span class="running-tag">' . e(($latest['kind'] ?? 'dtp')) . ' since ' . e(ct($latest['started_at'] ?? '', 'g:i a')) . '</span>'
                  : e($r['tag']) ?></span>
              </td>
              <?php // Web leads, then the prose, then the five device columns —
                    // the same order the matrix stores them in. ?>
              <?php $cell = $r['plat']['web']; ?>
              <td><span class="chip <?= cell_chip($cell[0], $r['name'], $RUNNING) ?>"><?= $cell[1] ?></span></td>
              <td class="prose"><?= $r['sync'] ?></td>
              <?php foreach (['macos', 'windows', 'ios', 'watchos', 'android'] as $plat):
                $c = $r['plat'][$plat] ?? [null, '&mdash;']; ?>
                <td>
                  <span class="chip <?= cell_chip($c[0], $r['name'], $RUNNING) ?>"><?= $c[1] ?></span>
                  <?php if (!empty($c[2])): ?><span class="cell-note"><?= $c[2] ?></span><?php endif; ?>
                </td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if ($gkey === 'mindsuite'): ?>
    <div class="legend">
      <?php // ONE vocabulary for the whole page — the same five words the
            // History axis uses, so a band there and a chip here mean the same
            // thing without translation. ?>
      <div class="legend-item"><span class="swatch running"></span> <strong>In Progress</strong> — shipping now</div>
      <div class="legend-item"><span class="swatch live"></span> <strong>Operational</strong> — installed, seen working</div>
      <div class="legend-item"><span class="swatch done"></span> <strong>Build Only</strong> — builds, not installed</div>
      <div class="legend-item"><span class="swatch partial"></span> <strong>Issue for Claude</strong> — mine to fix</div>
      <div class="legend-item"><span class="swatch crit"></span> <strong>Needs Attention</strong> — yours</div>
      <div class="legend-item"><span class="swatch none"></span> <strong>n/a</strong> — no such target</div>
    </div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>

  </div>


  <!-- ============================================================ HISTORY -->
  <div class="tab-panel" id="tab-history">
  <?php
  /**
   * ONE CHART PER REPO, each with its own platform picker — Sean, 2026-08-23:
   * "every single repo should have its own lineplot in history with a platform
   * dropdown for each one".
   *
   * It was one chart per GROUP with repo toggles inside it, which meant the
   * five Mind-suite repos shared six colours: a colour said "iOS" but nothing
   * said which repo's iOS, so two repos diverging looked like one line that
   * forked. A chart per repo makes colour mean platform and nothing else, and
   * a pane is then readable on its own without a legend lookup.
   *
   * WHICH PANES ARE SHOWN is a picker at the top, because nine panes stacked
   * is a scroll, not a comparison.
   *
   * Drawn in JS from the samples below rather than in PHP, so unticking a
   * platform is instant and needs no round trip — and so the live update can
   * redraw from fresh data without reloading anything.
   *
   * The x axis is TIME and the samples are state CHANGES, so a long calm
   * stretch is wide and a burst of change is dense. Every dot is an event.
   */
  $samples = status_samples();

  // Which repo.platform keys the samples actually carry — across ALL of them,
  // not just the first. A platform that gained a target mid-history exists,
  // and reading only sample zero would have hidden its pane for ever.
  $seenKeys = [];
  foreach ($samples as $smp) {
      foreach (array_keys($smp['s'] ?? []) as $k) { $seenKeys[$k] = true; }
  }
  $panes = [];
  foreach ($REPO_GROUPS as $gkey => [$gname, $gdek]) {
      foreach ($repos as $r) {
          if ($r['group'] !== $gkey) { continue; }
          $pl = [];
          foreach (array_keys($PLATFORMS) as $plat) {
              if (isset($seenKeys[$r['name'] . '.' . $plat])) { $pl[] = $plat; }
          }
          if ($pl) { $panes[$r['name']] = ['group' => $gname, 'plats' => $pl]; }
      }
  }
  ?>
  <script>
    const SAMPLES = <?= json_encode(array_map(fn($x) => [
        't' => (int) $x['ts'], 'u' => (int) ($x['until'] ?? $x['ts']), 's' => $x['s'] ?? [],
    ], $samples)) ?>;
    const PLATFORMS = <?= json_encode($PLATFORMS) ?>;
    // THE Y AXIS, top to bottom, each in the colour the legend uses for it.
    // `releasing` is above `fine` because it is not a degree of broken, and
    // `n/a` is below everything because it is not on the scale at all.
    const BANDS = [
      { sev:  4, label: 'In Progress',      color: 'var(--running)' },
      { sev:  0, label: 'Operational',      color: 'var(--live)' },
      { sev:  1, label: 'Build Only',       color: 'var(--done)' },
      { sev:  2, label: 'Issue for Claude', color: 'var(--partial)' },
      { sev:  3, label: 'Needs Attention',  color: 'var(--crit)' },
      { sev: -1, label: 'n/a',              color: 'var(--none)' },
    ];
    const BAND_AT = {}; BANDS.forEach((b, i) => { BAND_AT[b.sev] = i; });
    const SEV_LABEL = {}; BANDS.forEach(b => { SEV_LABEL[b.sev] = b.label; });
  </script>

  <?php // THE RUN CARDS COME FIRST — Sean, 2026-08-23: "the cards for which
        // release is being shown should be above the charts". The charts are
        // read in the context of a release, so the release is the heading. ?>
  <?php if (empty($history)): ?>
    <p class="none-yet">No runs recorded yet.</p>
  <?php else: ?>
    <div class="run-buttons" data-live="runs">
      <?php foreach ($history as $i => $run):
        $sev = (int) ($run['severity'] ?? 3);
        $isRun = ($run['status'] ?? '') === 'running';
        $cls = $isRun ? 'running' : severity_chip_class($sev);
      ?>
        <button class="run-btn<?= $i === 0 ? ' selected' : '' ?>" data-run="<?= $i ?>">
          <span class="t"><?= e(ctFull($run['started_at'] ?? '?')) ?></span>
          <?php // The separator is OUTSIDE e(): escaping '&middot;' turns its own
                // ampersand into &amp; and the button reads a literal "&middot;". ?>
          <span class="k"><?= e($run['kind'] ?? 'dtp') ?> &middot; <?= e($run['target'] ?? '?') ?></span>
          <span class="k"><?= $isRun
            ? 'started ' . e(ct($run['started_at'] ?? '', 'g:i a'))
            : (!empty($run['finished_at'])
                ? 'ended ' . e(ct($run['finished_at'], 'g:i a'))
                : 'no end recorded') ?></span>
          <span class="chip <?= $cls ?>"><?= $isRun ? 'running' : e($run['status'] ?? '?') ?></span>
        </button>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if (!$samples): ?>
    <p class="none-yet">No samples yet.</p>
  <?php else: ?>
    <?php // WHICH PANES ARE SHOWN, at the top, grouped the way the Current tab
          // groups them so the two tabs can be read against each other. ?>
    <div class="pane-controls">
      <details class="repo-pick" id="pane-pick">
        <summary>Charts <span class="caret">&#9662;</span></summary>
        <div class="repo-pick-menu">
          <?php $lastGroup = null; foreach ($panes as $name => $p): ?>
            <?php if ($p['group'] !== $lastGroup): $lastGroup = $p['group']; ?>
              <div class="pick-head"><?= e($p['group']) ?></div>
            <?php endif; ?>
            <label><input type="checkbox" checked data-pane="<?= e($name) ?>"><?= e($name) ?></label>
          <?php endforeach; ?>
        </div>
      </details>
      <span class="pane-count"><?= count($panes) ?> repo<?= count($panes) === 1 ? '' : 's' ?></span>
    </div>

    <?php foreach ($panes as $name => $p): ?>
      <div class="graph-card" data-graph data-repo="<?= e($name) ?>">
        <div class="graph-head">
          <h2><?= e($name) ?> <span class="graph-group"><?= e($p['group']) ?></span></h2>
          <?php // A platform picker PER PANE, listing only the platforms this
                // repo actually has samples for — a CoreMind pane offering to
                // hide its watchOS line would be offering nothing. ?>
          <details class="repo-pick">
            <summary>Platforms <span class="caret">&#9662;</span></summary>
            <div class="repo-pick-menu">
              <?php foreach ($p['plats'] as $plat): ?>
                <label>
                  <input type="checkbox" checked data-plat="<?= e($plat) ?>"><?= e($PLATFORMS[$plat]) ?>
                </label>
              <?php endforeach; ?>
            </div>
          </details>
        </div>
        <svg class="statechart" viewBox="0 0 900 210" style="width:100%;height:236px"></svg>
        <div class="graph-axis"><span class="ax-from"></span><span class="ax-mid"></span><span class="ax-to"></span></div>
        <?php // Colour means WHICH PLATFORMS TRAVEL TOGETHER, not which
              // platform — so the chart carries its own key. Built in JS from
              // the groupings actually drawn. ?>
        <div class="graph-key"></div>
      </div>
    <?php endforeach; ?>

    <script>

      /**
       * ONE LINE PER GROUP OF PLATFORMS THAT AGREE — Sean, 2026-08-23:
       * "overlapping lines should just be one line in a unique color... if
       * there's just one line that changes status, that color line should
       * shift to its new status, and the other remaining ones still have a dot
       * of their status when the other one changed.. every event puts a dot on
       * the graph for all platforms".
       *
       * Six platforms in the same state used to be six paths on one pixel row,
       * which is a picture that says "one thing" about six. Nudging them apart
       * only turned it into six near-identical lines saying nothing extra. So
       * agreement is drawn as agreement: one line, one colour, and the colour
       * identifies WHICH platforms are travelling together. When one leaves the
       * group, the group splits — a new colour peels off to the new band while
       * the rest keep theirs.
       *
       * AND EVERY EVENT DOTS EVERY PLATFORM. A change anywhere is a moment
       * worth reading across, so at each one every group gets a dot at whatever
       * state it is in — including the ones that did not move. Without that,
       * "nothing else changed" and "nothing else was recorded" looked alike.
       */
      const GROUP_COLORS = [
        '#5fb6ac', '#f0b429', '#8fa3e0', '#d98cc0', '#9ad17a',
        '#e08a5f', '#c58ef0', '#6fd0d8', '#e2725b', '#b9c86a',
      ];

      function drawChart(card) {
        const svg = card.querySelector('svg.statechart');
        if (!svg || !SAMPLES.length) { return; }
        const repo  = card.dataset.repo;
        const plats = [...card.querySelectorAll('.repo-pick input[data-plat]:checked')].map(i => i.dataset.plat);

        const W = 900, H = 210, padL = 108, padR = 18, padT = 18, padB = 22;
        const t0 = SAMPLES[0].t, t1 = Math.max(SAMPLES[SAMPLES.length - 1].u, t0 + 60);
        const x = (t) => padL + ((t - t0) / (t1 - t0)) * (W - padL - padR);
        const y = (sev) => padT + (BAND_AT[sev] / (BANDS.length - 1)) * (H - padT - padB);

        let out = '';
        BANDS.forEach((b) => {
          out += '<line x1="' + padL + '" y1="' + y(b.sev) + '" x2="' + (W - padR) + '" y2="' + y(b.sev) +
                 '" stroke="' + b.color + '" stroke-width="1" opacity="0.28"/>' +
                 '<text x="' + (padL - 10) + '" y="' + (y(b.sev) + 3.5) +
                 '" text-anchor="end" font-size="11" fill="' + b.color + '" opacity="0.85">' + b.label + '</text>';
        });

        const key = document.createElement('div');

        // Only the samples that say anything about this repo's chosen platforms.
        const mine = SAMPLES.filter(s => plats.some(p => (repo + '.' + p) in s.s));
        if (!plats.length || !mine.length) {
          svg.innerHTML = out;
          card.querySelector('.ax-from').textContent = fmtT(t0);
          card.querySelector('.ax-to').textContent = fmtT(t1);
          card.querySelector('.ax-mid').textContent = plats.length ? 'nothing recorded' : 'no platforms selected';
          card.querySelector('.graph-key').innerHTML = '';
          return;
        }

        // Who is where, at each sample.
        const at = mine.map((s) => {
          const m = {};
          plats.forEach(p => { const k = repo + '.' + p; if (k in s.s) { m[p] = s.s[k]; } });
          return m;
        });
        // …and the same, folded into groups: state -> the platforms in it.
        const groupsAt = at.map((m) => {
          const g = {};
          Object.keys(m).forEach(p => { (g[m[p]] = g[m[p]] || []).push(p); });
          return g;
        });

        // A colour per MEMBERSHIP, assigned in order of first appearance and
        // stable for as long as that set travels together. Two groups that
        // happen to share a state at different times keep their own colours;
        // the same set reappearing gets its old one back.
        const colorOf = {}; let ci = 0;
        const gkey = (members) => members.slice().sort().join('+');
        groupsAt.forEach(g => Object.keys(g).sort((a, b) => a - b).forEach((sev) => {
          const k = gkey(g[sev]);
          if (!(k in colorOf)) { colorOf[k] = GROUP_COLORS[ci++ % GROUP_COLORS.length]; }
        }));

        // The horizontal runs: one per group per sample.
        groupsAt.forEach((g, i) => {
          const xa = x(mine[i].t), xb = x(mine[i].u);
          Object.keys(g).forEach((sevStr) => {
            const sev = +sevStr, members = g[sevStr], c = colorOf[gkey(members)];
            out += '<line x1="' + xa + '" y1="' + y(sev) + '" x2="' + Math.max(xb, xa + 0.5) + '" y2="' + y(sev) +
                   '" stroke="' + c + '" stroke-width="2.6" stroke-linecap="round" vector-effect="non-scaling-stroke">' +
                   '<title>' + members.map(p => PLATFORMS[p]).join(', ') + ' — ' + SEV_LABEL[sev] + '</title></line>';
          });
        });

        // The vertical moves, drawn per DESTINATION group so a split shows the
        // colour that is arriving rather than the one being left behind.
        for (let i = 1; i < at.length; i++) {
          const moved = {};
          Object.keys(at[i]).forEach((p) => {
            const from = at[i - 1][p], to = at[i][p];
            if (from === undefined || from === to) { return; }
            (moved[from + '>' + to] = moved[from + '>' + to] || []).push(p);
          });
          Object.keys(moved).forEach((mk) => {
            const [from, to] = mk.split('>').map(Number);
            const members = moved[mk], xa = x(mine[i].t);
            const c = colorOf[gkey(groupsAt[i][to] || members)];
            out += '<line x1="' + xa + '" y1="' + y(from) + '" x2="' + xa + '" y2="' + y(to) +
                   '" stroke="' + c + '" stroke-width="2.6" stroke-linecap="round" vector-effect="non-scaling-stroke" opacity="0.85">' +
                   '<title>' + members.map(p => PLATFORMS[p]).join(', ') + ': ' + SEV_LABEL[from] +
                   ' → ' + SEV_LABEL[to] + '\n' + fmtF(mine[i].t) + '</title></line>';
          });
        }

        // THE DOTS. Every sample boundary is an event, and every group gets one
        // — the ones that moved and the ones that did not.
        let events = 0;
        groupsAt.forEach((g, i) => {
          const anyMove = i > 0 && Object.keys(at[i]).some(p => at[i - 1][p] !== undefined && at[i - 1][p] !== at[i][p]);
          if (i > 0 && !anyMove) { return; }
          if (i > 0) { events++; }
          const cx = x(mine[i].t);
          Object.keys(g).forEach((sevStr) => {
            const sev = +sevStr, members = g[sevStr], c = colorOf[gkey(members)];
            const R = members.length > 1 ? 6.5 : 5;
            const names = members.map(p => PLATFORMS[p]).join(', ');
            const tip = names + '\n' + SEV_LABEL[sev] + ' from ' + fmtF(mine[i].t) +
                        (i === 0 ? '\n(first recorded)' : '');
            out += '<circle cx="' + cx + '" cy="' + y(sev) + '" r="' + R + '" fill="' + c +
                   '" stroke="var(--surface)" stroke-width="1.5"><title>' + tip + '</title></circle>';
          });
        });

        svg.innerHTML = out;
        card.querySelector('.ax-from').textContent = fmtT(t0);
        card.querySelector('.ax-to').textContent = fmtT(t1);
        card.querySelector('.ax-mid').textContent =
          events === 0 ? 'no changes' : events + (events === 1 ? ' change' : ' changes');

        // THE KEY, because colour no longer means platform. It lists only the
        // groupings this chart actually drew.
        const seen = new Set(); let keyHtml = '';
        groupsAt.forEach(g => Object.keys(g).forEach((sev) => {
          const k = gkey(g[sev]);
          if (seen.has(k)) { return; }
          seen.add(k);
          keyHtml += '<span class="gk"><i style="background:' + colorOf[k] + '"></i>' +
                     g[sev].map(p => PLATFORMS[p]).join(' + ') + '</span>';
        }));
        card.querySelector('.graph-key').innerHTML = keyHtml;
      }

      function drawAll() { document.querySelectorAll('.graph-card[data-graph]').forEach(drawChart); }

      // WHICH PANES ARE SHOWN. Unticking hides the card; ticking it back
      // redraws, because a card hidden at load has never been drawn.
      document.querySelectorAll('#pane-pick input[data-pane]').forEach(cb =>
        cb.addEventListener('change', () => {
          const card = document.querySelector('.graph-card[data-repo="' + cb.dataset.pane + '"]');
          if (!card) { return; }
          card.hidden = !cb.checked;
          if (cb.checked) { drawChart(card); }
        }));
      document.querySelectorAll('.graph-card .repo-pick input[data-plat]').forEach(cb =>
        cb.addEventListener('change', () => drawChart(cb.closest('.graph-card'))));
      drawAll();
      window.addEventListener('resize', drawAll);
    </script>
  <?php endif; ?>


  </div>

  <!-- ============================================================ LIVE STATUS -->
  <div class="tab-panel" id="tab-live">

  <?php $checkedAt = (int) ($results['checked_at'] ?? @filemtime($cachePath) ?: time());
        $loginsAt  = (int) ($results['logins_checked_at'] ?? 0); ?>
  <p class="dek">Checked <strong><?= e(ct($checkedAt)) ?></strong><?= $loginsAt
    ? ' &middot; sign-ins ' . e(ctFull($loginsAt)) : '' ?><a class="recheck" href="?recheck=1#live">Check now</a></p>

  <?php // Hits, from lib/hitlog.php — every page on this host writes one line
        // per request into one log, so this counts the whole site rather than
        // whichever app happened to have logging wired up. ?>
  <div class="kpis" data-live="hits" style="margin-bottom:22px">
    <?php foreach ($hits as $label => $h): ?>
      <div class="kpi">
        <span class="n"><?= number_format($h['hits']) ?></span>
        <span class="l">hits in <?= e($label) ?><?= $h['people'] ? ' &middot; ' . $h['people'] . ' signed in' : '' ?></span>
      </div>
    <?php endforeach; ?>
  </div>

  <?php
  $groups = ['mindsuite' => 'Mind-Suite', 'site' => 'Rest of the site'];
  foreach ($groups as $key => $heading): ?>
    <div class="endpoint-group">
      <h2><?= e($heading) ?></h2>
      <?php foreach ($endpoints[$key] as $domain => $list): ?>
        <?php // The domain is the subsection. A whole sandbox being down is one
              // fact, and reading it as several unrelated rows is how it gets
              // mistaken for a coincidence. ?>
        <h3 class="domain-head"><?= e($domain) ?><?= $domain === 'seancheren.com' ? ' <span class="domain-note">production</span>' : ' <span class="domain-note">sandbox</span>' ?></h3>
        <?php
        /**
         * SIGN-IN REQUIRED vs PUBLIC, as two subsections — Sean, 2026-08-23:
         * "credentialed vs non-credentialed sites should have different
         * subsections in the live status page".
         *
         * They are not the same kind of row and were never comparable. A
         * public page has no sign-in to test and no scope to name, so mixing
         * them meant eight rows of "n/a" in two columns, plus a "Public — no
         * login" repeated down a third. Split, the public table needs three
         * columns and the credentialed one keeps the columns that say
         * something.
         */
        $sections = [
            ['Sign-in required', array_values(array_filter($list, fn($ep) => ($ep['scope_key'] ?? 'public') !== 'public')), true],
            ['Public',           array_values(array_filter($list, fn($ep) => ($ep['scope_key'] ?? 'public') === 'public')), false],
        ];
        foreach ($sections as [$secName, $secList, $gated]):
          if (!$secList) { continue; } ?>
          <div class="sec<?= $gated ? '' : ' sec-open' ?>">
            <div class="sec-head"><?= e($secName) ?></div>
            <div class="endpoint-row endpoint-head" data-sortable>
              <div data-col="0">Endpoint</div><div data-col="1">Status</div>
              <?php if ($gated): ?><div data-col="2">Sign-in</div><?php endif; ?>
              <div data-col="<?= $gated ? 3 : 2 ?>">Response</div>
              <?php if ($gated): ?><div data-col="4">Scope</div><?php endif; ?>
            </div>
            <div class="domain-rows">
            <?php foreach ($secList as $ep): $r = $results[$key][$ep['url']] ?? ['ok' => false, 'status' => 0, 'ms' => 0]; ?>
              <div class="endpoint-row" data-live="ep:<?= e($ep['url']) ?>" title="checked <?= e(ct($checkedAt)) ?>">
                <div data-sort="<?= e($ep['label']) ?>"><?= e($ep['label']) ?><div class="endpoint-url"><?= e($ep['url']) ?></div></div>
                <?php // The URL answering, and nothing more. A 401 is UP: the
                      // server replied. Whether anybody can get in is the next
                      // column's question. ?>
                <div data-sort="<?= $r['ok'] ? 0 : 1 ?>"><span class="chip <?= $r['ok'] ? 'live' : 'crit' ?>"><?= $r['ok'] ? 'up' : 'down' ?></span></div>
                <?php if ($gated):
                  // The SCOPE's verdict, not this row's. A scope is proven once and
                  // every row using it reports that result — which is the fact worth
                  // seeing: ChefMind goes down exactly when CalMind's accounts do.
                  $sk = $ep['scope_key'] ?? 'public';
                  $lg = $results['scopes'][$sk] ?? null; ?>
                  <div data-sort="<?= $lg === null ? 2 : (['ok' => 1, 'failed' => 3, 'skipped' => 2][$lg['state']] ?? 2) ?>"><?php
                    if ($lg === null)                  { echo '<span class="scope-chip">not probed</span>'; }
                    elseif ($lg['state'] === 'ok')     { echo '<span class="chip live" title="' . e($lg['why']) . '">works</span>'; }
                    elseif ($lg['state'] === 'failed') { echo '<span class="chip crit" title="' . e($lg['why']) . '">BROKEN</span>'; }
                    else { echo '<span class="chip partial" title="' . e($lg['why']) . '">not probed</span>'; }
                  ?></div>
                <?php endif; ?>
                <div class="endpoint-ms" data-sort="<?= (int) ($r['ms'] ?? 0) ?>"><?= $r['status'] ? $r['status'] . ' &middot; ' . $r['ms'] . 'ms' : '&mdash;' ?></div>
                <?php if ($gated): ?>
                  <?php // The mechanism rides as a tooltip. It was its own prose
                        // column, which duplicated the chip beside it on every
                        // site row and only said anything new on the API ones. ?>
                  <div data-sort="<?= e($ep['scope_key'] ?? 'public') ?>"><span class="scope-chip" title="<?= e(strip_tags($ep['auth'] ?? '')) ?>"><?= $ep['scope'] ?? 'unknown' ?></span></div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>

  <div class="legend">
    <div class="legend-item"><span class="swatch live"></span> <strong>up</strong> — answered; 401 counts</div>
    <div class="legend-item"><span class="swatch crit"></span> <strong>down</strong> — no answer</div>
    <div class="legend-item"><span class="swatch live"></span> <strong>works</strong> — a probe account signed in</div>
    <div class="legend-item"><span class="swatch crit"></span> <strong>BROKEN</strong> — up, nobody can get in</div>
    <div class="legend-item"><span class="swatch partial"></span> <strong>not probed</strong> — no credentials in <code>lib/config.php</code></div>
  </div>

  </div>

  <!-- ============================================================== USAGE -->
  <div class="tab-panel" id="tab-usage">
  <?php
  /**
   * WHO IS USING THIS — Sean, 2026-08-23: "separate hits from tests, hits from
   * my usage, and hits from other peoples usage.. on a 4th tab for Usage show
   * the name of each account, and their hits in the last hour, 12 hours, 3
   * days, 1 month, 1 year with timeseries line chart showing their requests
   * per minute".
   *
   * Everything here comes from the one host-wide hit log, so it counts every
   * app and site on the account rather than whichever one had logging wired
   * up. The lane split and the bucketing live in lib/hitlog.php.
   */
  $usage = hit_usage();
  $uw = $usage['windows'];
  $laneName = ['sean' => 'Sean', 'other' => 'Other people', 'test' => 'Tests'];
  $laneDek  = [
      'sean'  => 'production, signed in as sean',
      'other' => 'production, anybody else — signed in or not',
      'test'  => 'the test.seancheren.com sandbox',
  ];
  ?>

  <?php // HOW FAR BACK THE LOG ACTUALLY GOES. It rotates once at 4 MB and the
        // rotated copy is dropped on the next rotation, so the year column can
        // be reporting on a fortnight. Saying so is the difference between a
        // quiet year and a short log. ?>
  <p class="dek">Log starts <strong><?= $usage['oldest'] ? e(ctFull($usage['oldest'])) : '&mdash;' ?></strong><?php
    if ($usage['oldest'] && $usage['oldest'] > $usage['from']): ?> &middot; longer windows are capped by that<?php endif; ?></p>

  <div class="kpis" style="margin-bottom:6px">
    <?php foreach ($laneName as $lk => $ln): ?>
      <div class="kpi">
        <span class="n"><?= number_format($usage['lanes'][$lk]['3d'] ?? 0) ?></span>
        <span class="l"><?= e($ln) ?> &middot; last 3 days</span>
      </div>
    <?php endforeach; ?>
  </div>

  <?php foreach ($laneName as $lk => $ln):
    $rows = array_filter($usage['people'], fn($p) => $p['lane'] === $lk); ?>
    <div class="table-card">
      <div class="group-head">
        <h2><?= e($ln) ?></h2>
        <p><?= e($laneDek[$lk]) ?></p>
      </div>
      <?php if (!$rows): ?>
        <div class="usage-none">Nothing logged.</div>
      <?php else: ?>
      <div class="table-scroll">
        <table class="usage-table">
          <thead>
            <tr>
              <th>Account</th>
              <?php foreach ($uw as $w): ?><th class="num"><?= e($w['label']) ?></th><?php endforeach; ?>
              <th class="num">Last seen</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $rk => $p): ?>
              <tr>
                <td><span class="usage-dot" data-key="<?= e($rk) ?>"></span><span class="repo-name"><?= e($p['name']) ?></span></td>
                <?php foreach (array_keys($uw) as $wk): ?>
                  <td class="num<?= $p['counts'][$wk] ? '' : ' zero' ?>"><?= number_format($p['counts'][$wk]) ?></td>
                <?php endforeach; ?>
                <td class="num soft"><?= e(ctFull($p['last'])) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

  <?php // REQUESTS PER MINUTE, one line per account. The window picker changes
        // the bucket as well as the span, and the rate is divided back out, so
        // the y axis means the same thing at every zoom. ?>
  <div class="graph-card" id="usage-chart">
    <div class="graph-head">
      <h2>Requests per minute</h2>
      <div class="win-tabs">
        <?php foreach ($uw as $wk => $w): ?>
          <button class="win-tab<?= $wk === '3d' ? ' on' : '' ?>" data-win="<?= e($wk) ?>"><?= e($w['label']) ?></button>
        <?php endforeach; ?>
      </div>
    </div>
    <svg class="usagechart" viewBox="0 0 900 210" style="width:100%;height:236px"></svg>
    <div class="graph-axis"><span class="ax-from"></span><span class="ax-mid"></span><span class="ax-to"></span></div>
    <div class="graph-key"></div>
  </div>

  <script>
    const USAGE = <?= json_encode([
        'windows' => $uw,
        'series'  => $usage['series'],
        'people'  => array_map(fn($p) => ['name' => $p['name'], 'lane' => $p['lane']], $usage['people']),
        'now'     => $usage['now'],
    ]) ?>;
    // A colour per ACCOUNT, stable across every window so switching the span
    // does not repaint who is who.
    const USAGE_COLORS = ['#5fb6ac','#f0b429','#8fa3e0','#d98cc0','#9ad17a','#e08a5f','#c58ef0','#6fd0d8','#e2725b','#b9c86a'];
    const UCOLOR = {};
    Object.keys(USAGE.people).forEach((k, i) => { UCOLOR[k] = USAGE_COLORS[i % USAGE_COLORS.length]; });
    document.querySelectorAll('.usage-dot').forEach(d => { d.style.background = UCOLOR[d.dataset.key] || 'var(--none)'; });

    function drawUsage() {
      const card = document.getElementById('usage-chart');
      if (!card) { return; }
      const svg = card.querySelector('svg.usagechart');
      const wk  = (card.querySelector('.win-tab.on') || {}).dataset.win || '3d';
      const w   = USAGE.windows[wk];
      const rows = USAGE.series[wk] || {};
      const keys = Object.keys(rows).filter(k => rows[k].some(v => v > 0));

      const W = 900, H = 210, padL = 62, padR = 18, padT = 16, padB = 22;
      const n = (rows[Object.keys(rows)[0]] || []).length || 1;
      // The peak sets the scale, with a floor so an idle window is a flat line
      // near the bottom rather than noise magnified to full height.
      let peak = 0;
      keys.forEach(k => rows[k].forEach(v => { if (v > peak) { peak = v; } }));
      const top = Math.max(peak, 0.5);
      const x = (i) => padL + (n === 1 ? 0 : (i / (n - 1)) * (W - padL - padR));
      const y = (v) => padT + (1 - v / top) * (H - padT - padB);

      let out = '';
      [0, 0.5, 1].forEach((f) => {
        const v = top * f;
        out += '<line x1="' + padL + '" y1="' + y(v) + '" x2="' + (W - padR) + '" y2="' + y(v) +
               '" stroke="var(--line)" stroke-width="1" opacity="0.7"/>' +
               '<text x="' + (padL - 9) + '" y="' + (y(v) + 3.5) + '" text-anchor="end" font-size="11" ' +
               'fill="var(--ink-faint)">' + (v >= 10 ? Math.round(v) : v.toFixed(1)) + '</text>';
      });

      keys.forEach((k) => {
        const d = rows[k].map((v, i) => (i ? 'L' : 'M') + x(i) + ' ' + y(v)).join(' ');
        out += '<path d="' + d + '" fill="none" stroke="' + UCOLOR[k] + '" stroke-width="2.2" ' +
               'stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" opacity="0.9">' +
               '<title>' + USAGE.people[k].name + '</title></path>';
      });

      svg.innerHTML = out;
      const from = USAGE.now - w.secs;
      card.querySelector('.ax-from').textContent = fmtF(from);
      card.querySelector('.ax-to').textContent = fmtF(USAGE.now);
      card.querySelector('.ax-mid').textContent = keys.length
        ? 'peak ' + (peak >= 10 ? Math.round(peak) : peak.toFixed(2)) + '/min · ' +
          (w.bucket >= 86400 ? (w.bucket / 86400) + 'd' : w.bucket >= 3600 ? (w.bucket / 3600) + 'h' : (w.bucket / 60) + 'm') + ' buckets'
        : 'no requests in this window';
      card.querySelector('.graph-key').innerHTML = keys.map(k =>
        '<span class="gk"><i style="background:' + UCOLOR[k] + '"></i>' + USAGE.people[k].name +
        ' <span class="gk-lane">' + USAGE.people[k].lane + '</span></span>').join('');
    }

    document.querySelectorAll('.win-tab').forEach(b => b.addEventListener('click', () => {
      document.querySelectorAll('.win-tab').forEach(o => o.classList.remove('on'));
      b.classList.add('on');
      drawUsage();
    }));
    drawUsage();
  </script>

  </div>

</div>

<script>
  // THE TAB SURVIVES THE REFRESH. Sean, 2026-08-23: "keeps returning me to
  // current automatically". The page reloads itself every 60s and the reload
  // reset the tab, so reading History or Live Status for more than a minute
  // was impossible. The hash carries it — which also makes a tab linkable,
  // and means the restore happens before first paint rather than as a visible
  // flick from Current to wherever you were.
  function showTab(name) {
    const panel = document.getElementById('tab-' + name);
    if (!panel) { return false; }
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.toggle('active', b.dataset.tab === name));
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    panel.classList.add('active');
    return true;
  }
  document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      showTab(btn.dataset.tab);
      history.replaceState(null, '', '#' + btn.dataset.tab);
    });
  });
  showTab((location.hash || '').replace('#', '')) || showTab('current');

  // CLICK A COLUMN TO SORT IT, within its own subsection — Sean, 2026-08-23.
  // Scoped to the domain block rather than the whole group: sorting across
  // domains would undo the grouping the section exists to show.
  document.querySelectorAll('.endpoint-head[data-sortable] > [data-col]').forEach(th => {
    th.style.cursor = 'pointer';
    th.addEventListener('click', () => {
      const head = th.closest('.endpoint-head');
      const body = head.nextElementSibling;
      if (!body || !body.classList.contains('domain-rows')) { return; }
      const col = +th.dataset.col;
      const dir = head.dataset.dir === String(col) ? -1 : 1;
      head.dataset.dir = dir === 1 ? String(col) : '';
      const key = (row) => {
        const cell = row.children[col];
        const v = cell ? (cell.dataset.sort ?? cell.textContent.trim()) : '';
        const n = Number(v);
        return Number.isFinite(n) && v !== '' ? n : String(v).toLowerCase();
      };
      [...body.children]
        .sort((a, b) => { const x = key(a), y = key(b); return (x > y ? 1 : x < y ? -1 : 0) * dir; })
        .forEach(r => body.appendChild(r));
      head.querySelectorAll('[data-col]').forEach(h => h.classList.remove('sorted'));
      th.classList.add('sorted');
    });
  });

  // ── LIVE, WITHOUT RELOADING ────────────────────────────────────────────
  // Sean, 2026-08-23: "the page should live update without refreshing". It
  // used to call location.reload() every 60s, which threw away the tab, the
  // scroll position, the column sort and every picker — and did it while
  // somebody was reading. A release is exactly when the page is being
  // watched, and exactly when it was yanking itself out from under them.
  //
  // It re-fetches ITS OWN URL and swaps only the elements marked data-live.
  // One renderer, still PHP: the alternative is a JSON endpoint plus a second
  // copy of every chip rule in JavaScript, and two renderers of the same fact
  // disagree the first time one is edited.
  const LIVE_MS = 20000;
  let liveFails = 0;

  function applyLive(doc) {
    let changed = 0;
    doc.querySelectorAll('[data-live]').forEach(fresh => {
      const key = fresh.getAttribute('data-live');
      const here = document.querySelector('[data-live="' + CSS.escape(key) + '"]');
      if (!here) { return; }
      if (here.innerHTML !== fresh.innerHTML) { here.innerHTML = fresh.innerHTML; changed++; }
      // The headline carries its state in a CLASS, not in its text — without
      // this the dot stays green over a red table.
      if (here.className !== fresh.className) { here.className = fresh.className; }
    });
    // The dek appears and disappears; it is a whole element, not a swap.
    const freshDek = doc.querySelector('header .dek');
    const hereDek = document.querySelector('header .dek');
    if (freshDek && hereDek) { hereDek.innerHTML = freshDek.innerHTML; }
    else if (freshDek && !hereDek) { document.querySelector('header').appendChild(freshDek.cloneNode(true)); }
    else if (!freshDek && hereDek) { hereDek.remove(); }
    return changed;
  }

  async function tick() {
    try {
      // X-Live-Poll so the hit log does not count a poll as a page view — a
      // tab left open would otherwise report 180 visits an hour by itself.
      const res = await fetch(location.pathname, {
        headers: { 'X-Live-Poll': '1' }, cache: 'no-store', credentials: 'same-origin',
      });
      if (!res.ok) { throw new Error('HTTP ' + res.status); }
      const doc = new DOMParser().parseFromString(await res.text(), 'text/html');
      // A session that expired returns the sign-in page, which has no
      // data-live regions at all. Reloading is right THEN, and only then.
      if (!doc.querySelector('[data-live]')) { location.reload(); return; }
      applyLive(doc);
      liveFails = 0;
      const stamp = document.getElementById('live-stamp');
      if (stamp) {
        stamp.textContent = 'updated ' + new Date().toLocaleTimeString('en-US',
          { timeZone: 'America/Chicago', hour: 'numeric', minute: '2-digit', second: '2-digit' });
      }
    } catch (e) {
      // Back off rather than hammer a server that is already unwell — this
      // page's own polling must not be part of the problem it is reporting.
      liveFails++;
      const stamp = document.getElementById('live-stamp');
      if (stamp) { stamp.textContent = 'update failed — retrying'; }
    }
    setTimeout(tick, LIVE_MS * Math.min(8, 1 + liveFails));
  }
  setTimeout(tick, LIVE_MS);
</script>

</body>
</html>
