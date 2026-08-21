<?php
/**
 * ONE-TIME export of one user's app data, for the move to CalMind.
 *
 * Sean, 2026-08-20, with Aki's say-so for her own account: "fine to login as
 * me and her to grab the data this once, but make sure it's accessed in a way
 * that the passwords aren't a part of the log itself, the data is
 * filtered/copied once, moved to test, then deleted."
 *
 * No login happens. Logging in would mean handling two people's plaintext
 * passwords — which this suite stores as typed (lib/auth.php: the value goes
 * into passwords.json unhashed and login compares it raw), so a session route
 * would put real passwords through a shell, a process list and a scrollback
 * for no gain. The data is right here on disk, and store_read() opens it with
 * the config key. Nothing secret is read, so nothing secret can leak.
 *
 * WHAT IT READS is an allow-list, not an exclusion list — the difference
 * matters: a new sensitive file added to the data dir later is excluded by
 * default rather than by someone remembering to exclude it. passwords.json,
 * signups, tokens and the .datakey are not on the list and never will be.
 *
 * WHAT IT PRINTS is counts. Never a title, never a body, never a row.
 *
 *   php tools/export-suite.php --user=sean --out=/tmp/sean.json
 *
 * Then, on the CalMind side:
 *   php server/tools/import-suite.php --in=/tmp/sean.json \
 *       --url=https://…/test/calmind/api/index.php --token=<destination> --only-new
 *
 * …and then delete the bundle. It is one user's whole life in plaintext JSON;
 * it should not outlive the import that consumes it.
 */

// auth.php defines app_config() AND user_data_file(), and has no top-level
// side effects — no session, no output — so it is safe to pull into a CLI
// script. store.php is what decrypts. config.php is DATA (app_config requires
// it itself) and must not be required here.
require_once __DIR__ . '/../lib/store.php';
require_once __DIR__ . '/../lib/auth.php';

/** The ONLY files this tool will open. Anything not named here is not read. */
const EXPORT_KINDS = ['folders', 'reminders', 'notes', 'events', 'calendars', 'calprefs', 'habits'];

$user = $out = '';
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--user=')) { $user = substr($a, 7); }
    if (str_starts_with($a, '--out='))  { $out  = substr($a, 6); }
}
if ($user === '' || $out === '') {
    fwrite(STDERR, "usage: --user=<username> --out=<bundle.json>\n");
    exit(2);
}
// The same shape the suite itself demands of a username. A path separator or
// a '..' here would turn user_data_file into a way to read anything on disk.
if (!preg_match('/^[A-Za-z0-9_-]{1,32}$/', $user)) {
    fwrite(STDERR, "refusing: that is not a username\n");
    exit(2);
}

$cfg = app_config();
$dir = rtrim((string) $cfg['data_dir'], '/');

$bundle = [];
$counts = [];
foreach (EXPORT_KINDS as $kind) {
    $file = user_data_file($dir, $kind, $user);
    if (!is_file($file)) { $counts[$kind] = 'none'; continue; }
    $data = store_read($file);
    $bundle[$kind] = $data;
    $counts[$kind] = is_array($data) ? count($data) : 1;
}

if ($bundle === []) {
    fwrite(STDERR, "no data files for that user — nothing written\n");
    exit(1);
}

// 0600: the bundle is plaintext by necessity (the importer has to read it),
// so it is at least not readable by anyone else while it exists.
$json = json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$fh = fopen($out, 'w');
if ($fh === false) { fwrite(STDERR, "cannot write $out\n"); exit(1); }
@chmod($out, 0600);
fwrite($fh, (string) $json);
fclose($fh);

// Counts only.
echo "exported {$user} -> {$out}\n";
foreach ($counts as $kind => $n) { echo "  {$kind}: {$n}\n"; }
echo "DELETE THIS FILE once the import has run.\n";
