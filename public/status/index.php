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

// ------------------------------------------------------------- the repo matrix
/**
 * THE PLATFORM MATRIX, AS DATA — Sean, 2026-08-22: "group MindSuite, Developer
 * (AgentSuite/LocalLLM), and website repos ... make a line plot for each
 * category on history where each line is a repo's web/server status, macos
 * status, windows status, ios status, watchos status, and android status".
 *
 * It was hand-written table markup until then, which meant the Current tab,
 * the per-ping samples and the graphs could each have said something
 * different about the same repo. One array, three readers.
 *
 * Severity: 0 live, 1 deliberate, 2 small issue, 3 needs attention, null =
 * nothing to report (no such target). null is not a bad score — it is the
 * absence of one, and the graphs leave it out rather than plotting a zero.
 */
$PLATFORMS = [
    'web' => 'Web / server', 'macos' => 'macOS', 'windows' => 'Windows',
    'ios' => 'iOS', 'watchos' => 'watchOS', 'android' => 'Android',
];
$REPO_GROUPS = [
    'mindsuite' => ['Mind-Suite', 'The five repos and the apps they ship.'],
    'developer'  => ['Developer', 'Tooling for how the work gets done, not things that ship to a device.'],
    'website'    => ['Website', 'What is served from the seancheren.com account, suite or not.'],
];

/** [severity, label, note] — note optional. */
$repos = [
  ['name' => 'CalMind', 'group' => 'mindsuite', 'tag' => 'origin app',
   'sync' => '<code>seancheren.com/CalMind/api</code> — every client syncs through it. Also on <code>test.</code> and <code>dev.</code>',
   'plat' => [
     'web'     => [0, 'seancheren.com/CalMind'],
     'macos'   => [0, 'Tauri desktop'],
     'windows' => [0, 'CI build'],
     'ios'     => [0, 'on phone', '1 of 3 device slots'],
     'watchos' => [0, 'CalMindWatch', 'installs to paired watch'],
     'android' => [0, 'installs &amp; runs'],
   ]],
  ['name' => 'ChefMind', 'group' => 'mindsuite', 'tag' => 'split from CalMind',
   'sync' => '<code>seancheren.com/CalMind/api</code>, <code>chef</code> space. No backend of its own.',
   'plat' => [
     'web'     => [0, 'seancheren.com/ChefMind'],
     'macos'   => [0, 'installed', '/Applications, verified launching'],
     'windows' => [0, 'CI build'],
     'ios'     => [0, 'on phone', '1 of 3 — reinstalled 08-22'],
     'watchos' => [null, '&mdash;', 'no watch target'],
     'android' => [0, 'installs &amp; runs'],
   ]],
  ['name' => 'AcctMind', 'group' => 'mindsuite', 'tag' => 'separate build',
   'sync' => '<code>seancheren.com/AcctMind</code>, and <code>test.seancheren.com/AcctMind</code>.',
   'plat' => [
     'web'     => [0, 'seancheren.com/AcctMind'],
     'macos'   => [0, 'installed', '/Applications, verified launching'],
     'windows' => [0, 'CI build'],
     'ios'     => [0, 'on phone', '1 of 3 slots'],
     'watchos' => [null, '&mdash;', 'no watch target'],
     'android' => [0, 'installs &amp; runs'],
   ]],
  ['name' => 'MyCalMind', 'group' => 'mindsuite', 'tag' => 'extracted, renamed',
   'sync' => 'Bonjour over the LAN, <code>_calmind-local._tcp</code>. No internet, no backup — the device is the only copy.',
   'plat' => [
     'web'     => [null, 'none'],
     'macos'   => [0, 'installed', 'real Mac Catalyst app, verified running — the ReactNativeDependencies bundle repair is re-applied every build, never fixed upstream'],
     'windows' => [null, '&mdash;', 'no Tauri shell'],
     'ios'     => [1, 'build-only', "deliberate — protects the phone's 3-app cap"],
     'watchos' => [1, 'builds', 'CalMindWatch product — not installed to a watch'],
     'android' => [0, 'installs &amp; runs'],
   ]],
  ['name' => 'CoreMind', 'group' => 'mindsuite', 'tag' => 'shared tooling',
   'sync' => 'None. Distributes source into the other four repos; ships no app, holds no data.',
   'plat' => [
     'web' => [null, 'n/a'], 'macos' => [null, 'n/a'], 'windows' => [null, 'n/a'],
     'ios' => [null, 'n/a'], 'watchos' => [null, 'n/a'], 'android' => [null, 'n/a'],
   ]],

  ['name' => 'AgentSuite', 'group' => 'developer', 'tag' => 'conventions',
   'sync' => 'None. Conventions and skills for AI agents across projects — text, not code.',
   'plat' => [
     'web' => [null, 'none'], 'macos' => [null, '&mdash;'], 'windows' => [null, '&mdash;'],
     'ios' => [null, '&mdash;'], 'watchos' => [null, '&mdash;'], 'android' => [null, '&mdash;'],
   ]],
  ['name' => 'LLMLOCAL', 'group' => 'developer', 'tag' => 'local models',
   'sync' => 'None. Local-model experiments, run on this machine. Python, no deploy lane.',
   'plat' => [
     'web' => [null, 'none'], 'macos' => [null, '&mdash;'], 'windows' => [null, '&mdash;'],
     'ios' => [null, '&mdash;'], 'watchos' => [null, '&mdash;'], 'android' => [null, '&mdash;'],
   ]],

  ['name' => 'seancheren-site', 'group' => 'website', 'tag' => 'the hosting account',
   'sync' => '<code>seancheren.com</code> on NearlyFreeSpeech — the account every Mind-suite app deploys a subpath into. Its own pages are Chat, the bookshelf, the themes bench and this one.',
   'plat' => [
     'web'     => [0, 'seancheren.com'],
     'macos' => [null, '&mdash;'], 'windows' => [null, '&mdash;'],
     'ios' => [null, '&mdash;'], 'watchos' => [null, '&mdash;'], 'android' => [null, '&mdash;'],
   ]],
  ['name' => 'aki-tarot', 'group' => 'website', 'tag' => "Aki's, private repo",
   'sync' => 'Deployed from its own private repo, not from seancheren-site — which is exactly how it stayed out of these checks until 2026-08-22.',
   'plat' => [
     'web'     => [0, 'seancheren.com/akitarot'],
     'macos' => [null, '&mdash;'], 'windows' => [null, '&mdash;'],
     'ios' => [null, '&mdash;'], 'watchos' => [null, '&mdash;'], 'android' => [null, '&mdash;'],
   ]],
];

/** Which live endpoints decide a repo's WEB severity — the one row of the
 *  matrix that is measured rather than recorded. */
$WEB_PROBE = [
    'CalMind'         => ['https://seancheren.com/CalMind/', 'https://seancheren.com/CalMind/api/index.php'],
    'ChefMind'        => ['https://seancheren.com/ChefMind/'],
    'AcctMind'        => ['https://seancheren.com/AcctMind/'],
    'seancheren-site' => ['https://seancheren.com/'],
    'aki-tarot'       => ['https://seancheren.com/akitarot/'],
];

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

/**
 * One sample row per ping: `repo.platform => severity`, for every cell of the
 * matrix that has one. The platform rows are RECORDED rather than measured —
 * they only move when the matrix is edited or a build changes — so their lines
 * are flat by design. Web is the one that is actually probed, and the one that
 * dips.
 */
function status_sample_row(array $repos, array $webProbe, array $endpoints, array $results): array
{
    // Every probed URL's result, flattened, so a repo can ask about its own.
    $byUrl = [];
    foreach ($results as $group => $rows) {
        foreach ($rows as $url => $r) { $byUrl[$url] = $r; }
    }
    $row = ['ts' => time(), 's' => []];
    foreach ($repos as $repo) {
        foreach ($repo['plat'] as $plat => $cell) {
            $sev = $cell[0] ?? null;
            if ($plat === 'web' && isset($webProbe[$repo['name']])) {
                // MEASURED, not recorded: the worst of this repo's own probes.
                // A gated 401 counts as up — the server answered.
                $sev = 0;
                foreach ($webProbe[$repo['name']] as $u) {
                    if (empty($byUrl[$u]['ok'])) { $sev = 3; break; }
                }
            }
            if ($sev === null) { continue; }
            $row['s'][$repo['name'] . '.' . $plat] = (int) $sev;
        }
    }
    return $row;
}

function status_sample_record(array $row): void
{
    global $samplePath;
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
/**
 * DOES THE LOGIN ACTUALLY WORK — not just "the URL answered".
 *
 * Sean, 2026-08-22: "i don't see the point of up - asks to sign in... you
 * should be able to test a login and post if logging in is also up or just the
 * url itself". He is right: a 401 says a server is running, and says nothing
 * about whether anybody can get in. A sign-in that has been broken by a bad
 * config change answers 401 exactly as cheerfully as a healthy one.
 *
 * CREDENTIALS ARE NEVER IN THIS REPO. They come from `status_probes` in
 * lib/config.php, which is gitignored, never deployed, and hand-kept on the
 * server — the same file the site's own accounts live in. With none
 * configured the column says "not probed" rather than anything green:
 * a check that cannot run must never read as a check that passed.
 *
 *   'status_probes' => [
 *     'calmind'  => ['user' => '…', 'pass' => '…'],   // CalMind's API
 *     'acctmind' => ['user' => '…', 'pass' => '…'],   // AcctMind, HTTP Basic
 *     'site'     => ['user' => '…', 'pass' => '…'],   // this site's own login
 *   ],
 *
 * Use a PROBE ACCOUNT, not a real one. It signs in every 45 seconds forever;
 * that belongs to an account whose only job is to prove sign-in works.
 */
function probe_creds(string $key): ?array
{
    $all = app_config()['status_probes'] ?? null;
    if (!is_array($all) || !isset($all[$key])) { return null; }
    $c = $all[$key];
    return (!empty($c['user']) && !empty($c['pass'])) ? ['user' => (string) $c['user'], 'pass' => (string) $c['pass']] : null;
}

/** One HTTP round trip, returning [status, body]. */
function probe_http(string $url, string $method, array $headers, ?string $body): array
{
    $h = "X-Status-Probe: 1\r\n" . implode('', array_map(fn($k, $v) => "$k: $v\r\n", array_keys($headers), $headers));
    $ctx = stream_context_create([
        'http' => ['method' => $method, 'timeout' => 6, 'ignore_errors' => true,
                   'header' => $h, 'follow_location' => 0] + ($body === null ? [] : ['content' => $body]),
        'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $fh = @fopen($url, 'r', false, $ctx);
    if ($fh === false) { return [0, '']; }
    $status = 0;
    foreach (stream_get_meta_data($fh)['wrapper_data'] ?? [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) { $status = (int) $m[1]; }
    }
    $out = (string) @stream_get_contents($fh, 4096);
    fclose($fh);
    return [$status, $out];
}

/**
 * @return array{state:'ok'|'failed'|'skipped', why:string}
 */
function check_login(array $login): array
{
    $creds = probe_creds($login['cred']);
    if ($creds === null) {
        return ['state' => 'skipped', 'why' => "no status_probes['" . $login['cred'] . "'] in config.php"];
    }
    switch ($login['kind']) {
        case 'calmind-api':
            // The real sign-in, the one every client makes.
            [$st, $body] = probe_http($login['url'], 'POST', ['Content-Type' => 'application/json'],
                json_encode(['action' => 'login', 'username' => $creds['user'], 'password' => $creds['pass']]));
            $j = json_decode($body, true);
            if ($st === 200 && !empty($j['ok']) && !empty($j['token'])) {
                return ['state' => 'ok', 'why' => 'signed in, token issued'];
            }
            return ['state' => 'failed', 'why' => 'HTTP ' . $st . ' — ' . substr(strip_tags($body), 0, 80)];

        case 'http-basic':
            [$st] = probe_http($login['url'], 'GET',
                ['Authorization' => 'Basic ' . base64_encode($creds['user'] . ':' . $creds['pass'])], null);
            if ($st >= 200 && $st < 400) { return ['state' => 'ok', 'why' => 'HTTP ' . $st . ' with credentials']; }
            return ['state' => 'failed', 'why' => 'HTTP ' . $st . ' with credentials'];

        case 'site-form':
            // This site's own login answers a good password with a 302 to
            // LOGIN_LANDING and a bad one with the form again, 200.
            [$st] = probe_http($login['url'], 'POST', ['Content-Type' => 'application/x-www-form-urlencoded'],
                http_build_query(['username' => $creds['user'], 'password' => $creds['pass']]));
            if ($st === 302) { return ['state' => 'ok', 'why' => 'password accepted, redirected in']; }
            return ['state' => 'failed', 'why' => 'HTTP ' . $st . ' — a good password redirects'];
    }
    return ['state' => 'skipped', 'why' => 'no probe defined'];
}

$endpoints = [
    'mindsuite' => [
        'seancheren.com' => [
            ['label' => 'CalMind',    'app' => 'CalMind',  'url' => 'https://seancheren.com/CalMind/',
             'scope' => 'CalMind accounts', 'auth' => "CalMind's own accounts — bearer token or passkey, never this site's login"],
            ['label' => 'CalMind API', 'app' => 'CalMind', 'url' => 'https://seancheren.com/CalMind/api/index.php',
             'scope' => 'CalMind accounts',
             'post' => '{"action":"spaces"}',
             'login' => ['kind' => 'calmind-api', 'cred' => 'calmind', 'url' => 'https://seancheren.com/CalMind/api/index.php'],
             'auth' => "CalMind's own bearer token on every action except this one — `spaces` answers without auth, which is what makes it safe to probe from here"],
            ['label' => 'ChefMind',   'app' => 'ChefMind', 'url' => 'https://seancheren.com/ChefMind/',
             'scope' => 'CalMind accounts (borrowed)', 'auth' => "Delegated — no backend of its own; signs in through CalMind's API, same users and tokens, in the dedicated \"chef\" sync space"],
            ['label' => 'AcctMind',   'app' => 'AcctMind', 'url' => 'https://seancheren.com/AcctMind/',
             'scope' => 'AcctMind accounts &middot; HTTP Basic',
             'login' => ['kind' => 'http-basic', 'cred' => 'acctmind', 'url' => 'https://seancheren.com/AcctMind/'],
             'auth' => "AcctMind's own, separate account system, behind HTTP Basic"],
        ],
        'test.seancheren.com' => [
            ['label' => 'CalMind',  'app' => 'CalMind',  'url' => 'https://test.seancheren.com/CalMind/',
             'scope' => 'CalMind accounts (test store)', 'auth' => "CalMind's own accounts, test instance — its own data, its own store"],
            ['label' => 'AcctMind', 'app' => 'AcctMind', 'url' => 'https://test.seancheren.com/AcctMind/',
             'scope' => 'AcctMind accounts &middot; HTTP Basic', 'auth' => "AcctMind's own accounts, test instance, behind HTTP Basic"],
        ],
        'dev.seancheren.com' => [
            ['label' => 'CalMind',  'app' => 'CalMind',  'url' => 'https://dev.seancheren.com/CalMind/',
             'scope' => 'CalMind accounts (dev store)', 'auth' => "CalMind's own accounts, dev instance"],
        ],
    ],
    'site' => [
        'seancheren.com' => [
            ['label' => 'Home',            'app' => 'site', 'url' => 'https://seancheren.com/', 'scope' => 'public',              'auth' => 'Public — no login'],
            ['label' => 'About',           'app' => 'site', 'scope' => 'public', 'url' => 'https://seancheren.com/about/',        'auth' => 'Public — no login'],
            ['label' => 'Contact',         'app' => 'site', 'scope' => 'public', 'url' => 'https://seancheren.com/contact/',      'auth' => 'Public — no login'],
            ['label' => 'Projects',        'app' => 'site', 'scope' => 'public', 'url' => 'https://seancheren.com/projects/',     'auth' => 'Public — no login'],
            ['label' => 'Theme picker',    'app' => 'site', 'scope' => 'public &middot; sets a cookie', 'url' => 'https://seancheren.com/themepicker/',  'auth' => 'Public — sets a cookie, no login'],
            ['label' => 'Chat',            'app' => 'site', 'scope' => 'public &middot; deliberately none', 'url' => 'https://seancheren.com/chat/',         'auth' => 'Public — deliberately no login (see chat/index.php)'],
            ["label" => "Aki's Bookshelf", 'app' => 'site', 'scope' => 'site login &rarr; aki only', 'url' => 'https://seancheren.com/akisbookshelf/',
             'login' => ['kind' => 'site-form', 'cred' => 'site', 'url' => 'https://seancheren.com/akisbookshelf/'],
             'auth' => "Site login (lib/auth.php), then gated to the 'aki' account only"],
            ['label' => 'Themes bench',    'app' => 'site', 'scope' => 'site login', 'url' => 'https://seancheren.com/akisthemes/',   'auth' => 'Site login (lib/auth.php); no per-account gate'],
            ['label' => 'Status',          'app' => 'site', 'url' => 'https://seancheren.com/status/', 'scope' => 'site login &rarr; sean only',       'auth' => "Site login (lib/auth.php), then gated to the 'sean' account only"],
            ['label' => "Aki's Tarot",     'app' => 'site', 'scope' => 'public', 'url' => 'https://seancheren.com/akitarot/',     'auth' => 'Public — no login. Deployed from the private aki-tarot repo, not from seancheren-site'],
        ],
        'test.seancheren.com' => [
            ['label' => 'Home',   'app' => 'site', 'url' => 'https://test.seancheren.com/', 'scope' => 'public &middot; sandbox',       'auth' => 'Public — the sandbox mirror, its own data dir'],
            ['label' => 'Status', 'app' => 'site', 'url' => 'https://test.seancheren.com/status/', 'scope' => 'sandbox login &rarr; sean only', 'auth' => "Sandbox login (lib-test), then the 'sean' gate"],
        ],
        'dev.seancheren.com' => [
            ['label' => 'Home', 'app' => 'site', 'url' => 'https://dev.seancheren.com/', 'scope' => 'public &middot; sandbox', 'auth' => 'Public — the second sandbox slot'],
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
                $r = check_url($ep['url'], $ep['post'] ?? null);
                // The sign-in itself, where one is declared. Cached with the
                // rest, so a probe account signs in once per TTL and not once
                // per page view.
                if (!empty($ep['login'])) { $r['login'] = check_login($ep['login']); }
                $results[$group][$ep['url']] = $r;
            }
        }
    }
    @mkdir(dirname($cachePath), 0700, true);
    @file_put_contents($cachePath, json_encode($results));
    // A FRESH SWEEP IS A PING, and a ping is a sample worth keeping — it is
    // what the History graph draws a line per app from. Recorded only on a
    // real sweep, never on a cache hit, so the samples are spaced by the cache
    // TTL rather than by how often somebody opened the page.
    status_sample_record(status_sample_row($repos, $WEB_PROBE, $endpoints, $results));
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
    display: grid; grid-template-columns: 1.4fr 80px 128px 116px 128px 1.4fr; gap: 14px;
    padding: 14px 18px; border-bottom: 1px solid var(--line); align-items: start; font-size: 0.85rem;
  }
  .endpoint-row:last-child { border-bottom: none; }
  .endpoint-head {
    font-family: var(--font-mono); font-size: 0.68rem; letter-spacing: 0.08em;
    text-transform: uppercase; color: var(--ink-faint); padding-top: 8px; padding-bottom: 8px;
    background: var(--surface-alt);
  }
  .scope-chip {
    display: inline-block; font-family: var(--font-mono); font-size: 0.7rem; line-height: 1.35;
    color: var(--ink-soft); background: var(--surface-alt);
    border: 1px solid var(--line); border-radius: 6px; padding: 3px 7px;
  }

  /* ---------- group + graph heads ---------- */
  .group-head { padding: 16px 18px 12px; border-bottom: 1px solid var(--line); background: var(--surface-alt); }
  .group-head h2 { margin: 0; font-size: 1rem; font-weight: 650; }
  .group-head p { margin: 4px 0 0; font-size: 0.82rem; color: var(--ink-soft); }

  .graph-head { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 10px; }
  .graph-head h2 { margin: 0; font-size: 0.95rem; font-weight: 650; }
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
  .repo-pick-menu .dot { width: 10px; height: 3px; border-radius: 2px; flex: none; }
  .endpoint-url { font-family: var(--font-mono); font-size: 0.78rem; color: var(--ink-faint); word-break: break-all; }
  .endpoint-auth { color: var(--ink-soft); line-height: 1.4; }
  .endpoint-ms { font-family: var(--font-mono); color: var(--ink-faint); font-size: 0.78rem; }

  @media (max-width: 640px) {
    .page { padding: 16px 18px 56px; }
    .endpoint-row { grid-template-columns: 1fr; gap: 6px; }
    .endpoint-head { display: none; }
    .graph-head { flex-direction: column; align-items: flex-start; }
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
            <tr<?= $gkey === 'mindsuite' ? '' : ' class="outside"' ?>>
              <td>
                <span class="repo-name"><?= e($r['name']) ?></span>
                <span class="repo-tag"><?= e($r['tag']) ?></span>
              </td>
              <?php // Web leads, then the prose, then the five device columns —
                    // the same order the matrix stores them in. ?>
              <?php $cell = $r['plat']['web']; ?>
              <td><span class="chip <?= $cell[0] === null ? 'none' : severity_chip_class((int) $cell[0]) ?>"><?= $cell[1] ?></span></td>
              <td class="prose"><?= $r['sync'] ?></td>
              <?php foreach (['macos', 'windows', 'ios', 'watchos', 'android'] as $plat):
                $c = $r['plat'][$plat] ?? [null, '&mdash;']; ?>
                <td>
                  <span class="chip <?= $c[0] === null ? 'none' : severity_chip_class((int) $c[0]) ?>"><?= $c[1] ?></span>
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
      <div class="legend-item"><span class="swatch live"></span> live &amp; verified</div>
      <div class="legend-item"><span class="swatch done"></span> working as intended, deliberately not installed</div>
      <div class="legend-item"><span class="swatch partial"></span> small known issue</div>
      <div class="legend-item"><span class="swatch crit"></span> needs your attention</div>
      <div class="legend-item"><span class="swatch running"></span> a dtp/tdtp is running right now</div>
      <div class="legend-item"><span class="swatch none"></span> none, or not applicable</div>
    </div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>

  </div>


  </div>

  <!-- ============================================================ HISTORY -->
  <div class="tab-panel" id="tab-history">

  <?php
  // ONE PLOT PER CATEGORY, one line per repo-and-platform, x = pings.
  // Sean, 2026-08-22: "make a line plot for each category on history where
  // each line is a repo's web/server status, macos status, windows status,
  // ios status, watchos status, and android status ... make a drop down check
  // box picker for every graph to choose which repos are shown".
  //
  // Only WEB is measured per ping; the device rows are recorded facts, so
  // their lines are flat until a build changes them. That is the honest
  // rendering — a flat line is the claim "nothing moved", and it is the one
  // this page can actually make.
  $samples = status_samples();
  // A colour per repo, and a dash per platform: two keys, so a line says both
  // which repo and which platform without a legend entry per combination.
  $REPO_COLOR = ['CalMind' => '#5fb6ac', 'ChefMind' => '#f0b429', 'AcctMind' => '#8fa3e0',
                 'MyCalMind' => '#d98cc0', 'CoreMind' => '#9c978d',
                 'AgentSuite' => '#5fb6ac', 'LLMLOCAL' => '#f0b429',
                 'seancheren-site' => '#5fb6ac', 'aki-tarot' => '#f0b429'];
  $PLAT_DASH = ['web' => '', 'macos' => '6 3', 'windows' => '2 3',
                'ios' => '10 4', 'watchos' => '1 4', 'android' => '6 3 1 3'];
  ?>
  <?php if (count($samples) < 2): ?>
    <div class="graph-card">
      <div class="graph-empty">
        Not enough pings yet — this needs at least two, and one is recorded
        each time the <?= $cacheTtl ?>s reachability cache expires and a real
        sweep runs. Leave this page open for a couple of minutes.
      </div>
    </div>
  <?php else: ?>
    <?php
    $n = count($samples);
    $firstTs = $samples[0]['ts']; $lastTs = $samples[$n - 1]['ts'];
    foreach ($REPO_GROUPS as $gkey => [$gname, $gdek]):
      $rows = array_values(array_filter($repos, fn($r) => $r['group'] === $gkey));
      // Only repos that actually have a series in the samples; a repo with
      // nothing to plot would be a checkbox that does nothing.
      $plotted = [];
      foreach ($rows as $r) {
          foreach (array_keys($r['plat']) as $plat) {
              if (isset($samples[0]['s'][$r['name'] . '.' . $plat])) { $plotted[$r['name']] = true; break; }
          }
      }
      $gid = 'g' . $gkey;
      // A category with nothing measurable says so. Vanishing would read as a
      // rendering bug, and "these repos ship nothing to watch" is a fact worth
      // stating once rather than a gap to be puzzled over.
      if (!$plotted): ?>
        <div class="graph-card">
          <div class="graph-head"><h2><?= e($gname) ?></h2></div>
          <div class="graph-empty">
            Nothing to plot. These repos ship no server and no app — there is
            no endpoint to ping and no build to report, which is the whole
            point of the category.
          </div>
        </div>
      <?php continue; endif; ?>
      <?php ?>
      <div class="graph-card">
        <div class="graph-head">
          <h2><?= e($gname) ?></h2>
          <details class="repo-pick" data-graph="<?= $gid ?>">
            <summary>Repos <span class="caret">&#9662;</span></summary>
            <div class="repo-pick-menu">
              <?php foreach (array_keys($plotted) as $rn): ?>
                <label>
                  <input type="checkbox" checked data-graph="<?= $gid ?>" value="<?= e($rn) ?>">
                  <span class="dot" style="background:<?= $REPO_COLOR[$rn] ?? '#9c978d' ?>"></span>
                  <?= e($rn) ?>
                </label>
              <?php endforeach; ?>
            </div>
          </details>
        </div>
        <svg viewBox="0 0 640 170" style="width:100%;height:210px" preserveAspectRatio="none">
          <?php
          // Severity 0 at the top, 3 at the bottom, with each series nudged a
          // hair off the others: without it every healthy line draws on top of
          // every other, and "all fine" looks identical to "only one reported".
          // The offset is by PLATFORM, not by a running counter. Six fixed
          // lanes means a repo's macOS line sits at the same height as every
          // other repo's macOS line, so the eye reads rows; a counter spread
          // 22 series over the same 15px and drew a hairy band.
          $platLane = array_flip(array_keys($PLATFORMS));
          foreach ($rows as $r):
            foreach ($PLATFORMS as $plat => $platName):
              $key = $r['name'] . '.' . $plat;
              $pts = [];
              foreach ($samples as $i => $row) {
                  if (!isset($row['s'][$key])) { continue; }
                  $x = 18 + $i * (($n > 1) ? (640 - 36) / ($n - 1) : 0);
                  $y = 18 + ((int) $row['s'][$key] / 3) * (170 - 36) + ($platLane[$plat] - 2.5) * 5;
                  $pts[] = round($x, 1) . ',' . round($y, 1);
              }
              if (count($pts) < 2) { continue; }
          ?>
            <polyline class="series" data-graph="<?= $gid ?>" data-repo="<?= e($r['name']) ?>"
                      points="<?= implode(' ', $pts) ?>" fill="none"
                      stroke="<?= $REPO_COLOR[$r['name']] ?? '#9c978d' ?>"
                      stroke-width="2" stroke-linecap="round"
                      <?php // Without this the viewBox stretch (preserveAspectRatio=none)
                            // scales the dash pattern horizontally too, and six distinct
                            // platform dashes all smear into the same fuzz. ?>
                      vector-effect="non-scaling-stroke"
                      <?= $PLAT_DASH[$plat] !== '' ? 'stroke-dasharray="' . $PLAT_DASH[$plat] . '"' : '' ?>>
              <title><?= e($r['name'] . ' — ' . $platName) ?></title>
            </polyline>
          <?php endforeach; endforeach; ?>
        </svg>
        <div class="graph-axis">
          <span><?= e(date('H:i', $firstTs)) ?></span>
          <span><?= $n ?> pings &middot; top = fine, bottom = needs attention</span>
          <span><?= e(date('H:i', $lastTs)) ?></span>
        </div>
        <div class="legend" style="border-top:none;background:none;padding:10px 0 0">
          <?php foreach ($PLATFORMS as $plat => $platName): ?>
            <div class="legend-item">
              <svg width="26" height="6" style="flex:none"><line x1="0" y1="3" x2="26" y2="3"
                stroke="var(--ink-soft)" stroke-width="2"
                <?= $PLAT_DASH[$plat] !== '' ? 'stroke-dasharray="' . $PLAT_DASH[$plat] . '"' : '' ?> /></svg>
              <?= e($platName) ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
    <script>
      // The pickers. A repo's checkbox hides every line belonging to it, in
      // its own graph only — the same repo can appear in two categories and
      // unticking it in one must not blank the other.
      document.querySelectorAll('.repo-pick input[type=checkbox]').forEach(cb => {
        cb.addEventListener('change', () => {
          document.querySelectorAll(
            `polyline.series[data-graph="${cb.dataset.graph}"][data-repo="${CSS.escape(cb.value)}"]`
          ).forEach(l => { l.style.display = cb.checked ? '' : 'none'; });
        });
      });
      // Tapping outside a picker closes it — a <details> left open covers the
      // graph it belongs to.
      document.addEventListener('click', (e) => {
        document.querySelectorAll('details.repo-pick[open]').forEach(d => {
          if (!d.contains(e.target)) { d.removeAttribute('open'); }
        });
      });
    </script>
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
        <div class="endpoint-row endpoint-head">
          <div>Endpoint</div><div>Status</div><div>Sign-in</div><div>Response</div><div>Auth scope</div><div>How that auth works</div>
        </div>
        <?php foreach ($list as $ep): $r = $results[$key][$ep['url']] ?? ['ok' => false, 'status' => 0, 'ms' => 0]; ?>
          <div class="endpoint-row">
            <div><?= e($ep['label']) ?><div class="endpoint-url"><?= e($ep['url']) ?></div></div>
            <?php // STATUS and AUTH are separate columns now — Sean, 2026-08-22:
                  // "status should be separate from auth on live status". They
                  // answer different questions and a row that ran them together
                  // read as though the auth were the reason for the status. ?>
            <?php // The URL answering, and nothing more. A 401 is UP: the server
                  // replied. Whether anybody can get in is the next column's
                  // question, and conflating the two was the old label's whole
                  // problem. ?>
            <div><span class="chip <?= $r['ok'] ? 'live' : 'crit' ?>"><?= $r['ok'] ? 'up' : 'down' ?></span></div>
            <div><?php
              $lg = $r['login'] ?? null;
              if ($lg === null) { echo '<span class="scope-chip">n/a</span>'; }
              elseif ($lg['state'] === 'ok')      { echo '<span class="chip live" title="' . e($lg['why']) . '">sign-in works</span>'; }
              elseif ($lg['state'] === 'failed')  { echo '<span class="chip crit" title="' . e($lg['why']) . '">sign-in BROKEN</span>'; }
              else { echo '<span class="chip partial" title="' . e($lg['why']) . '">not probed</span>'; }
            ?></div>
            <div class="endpoint-ms"><?= $r['status'] ? $r['status'] . ' &middot; ' . $r['ms'] . 'ms' : '&mdash;' ?></div>
            <div><span class="scope-chip"><?= $ep['scope'] ?? 'unknown' ?></span></div>
            <div class="endpoint-auth"><?= e($ep['auth']) ?></div>
          </div>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>

  <div class="legend">
    <div class="legend-item"><span class="swatch live"></span> <strong>up</strong> — the URL answered. A 401 counts: the server replied.</div>
    <div class="legend-item"><span class="swatch crit"></span> <strong>down</strong> — no answer, or an error</div>
    <div class="legend-item"><span class="swatch live"></span> <strong>sign-in works</strong> — a probe account really signed in just now</div>
    <div class="legend-item"><span class="swatch crit"></span> <strong>sign-in BROKEN</strong> — the URL is up and nobody can get in</div>
    <div class="legend-item"><span class="swatch partial"></span> <strong>not probed</strong> — no probe credentials in <code>lib/config.php</code>; hover for which key is missing</div>
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
