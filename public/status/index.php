<?php
// A page served under /test/ (the sandbox mirror) loads lib-test/ instead of lib/, and one
// served under /dev/ (a second, fixed sandbox slot) loads lib-dev/ — each mirror
// isolated in code, config and data. Links stay root-relative — the sandboxes are
// subdomains and .htaccess maps test.seancheren.com/X to /test/X — so nothing here
// prefixes a href. Keep this preamble identical when adding a page.
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
$__dev    = preg_match('#/dev(/|$)#', __DIR__) === 1
         || strncmp($_SERVER['REQUEST_URI'] ?? '', '/dev/', 5) === 0
         || strncmp($__host, 'dev.', 4) === 0;
$__libDir = null;
$__cands  = $__dev
    ? [__DIR__ . '/../../../lib-dev', '/home/protected/lib-dev']
    : ($__test
        ? [__DIR__ . '/../../../lib-test', '/home/protected/lib-test']
        : [__DIR__ . '/../../lib',         '/home/protected/lib']);
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

// ------------------------------------------------------------- hits
// The hit log is written by lib/hitlog.php, which every page on this host
// inherits — see that file's header for why it is separate from usage.log.
require_once $__libDir . '/hitlog.php';
$hitWindows = ['the last hour' => 3600, 'the last 12 hours' => 12 * 3600, 'the last 3 days' => 3 * 86400];
$hits = hit_counts($hitWindows);

// ------------------------------------------------------------- status samples
// One row per PING — a fresh reachability sweep — so the History graph can
// draw a line per app rather than one line per release. Sean, 2026-08-22:
// "the linegraph should have a line for each app and its status from the
// current tab during each ping".
$samplePath = is_dir('/home/protected/status')
    ? '/home/protected/status/samples.jsonl'
    : __DIR__ . '/../../data/status-samples.jsonl';
const SAMPLE_KEEP = 120;   // at a 45s cache TTL, about an hour and a half of pings

/** The apps the graph draws, in the Current tab's order. */
function sample_apps(): array { return ['CalMind', 'ChefMind', 'AcctMind', 'site']; }

/**
 * One app's severity from a sweep: 0 while every endpoint answered, 3 as soon
 * as one did not. A GATED endpoint counts as answering — the server is up and
 * asking for credentials, which is the correct behaviour, not an outage.
 */
function sample_severity(array $endpoints, array $results, string $app): ?int
{
    $seen = false;
    foreach ($endpoints as $group => $domains) {
        foreach ($domains as $list) {
            foreach ($list as $ep) {
                if (($ep['app'] ?? '') !== $app) { continue; }
                $seen = true;
                $r = $results[$group][$ep['url']] ?? null;
                if (!$r || empty($r['ok'])) { return 3; }
            }
        }
    }
    return $seen ? 0 : null;
}

function status_sample_record(array $endpoints, array $results): void
{
    global $samplePath;
    $row = ['ts' => time(), 's' => []];
    foreach (sample_apps() as $app) {
        $sev = sample_severity($endpoints, $results, $app);
        if ($sev !== null) { $row['s'][$app] = $sev; }
    }
    @mkdir(dirname($samplePath), 0700, true);
    @file_put_contents($samplePath, json_encode($row) . "\n", FILE_APPEND | LOCK_EX);
    // Trim in place. JSONL rather than one JSON array precisely so the append
    // is a single cheap write; the trim is the only thing that reads it back.
    $lines = @file($samplePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    if (count($lines) > SAMPLE_KEEP) {
        @file_put_contents($samplePath, implode("\n", array_slice($lines, -SAMPLE_KEEP)) . "\n", LOCK_EX);
    }
    @chmod($samplePath, (((int) @fileperms($samplePath)) & 0777) | 0044);
}

/** Every kept sample, oldest first. */
function status_samples(): array
{
    global $samplePath;
    $out = [];
    foreach (@file($samplePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
        $r = json_decode($l, true);
        if (is_array($r) && isset($r['ts'])) { $out[] = $r; }
    }
    return $out;
}

// ------------------------------------------------------------- live reachability
// Server-side HEAD checks (avoids the CORS mess a client-side fetch() would
// hit on cross-subdomain requests, and gets real HTTP status codes). Cached
// briefly on disk so the 60s auto-refresh below doesn't hammer every
// endpoint on every single page load from every open tab.
function check_url(string $url, ?string $post = null): array
{
    $start = microtime(true);
    // GET, not HEAD: several of these are plain procedural pages that don't
    // handle HEAD cleanly and read as unreachable when they aren't.
    //
    // $post turns it into a real POST. CalMind's API answers 405 to a GET —
    // correctly, it is POST-only — which read as down. Its `spaces` action is
    // the honest probe: the one action that needs no auth, so a 200 here says
    // the API is up AND that it still reports the space ChefMind syncs into.
    // X-Status-Probe is what keeps this page out of its own hit counts.
    // hitlog.php drops any request carrying it — without that, most of the
    // "hits" reported below would be these checks, and the number would go up
    // the more often somebody looked at the page.
    $http = ['method' => $post === null ? 'GET' : 'POST', 'timeout' => 5,
             'ignore_errors' => true, 'header' => "X-Status-Probe: 1\r\n"];
    if ($post !== null) {
        $http['header'] .= "Content-Type: application/json\r\n";
        $http['content'] = $post;
    }
    $ctx = stream_context_create([
        'http' => $http,
        'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $fh = @fopen($url, 'r', false, $ctx);
    $status = 0;
    if ($fh !== false) {
        $meta = stream_get_meta_data($fh);
        foreach ($meta['wrapper_data'] ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) { $status = (int) $m[1]; }
        }
        fclose($fh);
    }
    $ms = (int) round((microtime(true) - $start) * 1000);
    // 401 and 403 are REACHED, not down — the server answered, it just wants
    // credentials this page deliberately does not carry. AcctMind sits behind HTTP
    // Basic and read as unreachable for as long as this only accepted 2xx/3xx.
    $gated = $status === 401 || $status === 403;
    $ok = ($status >= 200 && $status < 400) || $gated;
    return ['ok' => $ok, 'status' => $status, 'ms' => $ms, 'gated' => $gated];
}

/**
 * Every endpoint, in two GROUPS (the Mind-suite apps, and the rest of the
 * site) and within each a DOMAIN — Sean, 2026-08-22: "under the sections for
 * Mind-Suite vs Site, there should be subsections for domains like test etc".
 *
 * The domain is the real distinction the labels were smuggling: "CalMind —
 * test" and "AcctMind — test" are the same instance of the same host, and
 * reading them as two unrelated rows is how a whole sandbox being down looks
 * like two coincidences.
 *
 * `app` names which row of the Current tab an endpoint belongs to, so the
 * History graph can draw one line per app across the pings.
 */
$endpoints = [
    'mindsuite' => [
        'seancheren.com' => [
            ['label' => 'CalMind',    'app' => 'CalMind',  'url' => 'https://seancheren.com/calmind/',
             'auth' => "CalMind's own accounts — bearer token or passkey, never this site's login"],
            ['label' => 'CalMind API', 'app' => 'CalMind', 'url' => 'https://seancheren.com/calmind/api/index.php',
             'post' => '{"action":"spaces"}',
             'auth' => "CalMind's own bearer token on every action except this one — `spaces` answers without auth, which is what makes it safe to probe from here"],
            ['label' => 'ChefMind',   'app' => 'ChefMind', 'url' => 'https://seancheren.com/ChefMind/',
             'auth' => "Delegated — no backend of its own; signs in through CalMind's API, same users and tokens, in the dedicated \"chef\" sync space"],
            ['label' => 'AcctMind',   'app' => 'AcctMind', 'url' => 'https://seancheren.com/AcctMind/',
             'auth' => "AcctMind's own, separate account system, behind HTTP Basic"],
        ],
        'test.seancheren.com' => [
            ['label' => 'CalMind',  'app' => 'CalMind',  'url' => 'https://test.seancheren.com/calmind/',
             'auth' => "CalMind's own accounts, test instance — its own data, its own store"],
            ['label' => 'AcctMind', 'app' => 'AcctMind', 'url' => 'https://test.seancheren.com/AcctMind/',
             'auth' => "AcctMind's own accounts, test instance, behind HTTP Basic"],
        ],
        'dev.seancheren.com' => [
            ['label' => 'CalMind',  'app' => 'CalMind',  'url' => 'https://dev.seancheren.com/calmind/',
             'auth' => "CalMind's own accounts, dev instance"],
        ],
    ],
    'site' => [
        'seancheren.com' => [
            ['label' => 'Home',            'app' => 'site', 'url' => 'https://seancheren.com/',              'auth' => 'Public — no login'],
            ['label' => 'About',           'app' => 'site', 'url' => 'https://seancheren.com/about/',        'auth' => 'Public — no login'],
            ['label' => 'Contact',         'app' => 'site', 'url' => 'https://seancheren.com/contact/',      'auth' => 'Public — no login'],
            ['label' => 'Projects',        'app' => 'site', 'url' => 'https://seancheren.com/projects/',     'auth' => 'Public — no login'],
            ['label' => 'Theme picker',    'app' => 'site', 'url' => 'https://seancheren.com/themepicker/',  'auth' => 'Public — sets a cookie, no login'],
            ['label' => 'Chat',            'app' => 'site', 'url' => 'https://seancheren.com/chat/',         'auth' => 'Public — deliberately no login (see chat/index.php)'],
            ["label" => "Aki's Bookshelf", 'app' => 'site', 'url' => 'https://seancheren.com/akisbookshelf/','auth' => "Site login (lib/auth.php), then gated to the 'aki' account only"],
            ['label' => 'Themes bench',    'app' => 'site', 'url' => 'https://seancheren.com/akisthemes/',   'auth' => 'Site login (lib/auth.php); no per-account gate'],
            ['label' => 'Status',          'app' => 'site', 'url' => 'https://seancheren.com/status/',       'auth' => "Site login (lib/auth.php), then gated to the 'sean' account only"],
            ['label' => "Aki's Tarot",     'app' => 'site', 'url' => 'https://seancheren.com/akitarot/',     'auth' => 'Public — no login. Deployed from the private aki-tarot repo, not from seancheren-site'],
        ],
        'test.seancheren.com' => [
            ['label' => 'Home',   'app' => 'site', 'url' => 'https://test.seancheren.com/',       'auth' => 'Public — the sandbox mirror, its own data dir'],
            ['label' => 'Status', 'app' => 'site', 'url' => 'https://test.seancheren.com/status/', 'auth' => "Sandbox login (lib-test), then the 'sean' gate"],
        ],
        'dev.seancheren.com' => [
            ['label' => 'Home', 'app' => 'site', 'url' => 'https://dev.seancheren.com/', 'auth' => 'Public — the second sandbox slot'],
        ],
    ],
];

$cachePath = is_dir('/home/protected/status')
    ? '/home/protected/status/reachability-cache.json'
    : sys_get_temp_dir() . '/sc-status-reachability.json';
$cacheTtl = 45;
$results = null;
if (is_file($cachePath) && (time() - filemtime($cachePath)) < $cacheTtl) {
    $results = json_decode((string) file_get_contents($cachePath), true);
}
if (!is_array($results)) {
    $results = [];
    foreach ($endpoints as $group => $domains) {
        foreach ($domains as $list) {
            foreach ($list as $ep) {
                $results[$group][$ep['url']] = check_url($ep['url'], $ep['post'] ?? null);
            }
        }
    }
    @mkdir(dirname($cachePath), 0700, true);
    @file_put_contents($cachePath, json_encode($results));
    // A FRESH SWEEP IS A PING, and a ping is a sample worth keeping — it is
    // what the History graph draws a line per app from. Recorded only on a
    // real sweep, never on a cache hit, so the samples are spaced by the cache
    // TTL rather than by how often somebody opened the page.
    status_sample_record($endpoints, $results);
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
    --done: #4f9c6e;
    --done-bg: #eaf6ee;
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
      --done: #7dc79a;
      --done-bg: #1a2e22;
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
  .eyebrow-row a:hover { color: var(--ink-soft); text-decoration: underline; }

  .eyebrow {
    font-family: var(--font-mono);
    font-size: 0.78rem;
    font-weight: 500;
    letter-spacing: 0.14em;
    text-transform: uppercase;
    color: var(--accent);
  }

  h1 {
    margin: 0;
    font-size: clamp(1.9rem, 3vw, 2.5rem);
    font-weight: 650;
    letter-spacing: -0.01em;
    text-wrap: balance;
  }

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

  .cell-note { display: block; margin-top: 5px; font-size: 0.74rem; color: var(--ink-faint); line-height: 1.4; }

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
  .swatch.running { background: var(--running); animation: pulse 1.4s ease-in-out infinite; }
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

  .run-detail { background: var(--surface); border: 1px solid var(--line); border-radius: 12px; padding: 20px 24px; font-size: 0.88rem; color: var(--ink-soft); }
  .run-detail strong { color: var(--ink); }

  /* ---------- live status tab ---------- */

  .endpoint-group { background: var(--surface); border: 1px solid var(--line); border-radius: 12px; overflow: hidden; }
  .endpoint-group h2 {
    margin: 0; padding: 14px 18px; font-size: 0.95rem; font-weight: 650;
    background: var(--surface-alt); border-bottom: 1px solid var(--line);
  }
  .endpoint-row {
    display: grid; grid-template-columns: 1fr 110px 90px 2fr; gap: 16px;
    padding: 14px 18px; border-bottom: 1px solid var(--line); align-items: start; font-size: 0.85rem;
  }
  .endpoint-row:last-child { border-bottom: none; }
  .endpoint-url { font-family: var(--font-mono); font-size: 0.78rem; color: var(--ink-faint); word-break: break-all; }
  .endpoint-auth { color: var(--ink-soft); line-height: 1.4; }
  .endpoint-ms { font-family: var(--font-mono); color: var(--ink-faint); font-size: 0.78rem; }

  @media (max-width: 640px) {
    .page { padding: 16px 18px 56px; }
    .endpoint-row { grid-template-columns: 1fr; gap: 6px; }
  }
</style>
</head>
<body>

<div class="page">

  <header>
    <div class="eyebrow-row">
      <div class="eyebrow">Mind-Suite &middot; deploy &amp; sync status</div>
      <a href="?logout">Log out</a>
    </div>
    <h1>Five repos, five platforms, two ways of syncing</h1>
    <p class="dek">
      CalMind, ChefMind, AcctMind and MyCalMind, plus CoreMind's shared
      tooling behind all of them.
      <?php // Derived, never typed. A hardcoded date on a status page is wrong the
            // day after it is written, and wrong in the one way nobody checks. ?>
      <strong><?= $isRunning
        ? 'A dtp/tdtp is running right now.'
        : ($latest && !empty($latest['finished_at'])
            ? 'Last release: ' . e($latest['finished_at']) . '.'
            : 'No release recorded yet.') ?></strong>
    </p>
  </header>

  <div class="tabs">
    <button class="tab-btn active" data-tab="current">Current</button>
    <button class="tab-btn" data-tab="history">History</button>
    <button class="tab-btn" data-tab="live">Live Status</button>
  </div>

  <!-- ============================================================ CURRENT -->
  <div class="tab-panel active" id="tab-current">

  <div class="kpis">
    <div class="kpi"><span class="n">5 + 1</span><span class="l">repos in the suite, plus the legacy site shell</span></div>
    <div class="kpi"><span class="n">3</span><span class="l">apps syncing through a server</span></div>
    <div class="kpi"><span class="n">1</span><span class="l">app syncing local-only, via Bonjour</span></div>
    <div class="kpi"><span class="n">4 / 4</span><span class="l">apps building &amp; running on Android</span></div>
    <div class="kpi"><span class="n">3 / 3</span><span class="l">phone slots spent (free-tier cap)</span></div>
  </div>

  <div class="table-card">
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
          <tr>
            <td><span class="repo-name">CalMind</span><span class="repo-tag">origin app</span></td>
            <td><span class="chip live">seancheren.com/calmind</span></td>
            <td class="prose"><code>seancheren.com/calmind/api</code> — every client syncs through it. Also on <code>test.</code> and <code>dev.</code></td>
            <td><span class="chip live">Tauri desktop</span></td>
            <td><span class="chip live">CI build</span></td>
            <td><span class="chip live">on phone</span><span class="cell-note">1 of 3 device slots</span></td>
            <td><span class="chip live">CalMindWatch</span><span class="cell-note">installs to paired watch</span></td>
            <td><span class="chip live">installs &amp; runs</span></td>
          </tr>
          <tr>
            <td><span class="repo-name">ChefMind</span><span class="repo-tag">split from CalMind</span></td>
            <td><span class="chip live">seancheren.com/ChefMind</span></td>
            <td class="prose"><code>seancheren.com/calmind/api</code>, <code>chef</code> space. No backend of its own.</td>
            <td><span class="chip live">installed today</span><span class="cell-note">/Applications, verified launching</span></td>
            <td><span class="chip live">CI build</span></td>
            <td><span class="chip live">on phone</span><span class="cell-note">1 of 3 — reinstalled 08-22</span></td>
            <td><span class="chip none">&mdash;</span><span class="cell-note">no watch target</span></td>
            <td><span class="chip live">installs &amp; runs</span></td>
          </tr>
          <tr>
            <td><span class="repo-name">AcctMind</span><span class="repo-tag">separate build</span></td>
            <td><span class="chip live">seancheren.com/AcctMind</span></td>
            <td class="prose"><code>seancheren.com/AcctMind</code>, and <code>test.seancheren.com/AcctMind</code>.</td>
            <td><span class="chip live">installed</span><span class="cell-note">/Applications, verified launching</span></td>
            <td><span class="chip live">CI build</span></td>
            <td><span class="chip live">on phone</span><span class="cell-note">1 of 3 slots</span></td>
            <td><span class="chip none">&mdash;</span><span class="cell-note">no watch target</span></td>
            <td><span class="chip live">installs &amp; runs</span></td>
          </tr>
          <tr>
            <td><span class="repo-name">MyCalMind</span><span class="repo-tag">extracted, renamed</span></td>
            <td><span class="chip none">none</span></td>
            <td class="prose">Bonjour over the LAN, <code>_calmind-local._tcp</code>. No internet, no backup — the device is the only copy.</td>
            <td><span class="chip live">installed</span><span class="cell-note">real Mac Catalyst app, verified running &mdash; one fix (ReactNativeDependencies bundle repair) not yet durable across a fresh prebuild</span></td>
            <td><span class="chip none">&mdash;</span><span class="cell-note">no Tauri shell</span></td>
            <td><span class="chip done">build-only</span><span class="cell-note">deliberate — protects the phone's 3-app cap</span></td>
            <td><span class="chip done">builds</span><span class="cell-note">CalMindWatch product — not installed to a watch</span></td>
            <td><span class="chip live">installs &amp; runs</span></td>
          </tr>
          <tr>
            <td><span class="repo-name">CoreMind</span><span class="repo-tag">shared tooling</span></td>
            <td><span class="chip none">n/a</span></td>
            <td class="prose">None. Distributes source into the other four repos; ships no app, holds no data.</td>
            <td><span class="chip none">n/a</span></td><td><span class="chip none">n/a</span></td><td><span class="chip none">n/a</span></td>
            <td><span class="chip none">n/a</span></td><td><span class="chip none">n/a</span></td>
          </tr>
          <tr class="outside">
            <td><span class="repo-name">AgentSuite</span><span class="repo-tag">not one of the five</span></td>
            <td><span class="chip none">none</span></td>
            <td class="prose">None. Conventions and skills for AI agents across projects — text, not code.</td>
            <td colspan="5" class="prose">No app and no build. A project uses it by importing its <code>AGENTS.md</code> or copying a skill down.</td>
          </tr>
          <tr class="outside">
            <td><span class="repo-name">LLMLOCAL</span><span class="repo-tag">not one of the five</span></td>
            <td><span class="chip none">none</span></td>
            <td class="prose">None. Local-model experiments, run on this machine.</td>
            <td colspan="5" class="prose">Python, no deploy and no release lane. Last touched 2026-03-20.</td>
          </tr>
          <tr class="outside">
            <td><span class="repo-name">aki-tarot</span><span class="repo-tag">not one of the five</span></td>
            <td><span class="chip legacy">seancheren.com/akitarot</span></td>
            <td class="prose"><code>seancheren.com/akitarot</code> — live, and deployed from its own private repo rather than from seancheren-site.</td>
            <td colspan="5" class="prose">Aki's, not the suite's. Listed because it is SERVED from this host and was missing from these checks entirely until 2026-08-22.</td>
          </tr>
          <tr class="outside">
            <td><span class="repo-name">seancheren-site</span><span class="repo-tag">not one of the five</span></td>
            <td><span class="chip legacy">hosting shell</span></td>
            <td class="prose"><code>seancheren.com</code> on NearlyFreeSpeech — the account every Mind-suite app deploys a subpath into. Its own pages are Chat and Aki's Bookshelf.</td>
            <td colspan="5" class="prose">Has its own separate, legacy native iOS/watchOS/Android apps (SwiftUI, local-only) — entirely outside this suite's tooling and this table's scope.</td>
          </tr>
        </tbody>
      </table>
    </div>
    <div class="legend">
      <div class="legend-item"><span class="swatch live"></span> live &amp; verified</div>
      <div class="legend-item"><span class="swatch done"></span> working as intended, deliberately not installed</div>
      <div class="legend-item"><span class="swatch partial"></span> small known issue</div>
      <div class="legend-item"><span class="swatch crit"></span> needs your attention</div>
      <div class="legend-item"><span class="swatch running"></span> a dtp/tdtp is running right now</div>
      <div class="legend-item"><span class="swatch none"></span> none, or not applicable</div>
      <div class="legend-item"><span class="swatch legacy"></span> outside the five-repo suite</div>
    </div>
  </div>


  </div>

  <!-- ============================================================ HISTORY -->
  <div class="tab-panel" id="tab-history">

  <?php
  // THE GRAPH IS THE PINGS, not the releases. Sean, 2026-08-22: "the linegraph
  // should have a line for each app and its status from the current tab during
  // each ping". One polyline per app, sampled every time the reachability cache
  // expires and a real sweep runs — so the x axis is time as actually observed,
  // and a dip is a moment something answered badly rather than a release.
  $samples = status_samples();
  $APP_COLOR = ['CalMind' => 'var(--live)', 'ChefMind' => 'var(--gold, #f0b429)',
                'AcctMind' => 'var(--accent-soft-ink)', 'site' => 'var(--ink-soft)'];
  ?>
  <?php if (count($samples) < 2): ?>
    <div class="graph-card">
      <div class="graph-empty">
        Not enough pings yet — this graph needs at least two, and one is
        recorded each time the <?= $cacheTtl ?>s reachability cache expires and a
        real sweep runs. Leave this page open for a couple of minutes.
      </div>
    </div>
  <?php else: ?>
    <div class="graph-card">
      <svg viewBox="0 0 640 170" style="width:100%;height:200px" preserveAspectRatio="none">
        <?php
        $n = count($samples);
        $w = 640; $h = 170; $pad = 18;
        $stepX = $n > 1 ? ($w - 2 * $pad) / ($n - 1) : 0;
        // Severity 0 at the top, 3 at the bottom. Each app's line is nudged a
        // hair off the others so four healthy apps do not draw as one line —
        // without it "everything is fine" and "only CalMind reported" look the
        // same, which is the one thing this graph must never do.
        $lane = 0;
        foreach (sample_apps() as $app):
            $pts = [];
            foreach ($samples as $i => $row) {
                if (!isset($row['s'][$app])) { continue; }
                $sev = (int) $row['s'][$app];
                $x = $pad + $i * $stepX;
                $y = $pad + ($sev / 3) * ($h - 2 * $pad) + ($lane - 1.5) * 3;
                $pts[] = round($x, 1) . ',' . round($y, 1);
            }
            $lane++;
            if (count($pts) < 2) { continue; }
        ?>
          <polyline points="<?= implode(' ', $pts) ?>" fill="none"
                    stroke="<?= $APP_COLOR[$app] ?? 'var(--ink-soft)' ?>"
                    stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />
        <?php endforeach; ?>
      </svg>
      <div style="display:flex;justify-content:space-between;font-family:var(--font-mono);font-size:0.72rem;color:var(--ink-faint);margin-top:4px">
        <span><?= e(date('H:i', $samples[0]['ts'])) ?></span>
        <span><?= $n ?> pings &middot; top = all up, bottom = something down</span>
        <span><?= e(date('H:i', $samples[$n - 1]['ts'])) ?></span>
      </div>
      <div class="legend" style="border-top:none;background:none;padding:10px 0 0">
        <?php foreach (sample_apps() as $app): ?>
          <div class="legend-item">
            <span class="swatch" style="background:<?= $APP_COLOR[$app] ?? 'var(--ink-soft)' ?>;border-radius:2px;width:14px;height:3px"></span>
            <?= e($app === 'site' ? 'the site' : $app) ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <?php if (empty($history)): ?>
    <div class="graph-card">
      <div class="graph-empty">
        No dtp/tdtp runs recorded yet. The next <code>dtp</code> or
        <code>tdtp</code> from CoreMind's <code>bin/dtp.sh</code> reports here,
        and the last 5 show up as buttons below.
      </div>
    </div>
  <?php else: ?>
    <div class="run-buttons">
      <?php foreach ($history as $i => $run):
        $sev = (int) ($run['severity'] ?? 3);
        $isRun = ($run['status'] ?? '') === 'running';
        $cls = $isRun ? 'running' : severity_chip_class($sev);
      ?>
        <button class="run-btn<?= $i === 0 ? ' selected' : '' ?>" data-run="<?= $i ?>">
          <span class="t"><?= e($run['started_at'] ?? '?') ?></span>
          <?php // The separator is OUTSIDE e(): escaping '&middot;' turns its own
                // ampersand into &amp; and the button reads a literal "&middot;". ?>
          <span class="k"><?= e($run['kind'] ?? 'dtp') ?> &middot; <?= e($run['target'] ?? '?') ?></span>
          <span class="chip <?= $cls ?>"><?= e($isRun ? 'running' : ($run['status'] ?? '?')) ?></span>
        </button>
      <?php endforeach; ?>
    </div>

    <div class="run-detail" id="run-detail-box">
      <?php $r0 = $history[0]; ?>
      <p><strong><?= e($r0['kind'] ?? 'dtp') ?> &middot; <?= e($r0['target'] ?? '?') ?></strong> &mdash; started <?= e($r0['started_at'] ?? '?') ?><?= !empty($r0['finished_at']) ? ', finished ' . e($r0['finished_at']) : ' (running)' ?></p>
      <p><?= e($r0['summary'] ?? 'No summary recorded.') ?></p>
    </div>

    <script>
      const runs = <?= json_encode(array_map(function ($r) {
          return [
              'started_at' => $r['started_at'] ?? '?',
              'finished_at' => $r['finished_at'] ?? null,
              'kind' => $r['kind'] ?? 'dtp',
              'target' => $r['target'] ?? '?',
              'status' => $r['status'] ?? '?',
              'summary' => $r['summary'] ?? 'No summary recorded.',
          ];
      }, $history)) ?>;
      document.querySelectorAll('.run-btn').forEach(btn => {
        btn.addEventListener('click', () => {
          document.querySelectorAll('.run-btn').forEach(b => b.classList.remove('selected'));
          btn.classList.add('selected');
          const r = runs[+btn.dataset.run];
          const box = document.getElementById('run-detail-box');
          const when = r.finished_at ? `, finished ${r.finished_at}` : ' (running)';
          box.innerHTML = `<p><strong>${r.kind} &middot; ${r.target}</strong> — started ${r.started_at}${when}</p><p>${r.summary}</p>`;
        });
      });
    </script>
  <?php endif; ?>

  </div>

  <!-- ============================================================ LIVE STATUS -->
  <div class="tab-panel" id="tab-live">

  <p class="dek">Server-side reachability checks, cached <?= $cacheTtl ?>s. The page itself refreshes every 60s.</p>

  <?php // Hits, from lib/hitlog.php — every page on this host writes one line
        // per request into one log, so this counts the whole site rather than
        // whichever app happened to have logging wired up. ?>
  <div class="kpis" style="margin-bottom:22px">
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
        <?php foreach ($list as $ep): $r = $results[$key][$ep['url']] ?? ['ok' => false, 'status' => 0, 'ms' => 0]; ?>
          <div class="endpoint-row">
            <div><?= e($ep['label']) ?><div class="endpoint-url"><?= e($ep['url']) ?></div></div>
            <div><span class="chip <?= !$r['ok'] ? 'crit' : (!empty($r['gated']) ? 'done' : 'live') ?>"><?=
              !$r['ok'] ? 'down' : (!empty($r['gated']) ? 'up &middot; asks to sign in' : 'up') ?></span></div>
            <div class="endpoint-ms"><?= $r['status'] ? $r['status'] . ' &middot; ' . $r['ms'] . 'ms' : '&mdash;' ?></div>
            <div class="endpoint-auth"><?= e($ep['auth']) ?></div>
          </div>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>

  <div class="legend">
    <div class="legend-item"><span class="swatch live"></span> <strong>up</strong> — answered 2xx or 3xx</div>
    <div class="legend-item"><span class="swatch done"></span> <strong>up &middot; asks to sign in</strong> — answered 401/403, so the server is running and wants credentials this page deliberately does not carry. Not an outage.</div>
    <div class="legend-item"><span class="swatch crit"></span> <strong>down</strong> — no answer, or an error</div>
  </div>

  </div>

</div>

<script>
  document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
      document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
      btn.classList.add('active');
      document.getElementById('tab-' + btn.dataset.tab).classList.add('active');
    });
  });
  setTimeout(() => location.reload(), 60000);
</script>

</body>
</html>
