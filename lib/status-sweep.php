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
 *
 * IT DOES NOT SWEEP TWICE. This file used to run its own loop over every
 * endpoint AFTER including statuscheck.php, which sweeps on include — so a
 * scheduled run made 36 requests to make 18 checks, and the sample recorded
 * was from the second set. It now asks statuscheck to do the one sweep, by
 * setting the same two forces the page's "Check now" sets.
 */

$lib = __DIR__;
require_once $lib . '/auth.php';          // app_config(), for the probe credentials

// Set BEFORE the include, because statuscheck.php sweeps as it loads.
$GLOBALS['STATUS_FORCE_SWEEP']  = true;                                  // always
$GLOBALS['STATUS_FORCE_LOGINS'] = in_array('--logins', $argv, true);     // the slow clock

require_once $lib . '/statuscheck.php';   // sweeps, probes, records the sample

// The geo lookup rides HERE and nowhere else. It talks to a third party, so it
// belongs on the background clock the sweep already runs on rather than on the
// render path of any page — see lib/geoip.php's header for what is sent.
require_once $lib . '/geoip.php';
$geoN = geo_resolve_new();

// One line out, so a scheduled task's mail (or a dtp's log) says what happened
// rather than nothing at all.
$down = 0; $total = 0;
foreach ($results as $g => $rows) {
    if (!is_array($rows) || in_array($g, ['scopes', 'checked_at', 'logins_checked_at'], true)) { continue; }
    foreach ($rows as $r) { if (is_array($r)) { $total++; if (empty($r['ok'])) { $down++; } } }
}
printf("%s  %d/%d up%s%s\n", date('Y-m-d g:i:s a T'), $total - $down, $total,
       $GLOBALS['STATUS_FORCE_LOGINS'] ? '  (sign-ins probed)' : '',
       $geoN < 0 ? '  (GEO CACHE NOT WRITABLE — see the error log)'
                 : ($geoN ? "  (located $geoN new address" . ($geoN === 1 ? '' : 'es') . ')' : ''));
