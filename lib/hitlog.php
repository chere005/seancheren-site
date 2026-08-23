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
    // Local dev: beside the repo's own data dir, which is gitignored.
    $local = dirname(__DIR__) . '/data';
    if (!is_dir($local)) { @mkdir($local, 0700, true); }
    return $local . '/hits.log';
}

const HIT_LOG_MAX = 4 * 1024 * 1024;   // one rotation, so it cannot grow forever

/**
 * Which instance served this — '' for production, 'test' or 'dev' for the
 * sandboxes. Read from the config the page already loaded, so it cannot
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
    $p = preg_replace('#^/(test|dev)(?=/|$)#', '', $p) ?? $p;
    $first = strtok(trim($p, '/'), '/');
    if ($first === false || $first === '') { return 'home'; }
    // One clean token, capped — the segment reaches a log line, and a crafted
    // path must not be able to smuggle a tab, a newline or a fake record in.
    return substr(preg_replace('/[^A-Za-z0-9._-]/', '_', $first), 0, 32);
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
    // Nor should a preflight or a HEAD: neither renders anything.
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'OPTIONS' || $method === 'HEAD') { return; }

    $who = $user;
    if ($who === null && function_exists('current_user')) { $who = current_user(); }
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
    ]) . "\n";
    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    // Group-readable, like usage.log: the dir belongs to the web user and the
    // SSH login only shares its group, so this is what lets `tail` work.
    @chmod(dirname($file), (((int) @fileperms(dirname($file))) & 0777) | 0010);
    @chmod($file, (((int) @fileperms($file)) & 0777) | 0040);
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

/** Every logged hit at or after $since, oldest first. */
function hit_tail_since(int $since): array
{
    $rows = [];
    foreach ([hit_log_path(), hit_log_path() . '.1'] as $file) {
        if (!is_file($file)) { continue; }
        $reached = false;
        foreach (array_reverse(hit_read_tail($file)) as $line) {
            $f = explode("\t", rtrim($line, "\n"));
            if (count($f) < 5) { continue; }
            $ts = (int) $f[0];
            if ($ts < $since) { $reached = true; break; }
            $rows[] = ['ts' => $ts, 'instance' => $f[1], 'app' => $f[2], 'method' => $f[3], 'user' => $f[4]];
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
