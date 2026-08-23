<?php
/**
 * Create (or reset) the demo accounts the test run signs in as.
 *
 *   php tools/seed-accounts.php            # local ./data
 *   php tools/seed-accounts.php --force    # overwrite accounts that already exist
 *
 * This replaces seed-example.php and seed-buddy.php, which are gone with the app suite
 * they seeded (2026-08-22). Those two built plausible reminders, events, notes and habit
 * history because there were apps to show them in; what is left here — Chat, Aki's
 * Bookshelf, the themes workbench and the marketing pages — needs no such data. It needs
 * accounts that exist and can log in, so that is all this writes.
 *
 * Accounts land in data/accounts.json, the same place a sign-up goes, so nothing about
 * config.php has to change, and every file it writes belongs to these names alone.
 *
 * deploy.sh doesn't send tools/, and never sends data/, so a live host that wants these
 * needs it run there — as the WEB user, not over SSH: /home/protected/data/ is owned by
 * web (drwx------), so a CLI run as the SSH login gets Permission denied on every write
 * and, because those writes are unchecked, prints success anyway.
 */

$libDir = null;
foreach ([__DIR__ . '/../lib', '/home/protected/lib'] as $c) {
    if (is_file($c . '/auth.php')) { $libDir = $c; break; }
}
if ($libDir === null) { fwrite(STDERR, "Can't find lib/.\n"); exit(1); }
require_once $libDir . '/auth.php';

/**
 * `aki` is deliberately NOT here, even though the bookshelf area needs it. A developer's
 * own config.php names aki, and app_users() lets a config account WIN over one in
 * accounts.json — so seeding aki here would write a password that never takes effect and
 * fail every bookshelf test with a wrong one. The run's ensure_account() already handles
 * that case by writing the passwords.json override, which does win.
 */
const SEED = [
    'example' => 'examplepassword',
    'buddy'   => 'buddypassword',
];

$cfg   = app_config();
$dir   = rtrim($cfg['data_dir'], '/');
$force = in_array('--force', $argv, true);
if (!is_dir($dir)) { mkdir($dir, 0700, true); }

$accounts = accounts_load($cfg);
$made = [];
foreach (SEED as $user => $pass) {
    if (isset($accounts[$user]) && !$force) { continue; }
    // HASHED, like the signup path and every other writer. A seeder that
    // wrote plaintext was the one remaining way to put a readable password
    // back into a data dir after the whole store had been migrated off them.
    $accounts[$user] = ['email' => $user . '@seancheren.com',
                        'password' => password_hash($pass, PASSWORD_DEFAULT), 'created' => time()];
    $made[] = $user;
}
if (!$made) {
    echo "All demo accounts already exist. Re-run with --force to reset them.\n";
    exit(0);
}
accounts_save($cfg, $accounts);

// A password someone set by hand lives in passwords.json and WINS over the account
// entry above, so a reset that ignored it would leave the old password working.
$pw = store_read($dir . '/passwords.json');
foreach ($made as $user) { unset($pw[$user]); }
store_write($dir . '/passwords.json', $pw);

echo "Seeded: " . implode(', ', $made) . "\n";
