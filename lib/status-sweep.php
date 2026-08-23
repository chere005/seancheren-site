<?php
/**
 * Run one status sweep from the command line, with nobody watching.
 *
 *   php /home/protected/lib/status-sweep.php            # reachability only
 *   php /home/protected/lib/status-sweep.php --logins   # …and the sign-ins
 *
 * Sean, 2026-08-23: "i don't see data in history.... it should be populating
 * with data every minute during a dtp or tdtp", and separately "check status
 * uptime every 30 mins on status and signing in every 6 hours".
 *
 * WHY A CLI AND NOT THE PAGE. A sample used to be written only when a
 * signed-in browser loaded /status/ — so the graph filled in exactly while
 * somebody was already looking and stayed flat the rest of the time,
 * including for the whole of a release. This runs on a schedule and from the
 * dtp heartbeat, so the history is a record of what was true, not a record of
 * when the page happened to be open.
 *
 * NOT IN THE WEB ROOT, deliberately: it takes no arguments from a request,
 * has no auth to get wrong, and cannot be reached over HTTP at all. The
 * sign-in probes it can run hold real credentials, and the safest place for
 * that code is somewhere no URL points.
 *
 * TWO CADENCES, because they cost different things. Reachability is cheap and
 * tells you the most, so it runs often. A sign-in probe actually authenticates
 * a probe account and belongs on a slow clock — every six hours, not every
 * thirty seconds, because a login attempt is a thing that shows up in logs and
 * rate limits and should stay rare enough to mean something.
 */

$lib = __DIR__;
require_once $lib . '/auth.php';          // app_config(), for the probe credentials
require_once $lib . '/statuscheck.php';

$withLogins = in_array('--logins', $argv, true);

$results = [];
foreach ($endpoints as $group => $domains) {
    foreach ($domains as $list) {
        foreach ($list as $ep) {
            $results[$group][$ep['url']] = check_url($ep['url'], $ep['post'] ?? null);
        }
    }
}

// The sign-ins, only when asked. On the fast clock the previous verdict is
// carried forward rather than dropped: a Sign-in column that blanked every
// thirty minutes would read as "we stopped knowing", which is not what
// happened — nothing was asked.
$prev = is_file($cachePath) ? json_decode((string) file_get_contents($cachePath), true) : null;
foreach (auth_scopes() as $key => $sc) {
    if ($sc['probe'] === null) { $results['scopes'][$key] = null; continue; }
    $results['scopes'][$key] = $withLogins
        ? check_login($sc['probe'])
        : ($prev['scopes'][$key] ?? null);
}

$results['checked_at'] = time();
if ($withLogins) { $results['logins_checked_at'] = time(); }
elseif (isset($prev['logins_checked_at'])) { $results['logins_checked_at'] = $prev['logins_checked_at']; }

@mkdir(dirname($cachePath), 0770, true);
@file_put_contents($cachePath, json_encode($results));
@chmod($cachePath, 0664);

// Which repos are mid-release, so the sample can record the purple state.
// Read from the same history file the page draws its run list from.
$runningNow = [];
$hist = @json_decode((string) @file_get_contents('/home/protected/status/history.json'), true);
if (is_array($hist)) {
    usort($hist, fn($a, $b) => strcmp((string) ($b['started_at'] ?? ''), (string) ($a['started_at'] ?? '')));
    if (($hist[0]['status'] ?? '') === 'running') {
        foreach (preg_split('/\s+/', trim((string) ($hist[0]['target'] ?? ''))) as $t) {
            if ($t !== '') { $runningNow[$t === 'core' ? 'CoreMind' : $t] = true; }
        }
    }
}
status_sample_record(status_sample_row($repos, $WEB_PROBE, $endpoints, $results, $runningNow));

// One line out, so a scheduled task's mail (or a dtp's log) says what happened
// rather than nothing at all.
$down = 0; $total = 0;
foreach ($results as $g => $rows) {
    if (!is_array($rows) || in_array($g, ['scopes', 'checked_at', 'logins_checked_at'], true)) { continue; }
    foreach ($rows as $r) { $total++; if (empty($r['ok'])) { $down++; } }
}
printf("%s  %d/%d up%s\n", date('Y-m-d g:i:s a T'), $total - $down, $total,
       $withLogins ? '  (sign-ins probed)' : '');
