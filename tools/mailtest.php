<?php
/**
 * Send one test message, to check the mail settings in lib/config.php.
 *
 *   php tools/mailtest.php you@example.com
 *
 * DISARMED while mail_send() is stubbed (lib/mail.php): the stub logs
 * "would have emailed …" and returns false, so this prints FAILED without
 * sending anything. It comes back to life the moment the real body is
 * uncommented — nothing here needs to change.
 *
 * Prints what happened; on failure the reason is also appended to data/mail.log.
 * Not under public/, so it is never reachable over the web.
 */
require __DIR__ . '/../lib/auth.php';

$to = $argv[1] ?? '';
if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "usage: php tools/mailtest.php you@example.com\n");
    exit(1);
}
$cfg  = app_config();
$how  = empty($cfg['smtp_host']) ? 'PHP mail()' : 'SMTP ' . $cfg['smtp_host'] . ':' . ($cfg['smtp_port'] ?? 587);
$ok   = mail_send($cfg, $to, 'Test from seancheren.com', "If you're reading this, sending works.\n");
echo ($ok ? "sent" : "FAILED") . " to $to via $how\n";
exit($ok ? 0 : 1);
