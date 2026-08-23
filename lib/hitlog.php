<?php
/**
 * THE HIT LOG — one line per request, for every app and site on this host.
 *
 * Sean, 2026-08-22: "show how many hits to the site in the last hour, 12
 * hours, and 3 days .. make sure logging is a core mechanism that all apps and
 * sites inherit".
 *
 * This is NOT `usagelog.php`. That one records authenticated POSTs — what
 * somebody DID — and deliberately skips GETs, because a widget polling every
 * few minutes would drown it. This records every request that renders
 * something, which is what "hits" means, and holds far less per line.
 *
 * INHERITED, NOT WIRED UP. The hook is a single `hit_log()` call in the two
 * files every page on this site already loads — `lib/site.php` for the public
 * pages, `lib/auth.php` for the ones behind the login. A new page inherits it
 * by existing. The same file is copied into CalMind's and AcctMind's server
 * libs from CoreMind canon, so those apps log the same shape into the same
 * file; the `app` and `instance` fields are what keep them apart.
 *
 * ONE LOG FOR THE WHOLE HOST: /home/protected/logs/hits.log. Every app's PHP
 * runs as the `web` user, so they can all append to one file, and one file is
 * what makes "how many hits to the site" answerable at all — three logs in
 * three data dirs would need the reader to know about all three.
 *
 * WHAT IT DOES NOT HOLD. No IP address, no path, no query string, no referer,
 * no user agent. A hit counter needs none of them, and the difference between
 * "how busy is this" and "who went where" is the whole reason to write the
 * narrower thing. `usage.log` already carries IPs for the security question;
 * this one answers a different question and should not be a second copy.
 */

/** Where every instance's hits land. One file, host-wide. */
function hit_log_path(): string
{
    $shared = '/home/protected/logs';
    if (is_dir($shared) || @mkdir($shared, 0770, true)) {
        return $shared . '/hits.log';
    }
    // Local dev, and the test run. app_config()'s data_dir honours
    // SUITE_DATA_DIR, which is what the harness points at a scratch directory
    // — without going through it, a test run would write hits into the repo's
    // own data/, and "a test run cannot touch data/" is a rule this repo
    // states out loud.
    $dir = function_exists('app_config') ? (string) (app_config()['data_dir'] ?? '') : '';
    if ($dir === '') { $dir = dirname(__DIR__) . '/data'; }
    if (!is_dir($dir)) { @mkdir($dir, 0700, true); }
    return rtrim($dir, '/') . '/hits.log';
}

const HIT_LOG_MAX = 4 * 1024 * 1024;   // one rotation, so it cannot grow forever

/**
 * Which instance served this — 'prod' for production, 'test' for the sandbox. Read from the config the page already loaded, so it cannot
 * disagree with which lib/ is actually in play.
 */
function hit_instance(): string
{
    $b = function_exists('suite_base') ? trim(suite_base(), '/') : '';
    return $b === '' ? 'prod' : $b;
}

/**
 * Which app the request hit. Deliberately COARSE — the first path segment and
 * nothing below it, so the log holds "bookshelf" and never which book.
 */
function hit_app(): string
{
    $p = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    // Strip the instance prefix so /test/chat and /chat are one app.
    $p = preg_replace('#^/test(?=/|$)#', '', $p) ?? $p;
    $first = strtok(trim($p, '/'), '/');
    if ($first === false || $first === '') { return 'home'; }
    // One clean token, capped — the segment reaches a log line, and a crafted
    // path must not be able to smuggle a tab, a newline or a fake record in.
    return substr(preg_replace('/[^A-Za-z0-9._-]/', '_', $first), 0, 32);
}

/**
 * IS THIS A ROBOT, and specifically is it Claude's — Sean, 2026-08-23:
 * "separate all your traffic and make it called claudio in usage".
 *
 * Agent traffic is real traffic and belongs in the log, but counting a session
 * of shell probes as "somebody visited the site" makes every other number
 * meaningless. So it gets its own lane.
 *
 * A BOOLEAN, NOT THE USER AGENT. This file says out loud that it stores no
 * user agent, and it still does not: the string is tested and thrown away, and
 * what reaches disk is one of two tokens. The explicit header is the reliable
 * signal; the command-line clients are the fallback for a request that forgot
 * to send it. Browser-shaped agents never match, so a real visitor cannot be
 * filed here by accident.
 */
function hit_agent(): string
{
    if (!empty($_SERVER['HTTP_X_CLAUDIO'])) { return 'claudio'; }
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    return preg_match('#^(curl|Wget|python-requests|HTTPie|node-fetch|Go-http-client)/#i', $ua) ? 'claudio' : '-';
}

/**
 * Append one hit. Safe to call from anywhere, cheap, and never fatal: a log
 * that cannot be written must not take a page down with it.
 *
 * `$app` overrides the derived name, for a caller that knows better than the
 * URL does (CalMind's API, whose path is /calmind/api but whose app is the
 * API). `$user` likewise, for a page that has one and no session helper.
 */
function hit_log(?string $app = null, ?string $user = null): void
{
    // THE PAGE MUST NOT COUNT ITSELF. The status page probes every endpoint on
    // this host every 45 seconds; without this, most of the "hits" it reported
    // would be its own, and the number would rise the more often it was looked
    // at. The probe announces itself with this header.
    if (!empty($_SERVER['HTTP_X_STATUS_PROBE'])) { return; }
    // Nor a live poll. The status page re-fetches itself every 20s to update
    // in place; a tab left open would otherwise report 180 visits an hour on
    // its own and drown every real one.
    if (!empty($_SERVER['HTTP_X_LIVE_POLL'])) { return; }
    // Nor should a preflight or a HEAD: neither renders anything.
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'OPTIONS' || $method === 'HEAD') { return; }

    $who = $user;
    if ($who === null && function_exists('current_user')) { $who = current_user(); }
    /**
     * A SIGNED-IN VISITOR ON A PUBLIC PAGE IS STILL THAT PERSON.
     *
     * Sean, 2026-08-23: "i want to see actual usernames, not (signed out)".
     * current_user() reads $_SESSION, and a public page never starts a session
     * — so every visit Sean made to the home page, Chat or the tarot logged as
     * anonymous, and the Usage tab filed his own browsing under "other
     * people". The name was known; nobody had opened the envelope.
     *
     * READ AND CLOSE, and only when a session cookie is actually present: this
     * must not create a session for a genuine stranger, must not hold a lock
     * across the rest of the request, and must not slow a page that has no
     * session to read. session_boot() is what knows this instance's cookie
     * name — the whole point of which is that production and the sandbox do
     * not share one.
     */
    if ($who === null && session_status() === PHP_SESSION_NONE && function_exists('session_boot')) {
        $name = function_exists('session_cookie_name') && function_exists('app_config')
            ? (session_cookie_name(app_config()) ?? session_name())
            : session_name();
        if (!empty($_COOKIE[$name])) {
            session_boot();
            $who = function_exists('current_user') ? current_user() : null;
            session_write_close();
        }
    }
    $clean = fn($v) => substr(preg_replace('/[^A-Za-z0-9._@-]/', '_', (string) $v), 0, 32) ?: '-';

    $file = hit_log_path();
    clearstatcache(true, $file);
    if (($size = @filesize($file)) !== false && $size > HIT_LOG_MAX) {
        @rename($file, $file . '.1');
    }
    $line = implode("\t", [
        time(),
        $clean(hit_instance()),
        $clean($app ?? hit_app()),
        $clean($method),
        $clean($who ?? '-'),
        hit_agent(),
    ]) . "\n";
    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    hit_maybe_sweep();
    // Group-readable, like usage.log: the dir belongs to the web user and the
    // SSH login only shares its group, so this is what lets `tail` work.
    @chmod(dirname($file), (((int) @fileperms(dirname($file))) & 0777) | 0010);
    @chmod($file, (((int) @fileperms($file)) & 0777) | 0040);
}

/**
 * THE SITE KEEPS ITS OWN SCHEDULE — Sean, 2026-08-23: "top priority, fix
 * probing and testing automatically on the live status page".
 *
 * NFSN's scheduled tasks live in a control panel nobody had visited, so the
 * 30-minute sweep and 6-hour sign-in clock existed only as intentions. This
 * piggybacks them on traffic instead: any page hit that finds the
 * reachability cache stale kicks one background sweep. The status page's own
 * probes hit these pages every 45 seconds while it is open, and Claude's
 * checks land here too — so in practice the clock keeps ticking without a
 * scheduler at all, and a genuinely idle site quietly stops probing itself,
 * which is the correct behaviour for a thing nobody is looking at.
 *
 * The touch() BEFORE the exec is the stampede guard: the first stale hit
 * freshens the mtime, so the requests behind it see a young cache and spawn
 * nothing. One sweep, not one per visitor.
 */
function hit_maybe_sweep(): void
{
    $cache = '/home/protected/status/reachability-cache.json';
    $sweep = '/home/protected/lib/status-sweep.php';
    if (!is_file($sweep) || PHP_SAPI === 'cli') { return; }
    $age = is_file($cache) ? time() - (int) @filemtime($cache) : PHP_INT_MAX;
    if ($age < 1800) { return; }
    $prev = @json_decode((string) @file_get_contents($cache), true);
    $logins = (time() - (int) ($prev['logins_checked_at'] ?? 0)) >= 6 * 3600 ? ' --logins' : '';
    if (is_file($cache)) { @touch($cache); } else { @mkdir(dirname($cache), 0770, true); @touch($cache); }
    @exec('nohup php ' . escapeshellarg($sweep) . $logins . ' >/dev/null 2>&1 &');
}

/**
 * How many hits in the last N seconds, and from how many distinct apps.
 *
 * Reads BACKWARDS in blocks and stops at the first line older than the window,
 * because the log is append-only and time-ordered: counting the last hour must
 * not cost a scan of the last four megabytes. The rotated `.1` is only opened
 * when the live file did not reach far enough back, which for an hour is
 * never and for three days is rare.
 */
function hit_counts(array $windows): array
{
    $now = time();
    $oldest = $now - max($windows);
    $rows = hit_tail_since($oldest);
    $out = [];
    foreach ($windows as $label => $secs) {
        $from = $now - $secs;
        $n = 0; $users = [];
        foreach ($rows as $r) {
            if ($r['ts'] < $from) { continue; }
            $n++;
            if ($r['user'] !== '-') { $users[$r['user']] = true; }
        }
        $out[$label] = ['hits' => $n, 'people' => count($users)];
    }
    return $out;
}

/**
 * THE THREE LANES — Sean, 2026-08-23: "separate hits from tests, hits from my
 * usage, and hits from other peoples usage".
 *
 * The split is made from what the log already holds, and from nothing else:
 *
 *   test   the sandbox instance. test.seancheren.com is where a thing is
 *          tried before it is real, so its traffic is testing by definition.
 *          The repo's own `php tools/test.php` never appears here at all —
 *          it writes to a scratch data dir and never touches this file — so
 *          this lane is server-side testing, which is the only kind that
 *          leaves a mark.
 *   sean   production, signed in as sean.
 *   other  production, anybody else, INCLUDING signed out. A visitor who
 *          never logs in is still somebody else using the site, and filing
 *          them under "unknown" would have made the one lane that answers
 *          "is anyone out there" the emptiest of the three.
 */
function hit_lane(array $row): string
{
    // Claude's traffic first, whichever instance it hit: "separate ALL your
    // traffic" means the sandbox runs count as its own too.
    if (($row['agent'] ?? '-') === 'claudio') { return 'claudio'; }
    $inst = $row['instance'] ?? 'prod';
    // dev is its own lane since it became a real instance (2026-08-23) — its
    // traffic filed under "test" would say the wrong sandbox was busy.
    if ($inst === 'dev') { return 'dev'; }
    if ($inst !== 'prod') { return 'test'; }
    return ($row['user'] ?? '-') === 'sean' ? 'sean' : 'other';
}

/**
 * The window set the Usage tab reports, and the bucket each one draws at.
 *
 * A YEAR AT ONE-MINUTE RESOLUTION IS HALF A MILLION POINTS, so every window
 * buckets to at most ~72 of them and the rate is divided back out. The y axis
 * is always requests per MINUTE, whatever the bucket — which is what makes an
 * hour and a year comparable at a glance instead of just differently shaped.
 */
function hit_windows(): array
{
    return [
        'hour'  => ['label' => '1 hour',   'secs' => 3600,          'bucket' => 60],
        '12h'   => ['label' => '12 hours', 'secs' => 12 * 3600,     'bucket' => 600],
        '3d'    => ['label' => '3 days',   'secs' => 3 * 86400,     'bucket' => 3600],
        'month' => ['label' => '1 month',  'secs' => 30 * 86400,    'bucket' => 12 * 3600],
        'year'  => ['label' => '1 year',   'secs' => 365 * 86400,   'bucket' => 7 * 86400],
    ];
}

/**
 * Per-account usage: the counts per window, the lane each account belongs to,
 * and a requests-per-minute series per window for the chart.
 *
 * ONE PASS OVER THE LOG. The windows nest, so the longest one is read once and
 * every shorter count is taken from the same rows rather than re-reading the
 * file five times.
 *
 * `oldest` is returned because it is the difference between "nobody used this
 * last year" and "the log does not go back a year". The log rotates once at
 * 4 MB and the rotated copy is dropped on the next rotation, so a busy month
 * really can leave the year column reporting on a fortnight. The page says so.
 */
function hit_usage(): array
{
    $wins = hit_windows();
    $now  = time();
    $rows = hit_tail_since($now - max(array_column($wins, 'secs')), 8 * 1024 * 1024);

    $people = [];
    $lanes  = ['sean' => [], 'other' => [], 'claudio' => [], 'test' => [], 'dev' => []];
    $series = [];
    foreach ($wins as $wk => $w) {
        foreach (array_keys($lanes) as $lk) { $lanes[$lk][$wk] = 0; }
    }

    foreach ($rows as $r) {
        $lane = hit_lane($r);
        $who  = $r['user'] === '-' ? '(signed out)' : $r['user'];
        // The same name on the sandbox and on production is two lanes of
        // usage, not one account seen twice — so the lane is part of the key.
        $key  = $lane . "\0" . $who;
        if (!isset($people[$key])) {
            $people[$key] = ['name' => $who, 'lane' => $lane, 'last' => 0, 'counts' => array_fill_keys(array_keys($wins), 0)];
        }
        $people[$key]['last'] = max($people[$key]['last'], $r['ts']);
        foreach ($wins as $wk => $w) {
            if ($r['ts'] < $now - $w['secs']) { continue; }
            $people[$key]['counts'][$wk]++;
            $lanes[$lane][$wk]++;
            $b = (int) floor(($r['ts'] - ($now - $w['secs'])) / $w['bucket']);
            $series[$wk][$key][$b] = ($series[$wk][$key][$b] ?? 0) + 1;
        }
    }

    // Busiest first, on the shortest window that distinguishes them — an
    // account idle this hour but heavy this year should not sort to the bottom.
    uasort($people, function ($a, $b) use ($wins) {
        foreach (array_keys($wins) as $wk) {
            if ($a['counts'][$wk] !== $b['counts'][$wk]) { return $b['counts'][$wk] <=> $a['counts'][$wk]; }
        }
        return strcmp($a['name'], $b['name']);
    });

    // The series, as dense arrays of requests per minute — JSON with a hole in
    // it is a hole the chart has to guess about, and a quiet bucket is a real
    // zero, not a missing reading.
    $out = [];
    foreach ($wins as $wk => $w) {
        $n = (int) ceil($w['secs'] / $w['bucket']);
        foreach ($people as $key => $p) {
            $line = array_fill(0, $n, 0.0);
            foreach ($series[$wk][$key] ?? [] as $b => $c) {
                if ($b >= 0 && $b < $n) { $line[$b] = round($c / ($w['bucket'] / 60), 4); }
            }
            $out[$wk][$key] = $line;
        }
    }

    return [
        'windows' => $wins,
        'people'  => $people,
        'lanes'   => $lanes,
        'series'  => $out,
        'from'    => $now - max(array_column($wins, 'secs')),
        'oldest'  => $rows ? $rows[0]['ts'] : null,
        'now'     => $now,
    ];
}

/** Every logged hit at or after $since, oldest first. */
function hit_tail_since(int $since, int $bytes = 1024 * 1024): array
{
    $rows = [];
    foreach ([hit_log_path(), hit_log_path() . '.1'] as $file) {
        if (!is_file($file)) { continue; }
        $reached = false;
        foreach (array_reverse(hit_read_tail($file, $bytes)) as $line) {
            $f = explode("\t", rtrim($line, "\n"));
            if (count($f) < 5) { continue; }
            $ts = (int) $f[0];
            if ($ts < $since) { $reached = true; break; }
            // Lines written before the agent field exists are five long and
            // read as human, which is what they were.
            $rows[] = ['ts' => $ts, 'instance' => $f[1], 'app' => $f[2], 'method' => $f[3],
                       'user' => $f[4], 'agent' => $f[5] ?? '-'];
        }
        // The live file went back far enough; the rotated one cannot add
        // anything newer than its first line.
        if ($reached) { break; }
    }
    usort($rows, fn($a, $b) => $a['ts'] <=> $b['ts']);
    return $rows;
}

/**
 * The last chunk of a file as lines. Capped rather than complete: three days
 * of hits on a personal site is thousands of lines, not millions, and a cap
 * is what stops a runaway log from turning a page load into a timeout. A
 * truncated count reads low, which is the safe direction to be wrong in for a
 * number nobody makes a decision on.
 */
function hit_read_tail(string $file, int $bytes = 1024 * 1024): array
{
    $fh = @fopen($file, 'rb');
    if ($fh === false) { return []; }
    $size = (int) @filesize($file);
    $from = max(0, $size - $bytes);
    @fseek($fh, $from);
    if ($from > 0) { @fgets($fh); }   // drop the partial first line
    $lines = [];
    while (($l = fgets($fh)) !== false) { $lines[] = $l; }
    fclose($fh);
    return $lines;
}
