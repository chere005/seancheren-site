<?php
/**
 * Shared session auth for gated areas.
 *
 * Usage at the top of a protected page:
 *
 *   require_once __DIR__ . '/../../lib/auth.php';
 *   require_login('Reminders');   // renders login + exits if not signed in
 *
 * After this returns, the visitor is authenticated and app_config() is available.
 */

require_once __DIR__ . '/store.php';    // encrypted-at-rest storage helpers
require_once __DIR__ . '/mail.php';     // sending the sign-up verification code
require_once __DIR__ . '/util.php';     // small shared helpers (time parsing, …)
require_once __DIR__ . '/usagelog.php'; // per-user usage log (hooked below)

function app_config(): array
{
    static $config = null;
    if ($config === null) {
        $local = __DIR__ . '/config.php';
        $config = require (is_file($local) ? $local : __DIR__ . '/config.sample.php');
        // tools/test.php points this at a scratch directory so a test run works on real
        // pages and real storage but can never touch anyone's data. Nothing else sets it,
        // and the web server never has it in its environment.
        $dir = getenv('SUITE_DATA_DIR');
        if (is_string($dir) && $dir !== '') { $config['data_dir'] = $dir; }
        // Same idea for the link prefix: the /test/ mirror sets 'base' in its own
        // config.php, but a test run (or a local `SUITE_BASE=/test php -S …`) can force
        // it here without one. The web server never has this in its environment.
        $base = getenv('SUITE_BASE');
        if (is_string($base) && $base !== '') { $config['base'] = $base; }
    }
    return $config;
}

/**
 * URL prefix for this instance's cross-app links: '' in production, '/test' in the
 * sandbox mirror. Every hardcoded absolute link between apps (the tab bar, the login
 * landing, the widget link) is built through this, so the same source serves at the
 * site root and under /test/ without the links leaking out of their instance. The
 * value comes from config `base`; a page served under /test/ loads lib-test, whose
 * config sets it to '/test'. Redirects that use _self_path() already stay put — they
 * bounce back to the URL that called them — so only cross-app links need this.
 */
function suite_base(): string
{
    $b = trim((string) (app_config()['base'] ?? ''), '/');
    return $b === '' ? '' : '/' . $b;
}

/**
 * Where the CalMind suite's apps live under the instance: the instance base plus
 * /calmind. Suite cross-app links go through this; the session cookie path and the
 * apps that live outside the suite (chat, the bookshelf) keep suite_base() alone.
 */
function suite_path(): string
{
    return suite_base() . '/calmind';
}

// Everything in the suite runs on one clock. The server keeps UTC, so without this
// "today" rolls over in the evening and the calendar advances a day early.
date_default_timezone_set(app_config()['timezone'] ?? 'America/Chicago');

/** Current path without query string, for safe self-redirects. */
function _self_path(): string
{
    return strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
}

/**
 * Where you land once you're signed in — always the Calendar, whichever page asked
 * you to log in. Signing in from a bookmark to some other app used to drop you there,
 * which meant the answer to "what's on today" depended on which icon you'd tapped.
 * One session covers the whole suite, so the tab bar is a tap away from here anyway.
 */
const LOGIN_LANDING = '/calendar/';

/**
 * Accounts people made themselves, keyed by username: ['email' => …, 'password' => …].
 * config.php seeds the household accounts and is hand-kept on the server; anything
 * signed up for through the login page lands here instead, in the encrypted data dir.
 */
function accounts_load(array $cfg): array
{
    $a = store_read(rtrim($cfg['data_dir'], '/') . '/accounts.json');
    return is_array($a) ? $a : [];
}

function accounts_save(array $cfg, array $accounts): void
{
    store_write(rtrim($cfg['data_dir'], '/') . '/accounts.json', $accounts);
}

/** The user => password map, with backward-compat for the old single-user config. */
function app_users(array $cfg): array
{
    // Signed-up accounts sit alongside the configured ones; config wins on a clash.
    $signed = [];
    foreach (accounts_load($cfg) as $u => $a) { $signed[$u] = (string) ($a['password'] ?? ''); }
    if (!empty($cfg['users']) && is_array($cfg['users'])) {
        return $cfg['users'] + $signed;
    }
    if ($signed && !isset($cfg['auth_username'])) {
        return $signed;
    }
    if (isset($cfg['auth_username'])) {
        return [(string) $cfg['auth_username'] => (string) ($cfg['auth_password'] ?? '')] + $signed;
    }
    return $signed;
}

/**
 * Passwords people have changed themselves, keyed by username.
 *
 * config.php seeds the accounts, but it is hand-kept on the server and deliberately
 * never deployed — so a change lands in the encrypted data directory instead, and
 * wins over the config entry. Deleting passwords.json falls back to config.
 */
function auth_passwords_file(array $cfg): string
{
    return rtrim($cfg['data_dir'], '/') . '/passwords.json';
}

/** The password to check $user against: their own if they've set one, else config's. */
function auth_password_for(array $cfg, string $user): ?string
{
    $users = app_users($cfg);
    if (!isset($users[$user])) {
        return null;                       // not an account at all
    }
    $own = store_read(auth_passwords_file($cfg));
    return isset($own[$user]) ? (string) $own[$user] : (string) $users[$user];
}

/**
 * Does $given match what is stored, and is what is stored out of date?
 *
 * Returns [ok, needsUpgrade]. Until 2026-08-20 every password here was kept
 * AS TYPED — passwords.json, accounts.json and the pending-signup store all
 * held the real thing, and login was a hash_equals() against it. The store is
 * encrypted at rest, but that key sits beside the data, so it was obfuscation
 * and not hashing: anyone reading the data dir read everyone's passwords, and
 * password reuse made that everyone else's problem too.
 *
 * Both shapes are accepted on the way in, because the alternative is locking
 * every existing account out at once. A stored value that password_get_info()
 * can name is a real hash and gets password_verify(); anything else is a
 * legacy plaintext, compared in constant time and reported as needing an
 * upgrade. The caller rewrites it — see the login path.
 */
function auth_password_check(string $want, string $given): array
{
    if ($want === '' || $given === '') {
        return [false, false];
    }
    if ((password_get_info($want)['algo'] ?? null)) {
        return [password_verify($given, $want), password_needs_rehash($want, PASSWORD_DEFAULT)];
    }
    // LEGACY: the stored value IS the password.
    return [hash_equals($want, $given), true];
}

/**
 * Store a new password for $user — hashed, always.
 *
 * passwords.json WINS over config.php's entry (see auth_password_for), which
 * is what makes upgrade-on-login work for accounts seeded in config: the
 * plaintext there is simply never consulted again once a hash lands here.
 * config.php is hand-kept on the server and never deployed, so this is the
 * only place code can put one.
 */
function auth_password_set(array $cfg, string $user, string $password): void
{
    $file = auth_passwords_file($cfg);
    $own  = store_read($file);
    $own[$user] = password_hash($password, PASSWORD_DEFAULT);
    store_write($file, $own);
}

/**
 * Colour themes, chosen per user in the settings window and kept in
 * data/prefs-<user>.json. A theme is a whole palette, not just an accent: it repaints
 * the page and its surfaces, rules and text together. Every neutral in the suite reads
 * one of these variables (--bg / --surface / --text / --line / --muted / --gold …)
 * rather than a literal, so one theme repaints all of it, light or dark. "Midnight" is
 * the original #111 look and the default, so an untouched suite looks exactly as it did.
 *
 * These are the bookshelf's themes brought suite-wide (the bookshelf keeps its own picker
 * and its own pref key, but reads this same table so the two can't drift). The columns
 * are, in order: label, bg, surface, surface-2, line, line-soft, text, text-dim, muted,
 * accent, accent-ink, accent-soft, gold. The reminder/event/note palette and the error
 * red stay literal — like --gold they say what a thing *is*, not which theme you like,
 * except --gold has to be themed because #f0b429 is invisible on a cream page.
 */
const THEMES = [
    //             label            bg         surface    surface-2  line       line-soft  text       text-dim   muted      accent     accent-ink accent-soft gold
    'midnight' => ['Midnight',      '#111111', '#1a1a1a', '#2a2a2a', '#333333', '#262626', '#eeeeee', '#cccccc', '#888888', '#34d399', '#06251b', '#14332a', '#f0b429'],
    'sage'     => ['Sage & Cream',  '#fefae0', '#faedcd', '#e9edc9', '#ccd5ae', '#e4e7c9', '#3f3a2e', '#5c5545', '#776e56', '#96632f', '#fefae0', '#efe2c2', '#8a5a12'],
    'forest'   => ['Forest',        '#040303', '#16201d', '#3a4e48', '#3a4e48', '#263230', '#e4ddd6', '#beb0a7', '#6a7b76', '#8b9d83', '#0a0f0d', '#1c2a25', '#c9a227'],
    'olive'    => ['Olive & Slate', '#241e2d', '#332a3e', '#443850', '#564a62', '#3b3247', '#eaf0ce', '#c0c5c1', '#848b98', '#bbbe64', '#241e2d', '#3a3448', '#d8c46a'],
];

/** The themes whose page is lighter than their ink; they need color-scheme: light so
 *  native controls (selects, scrollbars, date pickers) draw the right way round. */
const THEMES_LIGHT = ['sage'];

function theme_file(): string
{
    return rtrim(app_config()['data_dir'], '/') . '/prefs-'
         . preg_replace('/[^A-Za-z0-9_-]/', '_', current_user() ?? 'default') . '.json';
}

function theme_get(): string
{
    $t = (string) (store_read(theme_file())['theme'] ?? '');
    return isset(THEMES[$t]) ? $t : 'midnight';
}

function theme_set(string $name): bool
{
    if (!isset(THEMES[$name])) { return false; }
    $p = store_read(theme_file());
    $p['theme'] = $name;
    store_write(theme_file(), $p);
    return true;
}

/**
 * One theme as the custom properties it sets, plus which way round the page is. The
 * single place THEMES' columns are named: theme_css() and the picker's swatches both
 * read this, so they can't drift into disagreeing about a colour.
 */
function theme_vars(string $key): array
{
    $key = isset(THEMES[$key]) ? $key : 'midnight';
    [, $bg, $sf, $sf2, $ln, $lns, $tx, $dim, $mut, $ac, $ink, $soft, $gold] = THEMES[$key];
    $scheme = in_array($key, THEMES_LIGHT, true) ? 'light' : 'dark';
    return ['scheme' => $scheme, 'vars' => [
        '--bg' => $bg, '--surface' => $sf, '--surface-2' => $sf2, '--line' => $ln,
        '--line-soft' => $lns, '--text' => $tx, '--text-dim' => $dim, '--muted' => $mut,
        '--accent' => $ac, '--accent-ink' => $ink, '--accent-soft' => $soft, '--gold' => $gold,
    ]];
}

/** The chosen theme as variables. Emit it before anything that reads them. */
function theme_css(): string
{
    $t   = theme_vars(theme_get());
    $out = '';
    foreach ($t['vars'] as $k => $v) { $out .= " $k: $v;"; }
    return "    :root {{$out} color-scheme: {$t['scheme']}; }\n";
}

/** The page background of the chosen theme — for the iOS status bar / PWA <meta>. */
function theme_bg(): string
{
    return THEMES[theme_get()][1];
}

/** Username of the signed-in user (null if not logged in). */
function current_user(): ?string
{
    return $_SESSION['user'] ?? null;
}

/**
 * Per-user data file path, e.g. reminders -> data/reminders-jacob.json.
 * The username is sanitized so it is always a safe filename.
 * $user names someone other than the signed-in user (sharing reads their files).
 */
function user_data_file(string $dir, string $base, ?string $user = null): string
{
    $u    = $user ?? (current_user() ?? 'default');
    $safe = preg_replace('/[^A-Za-z0-9_-]/', '_', $u);
    return rtrim($dir, '/') . "/{$base}-{$safe}.json";
}

/**
 * Whose token is this? The calendar widget and the watch app both authenticate with
 * the string in data/token-<user>.json instead of a session, since neither of them
 * can hold a login. Returns the username, or null if nothing matches.
 */
function token_user(string $dir, string $token): ?string
{
    if ($token === '') { return null; }
    foreach (glob(rtrim($dir, '/') . '/token-*.json') as $f) {
        $t = store_read($f);
        if (!empty($t['token']) && hash_equals((string) $t['token'], $token)) {
            return preg_replace('/^token-(.*)\.json$/', '$1', basename($f));
        }
    }
    return null;
}

/**
 * This instance's session cookie name.
 *
 * Production, /test/ and /dev/ are three instances on ONE domain, so the cookie path
 * cannot separate them: a cookie set at '/' is sent to every path under it, including
 * /test/ and /dev/. The only thing that reliably keeps three logins apart on one host
 * is a distinct cookie *name*, so each instance gets one — derived from its link
 * prefix, so a new instance is separate for free. Config 'session_name' overrides it.
 */
function session_cookie_name(array $cfg): ?string
{
    // Stripped to characters that are safe in a Set-Cookie name, so a mistyped config
    // can't break out of the header. PHP refuses an all-digit session name, so that
    // falls back too rather than blowing up at session_start().
    $n = preg_replace('/[^A-Za-z0-9_]/', '', (string) ($cfg['session_name'] ?? ''));
    if ($n !== '' && !ctype_digit($n)) { return $n; }
    $b = preg_replace('/[^A-Za-z0-9]/', '', trim((string) ($cfg['base'] ?? ''), '/'));
    // No base means production, which keeps PHP's own session name. Renaming its cookie
    // would sign every single person out the moment this deployed, and buy nothing:
    // production is the thing the sandboxes are being kept away from, so it is the
    // sandboxes that need a different name, not it.
    return $b === '' ? null : 'SCSESS_' . strtoupper($b);
}

/**
 * Where this instance keeps its session files — its own directory inside its own data
 * dir, so one instance's sessions are not sitting in a pile with another's. Returns
 * null if it can't be made, in which case the default save path is left alone: a
 * sandbox that can't write here should still log in, not fail shut.
 */
function session_store_dir(array $cfg): ?string
{
    // Sandboxes only, for the same reason as the cookie name: moving production's
    // session files somewhere new would end every signed-in session at once.
    if (trim((string) ($cfg['base'] ?? ''), '/') === '') { return null; }
    $d = rtrim((string) ($cfg['data_dir'] ?? ''), '/');
    if ($d === '') { return null; }
    $s = $d . '/sessions';
    if (!is_dir($s) && !@mkdir($s, 0700, true)) { return null; }
    return is_dir($s) && is_writable($s) ? $s : null;
}

/**
 * Start the session so it lasts until the user actually logs out. PHP's default is a
 * cookie that dies with the browser and a server-side GC after ~24 minutes idle — on an
 * iOS home-screen app that reads as "it logged me out again". Both halves have to move
 * together: a year-long cookie is useless if the server has already collected the file.
 * Called before any session_start() in the suite, so every page agrees on the lifetime.
 */
function session_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) { return; }
    $cfg  = app_config();
    $year = 365 * 24 * 60 * 60;
    // Each instance keeps its own sessions: its own cookie name (the part that actually
    // separates them), its own cookie path, and its own session files. Being signed into
    // production must not sign you into /test/ or /dev/, or the sandboxes are only a
    // sandbox for data and not for who you are.
    $name = session_cookie_name($cfg);
    if ($name !== null) { session_name($name); }
    $store = session_store_dir($cfg);
    if ($store !== null) { @session_save_path($store); }
    // '/dev' rather than '/' keeps the sandbox's cookie out of production's requests.
    // Cookie-path matching is on '/' boundaries, so '/dev' covers /dev/… and not /devil.
    $path = suite_base() === '' ? '/' : suite_base();
    @ini_set('session.gc_maxlifetime', (string) $year);
    @ini_set('session.cookie_lifetime', (string) $year);
    session_set_cookie_params([
        'lifetime' => $year,
        'path'     => $path,
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
    // The cookie is only sent once, at login; re-send it on every visit so the year is
    // always a year from *now* rather than from whenever the account was signed into.
    if (!empty($_SESSION['auth']) && !headers_sent()) {
        setcookie(session_name(), session_id(), [
            'expires'  => time() + $year,
            'path'     => $path,
            'secure'   => !empty($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}

function require_login(string $area = 'App'): void
{
    session_boot();

    // Logout
    if (isset($_GET['logout'])) {
        usage_log('logout');
        $_SESSION = [];
        session_destroy();
        header('Location: ' . _self_path());
        exit;
    }

    $cfg = app_config();

    // Login submission
    $error = '';
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
        && isset($_POST['username'], $_POST['password'])) {
        $u = (string) $_POST['username'];
        $p = (string) $_POST['password'];
        $want = auth_password_for($cfg, $u);
        [$ok, $needsUpgrade] = $want === null ? [false, false] : auth_password_check($want, $p);
        if ($ok) {
            // UPGRADE ON LOGIN: the one moment the plaintext is in hand and
            // known to be right. Every account converts the next time its
            // owner signs in, with nothing for them to do and no reset email.
            if ($needsUpgrade) {
                auth_password_set($cfg, $u, $p);
            }
            session_regenerate_id(true);
            $_SESSION['auth'] = true;
            $_SESSION['user'] = $u;
            usage_log('login', $u);
            header('Location: ' . suite_path() . LOGIN_LANDING);
            exit;
        }
        usage_log('login_fail', $u);
        $error = 'Invalid username or password.';
    }

    if (empty($_SESSION['auth'])) {
        [$stage, $suErr, $suUser] = signup_handle($cfg);
        render_login($area, $suErr !== '' ? $suErr : $error, $stage, $suUser);
        exit;
    }

    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }

    // Every authenticated POST leaves one usage line — just the action's name, which
    // is the kind of operation, never what it carried. One hook here covers every
    // app, since they all pass through require_login() before handling anything.
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') !== '') {
        usage_log((string) $_POST['action']);
    }

    // The settings window's theme pick, answered here for the same reason.
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'set_theme') {
        header('Content-Type: application/json');
        // A missing or wrong token is a rejected *request*, not a theme the suite
        // doesn't have — the same 400 every other mutation answers with, so a caller
        // (and the test sweep) can tell the two apart.
        if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Bad request.']);
            exit;
        }
        echo json_encode(['ok' => theme_set((string) ($_POST['theme'] ?? ''))]);
        exit;
    }

    // The settings window's password change. It's handled here rather than in each
    // app because the window rides in the top bar of every one of them.
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
        && ($_POST['action'] ?? '') === 'change_password') {
        header('Content-Type: application/json');
        if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Bad request.']);
            exit;
        }
        $me  = (string) current_user();
        $cur = (string) ($_POST['current'] ?? '');
        $new = (string) ($_POST['new'] ?? '');
        $want = auth_password_for($cfg, $me);
        [$curOk] = $want === null ? [false] : auth_password_check($want, $cur);
        if (!$curOk) {
            echo json_encode(['ok' => false, 'error' => 'That is not your current password.']);
        } elseif (strlen($new) < 6) {
            echo json_encode(['ok' => false, 'error' => 'Use at least 6 characters.']);
        } else {
            auth_password_set($cfg, $me, $new);
            echo json_encode(['ok' => true]);
        }
        exit;
    }
}


/**
 * Self-serve sign-up. A new account isn't real until the four-digit code emailed to
 * the address has come back, so the half-made account waits in data/signups.json
 * (encrypted like everything else) with its code and a fifteen-minute expiry.
 */
/** The code every sign-up gets while emailing is switched off. */
const SIGNUP_CODE = '5678';

function signups_file(array $cfg): string
{
    return rtrim($cfg['data_dir'], '/') . '/signups.json';
}

/** Tidy a wanted username; '' if it isn't one we'll allow. */
function signup_clean_user(string $u): string
{
    $u = strtolower(trim($u));
    return preg_match('/^[a-z0-9_-]{2,20}$/', $u) ? $u : '';
}

/**
 * Post the code out. Sending is turned off for now — nothing leaves the server and
 * the code is always SIGNUP_CODE, so sign-up can be used while the mailbox is being
 * sorted out. Put the two lines below back to start emailing real codes again, and
 * take the fixed code out of signup_handle() at the same time.
 */
function signup_send_code(array $cfg, string $email, string $code): bool
{
    // $body = "Your verification code is $code\n\n"
    //       . "It's good for fifteen minutes. If you didn't ask for an account, ignore this.\n";
    // return mail_send($cfg, $email, 'Your verification code', $body);
    mail_log($cfg, "would have emailed $email the code $code");
    return true;
}

/**
 * Handle the login page's sign-up and verify posts. Returns [$stage, $error, $user]:
 * $stage is 'verify' once a code is out, so the page can open the code window.
 */
function signup_handle(array $cfg): array
{
    $action = (string) ($_POST['action'] ?? '');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !in_array($action, ['signup', 'verify'], true)) {
        return ['login', '', ''];
    }
    $pending = store_read(signups_file($cfg));
    if (!is_array($pending)) { $pending = []; }
    // Anything past its fifteen minutes is gone, whichever way we came in.
    $pending = array_filter($pending, fn($p) => (int) ($p['expires'] ?? 0) > time());

    if ($action === 'signup') {
        $user  = signup_clean_user((string) ($_POST['newuser'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $pass  = (string) ($_POST['newpass'] ?? '');
        if ($user === '') {
            return ['signup', 'Pick a username: 2-20 letters, numbers, - or _.', ''];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['signup', 'That email address doesn\'t look right.', ''];
        }
        if (strlen($pass) < 6) {
            return ['signup', 'Use a password of at least 6 characters.', ''];
        }
        if (isset(app_users($cfg)[$user])) {
            return ['signup', 'That username is taken.', ''];
        }
        // Fixed while sending is off — see signup_send_code(). Back to
        // str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT) once mail works.
        $code = SIGNUP_CODE;
        // Hashed BEFORE it is stored, not when the account is created: the
        // pending store is a file on disk like any other, and a signup that is
        // never confirmed would otherwise leave a real password sitting in it
        // for its fifteen minutes.
        $pending[$user] = ['email' => $email, 'password' => password_hash($pass, PASSWORD_DEFAULT),
                           'code' => $code, 'expires' => time() + 900, 'tries' => 0];
        store_write(signups_file($cfg), $pending);
        if (!signup_send_code($cfg, $email, $code)) {
            return ['signup', 'Couldn\'t send the email. Try again in a moment.', ''];
        }
        usage_log('signup_request', $user);
        return ['verify', '', $user];
    }

    // action === 'verify'
    $user = signup_clean_user((string) ($_POST['newuser'] ?? ''));
    $p    = $pending[$user] ?? null;
    if (!$p) {
        return ['login', 'That code expired. Start again.', ''];
    }
    if ((int) $p['tries'] >= 5) {
        unset($pending[$user]);
        store_write(signups_file($cfg), $pending);
        return ['login', 'Too many wrong codes. Start again.', ''];
    }
    if (!hash_equals((string) $p['code'], trim((string) ($_POST['code'] ?? '')))) {
        $pending[$user]['tries'] = (int) $p['tries'] + 1;
        store_write(signups_file($cfg), $pending);
        usage_log('signup_badcode', $user);
        return ['verify', 'That code doesn\'t match.', $user];
    }
    $accounts = accounts_load($cfg);
    $accounts[$user] = ['email' => $p['email'], 'password' => $p['password'], 'created' => time()];
    accounts_save($cfg, $accounts);
    unset($pending[$user]);
    store_write(signups_file($cfg), $pending);

    session_regenerate_id(true);
    $_SESSION['auth'] = true;
    $_SESSION['user'] = $user;
    usage_log('signup_ok', $user);
    header('Location: ' . suite_path() . LOGIN_LANDING);
    exit;
}

function render_login(string $area, string $error = '', string $stage = 'login', string $pendingUser = ''): void
{
    $action = htmlspecialchars(_self_path(), ENT_QUOTES);
    $area   = htmlspecialchars($area, ENT_QUOTES);
    header('Content-Type: text/html; charset=utf-8');
    ?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title>Sign in — CalMind</title>
  <meta name="theme-color" content="<?= e(theme_bg()) ?>">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black">
  <?php // The login page is the suite's front door, so it presents as CalMind — the
        // individual apps keep their own names on their own pages. ?>
  <meta name="apple-mobile-web-app-title" content="CalMind">
  <link rel="apple-touch-icon" href="<?= suite_path() ?>/reminders/icon-180.png">
  <link rel="icon" href="<?= suite_path() ?>/reminders/icon-192.png">
  <link rel="manifest" href="<?= suite_path() ?>/manifest.webmanifest">
  <style>
    <?= theme_css() ?>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html, body { height: 100%; }
    body {
      font-family: system-ui, sans-serif; background: var(--bg); color: var(--text);
      /* svh is the viewport with the browser chrome *showing*, so the card is sized to
         the smallest the window ever gets and collapsing chrome can't make the page
         taller than the screen. 100vh stays as the fallback for older browsers. */
      min-height: 100vh; min-height: 100svh;
      display: flex; align-items: center; justify-content: center; padding: 1rem;
      /* Nothing here scrolls — one centred card, and both windows are fixed overlays —
         so the scrollbar was the only moving part on the page. Scrolling still works
         if a very short screen ever needs it; the bar just isn't drawn. */
      overflow-y: auto; scrollbar-width: none; -ms-overflow-style: none;
    }
    body::-webkit-scrollbar { width: 0; height: 0; }
    .login-box {
      background: var(--surface); border: 1px solid var(--line); border-radius: 8px;
      padding: 2rem; width: 100%; max-width: 320px;
    }
    .login-box h1 { font-size: 1.25rem; margin-bottom: 0.25rem; text-align: center; }
    .login-box .area { font-size: 0.8rem; color: var(--muted); margin-bottom: 1.5rem; text-align: center; }
    .login-box label { display: block; font-size: 0.8rem; color: var(--text-dim); margin-bottom: 0.25rem; }
    .login-box input {
      width: 100%; padding: 0.5rem 0.75rem; background: var(--surface-2); border: 1px solid var(--line);
      border-radius: 4px; color: var(--text); font-size: 1rem; margin-bottom: 1rem;
    }
    .login-box input:focus { outline: none; border-color: var(--muted); }
    .login-box button {
      width: 100%; padding: 0.6rem; background: var(--text); color: var(--bg); border: none;
      border-radius: 4px; font-size: 1rem; cursor: pointer;
    }
    .login-box button:hover { filter: brightness(1.1); }
    .error { color: #f66; font-size: 0.85rem; margin-top: 0.75rem; text-align: center; }
    /* Create account: a quieter button under Log in, and the form it reveals. */
    .login-box .makebtn { background: none; border: 1px solid var(--line); color: var(--text-dim); margin-top: 0.6rem; }
    .login-box .makebtn:hover { background: var(--surface-2); color: var(--text); }
    /* Creating an account is a window over the page, the same shape as the one
       that then waits for the code — the login box itself never changes size. */
    .modalback {
      position: fixed; inset: 0; background: rgba(0,0,0,0.7); display: flex;
      align-items: center; justify-content: center; padding: 1rem; z-index: 20;
    }
    .modalback[hidden] { display: none; }
    .modalbox {
      background: var(--surface); border: 1px solid var(--line); border-radius: 8px; padding: 1.5rem;
      width: 100%; max-width: 320px;
    }
    .modalbox h2 { font-size: 1.05rem; margin-bottom: 0.4rem; text-align: center; }
    .modalbox p { font-size: 0.82rem; color: var(--muted); margin-bottom: 1rem; text-align: center; }
    .modalbox label { display: block; font-size: 0.8rem; color: var(--text-dim); margin-bottom: 0.25rem; }
    .modalbox input {
      width: 100%; padding: 0.5rem 0.75rem; background: var(--surface-2); border: 1px solid var(--line);
      border-radius: 4px; color: var(--text); font-size: 1rem; margin-bottom: 1rem;
    }
    .modalbox input:focus { outline: none; border-color: var(--muted); }
    .modalbox button {
      width: 100%; padding: 0.6rem; background: var(--text); color: var(--bg); border: none;
      border-radius: 4px; font-size: 1rem; cursor: pointer;
    }
    .modalbox .cancel { background: none; border: 1px solid var(--line); color: var(--text-dim); margin-top: 0.6rem; }
    /* Amber, not the error red: it's a caution to read before submitting, not a refusal. */
    .modalbox .warn { color: #fbbf24; font-size: 0.78rem; margin: -0.35rem 0 1rem; text-align: center; }
    /* Four characters wide and no wider — a full-width box with half an em of
       letter-spacing pushed the last digit past the edge on a phone. */
    /* Full width like the button under it; the text-indent balances the trailing
       letter-space so the four digits sit centred rather than a nudge to the left. */
    .codebox input {
      width: 100%; text-align: center; font-size: 1.3rem;
      letter-spacing: 0.4em; text-indent: 0.4em;
    }
  </style>
</head>
<body>
  <div class="login-box">
    <h1>CalMind</h1>
    <div class="area"><?= $area ?></div>
    <form method="post" action="<?= $action ?>">
      <label for="username">Username</label>
      <?php // No shift key on the way in — usernames here are all lower case. ?>
      <input id="username" type="text" name="username" autocomplete="username"
             autocapitalize="none" autocorrect="off" spellcheck="false" required autofocus>
      <label for="password">Password</label>
      <input id="password" type="password" name="password" autocomplete="current-password" required>
      <button type="submit">Log in</button>
    </form>
    <button type="button" class="makebtn" id="makeBtn">Create account</button>
    <?php if ($error !== '' && $stage !== 'signup' && $stage !== 'verify'): ?>
      <p class="error"><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
    <?php endif; ?>
  </div>
  <div class="modalback" id="signBack"<?= $stage === 'signup' ? '' : ' hidden' ?>>
    <div class="modalbox">
    <form method="post" action="<?= $action ?>">
      <h2>Create an account</h2>
      <p>Pick a name and we'll email you a code.</p>
      <?php if ($stage === 'signup' && $error !== ''): ?>
        <p class="error"><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
      <?php endif; ?>
      <input type="hidden" name="action" value="signup">
      <label for="newuser">Username</label>
      <input id="newuser" type="text" name="newuser" autocapitalize="none" autocorrect="off"
             spellcheck="false" maxlength="20" required>
      <label for="email">Email</label>
      <input id="email" type="email" name="email" autocomplete="email" required>
      <label for="newpass">Password</label>
      <input id="newpass" type="password" name="newpass" autocomplete="new-password" minlength="6" required>
      <button type="submit">Send verification code</button>
      <button type="button" class="cancel" data-close>Cancel</button>
    </form>
    </div>
  </div>
  <?php // The account isn't made until this comes back matching what we emailed. ?>
  <div class="modalback" id="codeBack"<?= $stage === 'verify' ? '' : ' hidden' ?>>
    <div class="modalbox codebox">
      <h2>Check your email</h2>
      <p>Enter the four-digit code we sent you.</p>
      <?php if ($stage === 'verify' && $error !== ''): ?>
        <p class="error"><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
      <?php endif; ?>
      <form method="post" action="<?= $action ?>">
        <input type="hidden" name="action" value="verify">
        <input type="hidden" name="newuser" value="<?= htmlspecialchars($pendingUser, ENT_QUOTES) ?>">
        <input type="text" name="code" inputmode="numeric" maxlength="4" autocomplete="one-time-code"
               required autofocus>
        <button type="submit">Verify</button>
        <button type="button" class="cancel" data-close>Cancel</button>
      </form>
    </div>
  </div>
  <script>(function () {
    var b = document.getElementById('makeBtn'), f = document.getElementById('signBack');
    b.addEventListener('click', function () { f.hidden = false; f.querySelector('#newuser').focus(); });
    // Either window closes on its backdrop or its Cancel — nothing here is a
    // dead end, since a code that never arrives has to be escapable.
    document.querySelectorAll('.modalback').forEach(function (m) {
      m.addEventListener('click', function (e) {
        if (e.target === m || e.target.hasAttribute('data-close')) { m.hidden = true; }
      });
    });
  })();</script>
</body>
</html>
    <?php
}
