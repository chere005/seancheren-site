<?php
// A page served under /test/ (the sandbox mirror) loads lib-test/ instead of lib/,
// isolated in code, config and data. Links stay root-relative — the sandbox is a
// subdomain and .htaccess maps test.seancheren.com/X to /test/X — so nothing here
// prefixes a href. Keep this preamble identical when adding a page.
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

/**
 * A repo's WEB severity on one instance, measured from that instance's own
 * probes. Returns null when the repo is not deployed there at all — which is
 * an absence, not a failure, and must not paint a cell red.
 */
function web_sev_at(string $repo, string $inst, array $probeAt, array $byUrl): ?int
{
    $urls = $probeAt[$inst][$repo] ?? null;
    if (!$urls) { return null; }
    foreach ($urls as $u) { if (empty($byUrl[$u]['ok'])) { return 3; } }
    return 0;
}

// severity: 0 = live/good, 1 = done/deliberate, 2 = partial/small issue, 3 = crit/needs attention
function severity_chip_class(int $sev): string
{
    return match ($sev) {
        0 => 'live',
        5 => 'built',
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
require_once $__libDir . '/geoip.php';   // geo_for() reads the cache; the sweep fills it
$hitWindows = ['the last hour' => 3600, 'the last 12 hours' => 12 * 3600, 'the last 3 days' => 3 * 86400];
$hits = hit_counts($hitWindows);
// Computed BEFORE the header, because the app picker up there is built from
// the apps this log actually holds. The roster is the site's own account
// store; CalMind's users live behind a key this page cannot read, so they
// appear only once they have visited.
$USAGE_DATA = hit_usage(array_keys(app_users(app_config())));

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

/**
 * WHICH INSTANCE THE SERVER LAYS THE PAGE OUT FOR. The picker's default, and
 * the only instance PHP renders as "chosen" — every other instance's cells,
 * blocks and rows are in the DOM too, and showInstance() picks between them.
 * Named once so the Current tab's subsections, the Usage headlines and the
 * Usage rows cannot each assume a different default.
 */
$inst0 = 'prod';

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
<title>MindSuite Status</title>
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
    --built: #3fae86;
    --built-bg: #dff5ec;
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
      --built: #7fe8c0;
      --built-bg: #16332b;
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

  /* THE `hidden` ATTRIBUTE HAS TO WIN, and on this page it kept losing. The
     UA's rule is `[hidden] { display: none }` at the lowest specificity, so
     any class that sets `display` beats it — which is how the KPI strip
     (`.kpi { display: flex }`) stayed on screen through every instance the
     picker could choose, while the tables beside it, which set no display,
     hid correctly. Half a page filtering is worse than none: the numbers
     looked like they belonged to the instance that was selected.
     One global rule, so the next element to grow a `display` cannot bring
     the bug back. */
  [hidden] { display: none !important; }

  body {
    margin: 0;
    background: var(--bg);
    color: var(--ink);
    font-family: var(--font-sans);
    -webkit-font-smoothing: antialiased;
  }

  .page {
    /* Wide enough that the platform matrix stops needing a scrollbar on a
       big screen. The cap still exists — prose at 2000px is unreadable — but
       it sits above the widest thing on the page rather than below it. */
    max-width: 1800px;
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
  .table-card .sec-head { border-top: 1px solid var(--line); }
  .table-card .sec:first-of-type .sec-head { border-top: none; }
  .table-card th[data-sort-col] { cursor: pointer; }
  .table-card th[data-sort-col]::after { content: " \2195"; opacity: 0.35; }
  .table-card th.sorted-asc::after { content: " \2191"; opacity: 1; }
  .table-card th.sorted-desc::after { content: " \2193"; opacity: 1; }
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

  /* The matrix is WIDE and scrolls; the columns are sized so nothing wraps
     mid-value. A 150px device column broke "Aug 22, 4:23 pm" across two lines
     and pushed "/Applications/CalMind.app" into its neighbour — a date split
     after the comma reads as two facts. Every track below holds its longest
     real content, and .cell-note keeps each line whole. */
  table { border-collapse: collapse; table-layout: fixed; width: 100%; }
  /* The 1420px floor belongs to the platform MATRIX, which is genuinely wide
     and scrolls. It was on every table, so the Usage table — seven narrow
     columns that fit twice over — was forced into a horizontal scrollbar for
     space it did not want. */
  /* The floor is the SUM of the tracks below, not a guess: they disagreed
     (1420 against 1610), so the table could never actually fit its own
     columns and the scrollbar never went away however wide the window got. */
  .table-card .table-scroll > table:not(.usage-table) { min-width: 1508px; }

  col.repo { width: 164px; }
  col.web  { width: 208px; }
  /* Was 420px, when this column held a paragraph each. It holds one line now. */
  col.sync { width: 288px; }
  col.plat { width: 169px; }   /* ×5 — the five device columns */

  thead th {
    position: sticky; top: 0; background: var(--surface-alt); text-align: left;
    font-family: var(--font-mono); font-size: 0.72rem; font-weight: 500;
    letter-spacing: 0.08em; text-transform: uppercase; color: var(--ink-soft);
    padding: 12px 14px; border-bottom: 1px solid var(--line); white-space: nowrap;
  }
  tbody td { padding: 14px; border-bottom: 1px solid var(--line); vertical-align: top; font-size: 0.87rem; line-height: 1.5; }
  td.prose code { white-space: nowrap; }
  /* A repo's tag under its name is a label, not a sentence — wrapping
     "cloned from the bookshelf" over three lines made one row twice the
     height of its neighbours for no information. */
  .repo-tag { white-space: nowrap; }
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
  .chip.built { background: var(--built-bg); color: var(--built); }
  .chip.built::before { background: var(--built); opacity: 0.75; }
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

  .cell-note { display: block; margin-top: 5px; font-size: 0.74rem; color: var(--ink-faint); line-height: 1.45;
               white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  /* The one note that is legitimately long. It may wrap; it may not be cut. */
  .cell-note .nowrap { white-space: nowrap; overflow: visible; }
  .cell-note.building { color: var(--running); opacity: 0.85; }
  .cell-note .nowrap { white-space: nowrap; }
  .cell-note br { content: ""; display: block; margin-top: 1px; }

  /* The legend used to reuse .chip with an &nbsp; inside, which made every
     swatch a different width: a chip is a pill sized by its own text, and
     seven pills holding one space each came out seven different sizes. The
     swatch is its own fixed-size element now — same 10px marker the chips
     draw, nothing sizing it but the CSS. */
  .domain-head {
    font-family: var(--font-mono); font-size: 0.78rem; font-weight: 600;
    letter-spacing: 0.05em; color: var(--ink);
    margin: 0; padding: 18px 18px 10px; background: var(--surface-alt);
    border-top: 1px solid var(--line); border-bottom: 1px solid var(--line);
  }
  .endpoint-group > div:first-of-type .domain-head { border-top: none; }
  .domain-note { color: var(--ink-faint); letter-spacing: 0.04em; text-transform: uppercase; font-size: 0.66rem; }

  .legend { display: flex; flex-wrap: wrap; gap: 10px 22px; padding: 16px 18px; background: var(--surface-alt); border-top: 1px solid var(--line); font-size: 0.8rem; color: var(--ink-soft); }
  .legend-item { display: flex; align-items: center; gap: 8px; }
  .swatch { width: 10px; height: 10px; border-radius: 50%; flex: none; box-sizing: border-box; }
  .swatch.live    { background: var(--live); }
  .swatch.built   { background: var(--built); opacity: 0.75; }
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
    display: grid; grid-template-columns: 116px minmax(0, 1fr) 110px 156px 98px 130px 96px; gap: 12px;
    padding: 14px 18px; border-bottom: 1px solid var(--line); align-items: start; font-size: 0.85rem;
  }
  /* The public rows keep the SAME tracks and simply leave two of them empty,
     so Endpoint, Status and Response line up down the whole domain instead of
     the two subsections looking like two unrelated tables. */
  .sec-open .endpoint-row > [data-col="3"],
  .sec-open .endpoint-ms { grid-column: 5; }
  .endpoint-row:last-child { border-bottom: none; }
  /* A SUBSECTION IS A HEADING, not a stray label. It sat in the same weight
     and colour as the column header directly under it, so "SIGN-IN REQUIRED"
     and "ENDPOINT / URL" read as one confused two-line strip. It gets its own
     band, a rule above it, and an accent mark — three levels now read as
     three: domain, subsection, columns. */
  .sec + .sec { margin-top: 0; }
  .sec-head {
    display: flex; align-items: center; gap: 9px;
    font-family: var(--font-mono); font-size: 0.7rem; font-weight: 600;
    letter-spacing: 0.11em; text-transform: uppercase; color: var(--ink-soft);
    padding: 16px 18px 10px; border-top: 1px solid var(--line);
  }
  .sec:first-child .sec-head { border-top: none; }
  .sec-head::before {
    content: ""; width: 3px; height: 12px; border-radius: 2px; flex: none;
    background: var(--accent);
  }
  /* Public rows are the open half — a quieter mark says so without a word. */
  .sec-open .sec-head { color: var(--ink-faint); }
  .sec-open .sec-head::before { background: var(--ink-faint); opacity: 0.5; }
  .sec-count { margin-left: auto; font-weight: 400; letter-spacing: 0.06em; color: var(--ink-faint); }
  .endpoint-head {
    font-family: var(--font-mono); font-size: 0.64rem; letter-spacing: 0.07em;
    text-transform: uppercase; color: var(--ink-faint); padding-top: 4px; padding-bottom: 8px;
    background: transparent; border-bottom: 1px solid var(--line);
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
    display: inline-block; white-space: nowrap; font-family: var(--font-mono); font-size: 0.7rem; line-height: 1.35;
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

  /* TIGHT. Eight columns at 18px of side padding each threw the numbers to
     the far edge of a wide screen with nothing between them — the table is
     read across a row, so the row has to be readable at a glance. */
  .usage-table { width: 100%; border-collapse: collapse; font-size: 0.82rem; table-layout: fixed; }
  .usage-table th, .usage-table td { padding: 7px 10px; border-bottom: 1px solid var(--line); text-align: left; }
  .usage-table th:first-child, .usage-table td:first-child { padding-left: 18px; }
  .usage-table th:last-child, .usage-table td:last-child { padding-right: 18px; }
  .usage-table col.who  { width: 150px; }
  /* Wide enough for "1,229 addresses" and for an address wearing a "+2" —
     at 128px both ended in an ellipsis, which on a column of numbers reads as
     a truncated number rather than a truncated label. */
  .usage-table col.addr { width: 158px; }
  .usage-table col.loc  { width: auto; }
  .usage-table col.n    { width: 68px; }
  .usage-table col.seen { width: 128px; }
  .usage-table .mono { font-family: var(--font-mono); font-size: 0.72rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .usage-table .unloc { opacity: 0.55; }

  /* The anonymous row: one line for the crowd, its busiest addresses folded
     underneath until somebody asks for them. */
  .agg-toggle {
    display: inline-flex; align-items: center; gap: 8px; font: inherit;
    background: none; border: 0; color: inherit; cursor: pointer; padding: 0;
  }
  .agg-toggle .caret { display: inline-block; transition: transform 0.12s; color: var(--ink-faint); }
  .agg-toggle[aria-expanded="true"] .caret { transform: rotate(90deg); }
  .agg-row td { background: var(--surface-alt); }
  .addr-row td { background: var(--bg); }
  .addr-row td:first-child { padding-left: 34px; }
  .addr-more td { padding-left: 34px; color: var(--ink-faint); font-size: 0.76rem; }
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
  .usage-dot.in  { background: var(--live); }
  .usage-dot.out { background: var(--partial); }
  .usage-dot.never { background: transparent; border: 1.5px solid var(--ink-faint); }
  .who-ip { display: block; margin-top: 3px; font-family: var(--font-mono); font-size: 0.66rem; color: var(--ink-faint); }
  .usage-legend { border-top: none; border-radius: 12px; }
  .app-pick { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-top: 10px; }
  .app-n { margin-left: 7px; font-family: var(--font-mono); font-size: 0.66rem; opacity: 0.7; }
  tr.app-zero { opacity: 0.4; }
  .usage-none { padding: 20px 18px; color: var(--ink-faint); font-size: 0.85rem; }

  .tabs-row { display: flex; align-items: center; justify-content: space-between; gap: 14px; flex-wrap: wrap; }
  .inst-pick { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
  .inst-label { font-family: var(--font-mono); font-size: 0.7rem; letter-spacing: 0.08em;
                text-transform: uppercase; color: var(--ink-faint); margin-right: 2px; }
  /* The app picker wears the same pill, and used to wear the same CLASS —
     which is how one `querySelectorAll('.inst-tab')` handler ended up wired to
     both pickers. Same look, different name, so a selector can only ever mean
     one of them. */
  .inst-tab, .app-tab {
    font: inherit; font-size: 0.76rem; cursor: pointer; color: var(--ink-faint);
    background: transparent; border: 1px solid var(--line); border-radius: 999px; padding: 4px 12px;
  }
  .inst-tab:hover, .app-tab:hover { color: var(--ink-soft); border-color: var(--ink-soft); }
  .inst-tab.on, .app-tab.on { background: var(--accent-soft); border-color: var(--accent); color: var(--accent); font-weight: 600; }
  .web-cell { display: none; }
  .web-cell.on { display: inline; }

  .win-tabs { display: flex; gap: 6px; flex-wrap: wrap; }
  .win-tab {
    font: inherit; font-size: 0.76rem; cursor: pointer; color: var(--ink-faint);
    background: transparent; border: 1px solid var(--line); border-radius: 999px; padding: 4px 11px;
  }
  .win-tab:hover { color: var(--ink-soft); border-color: var(--ink-soft); }
  .win-tab.on { background: var(--accent-soft); border-color: var(--accent); color: var(--accent); font-weight: 600; }
  .gk-lane { color: var(--ink-faint); font-family: var(--font-mono); font-size: 0.68rem; }

  /* The instant tooltip. Fixed, so the chart's own box can never clip it. */
  .evt-tip {
    position: fixed; z-index: 90; pointer-events: none; white-space: pre-line;
    background: var(--surface); color: var(--ink); border: 1px solid var(--line);
    border-radius: 8px; padding: 7px 10px; font-size: 0.76rem; line-height: 1.4;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.45); max-width: 280px;
  }
  .evt-tip[hidden] { display: none; }
  .evt { cursor: pointer; }

  .graph-key { display: flex; flex-wrap: wrap; gap: 6px 16px; margin-top: 10px; }
  .gk { display: inline-flex; align-items: center; gap: 7px; font-size: 0.76rem; color: var(--ink-soft); }
  .gk i { width: 14px; height: 3px; border-radius: 2px; flex: none; }
  .gk i.gk-mix { display: inline-flex; overflow: hidden; }
  .gk i.gk-mix b { flex: 1; height: 3px; }

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
  .endpoint-url { font-family: var(--font-mono); font-size: 0.76rem; color: var(--ink-faint); word-break: break-all; }
  .scope-gate { display: inline-block; font-family: var(--font-mono); font-size: 0.72rem; color: var(--partial); }
  /* When the cell beside it was last actually asked. Its own line so a narrow
     column never pushes the chip out of shape. */
  /* Beside the chip, not under it. As a block it dropped to a second line and
     left every status row two lines tall for a five-character time; the two
     columns that carry one are widened to hold "up 9:48 am" on one. */
  .checked-at { margin-left: 7px; font-family: var(--font-mono); font-size: 0.68rem;
                color: var(--ink-faint); white-space: nowrap; }
  .endpoint-row > div:has(> .checked-at) { white-space: nowrap; }
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
      <div class="eyebrow">MindSuite &middot; deploy &amp; sync status</div>
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

  <?php // ONE instance picker for the whole page — Sean, 2026-08-23: "history
        // live status and usage should all have the prod/test/dev picker". It
        // drives the Current web cells, the Live Status domain sections and
        // the Usage lanes from a single choice. ?>
  <div class="tabs-row">
  <div class="tabs">
    <button class="tab-btn active" data-tab="current">Current</button>
    <button class="tab-btn" data-tab="history">History</button>
    <button class="tab-btn" data-tab="live">Live Status</button>
    <button class="tab-btn" data-tab="usage">Usage</button>
  </div>
  <div class="inst-pick">
    <?php foreach ($WEB_INSTANCES as $inst => $host): ?>
      <button class="inst-tab<?= $inst === 'prod' ? ' on' : '' ?>" data-inst="<?= e($inst) ?>"><?= e($host) ?></button>
    <?php endforeach; ?>
  </div>
  </div>
  <?php // WHICH APP the Usage numbers are about — Sean, 2026-08-23: "usage
        // should be split by app or website .. put selector buttons for what
        // usage is being tracked below the seancheren.com, test.seancheren.com
        // etc buttons". Only meaningful on Usage, so it shows with that tab.
        //
        // The count on each pill is THIS INSTANCE'S, rewritten when the
        // instance changes. It used to be the host-wide number, so picking the
        // dev sandbox left a row of pills reporting production's traffic
        // directly above tables that had correctly narrowed to dev. ?>
  <div class="app-pick" id="app-pick" hidden>
    <button class="app-tab on" data-app="*" title="Every app on this instance">All apps<span class="app-n"></span></button>
    <?php // $USAGE_DATA, not $usage — the Usage tab assigns $usage further down
          // the page, so up here it was undefined and this list came out empty. ?>
    <?php foreach (($USAGE_DATA['app_order'] ?? []) as $appName): ?>
      <button class="app-tab" data-app="<?= e($appName) ?>" title="Requests in the last 3 days, on the selected instance"><?= e($appName) ?><span class="app-n"><?= (int) ($USAGE_DATA['apps']['prod'][$appName]['3d'] ?? 0) ?></span></button>
    <?php endforeach; ?>
  </div>

  <!-- ============================================================ CURRENT -->
  <div class="tab-panel active" id="tab-current">

  <?php
  // Every probed URL's verdict, flattened, so a web cell can ask about its own
  // instance without walking the group structure per row.
  $byUrl = [];
  foreach ($results as $g => $rowset) {
      if (!is_array($rowset) || in_array($g, ['scopes', 'checked_at', 'logins_checked_at'], true)) { continue; }
      foreach ($rowset as $u => $rr) { if (is_array($rr)) { $byUrl[$u] = $rr; } }
  }
  ?>
  <?php
  /**
   * COUNTED FROM THE MATRIX, never typed. Every number here used to be a
   * literal in the markup — "6 Mind-suite repos", "2 apps syncing through a
   * server", "4 / 4 on Android" — sitting directly above the table that could
   * contradict them, and one already had: three repos reach the API, not two.
   * A headline that has to be re-typed when a row changes is a headline that
   * goes quietly wrong. The one figure that is not in the matrix, because it
   * is a fact about Apple rather than about this code, is IOS_FREE_SLOTS.
   */
  $inGroup  = fn(string $g) => count(array_filter($repos, fn($r) => $r['group'] === $g));
  $ofKind   = fn(string $k) => count(array_filter($repos, fn($r) => ($r['sync_kind'] ?? 'none') === $k));
  /** Repos with a cell on this platform at all, and how many of those are live. */
  $onPlat   = function (string $plat, array $sevs) use ($repos) {
      $has = array_filter($repos, fn($r) => ($r['plat'][$plat][0] ?? null) !== null);
      return [count(array_filter($has, fn($r) => in_array($r['plat'][$plat][0], $sevs, true))), count($has)];
  };
  [$androidOk, $androidAll] = $onPlat('android', [0]);
  // A slot is spent by a build that is ON the phone — installed (0) or built
  // this release (SEV_BUILT). A repo that deliberately only compiles for iOS
  // is not occupying one, which is the whole distinction severity 1 records.
  $slots = count(array_filter($repos, fn($r) => in_array($r['plat']['ios'][0] ?? null, [0, SEV_BUILT], true)));
  ?>
  <div class="kpis">
    <div class="kpi"><span class="n"><?= $inGroup('mindsuite') ?></span><span class="l">MindSuite repos &middot; <?= $inGroup('developer') ?> developer &middot; <?= $inGroup('website') ?> website</span></div>
    <div class="kpi"><span class="n"><?= $ofKind('server') ?></span><span class="l">apps syncing through a server<?= $ofKind('login') ? ' &middot; ' . $ofKind('login') . ' signing in to one' : '' ?></span></div>
    <div class="kpi"><span class="n"><?= $ofKind('local') ?></span><span class="l">app<?= $ofKind('local') === 1 ? '' : 's' ?> syncing local-only, via Bonjour</span></div>
    <div class="kpi"><span class="n"><?= $androidOk ?> / <?= $androidAll ?></span><span class="l">apps building &amp; running on Android</span></div>
    <div class="kpi"><span class="n"><?= $slots ?> / <?= IOS_FREE_SLOTS ?></span><span class="l">phone slots spent (free-tier cap)</span></div>
  </div>

  <?php
  // ONE TABLE PER CATEGORY, all from $repos. Sean, 2026-08-22: "group
  // MindSuite, Developer (AgentSuite/LocalLLM), and website repos".
  foreach ($REPO_GROUPS as $gkey => [$gname, $gdek]):
    $rows = array_values(array_filter($repos, fn($r) => $r['group'] === $gkey));
    if (!$rows) { continue; }
  ?>
  <?php
  /**
   * DEPLOYED vs NOT — Sean, 2026-08-23: "split deployed and not deployed apps
   * by a subsection in the mindsuite current page". A repo that serves a URL
   * and one that ships nothing to a server are different kinds of thing, and
   * reading CoreMind's row of n/a beside CalMind's live one invited the
   * question every time.
   *
   * "Deployed" means what the Web / server column beside it measures ON THE
   * SELECTED INSTANCE: a URL this page probes. It used to mean "on any
   * instance", which the comment already claimed it did not — so picking dev
   * left CalMind under a heading reading "Deployed" with a cell beside it
   * reading "not deployed". Each row carries the instances it ships to and
   * showInstance() re-buckets them; the server lays them out for production,
   * the picker's own default.
   */
  $deployAt = function (array $r) use ($WEB_INSTANCES, $WEB_PROBE_AT) {
      $at = [];
      foreach (array_keys($WEB_INSTANCES) as $inst) {
          if (!empty($WEB_PROBE_AT[$inst][$r['name']])) { $at[] = $inst; }
      }
      return $at;
  };
  $split = ['Deployed' => [], 'Not deployed' => []];
  foreach ($rows as $r) {
      $split[in_array($inst0, $deployAt($r), true) ? 'Deployed' : 'Not deployed'][] = $r;
  }
  ?>
  <div class="table-card">
    <div class="group-head">
      <h2><?= e($gname) ?></h2>
      <p><?= e($gdek) ?></p>
    </div>
    <?php // Both subsections are always rendered — an empty one hides itself —
          // because the picker moves rows between them and a section that was
          // never in the DOM has nowhere to put them. ?>
    <?php foreach ($split as $subName => $subRows): ?>
    <div class="sec" data-deploy-sec="<?= $subName === 'Deployed' ? 'yes' : 'no' ?>"<?= $subRows ? '' : ' hidden' ?>>
      <div class="sec-head"><?= e($subName) ?><span class="sec-count"><?= count($subRows) ?></span></div>
    <div class="table-scroll">
      <table>
        <colgroup>
          <col class="repo"><col class="web"><col class="sync">
          <col class="plat"><col class="plat"><col class="plat"><col class="plat"><col class="plat">
        </colgroup>
        <thead>
          <?php // Sortable like Live's — Sean, 2026-08-23: "sorting by columns
                // should work in the current page too". Sorting is by the
                // cell's visible text, which for a chip is its label — so
                // Operational groups with Operational, and n/a sinks. ?>
          <tr>
            <th data-sort-col="0">Repo</th><th data-sort-col="1">Web / server</th><th data-sort-col="2">Sync &amp; sharing mechanism</th>
            <th data-sort-col="3">macOS</th><th data-sort-col="4">Windows</th><th data-sort-col="5">iOS</th><th data-sort-col="6">watchOS</th><th data-sort-col="7">Android</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($subRows as $r): ?>
            <tr data-live="repo:<?= e($r['name']) ?>" data-deploy-at="<?= e(implode(' ', $deployAt($r))) ?>"<?= $gkey === 'mindsuite' ? '' : ' class="outside"' ?>>
              <td>
                <span class="repo-name"><?= e($r['name']) ?></span>
                <span class="repo-tag"><?= isset($RUNNING[$r['name']])
                  ? '<span class="running-tag">' . e(($latest['kind'] ?? 'dtp')) . ' since ' . e(ct($latest['started_at'] ?? '', 'g:i a')) . '</span>'
                  : e($r['tag']) ?></span>
              </td>
              <?php // Web leads, then the prose, then the five device columns —
                    // the same order the matrix stores them in.
                    //
                    // ONE CELL PER INSTANCE, all rendered, one shown. Sean,
                    // 2026-08-23: "current should have a dropdown to pick from
                    // looking at prod, test, or dev statuses". Every instance's
                    // verdict is already in $results, so switching is a class
                    // toggle rather than a round trip — and the live poller
                    // keeps updating the hidden ones too. ?>
              <td><?php foreach ($WEB_INSTANCES as $inst => $host):
                    $sev   = web_sev_at($r['name'], $inst, $WEB_PROBE_AT, $byUrl);
                    $label = $WEB_LABEL_AT[$inst][$r['name']] ?? null; ?>
                <span class="web-cell" data-inst="<?= e($inst) ?>"><?php
                  if ($label === null) { echo '<span class="chip none">not deployed</span>'; }
                  else { echo '<span class="chip ' . cell_chip($sev, $r['name'], $RUNNING) . '">' . $label . '</span>'; }
                ?></span>
              <?php endforeach; ?></td>
              <td class="prose"><?= $r['sync'] ?></td>
              <?php foreach (['macos', 'windows', 'ios', 'watchos', 'android'] as $plat):
                $c = $r['plat'][$plat] ?? [null, '&mdash;']; ?>
                <td>
                  <span class="chip <?= cell_chip($c[0], $r['name'], $RUNNING) ?>"><?= $c[1] ?></span>
                  <?php // A NOTE IS A CLAIM ABOUT THE LAST BUILD, so it goes
                        // quiet while the next one runs — Sean, 2026-08-23:
                        // "the install dates and destination should disappear
                        // while building and then update with their new status
                        // after another event occurs in the build log". A cell
                        // reading "Aug 22, 4:23 pm" under a purple chip is
                        // dating a bundle that is being replaced as you read
                        // it. The note returns, with its new date, on the
                        // first sweep after the run ends.
                        if (!empty($c[2]) && !isset($RUNNING[$r['name']])): ?><span class="cell-note"><?= $c[2] ?></span>
                  <?php elseif (isset($RUNNING[$r['name']])): ?><span class="cell-note building">building&hellip;</span><?php endif; ?>
                </td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    </div>
    <?php endforeach; ?>
    <?php if ($gkey === 'mindsuite'): ?>
    <div class="legend">
      <?php // ONE vocabulary for the whole page — the same five words the
            // History axis uses, so a band there and a chip here mean the same
            // thing without translation. ?>
      <div class="legend-item"><span class="swatch running"></span> <strong>In Progress</strong> — shipping now</div>
      <div class="legend-item"><span class="swatch live"></span> <strong>Operational</strong> — installed, seen working</div>
      <div class="legend-item"><span class="swatch built"></span> <strong>built</strong> — built, not installed; the device carries an older one</div>
      <div class="legend-item"><span class="swatch done"></span> <strong>Build Only</strong> — builds, deliberately never installed</div>
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
  // A PLATFORM THAT NEVER EXISTED STAYS OFF THE CHART — Sean, 2026-08-23:
  // "if the platform intentionally doesn't exist, don't show it on the
  // history". n/a is recorded in the samples so that a target APPEARING or
  // DROPPING is an event with a before; but a line that has read n/a for its
  // whole recorded life says only "there is no such thing", forever, and six
  // of those made CoreMind's pane an empty grid. The n/a band stays on the
  // axis for the transitions.
  $everReal = [];
  foreach ($samples as $smp) {
      foreach (($smp['s'] ?? []) as $k => $v) { if ((int) $v !== SEV_NA) { $everReal[$k] = true; } }
  }
  $panes = [];
  foreach ($REPO_GROUPS as $gkey => [$gname, $gdek]) {
      foreach ($repos as $r) {
          if ($r['group'] !== $gkey) { continue; }
          $pl = [];
          foreach (array_keys($PLATFORMS) as $plat) {
              // Web is recorded per instance, so a repo that lives only on a
              // sandbox still earns the line — asking about production's key
              // alone would have left its pane without one.
              $keys = $plat === 'web'
                  ? array_map(fn($i) => status_sample_key($r['name'], 'web', $i), array_keys($WEB_INSTANCES))
                  : [status_sample_key($r['name'], $plat)];
              foreach ($keys as $k) { if (isset($everReal[$k])) { $pl[] = $plat; break; } }
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
    /**
     * WHICH SAMPLE A PLATFORM READS, and the one place it is decided —
     * status_sample_key() in PHP writes exactly this. Web is the only platform
     * whose answer differs per instance, so it carries the instance; every
     * other platform is a device and has one answer for all three. Production
     * keeps the bare key, so every sample recorded before this stayed readable
     * rather than becoming a gap in the middle of the chart.
     *
     * Without it the History tab's web line was production's whichever button
     * was lit — a chart drawn under a picker it did not obey.
     */
    const sampleKey = (repo, plat) => {
      const inst = (document.querySelector('.inst-pick .inst-tab.on') || { dataset: {} }).dataset.inst || 'prod';
      return repo + '.' + plat + (plat === 'web' && inst !== 'prod' ? '@' + inst : '');
    };
    // THE Y AXIS, top to bottom, each in the colour the legend uses for it.
    // `releasing` is above `fine` because it is not a degree of broken, and
    // `n/a` is below everything because it is not on the scale at all.
    const BANDS = [
      { sev:  4, label: 'In Progress',      color: 'var(--running)' },
      { sev:  0, label: 'Operational',      color: 'var(--live)' },
      { sev:  5, label: 'Built, not installed', color: 'var(--built)' },
      { sev:  1, label: 'Build Only',       color: 'var(--done)' },
      { sev:  2, label: 'Issue for Claude', color: 'var(--partial)' },
      { sev:  3, label: 'Needs Attention',  color: 'var(--crit)' },
      { sev: -1, label: 'n/a',              color: 'var(--none)' },
    ];
    const BAND_AT = {}; BANDS.forEach((b, i) => { BAND_AT[b.sev] = i; });
    // Short names, because an annotation at every event has to fit beside its
    // dot rather than beside the next one along.
    const PLAT_SHORT = { web: 'Web', macos: 'Mac', windows: 'Win', ios: 'iOS', watchos: 'Watch', android: 'Andr' };

    /**
     * WHAT HAPPENED, not what it is — Sean, 2026-08-23: "the annotation for
     * each event is more like 'Build Started' etc etc".
     *
     * A label reading "Web, iOS" named the subject and left the verb out, so
     * the chart still had to be decoded against the y axis to learn anything.
     * A transition has a name: entering In Progress is a release starting,
     * leaving it is that release landing, and landing on Needs Attention is a
     * different event from landing on Operational even though both end a
     * build. The pair (from, to) is the whole event, so that is what is read.
     *
     * WHO moved stays in the tooltip and in the line colour. One label, one
     * fact.
     */
    function eventPhrase(from, to) {
      if (from === null)            { return 'First check'; }
      if (to === 4)                 { return 'Build started'; }
      if (from === 4) {
        if (to === 0)  { return 'Build finished, installed'; }
        // 5 is the common ending now: the release compiled it and left the
        // device carrying the previous copy.
        if (to === 5)  { return 'Built, not installed'; }
        if (to === 1)  { return 'Build only'; }
        if (to === 2)  { return 'Finished with an issue'; }
        if (to === 3)  { return 'Build failed'; }
        if (to === -1) { return 'Target dropped'; }
      }
      if (to === -1)                { return 'Target dropped'; }
      if (from === -1)              { return 'Target added'; }
      if (to === 3)                 { return 'Went down'; }
      if (from === 3 && to === 0)   { return 'Recovered'; }
      if (from === 3)               { return 'Partly recovered'; }
      if (to === 2)                 { return 'Issue appeared'; }
      if (from === 2 && to === 0)   { return 'Issue fixed'; }
      if (to === 5)                 { return 'Built, not installed'; }
      if (from === 5 && to === 0)   { return 'Installed'; }
      if (to === 1)                 { return 'Build only'; }
      if (to === 0)                 { return 'Back to operational'; }
      return SEV_LABEL[to] || '';
    }
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
        // The card carries its own span and target, so selecting it can zoom
        // the charts and hide the repos it never touched — without a fetch.
        // The id is a UTC stamp (report-status.sh writes date -u), which is
        // sturdier than parsing the human-readable Central string back.
        $d = DateTime::createFromFormat('YmdHis', (string) ($run['id'] ?? ''), new DateTimeZone('UTC'));
        $fromEp = $d ? $d->getTimestamp() : '';
        $toEp = $isRun ? '' : ((int) strtotime((string) ($run['finished_at'] ?? '')) ?: '');
      ?>
        <button class="run-btn<?= $i === 0 ? ' selected' : '' ?>" data-run="<?= $i ?>"
                data-from="<?= e($fromEp) ?>" data-to="<?= e($toEp) ?>"
                data-repos="<?= e($run['target'] ?? '') ?>">
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
      /**
       * A COLOUR PER PLATFORM, and one for ALL — Sean, 2026-08-23: "make a
       * color for all so the history is easier to read (checking legend gives
       * the info enough)".
       *
       * Colouring by arbitrary GROUP meant the same platform changed colour
       * every time its company did, so nothing on the chart was stable enough
       * to learn. Now web is always teal and iOS always pink, the full set
       * moving together gets its own neutral, and a mixed subset is drawn as a
       * pie of its members' colours rather than a colour of its own.
       */
      /**
       * PASTELS ONLY — Sean, 2026-08-23: "make all the colors nice pastel
       * colors.. not things like yellow or red that make it look like
       * something bad is happening". On a status page a saturated amber or
       * red is a WORD, not a hue: it means attention. These six identify a
       * platform and nothing more, so they must stay out of that vocabulary —
       * which the band colours behind them own.
       */
      const PLAT_COLOR = {
        web:     '#7fd4c8',   // aqua
        macos:   '#b6c8f0',   // periwinkle
        windows: '#a8b8e8',   // lilac-blue
        ios:     '#e6b3d4',   // rose
        watchos: '#b3ddb0',   // mint
        android: '#d9c2e8',   // lavender
      };
      const ALL_COLOR = '#cfd6dc';   // every platform, in step — the calm case


      // Attribute-safe: the tip carries newlines and app names.
      const esc = (t) => String(t).replace(/&/g, '&amp;').replace(/"/g, '&quot;')
                                  .replace(/</g, '&lt;').replace(/\n/g, '&#10;');

      function drawChart(card) {
        const svg = card.querySelector('svg.statechart');
        if (!svg || !SAMPLES.length) { return; }
        const repo  = card.dataset.repo;
        const plats = [...card.querySelectorAll('.repo-pick input[data-plat]:checked')].map(i => i.dataset.plat);

        const W = 900, H = 210, padL = 108, padR = 18, padT = 42, padB = 38;
        /**
         * THE WINDOW IS THE SELECTED RUN — Sean, 2026-08-23: "make sure i can
         * switch between different runs from the cards at the top". With no
         * card selected the chart spans everything recorded; with one, it
         * zooms to that run plus a margin, so the before and after states are
         * both in frame.
         */
        let t0 = SAMPLES[0].t, t1 = Math.max(SAMPLES[SAMPLES.length - 1].u, t0 + 60);
        if (RUN_WINDOW) {
          const pad = Math.max(300, (RUN_WINDOW[1] - RUN_WINDOW[0]) * 0.2);
          t0 = RUN_WINDOW[0] - pad; t1 = RUN_WINDOW[1] + pad;
        }
        const x = (t) => padL + ((t - t0) / (t1 - t0)) * (W - padL - padR);
        const y = (sev) => padT + (BAND_AT[sev] / (BANDS.length - 1)) * (H - padT - padB);

        let out = '';
        BANDS.forEach((b) => {
          out += '<line x1="' + padL + '" y1="' + y(b.sev) + '" x2="' + (W - padR) + '" y2="' + y(b.sev) +
                 '" stroke="' + b.color + '" stroke-width="1" opacity="0.28"/>' +
                 '<text x="' + (padL - 10) + '" y="' + (y(b.sev) + 3.5) +
                 '" text-anchor="end" font-size="11" fill="' + b.color + '" opacity="0.85">' + b.label + '</text>';
        });

        /**
         * A TIME AXIS, drawn on the chart — Sean, 2026-08-23: "x axis should
         * show timestamps". The span used to be reported as two numbers below
         * the picture, which told you where it started and stopped and nothing
         * about where anything in the middle sat. Ticks put every dot on a
         * clock.
         *
         * Under a day it is times only; past that a date is needed or two
         * different Tuesdays read alike.
         */
        const axisFmt = (t1 - t0) <= 86400 ? fmtT : fmtF;
        out += '<text x="' + ((padL + W - padR) / 2) + '" y="' + (H - 2) +
               '" text-anchor="middle" font-size="9.5" fill="var(--ink-faint)">' +
               'time — every dot is a recorded check</text>';
        const TICKS = 5;
        for (let i = 0; i < TICKS; i++) {
          const tt = t0 + (t1 - t0) * (i / (TICKS - 1)), tx = x(tt);
          out += '<line x1="' + tx + '" y1="' + (H - padB + 5) + '" x2="' + tx + '" y2="' + (H - padB + 10) +
                 '" stroke="var(--line)" stroke-width="1"/>' +
                 '<text x="' + tx + '" y="' + (H - padB + 24) + '" text-anchor="' +
                 (i === 0 ? 'start' : i === TICKS - 1 ? 'end' : 'middle') +
                 '" font-size="10" fill="var(--ink-faint)">' + axisFmt(tt) + '</text>';
        }

        // Only the samples that say anything about this repo's chosen platforms.
        const mine = SAMPLES.filter(s => s.u >= t0 && s.t <= t1 && plats.some(p => sampleKey(repo, p) in s.s));
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
          plats.forEach(p => { const k = sampleKey(repo, p); if (k in s.s) { m[p] = s.s[k]; } });
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
        const colorOf = {};
        const gkey = (members) => members.slice().sort().join('+');
        // One platform → its own colour. Every platform → the "all" neutral.
        // A subset → the first member's colour for the LINE (the dot is a pie
        // of all of them, which is where the detail belongs).
        const colorFor = (members) => {
          if (members.length === 1) { return PLAT_COLOR[members[0]] || '#9c978d'; }
          if (members.length === plats.length) { return ALL_COLOR; }
          return PLAT_COLOR[members.slice().sort()[0]] || '#9c978d';
        };
        groupsAt.forEach(g => Object.keys(g).sort((a, b) => a - b).forEach((sev) => {
          const k = gkey(g[sev]);
          if (!(k in colorOf)) { colorOf[k] = colorFor(g[sev]); }
        }));

        // The horizontal runs: one per group per sample.
        groupsAt.forEach((g, i) => {
          const xa = x(Math.max(mine[i].t, t0)), xb = x(Math.min(mine[i].u, t1));
          Object.keys(g).forEach((sevStr) => {
            const sev = +sevStr, members = g[sevStr], c = colorOf[gkey(members)];
            out += '<line x1="' + xa + '" y1="' + y(sev) + '" x2="' + Math.max(xb, xa + 0.5) + '" y2="' + y(sev) +
                   '" stroke="' + c + '" stroke-width="2.6" stroke-linecap="round" vector-effect="non-scaling-stroke">' +
                   '<title>' + members.map(p => PLATFORMS[p]).join(', ') + ' — ' + SEV_LABEL[sev] + '</title></line>';
          });
        });

        // The vertical moves, drawn per DESTINATION group so a split shows the
        // colour that is arriving rather than the one being left behind.
        const evts = [];
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
            const who = members.map(p => PLATFORMS[p]).join(', ');
            out += '<line x1="' + xa + '" y1="' + y(from) + '" x2="' + xa + '" y2="' + y(to) +
                   '" stroke="' + c + '" stroke-width="2.6" stroke-linecap="round" vector-effect="non-scaling-stroke" opacity="0.85">' +
                   '<title>' + who + ': ' + SEV_LABEL[from] +
                   ' → ' + SEV_LABEL[to] + '\n' + fmtF(mine[i].t) + '</title></line>';
            evts.push({ t: mine[i].t, phrase: eventPhrase(from, to), who });
          });
        }
        if (mine.length && mine[0].t >= t0) {
          evts.push({ t: mine[0].t, phrase: 'First check', who: '' });
        }

        /**
         * THE ANNOTATIONS, off the data — Sean, 2026-08-23: "annotations
         * should not overlay.. draw a faint vertical dotted line over the time
         * of the event, and a readable description next to that line".
         *
         * A label beside its dot sat wherever the dot sat, which above a busy
         * band meant labels through lines and through each other. The dotted
         * rule puts the WHEN on the chart at full height, and the words live
         * in the clear strip above the top band. Labels close in x step down
         * through three rows rather than colliding; same-instant events share
         * one rule and one label, joined.
         */
        const byT = {};
        evts.forEach(e => { (byT[e.t] = byT[e.t] || []).push(e); });
        const laneEnd = [-1e9, -1e9, -1e9];
        Object.keys(byT).map(Number).sort((a, b) => a - b).forEach((tt) => {
          const lx = x(tt);
          const phrases = [...new Set(byT[tt].map(e => e.phrase))].join(' · ');
          const whos = [...new Set(byT[tt].map(e => e.who).filter(Boolean))].join('; ');
          out += '<line x1="' + lx + '" y1="' + (padT - 2) + '" x2="' + lx + '" y2="' + (H - padB) +
                 '" stroke="var(--ink-faint)" stroke-width="1" stroke-dasharray="2,5" opacity="0.5"/>';
          const near = lx > (padL + (W - padR)) / 2;
          const wpx = phrases.length * 5.4;
          const x0 = near ? lx - 5 - wpx : lx + 5, x1e = x0 + wpx;
          let lane = 0;
          while (lane < 2 && x0 < laneEnd[lane] + 8) { lane++; }
          laneEnd[lane] = x1e;
          out += '<text x="' + (lx + (near ? -5 : 5)) + '" y="' + (5.5 + lane * 11) +
                 '" text-anchor="' + (near ? 'end' : 'start') + '" font-size="9.5" fill="var(--ink-soft)">' +
                 phrases + '<title>' + (whos ? whos + '\n' : '') + fmtF(tt) + '</title></text>';
        });

        // THE DOTS. Every sample boundary is an event, and every group gets one
        // — the ones that moved and the ones that did not.
        let events = 0;
        groupsAt.forEach((g, i) => {
          // EVERY PING GETS ITS SET OF CIRCLES — Sean, 2026-08-23: "a set of
          // circles should be there for every event that was pinged". A sample
          // is written whenever ANY repo's state moves, so a chart that only
          // dotted its own repo's changes went blank through events it was
          // present for. Reading across two charts then meant guessing whether
          // the gap was "unchanged" or "unrecorded".
          const anyMove = i > 0 && Object.keys(at[i]).some(p => at[i - 1][p] !== undefined && at[i - 1][p] !== at[i][p]);
          if (anyMove) { events++; }
          if (mine[i].t < t0 || mine[i].t > t1) { return; }
          const cx = x(mine[i].t);
          Object.keys(g).forEach((sevStr) => {
            const sev = +sevStr, members = g[sevStr], cy = y(sev);
            const R = members.length > 1 ? 6.5 : 5;
            const names = members.map(p => PLATFORMS[p]).join(', ');
            const tip = names + '\n' + SEV_LABEL[sev] + '\nchecked ' + fmtF(mine[i].t) +
                        (i === 0 ? '\n(first recorded)' : '');
            // A SUBSET IS DRAWN AS A PIE OF ITS MEMBERS — Sean, 2026-08-23:
            // "pie charts for dots that are some subset of colors and the
            // color itself if it's just one platform". One platform is its own
            // colour; the whole set is the calm neutral, because six equal
            // slices is a pattern rather than a fact; anything between is a
            // pie, which says WHICH platforms are here without a lookup.
            const solid = members.length === 1 || members.length === plats.length;
            if (solid) {
              out += '<circle class="evt" cx="' + cx + '" cy="' + cy + '" r="' + R + '" fill="' +
                     colorOf[gkey(members)] + '" stroke="var(--surface)" stroke-width="1.5" ' +
                     'data-tip="' + esc(tip) + '"><title>' + tip + '</title></circle>';
            } else {
              const step = (Math.PI * 2) / members.length;
              let a0 = -Math.PI / 2;
              members.slice().sort().forEach((m) => {
                const a1 = a0 + step;
                const p0 = [cx + R * Math.cos(a0), cy + R * Math.sin(a0)];
                const p1 = [cx + R * Math.cos(a1), cy + R * Math.sin(a1)];
                out += '<path class="evt" d="M ' + cx + ' ' + cy + ' L ' + p0[0] + ' ' + p0[1] +
                       ' A ' + R + ' ' + R + ' 0 ' + (step > Math.PI ? 1 : 0) + ' 1 ' +
                       p1[0] + ' ' + p1[1] + ' Z" fill="' + (PLAT_COLOR[m] || '#9c978d') +
                       '" data-tip="' + esc(tip) + '"><title>' + tip + '</title></path>';
                a0 = a1;
              });
              out += '<circle cx="' + cx + '" cy="' + cy + '" r="' + R +
                     '" fill="none" stroke="var(--surface)" stroke-width="1.5"/>';
            }
          });
        });

        svg.innerHTML = out;
        card.querySelector('.ax-from').textContent = '';
        card.querySelector('.ax-to').textContent = mine.length + (mine.length === 1 ? ' check' : ' checks');
        card.querySelector('.ax-mid').textContent =
          events === 0 ? 'no changes' : events + (events === 1 ? ' change' : ' changes');

        // THE KEY, because colour no longer means platform. It lists only the
        // groupings this chart actually drew.
        const seen = new Set(); let keyHtml = '';
        groupsAt.forEach(g => Object.keys(g).forEach((sev) => {
          const k = gkey(g[sev]);
          if (seen.has(k)) { return; }
          seen.add(k);
          const mem = g[sev];
          // A mixed subset has no single colour — its key entry shows the same
          // slices the dot does, so the two read as the same thing.
          const swatch = (mem.length === 1 || mem.length === plats.length)
            ? '<i style="background:' + colorOf[k] + '"></i>'
            : '<i class="gk-mix">' + mem.slice().sort().map(m =>
                '<b style="background:' + (PLAT_COLOR[m] || '#9c978d') + '"></b>').join('') + '</i>';
          keyHtml += '<span class="gk">' + swatch +
                     (mem.length === plats.length ? 'all platforms' : mem.map(p => PLATFORMS[p]).join(' + ')) +
                     '</span>';
        }));
        card.querySelector('.graph-key').innerHTML = keyHtml;
      }

      /**
       * AN INSTANT TOOLTIP — Sean, 2026-08-23: "tooltip for what happened at
       * the event should come up quicker". The SVG `<title>` element is the
       * OS's tooltip and waits about a second before appearing, which on a
       * chart you are scrubbing across is long enough to give up on. This
       * shows the same text on hover with no delay; the `<title>` stays as the
       * fallback for a page reached without JS and for screen readers, which
       * is why both are emitted rather than one replacing the other.
       */
      const tipBox = document.createElement('div');
      tipBox.className = 'evt-tip';
      tipBox.hidden = true;
      document.body.appendChild(tipBox);
      document.addEventListener('mouseover', (e) => {
        const el = e.target.closest && e.target.closest('.evt');
        if (!el) { return; }
        tipBox.textContent = el.dataset.tip || '';
        tipBox.hidden = false;
        const r = el.getBoundingClientRect();
        const bw = tipBox.offsetWidth, bh = tipBox.offsetHeight;
        let left = r.left + r.width / 2 - bw / 2;
        left = Math.max(8, Math.min(left, window.innerWidth - bw - 8));
        let top = r.top - bh - 10;
        if (top < 8) { top = r.bottom + 10; }
        tipBox.style.left = Math.round(left) + 'px';
        tipBox.style.top  = Math.round(top) + 'px';
      });
      document.addEventListener('mouseout', (e) => {
        if (e.target.closest && e.target.closest('.evt')) { tipBox.hidden = true; }
      });
      document.addEventListener('scroll', () => { tipBox.hidden = true; }, true);

      function drawAll() { document.querySelectorAll('.graph-card[data-graph]').forEach(c => { if (!c.hidden) { drawChart(c); } }); }

      /**
       * THE SELECTED RUN drives both the window and which panes show — Sean,
       * 2026-08-23: "if a deployment only affects some specific repos, only
       * show the affected repos history plot". Deselecting (clicking the card
       * again) returns to everything.
       */
      let RUN_WINDOW = null, RUN_REPOS = null;
      function applyRun(btn) {
        RUN_WINDOW = null; RUN_REPOS = null;
        if (btn) {
          const f = parseInt(btn.dataset.from, 10);
          const tt = btn.dataset.to ? parseInt(btn.dataset.to, 10) : Math.floor(Date.now() / 1000);
          if (!isNaN(f)) { RUN_WINDOW = [f, Math.max(tt, f + 60)]; }
          const names = (btn.dataset.repos || '').trim().split(/\s+/).filter(Boolean)
            .map(t => t === 'core' ? 'CoreMind' : t);
          if (names.length) { RUN_REPOS = new Set(names); }
        }
        paneSync();
        drawAll();
      }
      // A pane shows when its checkbox is on AND the selected run touched it.
      function paneSync() {
        document.querySelectorAll('.graph-card[data-graph]').forEach((card) => {
          const cb = document.querySelector('#pane-pick input[data-pane="' + card.dataset.repo + '"]');
          card.hidden = !((!cb || cb.checked) && (!RUN_REPOS || RUN_REPOS.has(card.dataset.repo)));
        });
      }
      document.addEventListener('click', (e) => {
        const btn = e.target.closest('.run-btn');
        if (!btn) { return; }
        const off = btn.classList.contains('selected');
        document.querySelectorAll('.run-btn').forEach(o => o.classList.remove('selected'));
        if (!off) { btn.classList.add('selected'); }
        applyRun(off ? null : btn);
      });

      // WHICH PANES ARE SHOWN. Unticking hides the card; ticking it back
      // redraws, because a card hidden at load has never been drawn.
      document.querySelectorAll('#pane-pick input[data-pane]').forEach(cb =>
        cb.addEventListener('change', () => { paneSync(); drawAll(); }));
      document.querySelectorAll('.graph-card .repo-pick input[data-plat]').forEach(cb =>
        cb.addEventListener('change', () => drawChart(cb.closest('.graph-card'))));
      applyRun(document.querySelector('.run-btn.selected'));
      window.addEventListener('resize', drawAll);
    </script>
  <?php endif; ?>


  </div>

  <!-- ============================================================ LIVE STATUS -->
  <div class="tab-panel" id="tab-live">

  <?php $checkedAt = (int) ($results['checked_at'] ?? @filemtime($cachePath) ?: time());
        $loginsAt  = (int) ($results['logins_checked_at'] ?? 0); ?>
  <?php // The timestamps moved INTO the rows — Sean, 2026-08-23: "status and
        // signin should show a timestamp that it was checked next to the
        // status indicator. drop the 'checked' at the top". One clock at the
        // top spoke for eighteen rows and two different cadences; each cell
        // now says when IT was asked, which is the only reading that is
        // true of that cell. ?>
  <p class="dek"><a class="recheck" href="?recheck=1#live">Check now</a></p>

  <?php // Hits, from lib/hitlog.php — every page on this host writes one line
        // per request into one log, so this counts the whole site rather than
        // whichever app happened to have logging wired up.
        //
        // Per instance, because the picker above claims to scope the page: a
        // strip that answered "the whole host" while sitting under a chosen
        // sandbox was read as that sandbox's traffic. The numbers for all
        // three ride along and the picker swaps them. ?>
  <div class="kpis" data-live="hits" style="margin-bottom:22px">
    <?php foreach ($hits as $label => $h): ?>
      <div class="kpi" data-hits="<?= e(json_encode($h['by_inst'])) ?>">
        <span class="n"><?= number_format($h['by_inst']['prod']['hits'] ?? 0) ?></span>
        <span class="l">hits in <?= e($label) ?><span class="who"><?= ($h['by_inst']['prod']['people'] ?? 0)
            ? ' &middot; ' . (int) $h['by_inst']['prod']['people'] . ' signed in' : '' ?></span></span>
      </div>
    <?php endforeach; ?>
  </div>

  <?php
  $groups = ['mindsuite' => 'MindSuite', 'site' => 'Rest of the site'];
  foreach ($groups as $key => $heading): ?>
    <div class="endpoint-group">
      <h2><?= e($heading) ?></h2>
      <?php foreach ($endpoints[$key] as $domain => $list): ?>
        <?php // The domain is the subsection. A whole sandbox being down is one
              // fact, and reading it as several unrelated rows is how it gets
              // mistaken for a coincidence. ?>
        <?php $dInst = strncmp($domain, 'test.', 5) === 0 ? 'test' : (strncmp($domain, 'dev.', 4) === 0 ? 'dev' : 'prod'); ?>
        <div class="domain-block" data-inst-only="<?= e($dInst) ?>">
        <h3 class="domain-head"><?= e($domain) ?><?= $dInst === 'prod' ? ' <span class="domain-note">production</span>' : ' <span class="domain-note">sandbox</span>' ?></h3>
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
            <div class="sec-head"><?= e($secName) ?><span class="sec-count"><?= count($secList) ?></span></div>
            <div class="endpoint-row endpoint-head" data-sortable>
              <div data-col="0">Endpoint</div><div data-col="1">URL</div><div data-col="2">Status</div>
              <?php if ($gated): ?><div data-col="3">Sign-in</div><?php endif; ?>
              <div data-col="<?= $gated ? 4 : 3 ?>">Response</div>
              <?php if ($gated): ?><div data-col="5">Auth by</div><div data-col="6">Access</div><?php endif; ?>
            </div>
            <div class="domain-rows">
            <?php foreach ($secList as $ep): $r = $results[$key][$ep['url']] ?? ['ok' => false, 'status' => 0, 'ms' => 0]; ?>
              <div class="endpoint-row" data-live="ep:<?= e($ep['url']) ?>" title="checked <?= e(ct($checkedAt)) ?>">
                <?php // The URL is its own column now. Tucked under the label it
                      // made every row two lines tall and could not be sorted or
                      // scanned down, which is the only way anybody reads a URL
                      // list. ?>
                <div data-sort="<?= e($ep['label']) ?>"><?= e($ep['label']) ?></div>
                <div class="endpoint-url" data-sort="<?= e($ep['url']) ?>"><?= e($ep['url']) ?></div>
                <?php // The URL answering, and nothing more. A 401 is UP: the
                      // server replied. Whether anybody can get in is the next
                      // column's question. ?>
                <div data-sort="<?= $r['ok'] ? 0 : 1 ?>">
                  <span class="chip <?= $r['ok'] ? 'live' : 'crit' ?>"><?= $r['ok'] ? 'up' : 'down' ?></span>
                  <span class="checked-at"><?= e(ct($checkedAt, 'g:i a')) ?></span>
                </div>
                <?php if ($gated):
                  // The SCOPE's verdict, not this row's. A scope is proven once and
                  // every row using it reports that result — which is the fact worth
                  // seeing: ChefMind goes down exactly when CalMind's accounts do.
                  $sk = $ep['scope_key'] ?? 'public';
                  $lg = $results['scopes'][$sk] ?? null; ?>
                  <div data-sort="<?= $lg === null ? 2 : (['ok' => 1, 'failed' => 3, 'skipped' => 2][$lg['state']] ?? 2) ?>"><?php
                    if ($lg === null)                  { echo '<span class="scope-chip">not probed</span>'; }
                    elseif ($lg['state'] === 'ok')     { echo '<span class="chip live" title="' . e($lg['why']) . '">live</span>'; }
                    elseif ($lg['state'] === 'failed') { echo '<span class="chip crit" title="' . e($lg['why']) . '">BROKEN</span>'; }
                    else { echo '<span class="chip partial" title="' . e($lg['why']) . '">not probed</span>'; }
                    // Its OWN clock: sign-ins are probed every six hours, not
                    // every sweep, so borrowing the status stamp would claim a
                    // login was tried minutes ago when it was tried this morning.
                    if ($loginsAt) { echo '<span class="checked-at">' . e(ct($loginsAt, 'g:i a')) . '</span>'; }
                  ?></div>
                <?php endif; ?>
                <div class="endpoint-ms" data-sort="<?= (int) ($r['ms'] ?? 0) ?>"><?= $r['status'] ? $r['status'] . ' &middot; ' . $r['ms'] . 'ms' : '&mdash;' ?></div>
                <?php if ($gated): ?>
                  <?php // The mechanism rides as a tooltip. It was its own prose
                        // column, which duplicated the chip beside it on every
                        // site row and only said anything new on the API ones. ?>
                  <?php // WHO answers the password, then WHO is let through.
                        // Two facts, two lines — running them together as
                        // "site login &middot; aki only" read as one label and
                        // named no owner at all. ?>
                  <div data-sort="<?= e($ep['scope_key'] ?? 'public') ?>">
                    <span class="scope-chip" title="<?= e(strip_tags($ep['auth'] ?? '')) ?>"><?= e(scope_provider($ep['scope_key'] ?? '') ?? 'unknown') ?></span>
                  </div>
                  <?php // WHO exactly can get in — Sean, 2026-08-23: "the
                        // specific users that can login (if they exist)". A
                        // page gated to named accounts names them; one any
                        // signed-in account opens says so in two words. ?>
                  <div data-sort="<?= e($ep['gate'] ?? '~') ?>"><?php
                    echo empty($ep['gate'])
                        ? '<span class="scope-chip">any account</span>'
                        : '<span class="scope-gate">' . e(preg_replace('/\s+only$/', '', $ep['gate'])) . '</span>';
                  ?></div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>

  <div class="legend">
    <div class="legend-item"><span class="swatch live"></span> <strong>up</strong> — answered; 401 counts</div>
    <div class="legend-item"><span class="swatch crit"></span> <strong>down</strong> — no answer</div>
    <div class="legend-item"><span class="swatch live"></span> <strong>live</strong> — a probe account signed in</div>
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
  // The roster is the site's own account store — config users plus everyone
  // who signed up. CalMind's accounts live behind a key this page cannot
  // read, so its users appear here only once they have actually visited.
  $usage = $USAGE_DATA;
  $uw = $usage['windows'];
  $laneName = ['sean' => 'Sean', 'other' => 'Other people', 'bots' => 'Bots', 'claudio' => 'Claudio', 'test' => 'Tests', 'dev' => 'Dev'];
  $laneDek  = [
      'sean'    => 'production, signed in as sean',
      'other'   => 'production, anybody else — signed in or not',
      'bots'    => 'datacenter & scanner traffic, any instance — no browser behind it',
      'claudio' => "Claude's own requests, on the selected instance",
      'test'    => 'the test.seancheren.com sandbox',
      'dev'     => 'the dev.seancheren.com sandbox',
  ];
  // Which instance's traffic a lane is. Every lane but Claude's IS an
  // instance, so the picker hides the card outright; Claude hits all three, so
  // that card stays and its ROWS narrow instead ('all' survives every choice).
  $laneInst = ['sean' => 'prod', 'other' => 'prod', 'bots' => 'all', 'claudio' => 'all', 'test' => 'test', 'dev' => 'dev'];
  // Every number below is production's, because $inst0 is the picker's
  // default; JS rewrites all of them the moment it is moved, through the one
  // rule in usageTotal() / hit_usage_total().
  /** The instance a lane's rows are read at, for the server's first paint. */
  $laneAt = fn(string $lk) => $laneInst[$lk] === 'all' ? $inst0 : $laneInst[$lk];
  /** Every window's count for one person, as the sortable/rewritable cells. */
  $countCells = function (array $counts) use ($uw) {
      foreach (array_keys($uw) as $wk) {
          echo '<td class="num' . ($counts[$wk] ? '' : ' zero') . '" data-win="' . e($wk)
             . '" data-sort="' . (int) $counts[$wk] . '">' . number_format($counts[$wk]) . '</td>';
      }
  };
  /** A located label, or the honest offline classification, or a dash. */
  $where = function (?string $geo, string $ip): string {
      if ($geo !== null && $geo !== '' && $geo !== '-') { return e($geo); }
      if ($ip !== '' && $ip !== '-') { return '<span class="unloc">' . e(hit_where($ip)) . '</span>'; }
      return '&mdash;';
  };
  ?>

  <?php // The log rotates once at 4 MB, so a long window can be reporting on a
        // short log. The start date is the whole of that caveat. ?>
  <p class="dek">Log starts <strong><?= $usage['oldest'] ? e(ctFull($usage['oldest'])) : '&mdash;' ?></strong></p>

  <div class="kpis" style="margin-bottom:6px">
    <?php foreach ($laneName as $lk => $ln): ?>
      <div class="kpi" data-inst-only="<?= e($laneInst[$lk]) ?>" data-kpi-lane="<?= e($lk) ?>">
        <span class="n"><?= number_format(hit_usage_total($usage['people'], $lk, $laneAt($lk), '3d')) ?></span>
        <span class="l"><?= e($ln) ?> &middot; <span class="kpi-app"></span>last 3 days</span>
      </div>
    <?php endforeach; ?>
  </div>

  <div data-live="usage-tables">
  <?php foreach ($laneName as $lk => $ln):
    $rows = array_filter($usage['people'], fn($p) => $p['lane'] === $lk);
    // Visible on the server's first paint — the rest of this lane's rows are
    // in the DOM too, carrying the instance they belong to.
    $shown = array_filter($rows, fn($p) => $p['inst'] === $laneAt($lk)); ?>
    <div class="table-card" data-inst-only="<?= e($laneInst[$lk]) ?>">
      <div class="group-head">
        <h2><?= e($ln) ?></h2>
        <p><?= e($laneDek[$lk]) ?></p>
      </div>
      <div class="usage-none"<?= $shown ? ' hidden' : '' ?>>Nothing logged.</div>
      <div class="table-scroll"<?= $shown ? '' : ' hidden' ?>>
        <table class="usage-table">
          <colgroup>
            <col class="who"><col class="addr"><col class="loc">
            <?php foreach ($uw as $w): ?><col class="n"><?php endforeach; ?>
            <col class="seen">
          </colgroup>
          <thead>
            <tr>
              <th data-sort-col="0">Account</th>
              <th data-sort-col="1">Address</th>
              <th data-sort-col="2">Location</th>
              <?php // $uw is keyed by window NAME ('hour','12h'…), so the column
                    // index has to be counted rather than taken from the key. ?>
              <?php $ci = 3; foreach ($uw as $w): ?><th class="num" data-sort-col="<?= $ci++ ?>"><?= e($w['label']) ?></th><?php endforeach; ?>
              <th class="num" data-sort-col="<?= $ci ?>">Last seen</th>
            </tr>
          </thead>
          <tbody>
            <?php
            /**
             * ANONYMOUS IS ONE ROW — Sean, 2026-09-03: "group together
             * anonymous requests, don't list hundreds of anon-xxxx".
             *
             * The aggregating happens in hit_usage(), not here, so the chart,
             * the headline numbers and this table are all counting the same
             * thing. What is left for the page is a caret: the busiest dozen
             * addresses fold out underneath, which is the part of that wall
             * anybody actually reads, and the count says how many were left in
             * the drawer.
             */
            foreach ($rows as $rk => $p):
              $anon = !empty($p['anon']);
              $ip = (string) ($p['ip'] ?? '');
              $extra = max(0, (int) $p['addresses'] - count($p['top']));
              $openable = count($p['top']) > 1;
            ?>
              <tr data-key="<?= e($rk) ?>" data-inst="<?= e($p['inst']) ?>"
                  class="<?= $anon ? 'agg-row' : '' ?>"<?= $p['inst'] === $laneAt($lk) ? '' : ' hidden' ?>>
                <?php // THREE states, because there are three. Orange: traffic
                      // with no session behind it. Green: an account that has
                      // actually signed in. Grey: an account that exists and
                      // has never been seen — a green dot on those claimed
                      // they were signed in, which they never have been. ?>
                <td<?= $anon ? ' title="Requests with no session — public pages, the login wall, and anyone browsing signed out. One row for all of them; open it for the busiest addresses."' : '' ?>>
                  <?php if ($openable): ?>
                    <button type="button" class="agg-toggle" aria-expanded="false">
                      <span class="caret">&#9656;</span>
                      <span class="usage-dot <?= $anon ? 'out' : ($p['last'] ? 'in' : 'never') ?>"></span>
                      <span class="repo-name"><?= e($p['name']) ?></span>
                    </button>
                  <?php else: ?>
                    <span class="usage-dot <?= $anon ? 'out' : ($p['last'] ? 'in' : 'never') ?>"></span>
                    <span class="repo-name"><?= e($p['name']) ?></span>
                  <?php endif; ?>
                </td>
                <?php // ADDRESS AND LOCATION are their own columns so each can
                      // be sorted — under the name they could only be read one
                      // row at a time. The location is whatever geoip.php's
                      // cache holds; the offline class is the fallback, and it
                      // is honest about being one.
                      //
                      // The aggregate counts addresses instead of naming one,
                      // and says nothing about location: a thousand visitors
                      // are not in a place. ?>
                <td class="mono<?= $anon ? ' soft' : '' ?>" data-addr data-sort="<?= $anon ? (int) $p['addresses'] : e($ip) ?>"><?php
                  if ($anon) {
                      echo '<span class="addr-n">' . number_format((int) $p['addresses']) . '</span> address'
                         . ((int) $p['addresses'] === 1 ? '' : 'es');
                  } else {
                      echo $ip !== '' ? e($ip) : '&mdash;';
                      if ((int) $p['addresses'] > 1) { echo '<span class="app-n" title="addresses seen for this account">+' . ((int) $p['addresses'] - 1) . '</span>'; }
                  }
                ?></td>
                <td class="mono soft"><?= $anon ? '&mdash;' : $where($p['geo'] ?? null, $ip) ?></td>
                <?php $countCells($p['counts']); ?>
                <?php // An account with no traffic has no last-seen — a
                      // formatted epoch-zero would read as 1969. ?>
                <td class="num soft" data-sort="<?= (int) $p['last'] ?>"><?= $p['last'] ? e(ctFull($p['last'])) : '&mdash;' ?></td>
              </tr>
              <?php if ($openable): foreach ($p['top'] as $t): $tip = (string) $t['ip']; ?>
                <?php // The address sits in the ACCOUNT column, because for a
                      // visitor with no session the address is the only
                      // identity there is. Its own Address cell is left blank
                      // rather than dashed — a dash in a column of real values
                      // reads as a fact that went missing. ?>
                <tr class="addr-row" data-of="<?= e($rk) ?>" data-addr-ip="<?= e($tip) ?>" hidden>
                  <td class="mono"><?= $tip === '-' ? '<span class="unloc">no address recorded</span>' : e($tip) ?></td>
                  <td></td>
                  <td class="mono soft"><?= $where($t['geo'] ?? null, $tip) ?></td>
                  <?php $countCells($t['counts']); ?>
                  <td class="num soft" data-sort="<?= (int) $t['last'] ?>"><?= $t['last'] ? e(ctFull($t['last'])) : '&mdash;' ?></td>
                </tr>
              <?php endforeach; ?>
                <?php if ($extra): ?>
                  <tr class="addr-more" data-of="<?= e($rk) ?>" hidden>
                    <td colspan="<?= count($uw) + 4 ?>">and <?= number_format($extra) ?> more address<?= $extra === 1 ? '' : 'es' ?>, each quieter than these</td>
                  </tr>
                <?php endif; ?>
              <?php endif; ?>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endforeach; ?>

  </div>

  <div class="legend usage-legend">
    <div class="legend-item"><span class="usage-dot in"></span> signed in at least once</div>
    <div class="legend-item"><span class="usage-dot out"></span> anonymous — no session, grouped into one row per lane</div>
    <div class="legend-item"><span class="usage-dot never"></span> account exists, never seen</div>
  </div>

  <?php // REQUESTS PER MINUTE, one line per account. The window picker changes
        // the bucket as well as the span, and the rate is divided back out, so
        // the y axis means the same thing at every zoom. ?>
  <div class="graph-card" id="usage-chart">
    <div class="graph-head">
      <h2>Requests per bucket</h2>
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

  <?php
  /**
   * THE NUMBERS, SHIPPED ONCE. In a data-live region, so the 20-second poll
   * refreshes them the same way it refreshes the tables — the chart used to be
   * frozen at whatever the page loaded with, on a page whose headline claim is
   * that it updates without reloading.
   *
   * A <script type="application/json"> is inert: swapping its text does not
   * execute anything, which is exactly what the poller does to every other
   * data-live element.
   */
  ?>
  <script type="application/json" id="usage-data" data-live="usage-data"><?= json_encode([
      'windows' => $uw,
      'series'  => $usage['series'],
      'buckets' => $usage['buckets'],   // how many buckets each window has
      'apps'    => $usage['apps'],      // [instance][app][window], for the picker's counts
      // people: everything the table and the headline rewrite themselves from.
      'people'  => array_map(fn($p) => [
          'name' => $p['name'], 'lane' => $p['lane'], 'inst' => $p['inst'], 'anon' => (bool) $p['anon'],
          'counts' => $p['counts'], 'apps' => $p['apps'] ?? [],
          'addresses' => (int) $p['addresses'], 'addr_apps' => $p['addr_apps'] ?? [],
          // The folded-out addresses, keyed so a row can find its own numbers.
          'top' => array_column(array_map(fn($t) => ['ip' => $t['ip'], 'counts' => $t['counts'], 'apps' => $t['apps']], $p['top']), null, 'ip'),
      ], $usage['people']),
      'now'     => $usage['now'],
  ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

  <script>
    let USAGE = JSON.parse(document.getElementById('usage-data').textContent);
    // A colour per ACCOUNT, stable across every window so switching the span
    // does not repaint who is who.
    const USAGE_COLORS = ['#5fb6ac','#f0b429','#8fa3e0','#d98cc0','#9ad17a','#e08a5f','#c58ef0','#6fd0d8','#e2725b','#b9c86a'];
    let UCOLOR = {};
    function usageColors() {
      UCOLOR = {};
      Object.keys(USAGE.people).forEach((k, i) => { UCOLOR[k] = USAGE_COLORS[i % USAGE_COLORS.length]; });
    }
    usageColors();

    /** Which instance and which app every number below is about. */
    const usageInst = () => (document.querySelector('.inst-pick .inst-tab.on') || { dataset: {} }).dataset.inst || 'prod';
    const usageApp  = () => (document.querySelector('#app-pick .app-tab.on') || { dataset: {} }).dataset.app || '*';
    /** One person's count for a window, under the chosen app. */
    const personN = (p, wk, app) => (app === '*' ? (p.counts[wk] || 0) : (((p.apps || {})[app] || {})[wk] || 0));
    /**
     * The same rule hit_usage_total() applies in PHP, and the only place a
     * total is worked out here. Two of these drifting apart is how the lane
     * headline came to disagree with the rows underneath it.
     */
    function usageTotal(lane, inst, wk, app) {
      let n = 0;
      Object.keys(USAGE.people).forEach((k) => {
        const p = USAGE.people[k];
        if (p.lane === lane && p.inst === inst) { n += personN(p, wk, app); }
      });
      return n;
    }

    function drawUsage() {
      const card = document.getElementById('usage-chart');
      if (!card) { return; }
      const svg = card.querySelector('svg.usagechart');
      const wk  = (card.querySelector('.win-tab.on') || {}).dataset.win || '3d';
      const w   = USAGE.windows[wk];
      // The server ships SPARSE {bucket: count} per app; the zeroes are filled
      // in here, for the one app being drawn. "*" sums every app.
      const n0 = USAGE.buckets[wk];
      const byApp = USAGE.series[wk] || {};
      const app = usageApp();
      // …and only the instance that is selected. The chart drew every lane on
      // every instance, so picking a sandbox left production's traffic on the
      // plot under tables that had correctly narrowed to the sandbox.
      const inst = usageInst();
      const rows = {};
      Object.keys(byApp).forEach((a) => {
        if (app !== '*' && a !== app) { return; }
        Object.keys(byApp[a]).forEach((k) => {
          if (!USAGE.people[k] || USAGE.people[k].inst !== inst) { return; }
          if (!rows[k]) { rows[k] = new Array(n0).fill(0); }
          Object.keys(byApp[a][k]).forEach((b) => { rows[k][+b] += byApp[a][k][b]; });
        });
      });
      const keys = Object.keys(rows).filter(k => rows[k].some(v => v > 0));

      const W = 900, H = 210, padL = 62, padR = 18, padT = 16, padB = 40;
      const n = n0 || 1;
      // The peak sets the scale, with a floor so an idle window is a flat line
      // near the bottom rather than noise magnified to full height.
      let peak = 0;
      keys.forEach(k => rows[k].forEach(v => { if (v > peak) { peak = v; } }));
      // Whole requests, so the axis is whole numbers — a scale topping out at
      // 3.5 requests describes nothing that can happen.
      const top = Math.max(peak, 1);
      const x = (i) => padL + (n === 1 ? 0 : (i / (n - 1)) * (W - padL - padR));
      const y = (v) => padT + (1 - v / top) * (H - padT - padB);

      let out = '';
      [0, 0.5, 1].forEach((f) => {
        const v = Math.round(top * f);
        out += '<line x1="' + padL + '" y1="' + y(v) + '" x2="' + (W - padR) + '" y2="' + y(v) +
               '" stroke="var(--line)" stroke-width="1" opacity="0.7"/>' +
               '<text x="' + (padL - 9) + '" y="' + (y(v) + 3.5) + '" text-anchor="end" font-size="11" ' +
               'fill="var(--ink-faint)">' + v + '</text>';
      });

      // Declared before the lines are drawn: the per-bucket dots below read
      // both, and a const used above its declaration is a dead-zone throw.
      const from = USAGE.now - w.secs;
      const bucketLabel = w.bucket >= 86400 ? (w.bucket / 86400) + 'd'
                        : w.bucket >= 3600 ? (w.bucket / 3600) + 'h'
                        : (w.bucket / 60) + 'm';

      keys.forEach((k) => {
        const d = rows[k].map((v, i) => (i ? 'L' : 'M') + x(i) + ' ' + y(v)).join(' ');
        out += '<path d="' + d + '" fill="none" stroke="' + UCOLOR[k] + '" stroke-width="2.2" ' +
               'stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" opacity="0.9">' +
               '<title>' + USAGE.people[k].name + '</title></path>';
        // A dot on every bucket that actually carried traffic, with its count
        // and its clock time — the line alone cannot be read back to "how many,
        // when", which is the whole question this chart answers.
        rows[k].forEach((v, i) => {
          if (!v) { return; }
          out += '<circle cx="' + x(i) + '" cy="' + y(v) + '" r="3" fill="' + UCOLOR[k] +
                 '"><title>' + USAGE.people[k].name + ': ' + v + ' request' + (v === 1 ? '' : 's') +
                 '\n' + fmtF(from + i * w.bucket) + ' · ' + bucketLabel + ' bucket</title></circle>';
        });
      });

      // THE X AXIS, labelled — Sean, 2026-08-23: "add x axis labels to plots".
      // A row of buckets with no clock under it cannot be read back to "when".
      const TICKS = 5;
      for (let i = 0; i < TICKS; i++) {
        const bi = Math.round((n - 1) * (i / (TICKS - 1))), tx = x(bi);
        out += '<line x1="' + tx + '" y1="' + (H - padB + 4) + '" x2="' + tx + '" y2="' + (H - padB + 9) +
               '" stroke="var(--line)" stroke-width="1"/>' +
               '<text x="' + tx + '" y="' + (H - padB + 22) + '" text-anchor="' +
               (i === 0 ? 'start' : i === TICKS - 1 ? 'end' : 'middle') +
               '" font-size="10" fill="var(--ink-faint)">' + fmtF(from + bi * w.bucket) + '</text>';
      }
      out += '<text x="' + ((padL + W - padR) / 2) + '" y="' + (H - 2) +
             '" text-anchor="middle" font-size="9.5" fill="var(--ink-faint)">requests per ' +
             bucketLabel + ' bucket</text>';

      svg.innerHTML = out;
      card.querySelector('.ax-from').textContent = fmtF(from);
      card.querySelector('.ax-to').textContent = fmtF(USAGE.now);
      // The bucket is named, because the number only means anything with it:
      // "peak 6 per 5m" is a fact, "peak 6" is not.
      card.querySelector('.ax-mid').textContent = keys.length
        ? 'peak ' + peak + ' per ' + bucketLabel + ' · ' + n + ' buckets of ' + bucketLabel
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

    /** One number, written into a cell the same way everywhere. */
    function setNum(td, v) {
      td.textContent = v.toLocaleString();
      td.dataset.sort = v;                 // the sorter reads this, not the commas
      td.classList.toggle('zero', v === 0);
    }

    /**
     * EVERYTHING ON THIS TAB, FROM ONE CHOICE. The instance picker and the app
     * picker each used to rewrite their own half of the page, so the halves
     * disagreed: the lane headlines never moved off production, the app pills
     * counted every instance at once, and an app chosen before a poll was
     * silently un-applied when the poll replaced the table.
     *
     * So there is one pass, and every caller is this function. It decides:
     * which rows exist, what every count says, what each headline says, what
     * the pills claim, and what the chart draws.
     */
    function applyUsage() {
      const inst = usageInst(), app = usageApp();

      // The pills: this instance's counts, over three days.
      const appsHere = (USAGE.apps || {})[inst] || {};
      document.querySelectorAll('#app-pick .app-tab').forEach((b) => {
        const n = b.querySelector('.app-n');
        if (!n) { return; }
        const a = b.dataset.app;
        if (a === '*') {
          let t = 0;
          Object.keys(appsHere).forEach(k => { t += appsHere[k]['3d'] || 0; });
          n.textContent = t.toLocaleString();
        } else {
          n.textContent = ((appsHere[a] || {})['3d'] || 0).toLocaleString();
        }
      });

      // The rows: this instance's, with this app's numbers.
      document.querySelectorAll('#tab-usage .usage-table tbody tr[data-key]').forEach((tr) => {
        const p = USAGE.people[tr.dataset.key];
        if (!p) { return; }
        tr.hidden = p.inst !== inst;
        let total = 0;
        tr.querySelectorAll('td.num[data-win]').forEach((td) => {
          const v = personN(p, td.dataset.win, app);
          total += v;
          setNum(td, v);
        });
        tr.classList.toggle('app-zero', total === 0);
        // How many addresses, under this app — the aggregate's one non-count
        // column, and it has to move with the rest or it reads as the total.
        const addr = tr.querySelector('td[data-addr] .addr-n');
        if (addr && p.anon) {
          const n = app === '*' ? p.addresses : ((p.addr_apps || {})[app] || 0);
          addr.textContent = n.toLocaleString();
          addr.parentElement.dataset.sort = n;
        }
        // Its folded-out addresses follow it: same instance, same app, and
        // never on screen while their parent row is off it.
        document.querySelectorAll('#tab-usage tr[data-of="' + CSS.escape(tr.dataset.key) + '"]').forEach((sub) => {
          const open = !tr.hidden && tr.querySelector('.agg-toggle')?.getAttribute('aria-expanded') === 'true';
          const t = (p.top || {})[sub.dataset.addrIp || ''];
          let n = 0;
          sub.querySelectorAll('td.num[data-win]').forEach((td) => {
            const v = !t ? 0 : (app === '*' ? (t.counts[td.dataset.win] || 0) : (((t.apps || {})[app] || {})[td.dataset.win] || 0));
            n += v;
            setNum(td, v);
          });
          sub.classList.toggle('app-zero', n === 0 && !!t);
          sub.hidden = !open;
        });
      });

      // The lane headlines, and the empty state under each of them.
      document.querySelectorAll('#tab-usage .kpi[data-kpi-lane]').forEach((kpi) => {
        const lane = kpi.dataset.kpiLane;
        kpi.querySelector('.n').textContent = usageTotal(lane, inst, '3d', app).toLocaleString();
        const tag = kpi.querySelector('.kpi-app');
        if (tag) { tag.textContent = app === '*' ? '' : app + ' · '; }
      });
      document.querySelectorAll('#tab-usage .table-card').forEach((card) => {
        const any = [...card.querySelectorAll('tbody tr[data-key]')].some(tr => !tr.hidden);
        const none = card.querySelector('.usage-none'), scroll = card.querySelector('.table-scroll');
        if (none) { none.hidden = any; }
        if (scroll) { scroll.hidden = !any; }
      });

      drawUsage();
    }

    document.querySelectorAll('#app-pick .app-tab').forEach(b => b.addEventListener('click', () => {
      document.querySelectorAll('#app-pick .app-tab').forEach(o => o.classList.remove('on'));
      b.classList.add('on');
      applyUsage();
    }));

    // A row's addresses fold out in place. Delegated, because the poller
    // replaces the whole table.
    document.addEventListener('click', (e) => {
      const btn = e.target.closest('.agg-toggle');
      if (!btn) { return; }
      const tr = btn.closest('tr[data-key]');
      const open = btn.getAttribute('aria-expanded') !== 'true';
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      document.querySelectorAll('#tab-usage tr[data-of="' + CSS.escape(tr.dataset.key) + '"]')
        .forEach(sub => { sub.hidden = !open; });
    });
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
  // WHICH INSTANCE the whole page is talking about. Every instance's cell,
  // block and row is in the DOM; this decides which one is on screen.
  //
  // SCOPED TO `.inst-pick`, and that scope is the whole bug this had. The app
  // picker's pills wore the same class, so clicking one ran this with an
  // undefined instance: `b.dataset.inst === undefined` was TRUE for every app
  // pill and false for every instance pill, which lit the entire app row,
  // unlit the instance row, and hid every block that was not marked 'all'.
  function showInstance(inst) {
    document.querySelectorAll('.inst-pick .inst-tab').forEach(b => b.classList.toggle('on', b.dataset.inst === inst));
    document.querySelectorAll('.web-cell').forEach(c => c.classList.toggle('on', c.dataset.inst === inst));
    // Whole blocks that belong to one instance — Live's domain sections,
    // Usage's lane cards. 'all' survives every choice.
    document.querySelectorAll('[data-inst-only]').forEach(el => {
      const want = el.dataset.instOnly;
      el.hidden = !(want === 'all' || want === inst);
    });
    // The Current tab's Deployed / Not deployed split is about THIS instance,
    // so the rows move between the two subsections rather than the headings
    // being left to describe some other instance's deployment.
    document.querySelectorAll('#tab-current .table-card').forEach(card => {
      const secs = { yes: card.querySelector('[data-deploy-sec="yes"]'), no: card.querySelector('[data-deploy-sec="no"]') };
      if (!secs.yes || !secs.no) { return; }
      card.querySelectorAll('tr[data-deploy-at]').forEach(tr => {
        const want = (tr.dataset.deployAt || '').split(' ').includes(inst) ? 'yes' : 'no';
        const body = secs[want].querySelector('tbody');
        if (body && tr.parentElement !== body) { body.appendChild(tr); }
      });
      Object.values(secs).forEach(sec => {
        const n = sec.querySelectorAll('tbody tr').length;
        sec.querySelector('.sec-count').textContent = n;
        sec.hidden = n === 0;
      });
    });
    // Live Status' hit counters carry all three instances' numbers.
    document.querySelectorAll('.kpi[data-hits]').forEach(kpi => {
      let by = {};
      try { by = JSON.parse(kpi.dataset.hits) || {}; } catch (e) { return; }
      const h = by[inst] || { hits: 0, people: 0 };
      kpi.querySelector('.n').textContent = (h.hits || 0).toLocaleString();
      const who = kpi.querySelector('.who');
      if (who) { who.textContent = h.people ? ' · ' + h.people + ' signed in' : ''; }
    });
    if (typeof applyUsage === 'function') { applyUsage(); }
    // History's web line is per instance too, so the charts are redrawn rather
    // than left showing the instance that happened to be selected first.
    if (typeof drawAll === 'function') { drawAll(); }
  }
  document.querySelectorAll('.inst-pick .inst-tab').forEach(b =>
    b.addEventListener('click', () => showInstance(b.dataset.inst)));
  showInstance('prod');

  // The Current tables sort on a header click, same gesture as Live Status.
  // Delegated, because the live poller replaces rows wholesale.
  document.addEventListener('click', (e) => {
    const th = e.target.closest('.table-card th[data-sort-col]');
    if (!th) { return; }
    const table = th.closest('table'), body = table.querySelector('tbody');
    const col = +th.dataset.sortCol;
    const dir = th.classList.contains('sorted-asc') ? -1 : 1;
    table.querySelectorAll('th').forEach(o => o.classList.remove('sorted-asc', 'sorted-desc'));
    th.classList.add(dir === 1 ? 'sorted-asc' : 'sorted-desc');
    /**
     * SORT ON THE VALUE, not on what the value LOOKS like. Reading the cell's
     * text put "1,204" before "749" (a numeric locale compare stops at the
     * comma and sees 1 against 749) and sorted Last seen alphabetically, so
     * April led September. Every cell that means a number now carries one in
     * data-sort, and applyUsage keeps it in step when it rewrites the text.
     */
    const key = (row) => {
      const cell = row.children[col];
      if (!cell) { return ''; }
      const v = cell.dataset.sort ?? cell.textContent.trim();
      const n = Number(v);
      return Number.isFinite(n) && v !== '' ? n : String(v).toLowerCase();
    };
    // A row's folded-out addresses travel with it, and anonymous stays last:
    // it is one bucket standing in for a crowd, and sorting it in among the
    // named accounts scatters the group it exists to hold together.
    const rows = [...body.querySelectorAll('tr[data-key]')];
    const kids = (tr) => [...body.querySelectorAll('tr[data-of="' + CSS.escape(tr.dataset.key || '') + '"]')];
    rows.sort((a, b) => {
      const aggA = a.classList.contains('agg-row'), aggB = b.classList.contains('agg-row');
      if (aggA !== aggB) { return aggA ? 1 : -1; }
      const x = key(a), y = key(b);
      return (x > y ? 1 : x < y ? -1 : 0) * dir;
    }).forEach((tr) => { body.appendChild(tr); kids(tr).forEach(k => body.appendChild(k)); });
  });

  function showTab(name) {
    const panel = document.getElementById('tab-' + name);
    if (!panel) { return false; }
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.toggle('active', b.dataset.tab === name));
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    panel.classList.add('active');
    const ap = document.getElementById('app-pick');
    if (ap) { ap.hidden = name !== 'usage'; }
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
    // The Usage numbers ride in their own data-live region, so a poll brings
    // fresh ones; re-read them before anything is re-applied, or the tables
    // and the chart spend twenty seconds disagreeing about the same minute.
    const fresh = document.getElementById('usage-data');
    if (fresh) {
      try { USAGE = JSON.parse(fresh.textContent); usageColors(); } catch (e) { /* keep the last good payload */ }
    }
    // The rows are replaced wholesale, so the chosen instance has to be
    // re-applied — without this the Web / server column blanks on every poll,
    // and the chosen app was quietly dropped while its pill stayed lit.
    const inst = document.querySelector('.inst-pick .inst-tab.on');
    showInstance(inst ? inst.dataset.inst : 'prod');
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
      // A NEW DEPLOY reloads the tab. The poller patches data-live regions,
      // which covers data moving — it cannot cover the page itself changing
      // shape, and an open tab was quietly missing every new control until
      // somebody thought to refresh.
      const freshVer = (doc.querySelector('.page') || { dataset: {} }).dataset.pageVer;
      const hereVer = (document.querySelector('.page') || { dataset: {} }).dataset.pageVer;
      if (freshVer && hereVer && freshVer !== hereVer) { location.reload(); return; }
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
