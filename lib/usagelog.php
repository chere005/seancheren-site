<?php
/**
 * Per-user usage log: one tab-separated line per operation in data/usage.log —
 * time, IP, username, app, kind of action. Deliberately never any *content*: no
 * text, titles, folder names, emails or passwords, so the log can be read without
 * seeing anyone's data. The hooks live in lib/auth.php (require_login logs every
 * authenticated POST's action; login/logout/sign-up log themselves), so no app has
 * to wire it up. The token-read endpoints (feed.php, api/) are deliberately not
 * logged — a widget polls every few minutes and would drown the file.
 */

/** Append one usage line. $user overrides the session's (for login attempts). */
function usage_log(string $action, ?string $user = null): void
{
    $cfg = app_config();
    $dir = rtrim($cfg['data_dir'], '/');
    if (!is_dir($dir)) { @mkdir($dir, 0770, true); }
    $file = $dir . '/usage.log';
    // One rotation keeps it from growing without bound; usage.log.1 is the overflow.
    if (($size = @filesize($file)) !== false && $size > 5 * 1024 * 1024) {
        @rename($file, $file . '.1');
    }
    // Every field is squeezed to one clean token, so a crafted value can't smuggle
    // a newline (a fake log line) or anything unprintable into the file.
    $clean = fn($v) => substr(preg_replace('/[^\w.@:\/-]/', '_', (string) $v), 0, 64) ?: '-';
    $line = implode("\t", [
        date('c'),
        $clean($_SERVER['REMOTE_ADDR'] ?? '-'),
        $clean($user ?? (current_user() ?? '-')),
        $clean(usage_app()),
        $clean($action),
    ]) . "\n";
    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    // The log is read over SSH, but on the live host the data dir belongs to the web
    // user (drwx------) and the SSH login only shares its group. Add group traversal
    // to the dir and group read to the log — self-healing, and nothing else in the
    // dir becomes readable by it.
    @chmod($dir, (((int) @fileperms($dir)) & 0777) | 0010);
    @chmod($file, (((int) @fileperms($file)) & 0777) | 0040);
}

/** Which app the request hit, read off the URI: chat, bookshelf, themes, status, … */
function usage_app(): string
{
    $p = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    // /calmind/ is the CalMind repo's app now, which authenticates itself and never
    // reaches this logger; the branch that named its sub-apps went with the old suite.
    if (strpos($p, '/akisbookshelf') !== false) { return 'bookshelf'; }
    if (strpos($p, '/akisthemes') !== false) { return 'themes'; }
    $first = strtok(trim($p, '/'), '/');
    return $first === false || $first === '' ? 'home' : $first;
}
