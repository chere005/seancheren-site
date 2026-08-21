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

/**
 * THE KEY, BEFORE ANYTHING ELSE — and this guard is the whole reason this
 * block exists rather than a straight read.
 *
 * store.php's data_key() falls back to data/.datakey, and if it cannot READ
 * that file it does not fail: it generates a fresh random key and writes one.
 * Under the ssh login the read is denied (the file belongs to the web user,
 * 0600), so the first run of this tool went down that path, decrypted every
 * file to nothing with the wrong key, and reported "exported" with counts of
 * zero. The only reason it was harmless is that the WRITE was denied too. Had
 * it succeeded it would have replaced the key beside the data and made every
 * encrypted file on the server permanently unreadable.
 *
 * So: prove the real key is in hand before opening anything. Either
 * config.php names one, or .datakey exists and this process can read it.
 */
$keyFile = $dir . '/.datakey';
if ((string) ($cfg['data_key'] ?? '') === '') {
    if (!is_file($keyFile)) {
        fwrite(STDERR, "no data_key in config and no {$keyFile} — refusing (a read here MINTS A NEW KEY)\n");
        exit(1);
    }
    if (!is_readable($keyFile) || trim((string) @file_get_contents($keyFile)) === '') {
        fwrite(STDERR, "cannot read {$keyFile} as this user.\n");
        fwrite(STDERR, "Refusing: store.php would silently generate a REPLACEMENT key and every\n");
        fwrite(STDERR, "encrypted file would stop decrypting. Run as the user that owns the key,\n");
        fwrite(STDERR, "or set 'data_key' in lib/config.php to the same secret.\n");
        exit(1);
    }
}

$bundle = [];
$counts = [];
$present = 0;
$decoded = 0;
foreach (EXPORT_KINDS as $kind) {
    $file = user_data_file($dir, $kind, $user);
    if (!is_file($file)) { $counts[$kind] = 'none'; continue; }
    $present++;
    $data = store_read($file);
    $bundle[$kind] = $data;
    $counts[$kind] = is_array($data) ? count($data) : 1;
    if (is_array($data) ? $data !== [] : true) { $decoded++; }
}

if ($bundle === []) {
    fwrite(STDERR, "no data files for that user — nothing written\n");
    exit(1);
}
// Files on disk that ALL decode to nothing is a decrypt failure wearing the
// costume of an empty account. A real account with seven files has something
// in at least one of them.
if ($present > 0 && $decoded === 0) {
    fwrite(STDERR, "{$present} data files for '{$user}' and every one decoded to nothing.\n");
    fwrite(STDERR, "That is a wrong key, not an empty account — nothing written.\n");
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
