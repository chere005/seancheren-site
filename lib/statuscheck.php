<?php
/**
 * THE STATUS CHECK — the endpoint list, the probes, the repo matrix, and the
 * sweep that produces one sample.
 *
 * Split out of public/status/index.php on 2026-08-23, because the page was the
 * only thing that could run a check. Sean: "i don't see data in history.... it
 * should be populating with data every minute during a dtp or tdtp". It could
 * not: a sample was written only when a signed-in browser loaded the page, so
 * the graph filled in exactly when somebody was already watching and stayed
 * empty the rest of the time — including for the whole of a release, which is
 * the one stretch worth a graph.
 *
 * Now there are two callers of the same code: the page (when somebody looks)
 * and lib/status-sweep.php (a CLI, run on a schedule and once a minute during
 * a dtp). One implementation, so the two can never report different things
 * about the same endpoint.
 *
 * NOT web-reachable — it lives in lib/, like everything else here.
 */

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
    'mindsuite' => ['Mind-Suite', 'Five repos and the apps they ship.'],
    'developer' => ['Developer', 'Tooling. Nothing ships to a device.'],
    'website'   => ['Website', 'Served from the seancheren.com account.'],
];

/**
 * [severity, label, note] — note optional.
 *
 * A DEVICE CELL IS DATED. "verified" on macOS means a bundle sits in
 * /Applications and was seen to launch; on a phone or a watch it means
 * somebody installed it and watched it start, and neither is re-checked by
 * anything automatic. So each carries the day it was last true. A status page
 * may say "this was so on Friday"; it may not imply "this is so now" about a
 * thing nothing has asked since Friday.
 *
 * ONE SHAPE PER CELL: the label says WHAT it is, the note says WHERE. Sean,
 * 2026-08-23, of a macOS cell that ran to three lines of caveat: it "should
 * just say Mac Catalyst.. why doesn't it have a location like the others?" A
 * matrix is read across, and a cell answering a different question from the
 * one beside it cannot be.
 *
 * States, weakest to strongest: `builds` (it compiles), `installed` (it is on
 * the machine), `verified` (on the machine and seen running). They are not
 * synonyms — every cell claiming `verified` has actually been launched.
 *
 * Caveats do not live in cells. The ReactNativeDependencies repair behind
 * MyCalMind's Catalyst build is real and is written where somebody can act on
 * it — CoreMind's AGENTS.md — not in a cell that has to be read forty times.
 */
$repos = [
  ['name' => 'CalMind', 'group' => 'mindsuite', 'tag' => 'origin app',
   'sync' => '<code>seancheren.com/CalMind/api</code> — every client. Also on <code>test.</code>',
   'plat' => [
     'web'     => [0, 'seancheren.com/CalMind'],
     'macos'   => [0, 'desktop app', '/Applications/CalMind.app<br>Tauri shell'],
     'windows' => [0, 'CI build', 'GitHub Actions<br>green Aug 23'],
     'ios'     => [0, 'verified', 'iPhone &middot; 1 of 3 slots<br>Aug 22'],
     'watchos' => [0, 'verified', 'paired Apple Watch<br>Aug 22'],
     'android' => [0, 'verified', 'local emulator<br>Aug 22'],
   ]],
  ['name' => 'ChefMind', 'group' => 'mindsuite', 'tag' => 'split from CalMind',
   'sync' => 'CalMind\'s API, <code>chef</code> space. No backend of its own.',
   'plat' => [
     'web'     => [0, 'seancheren.com/ChefMind'],
     'macos'   => [0, 'desktop app', '/Applications/ChefMind.app<br>Tauri shell'],
     'windows' => [0, 'CI build', 'GitHub Actions<br>green Aug 23'],
     'ios'     => [0, 'verified', 'iPhone &middot; 1 of 3 slots<br>Aug 22'],
     'watchos' => [null, '&mdash;', 'no watch target'],
     'android' => [0, 'verified', 'local emulator<br>Aug 22'],
   ]],
  ['name' => 'AcctMind', 'group' => 'mindsuite', 'tag' => 'separate build',
   'sync' => 'Nothing syncs — the ledger lives in the browser.',
   'plat' => [
     'web'     => [0, 'seancheren.com/AcctMind'],
     'macos'   => [0, 'desktop app', '/Applications/AcctMind.app<br>Tauri shell'],
     'windows' => [0, 'CI build', 'GitHub Actions<br>green Aug 23'],
     'ios'     => [0, 'verified', 'iPhone &middot; 1 of 3 slots<br>Aug 22'],
     'watchos' => [null, '&mdash;', 'no watch target'],
     'android' => [0, 'verified', 'local emulator<br>Aug 22'],
   ]],
  ['name' => 'MyCalMind', 'group' => 'mindsuite', 'tag' => 'extracted, renamed',
   'sync' => 'Bonjour over the LAN — <code>_calmind-local._tcp</code>. No internet, no backup.',
   'plat' => [
     'web'     => [null, 'none'],
     'macos'   => [0, 'desktop app', '/Applications/MyCalMind.app<br>Mac Catalyst'],
     'windows' => [null, '&mdash;', 'no Tauri shell'],
     'ios'     => [1, 'builds', "not installed &mdash; protects the<br>phone's 3-app cap"],
     'watchos' => [1, 'builds', 'not installed to a watch'],
     'android' => [0, 'verified', 'local emulator<br>Aug 22'],
   ]],
  ['name' => 'CoreMind', 'group' => 'mindsuite', 'tag' => 'shared tooling',
   'sync' => 'Nothing syncs — no app, no data.',
   'plat' => [
     'web' => [null, 'n/a'], 'macos' => [null, 'n/a'], 'windows' => [null, 'n/a'],
     'ios' => [null, 'n/a'], 'watchos' => [null, 'n/a'], 'android' => [null, 'n/a'],
   ]],

  ['name' => 'AgentSuite', 'group' => 'developer', 'tag' => 'conventions',
   'sync' => 'Nothing syncs — text, not an app.',
   'plat' => [
     'web' => [null, 'none'], 'macos' => [null, '&mdash;'], 'windows' => [null, '&mdash;'],
     'ios' => [null, '&mdash;'], 'watchos' => [null, '&mdash;'], 'android' => [null, '&mdash;'],
   ]],
  ['name' => 'LLMLOCAL', 'group' => 'developer', 'tag' => 'local models',
   'sync' => 'Nothing syncs — local only.',
   'plat' => [
     'web' => [null, 'none'], 'macos' => [null, '&mdash;'], 'windows' => [null, '&mdash;'],
     'ios' => [null, '&mdash;'], 'watchos' => [null, '&mdash;'], 'android' => [null, '&mdash;'],
   ]],

  ['name' => 'seancheren-site', 'group' => 'website', 'tag' => 'hosting account',
   'sync' => 'Nothing syncs — server-rendered, no client store.',
   'plat' => [
     'web'     => [0, 'seancheren.com'],
     'macos' => [null, '&mdash;'], 'windows' => [null, '&mdash;'],
     'ios' => [null, '&mdash;'], 'watchos' => [null, '&mdash;'], 'android' => [null, '&mdash;'],
   ]],
  ['name' => 'aki-tarot', 'group' => 'website', 'tag' => "Aki's, private",
   'sync' => 'Nothing syncs — server-rendered, no client store.',
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

// ------------------------------------------------------------- status samples
// One row per PING — a fresh reachability sweep — so the History graph can
// draw a line per app rather than one line per release. Sean, 2026-08-22:
// "the linegraph should have a line for each app and its status from the
// current tab during each ping".
$samplePath = is_dir('/home/protected/status')
    ? '/home/protected/status/samples.jsonl'
    : __DIR__ . '/../../data/status-samples.jsonl';
// Changes, not pings — 120 of them is a long history now rather than 90
// minutes of it.
const SAMPLE_KEEP = 120;

/**
 * One sample row per ping: `repo.platform => severity`, for every cell of the
 * matrix that has one. The platform rows are RECORDED rather than measured —
 * they only move when the matrix is edited or a build changes — so their lines
 * are flat by design. Web is the one that is actually probed, and the one that
 * dips.
 */
/**
 * A repo mid-release is its own state, not a severity — Sean, 2026-08-23: the
 * graph "will enter the purple state at the very least once the tdtp is
 * underway". SEV_RUNNING sits above `fine` on the axis because releasing is
 * not a degree of broken; it is a different thing happening.
 */
const SEV_RUNNING = 4;

/**
 * NO SUCH TARGET, recorded rather than skipped — Sean, 2026-08-23: "there
 * needs to be a n/a category in the y axis".
 *
 * A null cell used to be dropped from the sample entirely, which left the
 * graph unable to tell "there is no Windows build of this" from "nothing has
 * been recorded yet", and made a platform that GAINS a target look as though
 * it had always had one. It is a band on the axis, at the bottom, outside the
 * good-to-bad run: not a score, the absence of one.
 */
const SEV_NA = -1;

/**
 * The most recent run, and which repos it is touching.
 *
 * Both readers of this file need it and both used to carry their own copy —
 * the page from history it had already loaded, the CLI from a hand-rolled
 * block. They could disagree, and did: a sweep triggered by an open browser
 * recorded a sample with NO running repos while the CLI's sweep a minute later
 * recorded the same instant as purple. One implementation, so a release looks
 * the same in the history whoever happened to ask.
 */
function status_latest_run(): ?array
{
    foreach (['/home/protected/status/history.json', __DIR__ . '/../data/status-history.json'] as $p) {
        if (!is_file($p)) { continue; }
        $raw = json_decode((string) @file_get_contents($p), true);
        if (!is_array($raw) || !$raw) { continue; }
        usort($raw, fn($a, $b) => strcmp((string) ($b['started_at'] ?? ''), (string) ($a['started_at'] ?? '')));
        return $raw[0];
    }
    return null;
}

/**
 * A run reports the resolved target ("core CalMind ChefMind"), so a release
 * that is not touching MyCalMind does not claim to be. During `tdtp all` that
 * is every repo, which is the case this was asked for.
 */
function status_running_repos(?array $latest): array
{
    if (!$latest || ($latest['status'] ?? '') !== 'running') { return []; }
    $out = [];
    foreach (preg_split('/\s+/', trim((string) ($latest['target'] ?? ''))) as $t) {
        if ($t !== '') { $out[$t === 'core' ? 'CoreMind' : $t] = true; }
    }
    return $out;
}

function status_sample_row(array $repos, array $webProbe, array $endpoints, array $results, array $running = []): array
{
    // Every probed URL's result, flattened, so a repo can ask about its own.
    // $results also carries scalars now (checked_at) and the scopes map, and
    // neither is a group of endpoints — walking them raised a warning on every
    // unattended sweep, which is the kind of noise that trains you to ignore
    // the log it lands in.
    $byUrl = [];
    foreach ($results as $group => $rows) {
        if (!is_array($rows) || $group === 'scopes') { continue; }
        foreach ($rows as $url => $r) { if (is_array($r)) { $byUrl[$url] = $r; } }
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
            // n/a stays n/a through a release: a repo shipping right now does
            // not acquire a watchOS app for the duration of its own dtp.
            if ($sev === null) { $row['s'][$repo['name'] . '.' . $plat] = SEV_NA; continue; }
            // A release in flight overrides whatever the cell would otherwise
            // say: for that stretch the honest answer is "this is being
            // replaced right now", and the old value is not yet false.
            if (isset($running[$repo['name']])) { $sev = SEV_RUNNING; }
            $row['s'][$repo['name'] . '.' . $plat] = (int) $sev;
        }
    }
    return $row;
}

/**
 * Record a sample ONLY WHEN THE STATE CHANGES.
 *
 * Sean, 2026-08-23: "if nothing has changed just update the timestamp on the
 * graph and everytime a state changes add a new entry with the dots on the
 * graph". A point per ping made a graph whose x axis was "how often somebody
 * swept", where an hour of calm and an hour of thrash drew the same width and
 * a change was one indistinguishable step among hundreds. A point per CHANGE
 * makes the x axis time, and every dot on it is an event.
 *
 * Each row carries `ts` (when this state began) and `until` (when it was last
 * confirmed still true). An unchanged sweep moves `until` and nothing else.
 */
function status_sample_record(array $row): void
{
    global $samplePath;
    $now = (int) ($row['ts'] ?? time());
    $lines = @file($samplePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $last = null;
    if ($lines) { $last = json_decode(end($lines), true); }

    if (is_array($last) && ($last['s'] ?? null) == ($row['s'] ?? null)) {
        // Same state: extend it. Rewriting the final line rather than
        // appending is what keeps a week of calm as one row instead of twenty
        // thousand identical ones.
        $last['until'] = $now;
        $lines[count($lines) - 1] = json_encode($last);
    } else {
        $row['until'] = $now;
        $lines[] = json_encode($row);
    }
    if (count($lines) > SAMPLE_KEEP) { $lines = array_slice($lines, -SAMPLE_KEEP); }
    @mkdir(dirname($samplePath), 0770, true);
    @file_put_contents($samplePath, implode("\n", $lines) . "\n", LOCK_EX);
    @chmod($samplePath, 0664);
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
 * THE SCOPES, and the one probe that proves each.
 *
 * Sean, 2026-08-23: "why is CalMind sign-in status n/a? it has an auth scope".
 * Right — the probe was attached to whichever ROW happened to carry it, so
 * CalMind's page said n/a while CalMind's API, behind the same accounts, said
 * it worked. A scope is the unit: it is proven once, and every row that uses
 * it reports that verdict. That is also the honest reading of "which auth
 * scope is used" — rows sharing a scope share its fate, which is exactly the
 * fact worth seeing (ChefMind goes down when CalMind's accounts do).
 */
function auth_scopes(): array
{
    return [
        'calmind' => ['label' => 'CalMind account',
                      'probe' => ['kind' => 'calmind-api', 'cred' => 'calmind',
                                  'url' => 'https://seancheren.com/CalMind/api/index.php']],
        // NO acctmind scope. AcctMind has no accounts of its own — it reuses the
        // suite's sign-in, same store and same session cookie. This page said it
        // had its own system "behind HTTP Basic", inferred from a bare 401; the
        // response carries no WWW-Authenticate at all, it is the site's form
        // answered with a 401 status. Read the headers, not the status code.
        'site' => ['label' => 'site login',
                   'probe' => ['kind' => 'site-form', 'cred' => 'site',
                               'url' => 'https://seancheren.com/akisthemes/']],
        // Not a scope with a sign-in to prove. Saying "none needed" is a real
        // answer; saying n/a is a shrug.
        'public' => ['label' => 'public', 'probe' => null],
    ];
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
             'scope' => 'CalMind', 'scope_key' => 'calmind', 'auth' => "Bearer token or passkey"],
            ['label' => 'CalMind API', 'app' => 'CalMind', 'url' => 'https://seancheren.com/CalMind/api/index.php',
             'scope' => 'CalMind', 'scope_key' => 'calmind',
             'post' => '{"action":"spaces"}',
             'auth' => "Bearer token; the <code>spaces</code> action answers without one"],
            ['label' => 'ChefMind',   'app' => 'ChefMind', 'url' => 'https://seancheren.com/ChefMind/',
             'scope' => 'CalMind', 'scope_key' => 'calmind', 'auth' => "Same users and tokens as CalMind"],
            ['label' => 'AcctMind',   'app' => 'AcctMind', 'url' => 'https://seancheren.com/AcctMind/',
             'scope' => 'site login', 'scope_key' => 'site',
             'auth' => "Site login, reused"],
        ],
        'test.seancheren.com' => [
            ['label' => 'CalMind',  'app' => 'CalMind',  'url' => 'https://test.seancheren.com/CalMind/',
             'scope' => 'CalMind', 'scope_key' => 'calmind', 'auth' => "Bearer token"],
            ['label' => 'AcctMind', 'app' => 'AcctMind', 'url' => 'https://test.seancheren.com/AcctMind/',
             'scope' => 'site login', 'scope_key' => 'site', 'auth' => "Its own account store"],
        ],
    ],
    'site' => [
        'seancheren.com' => [
            ['label' => 'Home',            'app' => 'site', 'url' => 'https://seancheren.com/', 'scope' => 'public', 'scope_key' => 'public',              'auth' => 'Public — no login'],
            ['label' => 'About',           'app' => 'site', 'scope' => 'public', 'scope_key' => 'public', 'url' => 'https://seancheren.com/about/',        'auth' => 'Public — no login'],
            ['label' => 'Contact',         'app' => 'site', 'scope' => 'public', 'scope_key' => 'public', 'url' => 'https://seancheren.com/contact/',      'auth' => 'Public — no login'],
            ['label' => 'Projects',        'app' => 'site', 'scope' => 'public', 'scope_key' => 'public', 'url' => 'https://seancheren.com/projects/',     'auth' => 'Public — no login'],
            ['label' => 'Theme picker',    'app' => 'site', 'scope' => 'public', 'scope_key' => 'public', 'url' => 'https://seancheren.com/themepicker/',  'auth' => 'Public'],
            ['label' => 'Chat',            'app' => 'site', 'scope' => 'public', 'scope_key' => 'public', 'url' => 'https://seancheren.com/chat/',         'auth' => 'Public'],
            ["label" => "Aki's Bookshelf", 'app' => 'site', 'scope' => 'site login &middot; aki only', 'scope_key' => 'site', 'url' => 'https://seancheren.com/akisbookshelf/',
             'auth' => "Site login, then aki only"],
            ['label' => 'Themes bench',    'app' => 'site', 'scope' => 'site login', 'scope_key' => 'site', 'url' => 'https://seancheren.com/akisthemes/',   'auth' => 'Any signed-in account'],
            ['label' => 'Status',          'app' => 'site', 'url' => 'https://seancheren.com/status/', 'scope' => 'site login &middot; sean only', 'scope_key' => 'site',       'auth' => "Site login, then sean only"],
            ['label' => "Aki's Tarot",     'app' => 'site', 'scope' => 'public', 'scope_key' => 'public', 'url' => 'https://seancheren.com/akitarot/',     'auth' => 'Public — no login'],
        ],
        'test.seancheren.com' => [
            ['label' => 'Home',   'app' => 'site', 'url' => 'https://test.seancheren.com/', 'scope' => 'public', 'scope_key' => 'public',       'auth' => 'Public'],
            ['label' => 'Status', 'app' => 'site', 'url' => 'https://test.seancheren.com/status/', 'scope' => 'site login &middot; sean only', 'scope_key' => 'site', 'auth' => "Its own account store, then sean only"],
        ],
    ],
];

$cachePath = is_dir('/home/protected/status')
    ? '/home/protected/status/reachability-cache.json'
    : sys_get_temp_dir() . '/sc-status-reachability.json';
$cacheTtl = 45;
/** Six hours between real sign-in attempts — see the probe block below. */
const LOGIN_TTL = 6 * 3600;
$results = null;
// ?recheck=1 throws the cache away and sweeps now — Sean, 2026-08-23: "run a
// check now across all of status". Without it the only way to force a sweep
// was to wait out the TTL.
/**
 * TWO FORCES, not one. "Check now" on the page means both: sweep the URLs and
 * try the sign-ins. The scheduled CLI means only the first on its 30-minute
 * clock, and both on its 6-hour one — which is the whole point of running it
 * twice at different cadences.
 */
$forceCheck  = isset($_GET['recheck']) || !empty($GLOBALS['STATUS_FORCE_SWEEP']);
$forceLogins = isset($_GET['recheck']) || !empty($GLOBALS['STATUS_FORCE_LOGINS']);
// The last sweep, whatever its age — the reachability half is thrown away on a
// re-sweep, but the sign-in half is carried forward off it.
$prev = is_file($cachePath) ? json_decode((string) file_get_contents($cachePath), true) : null;
if (!is_array($prev)) { $prev = []; }
if (!$forceCheck && is_file($cachePath) && (time() - filemtime($cachePath)) < $cacheTtl) {
    $results = $prev ?: null;
}
if (!is_array($results) || !$results) {
    $results = [];
    foreach ($endpoints as $group => $domains) {
        foreach ($domains as $list) {
            foreach ($list as $ep) {
                $r = check_url($ep['url'], $ep['post'] ?? null);
                $results[$group][$ep['url']] = $r;
            }
        }
    }
    /**
     * SIGN-INS ARE ON A SLOW CLOCK — Sean, 2026-08-23: "check status uptime
     * every 30 mins on status and signing in every 6 hours".
     *
     * They used to ride the reachability sweep, which meant a probe account
     * really authenticated every 45 seconds for as long as the page sat open.
     * A login attempt lands in logs and counts against rate limits; done twice
     * a minute it stops meaning anything and starts being the noise. So the
     * previous verdict is carried forward until it is SIX HOURS old.
     *
     * "Check now" still forces one, because that is what the button is for.
     */
    $lastLogins = (int) ($prev['logins_checked_at'] ?? 0);
    $dueLogins  = $forceLogins || (time() - $lastLogins) >= LOGIN_TTL;
    $probed     = false;
    foreach (auth_scopes() as $key => $sc) {
        if ($sc['probe'] === null) { $results['scopes'][$key] = null; continue; }
        if ($dueLogins) { $results['scopes'][$key] = check_login($sc['probe']); $probed = true; }
        else            { $results['scopes'][$key] = $prev['scopes'][$key] ?? null; }
    }
    // Stamped only when a probe ACTUALLY RAN. Stamping a forced sweep that had
    // no credentials to try would put a time against "not probed", which reads
    // as a probe that came back empty rather than one that never happened.
    if ($probed)          { $results['logins_checked_at'] = time(); }
    elseif ($lastLogins)  { $results['logins_checked_at'] = $lastLogins; }
    // Stamped, so every row can say WHEN it was last actually asked rather
    // than implying it is true right now.
    $results['checked_at'] = time();
    @mkdir(dirname($cachePath), 0700, true);
    @file_put_contents($cachePath, json_encode($results));
    // A FRESH SWEEP IS A PING, and a ping is a sample worth keeping — it is
    // what the History graph draws a line per app from. Recorded only on a
    // real sweep, never on a cache hit, so the samples are spaced by the cache
    // TTL rather than by how often somebody opened the page.
    status_sample_record(status_sample_row($repos, $WEB_PROBE, $endpoints, $results,
                                           status_running_repos(status_latest_run())));
}