<?php
/**
 * The export, run ONCE as the web user, because only the web user can read
 * the key.
 *
 * data/.datakey is `-rw------- web web` and data/ is `drwx--x---`, so the ssh
 * login can traverse to a file by name but cannot read the secret — and
 * cannot chmod it either, not owning it. A PHP page under /home/public runs
 * as web and can. That is the whole reason this exists; it does nothing the
 * CLI tool doesn't do.
 *
 * AUTHORIZATION IS A FILE, NOT A TOKEN. A secret in a query string is a
 * secret in the access log, and this page lives at a public URL for as long
 * as it exists. Instead it refuses unless /home/protected/tools/EXPORT_OK is
 * present — a file only someone with ssh can create. The public cannot make
 * it, so the public cannot run this, and nothing sensitive is ever typed into
 * a URL.
 *
 * It then removes BOTH the flag and itself, so a single successful run closes
 * the window it opened. A failed run leaves them, so you can read the error
 * and try again.
 *
 *   ssh …  'touch /home/protected/tools/EXPORT_OK'
 *   curl -sS https://seancheren.com/export-web-oneshot.php
 *   ssh …  'ls -l /home/protected/tools'     # bundles, 0640, group web
 *
 * Prints counts. Never a title, never a row, never the key.
 */

header('Content-Type: text/plain; charset=utf-8');

$flag = '/home/protected/tools/EXPORT_OK';
if (!is_file($flag)) {
    // Say nothing useful. An unauthorized caller learns only that this is not
    // an interesting URL.
    http_response_code(404);
    echo "Not found\n";
    exit;
}

require_once '/home/protected/lib/store.php';
require_once '/home/protected/lib/auth.php';

/** The ONLY files this will open — an allow-list, so anything added to the
 *  data dir later is excluded by default rather than by memory. */
const EXPORT_KINDS = ['folders', 'reminders', 'notes', 'events', 'calendars', 'calprefs', 'habits'];

/** WHO. Edit this line if the prod usernames differ; nothing is taken from
 *  the request, so no caller can aim this at an account. */
const EXPORT_USERS = ['sean', 'aki'];

const OUT_DIR = '/home/protected/tools';

$cfg = app_config();
$dir = rtrim((string) $cfg['data_dir'], '/');

// The same refusal the CLI tool makes, for the same reason: data_key() mints
// and writes a REPLACEMENT key when it cannot read .datakey, which would
// orphan every encrypted file on the server while reporting success.
$keyFile = $dir . '/.datakey';
if ((string) ($cfg['data_key'] ?? '') === '') {
    if (!is_file($keyFile) || !is_readable($keyFile) || trim((string) @file_get_contents($keyFile)) === '') {
        echo "REFUSING: cannot read {$keyFile} even as the web user.\n";
        echo "store.php would generate a replacement key and orphan the data.\n";
        exit(1);
    }
}

$failed = false;
foreach (EXPORT_USERS as $user) {
    if (!preg_match('/^[A-Za-z0-9_-]{1,32}$/', $user)) { echo "bad username in EXPORT_USERS\n"; $failed = true; continue; }
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
    if ($bundle === []) { echo "{$user}: no data files — nothing written\n"; $failed = true; continue; }
    if ($present > 0 && $decoded === 0) {
        echo "{$user}: {$present} files and every one decoded to nothing — wrong key, nothing written\n";
        $failed = true;
        continue;
    }
    $out = OUT_DIR . '/' . $user . '.json';
    file_put_contents($out, (string) json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    // 0640 + group web: the ssh login is IN group web (id showed 25000), so
    // this is the one thing it will be able to read afterwards.
    @chgrp($out, 'web');
    @chmod($out, 0640);
    echo "exported {$user} -> {$out}\n";
    foreach ($counts as $k => $n) { echo "  {$k}: {$n}\n"; }
}

if ($failed) {
    echo "\nSomething failed — leaving the flag and this page in place so it can be re-run.\n";
    exit(1);
}

// One successful run closes its own window.
@unlink($flag);
@unlink(__FILE__);
echo "\nDone. The flag and this page have deleted themselves.\n";
echo "Fetch the bundles, import them, then delete them.\n";
