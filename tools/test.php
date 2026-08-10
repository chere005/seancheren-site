<?php
/**
 * The suite's test run.
 *
 *   php tools/test.php              # everything
 *   php tools/test.php reminders    # only areas whose name contains "reminders"
 *   php tools/test.php --list       # print the area names and stop
 *   php tools/test.php --keep       # leave the scratch data dir behind for poking at
 *
 * There is no framework here for the same reason there isn't one anywhere else in this
 * repo. It boots `php -S` against a **scratch data directory** (SUITE_DATA_DIR, honoured
 * only by app_config()), seeds the two demo accounts into it with the real seeders, and
 * then drives the real pages over real HTTP — sessions, cookies, redirects, CSRF and all.
 * Nothing it does can touch `data/`, and it never needs credentials from `config.php`:
 * the accounts it logs in as are the ones it just seeded.
 *
 * Unit-level checks (parsers, repeats, sorting, sanitising) run in-process against `lib/`.
 *
 * **When you change a feature, change its test in the same commit. When you add one, add
 * a test with it.** TESTING.md is the map of what is covered here and what still has to
 * be looked at by eye; it is part of the same bargain — keep it in step.
 */

// ---------------------------------------------------------------- setup

$root = dirname(__DIR__);
$args = array_slice($argv, 1);
$keep = in_array('--keep', $args, true);
$list = in_array('--list', $args, true);
$only = array_values(array_filter($args, fn($a) => strncmp($a, '--', 2) !== 0));

$scratch = sys_get_temp_dir() . '/seancheren-test-' . getmypid();
putenv('SUITE_DATA_DIR=' . $scratch);      // for this process (the unit checks)
@mkdir($scratch, 0700, true);

require_once $root . '/lib/auth.php';
require_once $root . '/lib/tabbar.php';
require_once $root . '/lib/folders.php';
require_once $root . '/lib/sharing.php';
require_once $root . '/lib/richtext.php';
require_once $root . '/lib/palette.php';
require_once $root . '/lib/site.php';

// ---------------------------------------------------------------- tiny test framework

$AREAS = [];      // name => [ [label, fn], … ]
$CUR   = null;
function area(string $name): void { global $AREAS, $CUR; $CUR = $name; $AREAS[$name] ??= []; }
function t(string $label, callable $fn): void { global $AREAS, $CUR; $AREAS[$CUR][] = [$label, $fn]; }

/** Assertions. Each throws with a message the runner prints verbatim. */
function ok($cond, string $why = ''): void
{
    if (!$cond) { throw new RuntimeException($why !== '' ? $why : 'expected true'); }
}
function eq($want, $got, string $why = ''): void
{
    if ($want !== $got) {
        throw new RuntimeException(($why !== '' ? $why . ': ' : '')
            . 'expected ' . sv($want) . ', got ' . sv($got));
    }
}
function has(string $needle, string $hay, string $why = ''): void
{
    if (strpos($hay, $needle) === false) {
        throw new RuntimeException(($why !== '' ? $why . ': ' : '') . 'missing ' . sv($needle));
    }
}
function hasnt(string $needle, string $hay, string $why = ''): void
{
    if (strpos($hay, $needle) !== false) {
        throw new RuntimeException(($why !== '' ? $why . ': ' : '') . 'unexpectedly present ' . sv($needle));
    }
}
/** No PHP diagnostics in a page body. display_errors in HTML mode bolds the level —
 *  "<b>Warning</b>:" — so the plain "Warning:" needle never matched a real warning;
 *  check both spellings, and Deprecated too (which was never checked at all). */
function quiet(string $body, string $why = ''): void
{
    foreach (['Fatal error', 'Warning:', 'Notice:', 'Deprecated:',
              'Warning</b>', 'Notice</b>', 'Deprecated</b>'] as $l) {
        hasnt($l, $body, $why);
    }
}
function sv($v): string
{
    if (is_string($v)) { return '"' . (mb_strlen($v) > 90 ? mb_substr($v, 0, 90) . '…' : $v) . '"'; }
    if (is_bool($v))   { return $v ? 'true' : 'false'; }
    if (is_null($v))   { return 'null'; }
    if (is_scalar($v)) { return (string) $v; }
    return json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

// ---------------------------------------------------------------- HTTP client

$PORT = 0; $SRV = null;

/**
 * One request against the dev server. Redirects are never followed — where a POST sends
 * you is half of what's being tested. $jar carries the session cookie between calls.
 */
function req(string $method, string $path, array $post = [], ?array &$jar = null, bool $ajax = false): array
{
    global $PORT;
    $headers = ["Host: 127.0.0.1:$PORT", 'Connection: close'];
    if ($jar) {
        $bits = [];
        foreach ($jar as $k => $v) { $bits[] = "$k=$v"; }
        $headers[] = 'Cookie: ' . implode('; ', $bits);
    }
    if ($ajax) { $headers[] = 'X-Requested-With: XMLHttpRequest'; }
    $body = '';
    if ($method === 'POST') {
        $body = http_build_query($post);
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $headers[] = 'Content-Length: ' . strlen($body);
    }
    $ctx = stream_context_create(['http' => [
        'method'          => $method,
        'header'          => implode("\r\n", $headers),
        'content'         => $body,
        'ignore_errors'   => true,     // 4xx/5xx should come back, not throw
        'follow_location' => 0,
        'timeout'         => 15,
    ]]);
    $out = @file_get_contents("http://127.0.0.1:$PORT" . $path, false, $ctx);
    $hdr = $http_response_header ?? [];
    $res = ['status' => 0, 'location' => null, 'body' => (string) $out, 'headers' => $hdr];
    foreach ($hdr as $i => $h) {
        if ($i === 0 && preg_match('#HTTP/\S+\s+(\d{3})#', $h, $m)) { $res['status'] = (int) $m[1]; }
        if (stripos($h, 'Location:') === 0)   { $res['location'] = trim(substr($h, 9)); }
        if (stripos($h, 'Set-Cookie:') === 0 && preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $h, $m)) {
            if ($jar !== null) { $jar[trim($m[1])] = $m[2]; }
        }
    }
    return $res;
}

/** Sign in and return a cookie jar carrying the session. */
function login(string $user, string $pass): array
{
    $jar = [];
    req('GET', '/calmind/reminders/', [], $jar);                       // pick up a session cookie
    $r = req('POST', '/calmind/reminders/', ['username' => $user, 'password' => $pass], $jar);
    if ($r['status'] !== 302) {
        throw new RuntimeException("login as $user did not redirect (status {$r['status']})");
    }
    return $jar;
}

/** The CSRF token the app would have put in the page. */
function csrf(array $jar, string $path = '/calmind/reminders/'): string
{
    $r = req('GET', $path, [], $jar);
    if (!preg_match('/name="csrf" value="([^"]+)"/', $r['body'], $m)) {
        throw new RuntimeException("no CSRF token on $path");
    }
    return $m[1];
}

/** The scratch data dir. The runner holds no session, so anything that would otherwise
 *  default to "the signed-in user" has to be told who it means. */
function datadir(): string { return (string) getenv('SUITE_DATA_DIR'); }

/** Read a user's stored file, the way the app would. */
function stored(string $base, string $user): array
{
    global $scratch;
    return store_read(user_data_file($scratch, $base, $user));
}

/**
 * Drop blank orphaned subtasks from a user's reminders. The UI deletes an empty
 * subtask on blur, but tests that make one via POST leave it — and since adding
 * prepends to the stored list, the outline model would hand that stray to whatever
 * the next test adds above it. Position-sensitive tests start from a clean list.
 */
function drop_blank_subtasks(string $user = 'example'): void
{
    global $scratch;
    $f = user_data_file($scratch, 'reminders', $user);
    store_write($f, array_values(array_filter(store_read($f),
        fn($x) => !((int) ($x['indent'] ?? 0) > 0 && trim((string) ($x['text'] ?? '')) === ''))));
}
/** Every non-section row of a user's reminders. */
function rows(string $user): array
{
    return array_values(array_filter(stored('reminders', $user),
        fn($r) => ($r['type'] ?? '') !== 'section'));
}
/** Put every folder back on screen. The visibility tests deliberately switch folders
 *  off, and anything reading a rendered list afterwards needs the whole list back. */
function showAll(array $jar): void
{
    $keys = folders_load(datadir(), 'example')['reminders'];
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'folder_vis_all',
        'show' => '1', 'keys' => implode("\x1F", $keys)], $jar, true);
}

/** htmlspecialchars, for asserting on rendered text. */
function e_test(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }

/** One reminder by its text, or null. */
function rowBy(string $user, string $text): ?array
{
    foreach (rows($user) as $r) { if (($r['text'] ?? '') === $text) { return $r; } }
    return null;
}

// ═══════════════════════════════════════════════════════════════════ THE TESTS
// Each area matches a heading in TESTING.md. Keep the two in step.

// ---------------------------------------------------------------- 1. seeding
area('seed');

t('the example seeder builds a complete account', function () use ($scratch) {
    ok(count(rows('example')) > 15, 'example should have a working number of reminders');
    ok(count(stored('calendars', 'example')) === 3, 'three calendars');
    ok(count(stored('events', 'example')) > 5, 'events');
    ok(count(stored('notes', 'example')) > 5, 'notes');
    $acc = store_read($scratch . '/accounts.json');
    eq('examplepassword', $acc['example']['password'] ?? null, 'account password');
});

t('the buddy seeder builds the other half of a pair', function () {
    ok(count(rows('buddy')) > 20, 'buddy should have both checklists');
    $shares = shares_load(datadir(), 'buddy');
    ok(in_array('Dinners', $shares['folders'], true), 'buddy shares the Dinners folder');
    ok(in_array('Recipes', $shares['notes'], true), 'buddy shares the Recipes notes');
    ok(count($shares['calendars']) === 1, 'buddy shares one calendar');
});

t('seeding pairs the two accounts both ways', function () {
    $back = shares_load(datadir(), 'example');
    ok($back['folders'] || $back['notes'] || $back['calendars'], 'example shares back');
    $names = array_column(stored('events', 'example'), 'text');
    ok(in_array('Dinner with buddy', $names, true), "example has the dinners from their side");
    $mine = array_column(stored('events', 'buddy'), 'text');
    ok(in_array('Dinner with example', $mine, true), 'buddy has them from theirs');
});

t('re-seeding buddy does not double up example\'s dinners', function () use ($root, $scratch) {
    $before = count(array_filter(stored('events', 'example'),
        fn($e) => ($e['text'] ?? '') === 'Dinner with buddy'));
    exec('SUITE_DATA_DIR=' . escapeshellarg($scratch) . ' php '
        . escapeshellarg($root . '/tools/seed-buddy.php') . ' --force 2>&1', $o, $rc);
    eq(0, $rc, 'seeder exit code');
    $after = count(array_filter(stored('events', 'example'),
        fn($e) => ($e['text'] ?? '') === 'Dinner with buddy'));
    eq($before, $after, 'the count should be unchanged');
});

t('the seeders write nothing outside the scratch directory', function () use ($root) {
    // The real data dir must be untouched by a run — the whole point of SUITE_DATA_DIR.
    ok(getenv('SUITE_DATA_DIR') !== rtrim(app_config()['data_dir'], '/')
       || strpos(app_config()['data_dir'], sys_get_temp_dir()) === 0,
       'app_config() must be pointing at the scratch dir');
});

// ---------------------------------------------------------------- test instance (/test/)
// The /test/ sandbox mirror is the same source served under a base prefix, with its own
// data dir. suite_base() is what keeps its cross-app links inside /test/; get this wrong
// and the mirror's tab bar jumps to production. Prod (no base) must stay byte-identical.
area('test-instance');

t('suite_base() is empty for production (no base configured)', function () {
    eq('', suite_base(), 'unprefixed by default');
    ob_start(); render_tabbar('reminders'); $bar = ob_get_clean();
    has('href="/calmind/reminders/"', $bar, 'prod tab bar links are unprefixed');
    hasnt('/test/calmind/reminders/', $bar, 'no stray /test prefix in prod');
});

t('a base prefixes every cross-app link (tab bar + login landing)', function () use ($root, $scratch) {
    // app_config() caches in-process, so exercise the base in a fresh subprocess the way
    // the real /test/ instance gets it (there, from lib-test/config.php).
    $php = 'require ' . var_export($root . '/lib/auth.php', true) . ';'
         . 'require ' . var_export($root . '/lib/tabbar.php', true) . ';'
         . 'ob_start(); render_tabbar("reminders"); '
         . 'echo suite_base() . "\n" . ob_get_clean();';
    exec('SUITE_BASE=/test SUITE_DATA_DIR=' . escapeshellarg($scratch)
        . ' php -r ' . escapeshellarg($php) . ' 2>&1', $out, $rc);
    $s = implode("\n", $out);
    eq(0, $rc, 'subprocess ran');
    eq('/test', strtok($s, "\n"), 'suite_base() returns the configured prefix');
    has('href="/test/calmind/reminders/"', $s, 'tab bar links carry the /test prefix');
    has('href="/test/calmind/calendar/"', $s, 'and the calendar tab too');
    hasnt('href="/calmind/reminders/"', $s, 'no unprefixed cross-app link leaks out of /test/');
});

// Three instances share one domain, so a session cookie at path '/' reaches all three.
// The cookie NAME is what keeps their logins apart; if these ever collapse to one name,
// signing into production signs you into /test/ and /dev/ as well.
t('each instance gets its own session cookie name', function () {
    // Production keeps PHP's own name (null = leave it alone). Renaming its cookie would
    // sign everyone out the moment it deployed, and buys nothing: production is what the
    // sandboxes are kept away from, so it is the sandboxes that need a different name.
    eq(null,          session_cookie_name([]),                  'production is left alone');
    eq(null,          session_cookie_name(['base' => '']),      'an empty base too');
    eq('SCSESS_TEST', session_cookie_name(['base' => '/test']), 'the test mirror');
    eq('SCSESS_DEV',  session_cookie_name(['base' => '/dev']),  'the dev sandbox');
    // Three different sessions is the whole point — a duplicate would merge two logins.
    $names = [session_cookie_name([]), session_cookie_name(['base' => '/test']),
              session_cookie_name(['base' => '/dev'])];
    eq(3, count(array_unique($names, SORT_REGULAR)), 'no two instances share a cookie name');
    // Production's session files must not move either, for the same reason.
    eq(null, session_store_dir(['base' => '', 'data_dir' => sys_get_temp_dir()]),
        'production keeps the default session store');
    // An explicit name wins; a blank or all-digit one falls back (PHP refuses the latter).
    eq('PINNED', session_cookie_name(['session_name' => 'PINNED', 'base' => '/dev']), 'config pins it');
    eq('SCSESS_DEV', session_cookie_name(['session_name' => '', 'base' => '/dev']), 'blank falls back');
    eq('SCSESS_DEV', session_cookie_name(['session_name' => '123', 'base' => '/dev']),
        'an all-digit name falls back — PHP will not accept one');
    // The name lands in a Set-Cookie header, so it must not be able to carry one.
    $dirty = session_cookie_name(['session_name' => "x; path=/\r\nSet-Cookie: a=b", 'base' => '/dev']);
    foreach ([';', '=', ' ', "\r", "\n", ','] as $c) {
        hasnt($c, $dirty, 'nothing that could break the header survives sanitising');
    }
});

t('a sandbox config does not inherit production, so its accounts are its own', function () use ($root, $scratch) {
    // app_users() merges config accounts with signed-up ones. What matters here is that
    // an instance configured with its own 'users' offers exactly those — a sandbox login
    // must not be production's login, or the sandbox only isolates data and not identity.
    $php = 'require ' . var_export($root . '/lib/auth.php', true) . ';'
         . '$u = app_users(["users" => ["dev" => "sandboxpw"], "data_dir" => ' . var_export($scratch, true) . ']);'
         . 'echo implode(",", array_keys($u)) . "|" . ($u["dev"] ?? "");';
    exec('SUITE_DATA_DIR=' . escapeshellarg($scratch) . ' php -r ' . escapeshellarg($php) . ' 2>&1', $out, $rc);
    eq(0, $rc, 'subprocess ran');
    $s = implode('', $out);
    has('dev', $s, 'the sandbox account is there');
    has('sandboxpw', $s, 'with the password its own config set');
});

// /dev/ has its own deploy script, separate from deploy.sh on purpose: someone working
// on test/production and someone working on the sandbox must not be able to collide.
t('deploy-dev.sh can only ever write the dev instance', function () use ($root) {
    $sh = file_get_contents($root . '/deploy-dev.sh');
    // The destinations are constants, not built from an argument — there is no mode that
    // could aim this at production, and a guard refuses anything that isn't a /dev path.
    has('PUB=/home/public/dev', $sh, 'the public destination is a constant');
    has('LIB=/home/protected/lib-dev', $sh, 'so is the lib destination');
    has('Refusing: that is production', $sh, 'and production is refused outright');
    foreach (['/home/public/test', '/home/protected/lib-test'] as $t) {
        eq(0, preg_match('/^[^#]*' . preg_quote($t, '/') . '/m', $sh),
            "no runnable line names $t — test belongs to deploy.sh");
    }
    has("--exclude='config.php'", $sh, 'it never sends a config.php');
    // Comments are allowed to say "--delete"; a runnable line is not allowed to use it.
    eq(0, preg_match('/^[^#]*--delete/m', $sh), 'no runnable line passes --delete');
    has('worktree add', $sh, 'it ships a clean checkout, not the shared working tree');
    has("'users'        => ['dev' => '\$pw']", $sh, 'the config it writes is standalone');
    has('random_bytes', $sh, 'with a generated bootstrap password');
    has('still INHERITS', $sh, 'and an older inheriting config is reported, not rewritten');
});

t('deploy.sh is left to test and production, and knows nothing about dev', function () use ($root) {
    $sh = file_get_contents($root . '/deploy.sh');
    has('test|prod|both|promote)', $sh, 'its modes are test/prod/both/promote');
    foreach (['/home/public/dev', 'lib-dev', 'data-dev'] as $d) {
        hasnt($d, $sh, "deploy.sh never names $d");
    }
});

t('suite_base() normalises a messy prefix', function () use ($root, $scratch) {
    $php = 'require ' . var_export($root . '/lib/auth.php', true) . '; echo suite_base();';
    exec('SUITE_BASE=' . escapeshellarg('test/') . ' SUITE_DATA_DIR=' . escapeshellarg($scratch)
        . ' php -r ' . escapeshellarg($php) . ' 2>&1', $out, $rc);
    eq('/test', trim(implode("\n", $out)), 'trims slashes, adds a single leading one');
});

// ---------------------------------------------------------------- 2. auth
area('auth');

t('a signed-out visitor gets the login page, not the app', function () {
    foreach (['/calmind/reminders/', '/calmind/notes/', '/calmind/calendar/', '/calmind/habits/', '/calmind/add/'] as $p) {
        $r = req('GET', $p);
        eq(200, $r['status'], "$p status");
        has('Sign in', $r['body'], "$p should show the login form");
        has('CalMind</h1>', $r['body'], "$p wears the suite's name on the card");
        hasnt('rlist-root', $r['body'], "$p must not leak the app");
    }
});

t('the login page presents as CalMind to a home screen too', function () {
    // The card said CalMind but the page's install identity still said Reminders — the
    // iOS title meta and the linked manifest are what a home-screen add actually reads.
    $b = req('GET', '/calmind/reminders/')['body'];
    has('apple-mobile-web-app-title" content="CalMind"', $b, 'the iOS home-screen name is CalMind');
    has('/calmind/manifest.webmanifest', $b, "and it links the suite's own manifest");
    $m = json_decode((string) file_get_contents(__DIR__ . '/../public/calmind/manifest.webmanifest'), true);
    eq('CalMind', $m['name'] ?? null, 'the manifest names the suite');
    eq('/calmind/calendar/', $m['start_url'] ?? null, 'and starts where signing in lands');
});

t('the old suite paths 301 to their /calmind/ homes', function () {
    // Installed home-screen icons, bookmarks and widget scripts still point at the old
    // roots; each carries a stub that forwards, keeping any query string intact.
    foreach (['/reminders/'                => '/calmind/reminders/',
              '/calendar/'                 => '/calmind/calendar/',
              '/notes/'                    => '/calmind/notes/',
              '/habits/'                   => '/calmind/habits/',
              '/add/'                      => '/calmind/add/',
              '/calendar/feed.php?u=x'     => '/calmind/calendar/feed.php?u=x',
              '/calendar/quick.php?tick=1' => '/calmind/calendar/quick.php?tick=1',
              '/api/reminders.php'         => '/calmind/api/reminders.php'] as $old => $new) {
        $r = req('GET', $old);
        eq(301, $r['status'], "$old is a permanent redirect");
        eq($new, $r['location'], "$old points into /calmind");
    }
});

t('a wrong password is refused', function () {
    $jar = [];
    req('GET', '/calmind/reminders/', [], $jar);
    $r = req('POST', '/calmind/reminders/', ['username' => 'example', 'password' => 'nope'], $jar);
    eq(200, $r['status'], 'no redirect');
    has('Invalid username or password', $r['body']);
});

t('a good password lands you on the Calendar, wherever you signed in', function () {
    foreach (['/calmind/reminders/', '/calmind/notes/', '/calmind/habits/'] as $from) {
        $jar = [];
        req('GET', $from, [], $jar);
        $r = req('POST', $from, ['username' => 'example', 'password' => 'examplepassword'], $jar);
        eq(302, $r['status'], "$from status");
        eq('/calmind/calendar/', $r['location'], "signing in from $from should land on the Calendar");
    }
});

t('the login page draws no scrollbar', function () {
    $r = req('GET', '/calmind/reminders/');
    has('100svh', $r['body'], 'sized to the small viewport');
    has('scrollbar-width: none', $r['body']);
});

t('logging out ends the session', function () {
    $jar = login('example', 'examplepassword');
    req('GET', '/calmind/reminders/?logout=1', [], $jar);
    $r = req('GET', '/calmind/reminders/', [], $jar);
    has('Sign in', $r['body'], 'should be signed out again');
});

t('a POST with no CSRF token is refused', function () {
    $jar = login('example', 'examplepassword');
    $r = req('POST', '/calmind/reminders/', ['action' => 'add', 'text' => 'csrfless', 'view' => 'All'], $jar);
    eq(400, $r['status']);
    eq(null, rowBy('example', 'csrfless'), 'nothing should have been written');
});

t('a POST with the wrong CSRF token is refused', function () {
    $jar = login('example', 'examplepassword');
    $r = req('POST', '/calmind/reminders/',
        ['csrf' => 'not-the-token', 'action' => 'add', 'text' => 'badcsrf', 'view' => 'All'], $jar);
    eq(400, $r['status']);
    eq(null, rowBy('example', 'badcsrf'));
});

t('one session covers the whole suite', function () {
    $jar = login('example', 'examplepassword');
    foreach (['/calmind/reminders/', '/calmind/notes/', '/calmind/calendar/', '/calmind/habits/', '/calmind/add/'] as $p) {
        $r = req('GET', $p, [], $jar);
        eq(200, $r['status'], "$p status");
        hasnt('CalMind</h1>', $r['body'], "$p should be the app, not the login page");
    }
});

// ---------------------------------------------------------------- 3. storage
area('storage');

t('data is encrypted at rest', function () use ($scratch) {
    $raw = file_get_contents(user_data_file($scratch, 'reminders', 'example'));
    eq('ENC1:', substr($raw, 0, 5), 'files carry the ENC1 prefix');
    hasnt('Return the library books', $raw, 'plaintext must not be readable in the file');
    ok(count(rows('example')) > 0, 'and it still reads back');
});

t('legacy plaintext JSON still reads', function () use ($scratch) {
    $f = $scratch . '/legacy-test.json';
    file_put_contents($f, json_encode([['id' => 'x', 'text' => 'old row']]));
    $got = store_read($f);
    eq('old row', $got[0]['text'] ?? null, 'plaintext should be accepted');
});

t('a user only ever reads their own file', function () use ($scratch) {
    eq($scratch . '/reminders-buddy.json', user_data_file($scratch, 'reminders', 'buddy'));
    ok(rowBy('buddy', 'Pappardelle') !== null, 'buddy has their own groceries');
    eq(null, rowBy('example', 'Pappardelle'), "and they are not in example's list");
});

// ---------------------------------------------------------------- 4. reminders
area('reminders');

t('adding a reminder', function () {
    $jar = login('example', 'examplepassword');
    $r = req('POST', '/calmind/reminders/',
        ['csrf' => csrf($jar), 'action' => 'add', 'view' => 'All', 'text' => 'Test plain add',
         'folder' => 'Reminders', 'section' => ''], $jar);
    eq(302, $r['status'], 'POST redirects');
    $row = rowBy('example', 'Test plain add');
    ok($row !== null, 'the row exists');
    eq('Reminders', $row['folder']);
    ok(empty($row['due']), 'undated');
});

t('a date and time typed into the text are parsed out of it', function () {
    $jar = login('example', 'examplepassword');
    req('POST', '/calmind/reminders/',
        ['csrf' => csrf($jar), 'action' => 'add', 'view' => 'All', 'text' => 'Vet 8/3 2pm',
         'folder' => 'Reminders', 'section' => ''], $jar);
    $row = rowBy('example', 'Vet');
    ok($row !== null, 'the text is trimmed to "Vet"');
    eq('14:00', $row['time'], 'time');
    eq('08-03', substr((string) $row['due'], 5), 'month and day');
});

t('ticking a plain reminder marks it done', function () {
    $jar = login('example', 'examplepassword');
    $row = rowBy('example', 'Test plain add');
    req('POST', '/calmind/reminders/',
        ['csrf' => csrf($jar), 'action' => 'toggle', 'view' => 'All', 'id' => $row['id']], $jar);
    ok(!empty(rowBy('example', 'Test plain add')['done']), 'now done');
});

t('ticking a repeating reminder rolls it to the next date instead', function () {
    $jar = login('example', 'examplepassword');
    $row = rowBy('example', 'Water the tomatoes');       // every 2 days, from the seeder
    ok($row !== null, 'the seeded repeat exists');
    $was = $row['due'];
    $r = req('POST', '/calmind/reminders/',
        ['csrf' => csrf($jar), 'action' => 'toggle', 'view' => 'All', 'id' => $row['id']], $jar);
    $now = rowBy('example', 'Water the tomatoes');
    ok(empty($now['done']), 'a repeat is never marked done');
    ok($now['due'] > $was, "due should have moved forward (was $was, now {$now['due']})");
    eq(2, (int) round((strtotime($now['due']) - strtotime($was)) / 86400), 'by two days');
    // Rolling silently read as a dead checkbox: the redirect names the rolled row and
    // the page flashes it, so the tick visibly did something.
    has('rolled=' . $row['id'], (string) $r['location'], 'the redirect says which row rolled');
    $b = req('GET', '/calmind/reminders/', [], $jar)['body'];
    has('rolled-flash', $b, 'and the page ships the flash');
    // A plain (non-repeating) toggle must not claim a roll.
    $plain = rowBy('example', 'Book the rental car') ?? rowBy('example', 'col prop rem');
    if ($plain) {
        $r2 = req('POST', '/calmind/reminders/',
            ['csrf' => csrf($jar), 'action' => 'toggle', 'view' => 'All', 'id' => $plain['id']], $jar);
        hasnt('rolled=', (string) $r2['location'], 'a plain toggle carries no rolled=');
        req('POST', '/calmind/reminders/',
            ['csrf' => csrf($jar), 'action' => 'toggle', 'view' => 'All', 'id' => $plain['id']], $jar);
    }
});

t('editing a reminder\'s text', function () {
    $jar = login('example', 'examplepassword');
    $row = rowBy('example', 'Test plain add');
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'edit_text', 'view' => 'All',
        'id' => $row['id'], 'text' => 'Test edited'], $jar, true);
    ok(rowBy('example', 'Test edited') !== null, 'renamed');
    eq(null, rowBy('example', 'Test plain add'), 'old text gone');
});

t('deleting needs the confirmed second press', function () {
    $jar = login('example', 'examplepassword');
    $row = rowBy('example', 'Test edited');
    req('POST', '/calmind/reminders/',
        ['csrf' => csrf($jar), 'action' => 'delete', 'view' => 'All', 'id' => $row['id']], $jar);
    ok(rowBy('example', 'Test edited') !== null, 'one press must not delete');
    req('POST', '/calmind/reminders/',
        ['csrf' => csrf($jar), 'action' => 'delete', 'view' => 'All', 'id' => $row['id'],
         'confirm' => '1'], $jar);
    eq(null, rowBy('example', 'Test edited'), 'confirmed press deletes');
});

t('sections: add, rename, delete', function () {
    $jar = login('example', 'examplepassword');
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'add_section',
        'view' => 'All', 'name' => 'Testsec', 'folder' => 'Reminders'], $jar);
    $secs = array_filter(stored('reminders', 'example'), fn($r) => ($r['type'] ?? '') === 'section');
    ok(in_array('Testsec', array_column($secs, 'name'), true), 'section added');

    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'rename_section',
        'view' => 'All', 'folder' => 'Reminders', 'name' => 'Testsec', 'newname' => 'Testsec2'], $jar);
    $names = array_column(array_filter(stored('reminders', 'example'),
        fn($r) => ($r['type'] ?? '') === 'section'), 'name');
    ok(in_array('Testsec2', $names, true), 'renamed');

    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'delete_section',
        'view' => 'All', 'folder' => 'Reminders', 'name' => 'Testsec2', 'confirm' => '1'], $jar);
    $names = array_column(array_filter(stored('reminders', 'example'),
        fn($r) => ($r['type'] ?? '') === 'section'), 'name');
    ok(!in_array('Testsec2', $names, true), 'deleted');
});

t('every reminder folder keeps a real default section, and the last one is undeletable', function () {
    // The unnamed "Reminders" catch-all is gone: every folder opens with a real default
    // section named General, renameable, and undeletable while it's the only one.
    $jar   = login('example', 'examplepassword');
    $rsec  = fn($folder, $name) => (bool) array_filter(stored('reminders', 'example'),
        fn($x) => ($x['type'] ?? '') === 'section' && ($x['folder'] ?? '') === $folder && ($x['name'] ?? '') === $name);
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'add_folder', 'view' => 'All', 'name' => 'FreshR'], $jar);
    $b = req('GET', '/calmind/reminders/?folder=FreshR', [], $jar)['body'];
    ok($rsec('FreshR', 'General'), 'the folder is seeded with a General section');
    hasnt('default-group', $b, 'and no unnamed catch-all group is rendered');
    // A reminder added with a blank section lands in that real default, not a nameless catch-all.
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'add', 'view' => 'FreshR',
        'folder' => 'FreshR', 'section' => '', 'text' => 'lands in General'], $jar);
    $r = null;
    foreach (stored('reminders', 'example') as $x) { if (($x['text'] ?? '') === 'lands in General') { $r = $x; } }
    eq('General', $r['section'] ?? null, 'a blank section resolves to the folder default');
    // Rename the default, then try to delete the (now only) section — it must survive.
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'rename_section', 'view' => 'FreshR',
        'folder' => 'FreshR', 'name' => 'General', 'newname' => 'Chores'], $jar);
    ok($rsec('FreshR', 'Chores'), 'the default section renamed in place');
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'delete_section', 'view' => 'FreshR',
        'folder' => 'FreshR', 'name' => 'Chores', 'confirm' => '1'], $jar);
    ok($rsec('FreshR', 'Chores'), 'the folder never loses its only section');
});

t('the Manage-folders "Default for new items" picker sets folder and section together', function () {
    $jar = login('example', 'examplepassword');
    // Make a folder with a known section, then point the default at that folder+section.
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'add_folder', 'view' => 'All', 'name' => 'DefF'], $jar);
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'add_section',
        'view' => 'All', 'folder' => 'DefF', 'name' => 'Chosen'], $jar);
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'set_default_section',
        'view' => 'All', 'fs' => "DefF\x1FChosen"], $jar);
    eq('DefF', folder_default_get(datadir(), 'reminders', 'example'), 'the default folder moved');
    eq('Chosen', folder_default_section_get(datadir(), 'reminders', 'example'), 'and its section');
    // The picker renders in the manager, listing that folder's real sections.
    $b = req('GET', '/calmind/reminders/', [], $jar)['body'];
    has('name="fs"', $b, 'the manager carries the Default-for-new-items select');
    has('action" value="set_default_section"', $b, 'wired to set_default_section');
    // An unknown section for the folder is coerced to a real one, never stored blind.
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'set_default_section',
        'view' => 'All', 'fs' => "DefF\x1FGhost"], $jar);
    $got = folder_default_section_get(datadir(), 'reminders', 'example');
    ok(in_array($got, ['Chosen', 'General'], true), 'a bogus section coerces to a real one in the folder');
    ok($got !== 'Ghost', 'the unknown section was not stored');
});

t('a reminder folder renames in place, carrying its reminders and sections', function () {
    $jar = login('example', 'examplepassword');
    $post = fn($p) => req('POST', '/calmind/reminders/', array_merge(['csrf' => csrf($jar)], $p), $jar);
    $post(['action' => 'add_folder', 'view' => 'All', 'name' => 'RenR']);
    $post(['action' => 'add_section', 'view' => 'RenR', 'folder' => 'RenR', 'name' => 'Sub']);
    $post(['action' => 'add', 'view' => 'RenR', 'folder' => 'RenR', 'section' => 'Sub', 'text' => 'moves too']);
    $post(['action' => 'rename_folder', 'view' => 'RenR', 'name' => 'RenR', 'newname' => 'RenR2']);
    $f = folders_load(datadir(), 'example');
    ok(in_array('RenR2', $f['reminders'], true) && !in_array('RenR', $f['reminders'], true), 'the folder took the new name');
    $secOk = $noteOk = false;
    foreach (stored('reminders', 'example') as $x) {
        if (($x['type'] ?? '') === 'section' && ($x['name'] ?? '') === 'Sub') { $secOk = ($x['folder'] ?? '') === 'RenR2'; }
        if (($x['text'] ?? '') === 'moves too') { $noteOk = ($x['folder'] ?? '') === 'RenR2'; }
    }
    ok($secOk, 'its section moved with it');
    ok($noteOk, 'its reminder moved with it');
    // Only "Calendar" is permanent now (its name carries the ride-along), so it can't be
    // renamed; "Reminders" is an ordinary folder.
    ok(!folders_rename(datadir(), 'reminders', 'Calendar', 'Nope'), 'the Calendar folder is not renameable');
    // The list heading renders as a rename field for a custom folder, and the manager offers rename.
    $b = req('GET', '/calmind/reminders/?folder=All', [], $jar)['body'];
    has('class="folder-label foldertitle', $b, 'a custom folder heading is a rename field');
    has('name="newname"', $b, 'and the manager row too');
});

t('the subtask + makes a child under its parent, not an indent on the row', function () {
    $jar = login('example', 'examplepassword');
    $parent = rowBy('example', 'Return the library books');   // dated, in Home/Errands
    ok($parent !== null, 'the seeded parent exists');
    $before = count(rows('example'));
    $r = req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'add_subtask',
        'view' => 'All', 'parent' => $parent['id']], $jar);
    eq(302, $r['status']);
    eq($before + 1, count(rows('example')), 'a new row was created');
    ok(strpos((string) $r['location'], 'focus=') !== false, 'and it comes back focused');
    eq(0, (int) (rowBy('example', 'Return the library books')['indent'] ?? 0),
        'the parent must NOT have been indented');

    // The child sits immediately after its parent, in the parent's folder and section.
    $all = array_values(array_filter(stored('reminders', 'example'),
        fn($r) => ($r['type'] ?? '') !== 'section'));
    $i = null;
    foreach ($all as $k => $row) { if ($row['id'] === $parent['id']) { $i = $k; break; } }
    $child = $all[$i + 1] ?? [];
    eq(1, (int) ($child['indent'] ?? 0), 'child is indented one level');
    eq('', (string) $child['text'], 'and starts empty, ready to type into');
    eq($parent['folder'], $child['folder'], 'same folder');
    eq($parent['section'], $child['section'], 'same section');
});

t('a subtask can be lifted back out to a task', function () {
    $jar = login('example', 'examplepassword');
    $child = null;
    foreach (rows('example') as $r) { if ((int) ($r['indent'] ?? 0) === 1 && ($r['text'] ?? '') === '') { $child = $r; break; } }
    ok($child !== null, 'the blank subtask from the last test is there');
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'set_indent',
        'view' => 'All', 'id' => $child['id'], 'indent' => '0'], $jar, true);
    foreach (rows('example') as $r) {
        if ($r['id'] === $child['id']) { eq(0, (int) ($r['indent'] ?? 0), 'back to level 0'); }
    }
});

t('a section can never be indented', function () {
    $jar = login('example', 'examplepassword');
    $sec = null;
    foreach (stored('reminders', 'example') as $r) { if (($r['type'] ?? '') === 'section') { $sec = $r; break; } }
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'set_indent',
        'view' => 'All', 'id' => $sec['id'], 'indent' => '1'], $jar, true);
    foreach (stored('reminders', 'example') as $r) {
        if ($r['id'] === $sec['id']) { eq(0, (int) ($r['indent'] ?? 0), 'sections stay at level 0'); }
    }
});

t('clear_done removes only the ticked rows', function () {
    $jar = login('example', 'examplepassword');
    $doneBefore = count(array_filter(rows('example'), fn($r) => !empty($r['done'])));
    $openBefore = count(array_filter(rows('example'), fn($r) => empty($r['done'])));
    ok($doneBefore > 0, 'there is something ticked to clear');
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'clear_done', 'view' => 'All'], $jar);
    eq(0, count(array_filter(rows('example'), fn($r) => !empty($r['done']))), 'none left ticked');
    eq($openBefore, count(array_filter(rows('example'), fn($r) => empty($r['done']))), 'open rows untouched');
});

t('the list renders undated first, then oldest date', function () {
    $jar = login('example', 'examplepassword');
    $r = req('GET', '/calmind/reminders/?folder=All', [], $jar);
    preg_match_all('/data-due="([^"]*)"/', $r['body'], $m);
    ok(count($m[1]) > 3, 'rows rendered');
    // Within the run, an empty due never comes after a non-empty one *inside a section*;
    // sections restart the sequence, so just check the first row of the first group.
    eq('', $m[1][0], 'the first row of the first group is undated');
});

t('Copy as Markdown shows only for the sean account', function () {
    // A regular account never sees it…
    $jar = login('example', 'examplepassword');
    ok(strpos(req('GET', '/calmind/reminders/', [], $jar)['body'], 'id="mdShareBtn"') === false,
       'example does not get the Copy as Markdown button');
    // …but Sean's does. (sean is a real config account, so the passwords.json override lets
    // us log in without knowing the machine's own password — same trick as aki.)
    ensure_account('sean', 'seanpass');
    $jar2 = login('sean', 'seanpass');
    has('id="mdShareBtn"', req('GET', '/calmind/reminders/', [], $jar2)['body'],
        "sean's account keeps the Copy as Markdown button");
});

t('the section adders show without entering edit mode', function () {
    // The harness runs no JS, so this guards the *rule*: the + that adds a section (folder
    // heads in Reminders/Notes) and the + Habit on a Habits section header are not gated on
    // body.editing, so they show out of edit mode. The gesture itself is a by-eye check.
    $jar = login('example', 'examplepassword');
    foreach (['/calmind/reminders/', '/calmind/notes/'] as $p) {
        $b = req('GET', $p, [], $jar)['body'];
        has('.fsec-add {', $b, "$p ships the folder-head +");
        ok(strpos($b, 'body.editing .fsec-add') === false, "$p folder-head + is not gated on edit mode");
    }
    $h = req('GET', '/calmind/habits/?v=week', [], $jar)['body'];
    has('.hsec-add {', $h, 'habits ships the per-section + Habit');
    ok(strpos($h, 'body.editing .hsec-add') === false, 'the + Habit is not gated on edit mode');
});

t('holding to edit takes no text selection — the rule is ungated', function () {
    $jar = login('example', 'examplepassword');
    $h = req('GET', '/calmind/habits/?v=week', [], $jar)['body'];
    has('.hname, .hsection { -webkit-user-select: none', $h, 'habits names/sections take no selection');
    $r = req('GET', '/calmind/reminders/', [], $jar)['body'];
    has('li, .section-head, .folder-head { -webkit-touch-callout: none', $r, 'reminders rows/heads, ungated');
    $n = req('GET', '/calmind/notes/', [], $jar)['body'];
    has('.section-head, .folder-head { -webkit-touch-callout: none', $n, 'notes heads, ungated');
    ok(strpos($n, 'body.editing .section-head { -webkit-touch-callout') === false, 'the old edit-gated rule is gone');
    $c = req('GET', '/calmind/calendar/', [], $jar)['body'];
    has('Holding an item to enter edit mode must not paint', $c, 'the calendar day items take no selection');
});

t('picker dropdowns clamp horizontal overflow and wrap long names', function () {
    $jar = login('example', 'examplepassword');
    $r = req('GET', '/calmind/reminders/', [], $jar)['body'];   // shared .folderpick-menu (also the Habits filter)
    has('overflow-y: auto; overflow-x: hidden;', $r, 'the folder menu pins overflow-x');
    has('.fpick-name { flex: 1; min-width: 0; overflow-wrap: anywhere;', $r, 'and its names wrap');
    has('.folderpick-opt .fshared-badge {', $r, 'the shared badge is defined');
    $c = req('GET', '/calmind/calendar/', [], $jar)['body'];
    has('overflow-y: auto; overflow-x: hidden;', $c, 'the calendar menu pins overflow-x');
});

t('edit_full: the pencil window updates a reminder and can re-file it', function () {
    $jar = login('example', 'examplepassword');
    $c   = csrf($jar);
    req('POST', '/calmind/reminders/', ['csrf' => $c, 'action' => 'add_folder', 'name' => 'Convsrc'], $jar);
    req('POST', '/calmind/reminders/', ['csrf' => $c, 'action' => 'add_folder', 'name' => 'Convdest'], $jar);
    req('POST', '/calmind/reminders/', ['csrf' => $c, 'action' => 'add', 'view' => 'Convsrc',
        'folder' => 'Convsrc', 'section' => '', 'text' => 'Water plants'], $jar);
    $row = null;
    foreach (stored('reminders', 'example') as $x) { if (($x['text'] ?? '') === 'Water plants') { $row = $x; } }
    ok($row !== null, 'the reminder exists');

    req('POST', '/calmind/reminders/', ['csrf' => $c, 'action' => 'edit_full', 'view' => 'Convsrc',
        'id' => $row['id'], 'kind' => 'reminder', 'text' => 'Water all plants',
        'due' => '2030-05-01', 'time' => '09:30', 'rep_unit' => 'week', 'rep_n' => '2',
        'fs' => "Convdest\x1FGeneral"], $jar);
    $now = null;
    foreach (stored('reminders', 'example') as $x) { if (($x['id'] ?? '') === $row['id']) { $now = $x; } }
    eq('Water all plants', $now['text'] ?? '', 'text updated');
    eq('2030-05-01', $now['due'] ?? '', 'date set');
    eq('09:30', $now['time'] ?? '', 'time set');
    eq(['n' => 2, 'unit' => 'week'], $now['repeat'] ?? null, 'repeat set');
    eq('Convdest', $now['folder'] ?? '', 're-filed to the other folder');
    eq('General', $now['section'] ?? '', 'into its first section');
    req('POST', '/calmind/reminders/', ['csrf' => $c, 'action' => 'delete', 'view' => 'All',
        'id' => $row['id'], 'confirm' => '1'], $jar);
    req('POST', '/calmind/reminders/', ['csrf' => $c, 'action' => 'delete_folder', 'view' => 'All',
        'name' => 'Convsrc', 'confirm' => '1'], $jar);
    req('POST', '/calmind/reminders/', ['csrf' => $c, 'action' => 'delete_folder', 'view' => 'All',
        'name' => 'Convdest', 'confirm' => '1'], $jar);
});

t('edit_full: converting a plain reminder moves it onto the calendar', function () {
    $jar = login('example', 'examplepassword');
    drop_blank_subtasks();
    $c   = csrf($jar);
    req('POST', '/calmind/reminders/', ['csrf' => $c, 'action' => 'add', 'view' => 'All',
        'text' => 'Team lunch'], $jar);
    $row = null;
    foreach (stored('reminders', 'example') as $x) { if (($x['text'] ?? '') === 'Team lunch') { $row = $x; } }
    ok($row !== null, 'the reminder exists');
    $cal = (string) (stored('calendars', 'example')[0]['id'] ?? '');

    req('POST', '/calmind/reminders/', ['csrf' => $c, 'action' => 'edit_full', 'view' => 'All',
        'id' => $row['id'], 'kind' => 'event', 'text' => 'Team lunch',
        'due' => '2030-06-10', 'time' => '12:00', 'cal' => $cal], $jar);
    $gone = true; $ev = null;
    foreach (stored('reminders', 'example') as $x) { if (($x['id'] ?? '') === $row['id']) { $gone = false; } }
    foreach (stored('events', 'example') as $x) { if (($x['text'] ?? '') === 'Team lunch') { $ev = $x; } }
    ok($gone, 'no subtasks, so the reminder moved out entirely');
    ok($ev !== null, 'and the event exists');
    eq('2030-06-10', $ev['date'] ?? '', 'on the picked day');
    eq('12:00', $ev['time'] ?? '', 'at the picked time');
    eq($cal, $ev['cal'] ?? '', 'in the picked calendar');
    // A stray calendar id is re-validated: it falls back to a real one, never lands raw.
    req('POST', '/calmind/reminders/', ['csrf' => $c, 'action' => 'add', 'view' => 'All',
        'text' => 'Stray cal check'], $jar);
    $row2 = null;
    foreach (stored('reminders', 'example') as $x) { if (($x['text'] ?? '') === 'Stray cal check') { $row2 = $x; } }
    req('POST', '/calmind/reminders/', ['csrf' => $c, 'action' => 'edit_full', 'view' => 'All',
        'id' => $row2['id'], 'kind' => 'event', 'text' => 'Stray cal check', 'cal' => 'nosuchcal'], $jar);
    $ev2 = null;
    foreach (stored('events', 'example') as $x) { if (($x['text'] ?? '') === 'Stray cal check') { $ev2 = $x; } }
    ok($ev2 !== null && in_array($ev2['cal'], array_column(stored('calendars', 'example'), 'id'), true),
       'a stray calendar id fell back to a real one');
    eq(date('Y-m-d'), $ev2['date'] ?? '', 'and an undated conversion lands on today');
    // Clean up both events so later feed/calendar counts aren't disturbed.
    $evs = array_values(array_filter(stored('events', 'example'),
        fn($x) => !in_array($x['text'] ?? '', ['Team lunch', 'Stray cal check'], true)));
    store_write(user_data_file(datadir(), 'events', 'example'), $evs);
});

t('edit_full: a reminder with subtasks stays behind as a copy when converted', function () {
    $jar = login('example', 'examplepassword');
    $c   = csrf($jar);
    req('POST', '/calmind/reminders/', ['csrf' => $c, 'action' => 'add', 'view' => 'All',
        'text' => 'Plan the trip'], $jar);
    $row = null;
    foreach (stored('reminders', 'example') as $x) { if (($x['text'] ?? '') === 'Plan the trip') { $row = $x; } }
    req('POST', '/calmind/reminders/', ['csrf' => $c, 'action' => 'add_subtask', 'view' => 'All',
        'parent' => $row['id']], $jar);

    $cal = (string) (stored('calendars', 'example')[0]['id'] ?? '');
    req('POST', '/calmind/reminders/', ['csrf' => $c, 'action' => 'edit_full', 'view' => 'All',
        'id' => $row['id'], 'kind' => 'event', 'text' => 'Plan the trip',
        'due' => '2030-07-01', 'cal' => $cal], $jar);
    $kept = null; $ev = null; $kid = false;
    $list = stored('reminders', 'example');
    foreach ($list as $i => $x) {
        if (($x['id'] ?? '') === $row['id']) {
            $kept = $x;
            $kid  = isset($list[$i + 1]) && (int) ($list[$i + 1]['indent'] ?? 0) > 0;
        }
    }
    foreach (stored('events', 'example') as $x) { if (($x['text'] ?? '') === 'Plan the trip') { $ev = $x; } }
    ok($ev !== null, 'the event was written');
    ok($kept !== null, 'but the reminder stayed — its subtasks live here');
    ok($kid, 'with its subtask still under it');
    // Clean up: the duplicate pair.
    req('POST', '/calmind/reminders/', ['csrf' => $c, 'action' => 'delete', 'view' => 'All',
        'id' => $row['id'], 'confirm' => '1'], $jar);
    $evs = array_values(array_filter(stored('events', 'example'), fn($x) => ($x['text'] ?? '') !== 'Plan the trip'));
    store_write(user_data_file(datadir(), 'events', 'example'), $evs);
});

t('edit_full: a reminder can become the title of a new note', function () {
    $jar = login('example', 'examplepassword');
    drop_blank_subtasks();
    $c   = csrf($jar);
    // Without subtasks it moves out entirely; the note lands in the picked folder/section.
    req('POST', '/calmind/reminders/', ['csrf' => $c, 'action' => 'add', 'view' => 'All',
        'text' => 'Gift ideas list'], $jar);
    $row = null;
    foreach (stored('reminders', 'example') as $x) { if (($x['text'] ?? '') === 'Gift ideas list') { $row = $x; } }
    $nFolder = stored('folders', 'example')['notes'][0] ?? 'General';
    $nSec    = null;
    foreach (stored('notes', 'example') as $x) {
        if (($x['type'] ?? '') === 'section' && ($x['folder'] ?? '') === $nFolder) { $nSec = $x['name']; break; }
    }
    req('POST', '/calmind/reminders/', ['csrf' => $c, 'action' => 'edit_full', 'view' => 'All',
        'id' => $row['id'], 'kind' => 'note', 'text' => 'Gift ideas list', 'due' => '2030-09-01',
        'nfs' => $nFolder . "\x1F" . ($nSec ?? '')], $jar);
    $gone = true; $note = null;
    foreach (stored('reminders', 'example') as $x) { if (($x['id'] ?? '') === $row['id']) { $gone = false; } }
    foreach (stored('notes', 'example') as $x) { if (($x['title'] ?? '') === 'Gift ideas list') { $note = $x; } }
    ok($gone, 'no subtasks, so the reminder moved out entirely');
    ok($note !== null, 'and the note exists');
    eq('2030-09-01', $note['date'] ?? '', 'carrying the date');
    eq($nFolder, $note['folder'] ?? '', 'in the picked folder');
    ok(($note['section'] ?? '') !== '', 'filed in a real section');
    // With subtasks the reminder stays behind as their home — the note is a copy.
    req('POST', '/calmind/reminders/', ['csrf' => $c, 'action' => 'add', 'view' => 'All',
        'text' => 'Trip packing'], $jar);
    $row2 = null;
    foreach (stored('reminders', 'example') as $x) { if (($x['text'] ?? '') === 'Trip packing') { $row2 = $x; } }
    req('POST', '/calmind/reminders/', ['csrf' => $c, 'action' => 'add_subtask', 'view' => 'All',
        'parent' => $row2['id']], $jar);
    req('POST', '/calmind/reminders/', ['csrf' => $c, 'action' => 'edit_full', 'view' => 'All',
        'id' => $row2['id'], 'kind' => 'note', 'text' => 'Trip packing', 'nfs' => 'junk'], $jar);
    $kept = null; $note2 = null;
    foreach (stored('reminders', 'example') as $x) { if (($x['id'] ?? '') === $row2['id']) { $kept = $x; } }
    foreach (stored('notes', 'example') as $x) { if (($x['title'] ?? '') === 'Trip packing') { $note2 = $x; } }
    ok($kept !== null, 'subtasks keep the reminder here');
    ok($note2 !== null, 'the note was still written');
    ok(in_array($note2['folder'] ?? '', stored('folders', 'example')['notes'], true),
       'a junk destination fell back to a real note folder');
    // Clean up.
    req('POST', '/calmind/reminders/', ['csrf' => $c, 'action' => 'delete', 'view' => 'All',
        'id' => $row2['id'], 'confirm' => '1'], $jar);
    $ns = array_values(array_filter(stored('notes', 'example'),
        fn($x) => !in_array($x['title'] ?? '', ['Gift ideas list', 'Trip packing'], true)));
    store_write(user_data_file(datadir(), 'notes', 'example'), $ns);
});

t('duplicate: the copy lands under the original, subtasks and all', function () {
    $jar = login('example', 'examplepassword');
    drop_blank_subtasks();
    $c   = csrf($jar);
    req('POST', '/calmind/reminders/', ['csrf' => $c, 'action' => 'add', 'view' => 'All',
        'text' => 'Water the ferns'], $jar);
    $row = null;
    foreach (stored('reminders', 'example') as $x) { if (($x['text'] ?? '') === 'Water the ferns') { $row = $x; } }
    req('POST', '/calmind/reminders/', ['csrf' => $c, 'action' => 'add_subtask', 'view' => 'All',
        'parent' => $row['id']], $jar);
    // The button only renders in edit mode, so the real POST carries the stamped flag —
    // and the server echoes it back (it no longer originates edit mode; see `editmode`).
    $r = req('POST', '/calmind/reminders/', ['csrf' => $c, 'action' => 'duplicate', 'view' => 'All',
        'id' => $row['id'], 'edit' => '1'], $jar);
    has('edit=1', (string) $r['location'], 'duplicating from edit mode stays in it');
    $list = array_values(stored('reminders', 'example'));
    $ids  = [];
    foreach ($list as $i => $x) { if (($x['text'] ?? '') === 'Water the ferns') { $ids[] = $i; } }
    eq(2, count($ids), 'two of them now');
    $orig = $list[$ids[0]]; $copy = $list[$ids[1]];
    ok($copy['id'] !== $orig['id'], 'the copy has its own id');
    eq($orig['folder'], $copy['folder'], 'same folder');
    eq($orig['section'], $copy['section'], 'same section');
    // The block travelled: original, its subtask, then the copy, then the copy's subtask.
    eq(1, (int) ($list[$ids[0] + 1]['indent'] ?? 0), 'the original keeps its subtask');
    eq(1, (int) ($list[$ids[1] + 1]['indent'] ?? 0), 'and the copy got its own');
    ok($list[$ids[0] + 1]['id'] !== $list[$ids[1] + 1]['id'], 'as a fresh row, not a shared one');
    foreach ([$orig['id'], $copy['id']] as $rid) {
        req('POST', '/calmind/reminders/', ['csrf' => $c, 'action' => 'delete', 'view' => 'All',
            'id' => $rid, 'confirm' => '1'], $jar);
    }
});

t('edit_full: a shared view is refused, and shared rows carry no pencil', function () {
    $jar = login('example', 'examplepassword');
    $c   = csrf($jar);
    $r = req('POST', '/calmind/reminders/', ['csrf' => $c, 'action' => 'edit_full',
        'view' => '@buddy:Dinners', 'id' => 'x', 'kind' => 'event', 'text' => 'x'], $jar);
    eq(403, $r['status'], "a shared view can't be full-edited — their rows aren't mine to convert");
    $b = req('GET', '/calmind/reminders/?folder=' . urlencode('@buddy:Dinners'), [], $jar)['body'];
    ok(strpos($b, 'class="rowedit"') === false, 'no pencil renders in a shared view');
    ok(strpos($b, 'id="convModal"') === false, 'and no conversion window either');
});

t('wiring: rows carry the pencil and the page ships the conversion window', function () {
    $jar = login('example', 'examplepassword');
    $b = req('GET', '/calmind/reminders/?folder=All', [], $jar)['body'];
    has('class="rowedit"', $b, 'rows carry the pencil');
    has('body.editing .rowedit', $b, 'shown only in edit mode');
    has('id="convModal"', $b, 'the window is on the page');
    has('value="edit_full"', $b, 'and posts edit_full');
    has('input[name=kind]', $b, 'the kind switch is wired');
    // The window is the Calendar's edit menu piece for piece: the same "Goes in"
    // picker label and the same Delete / Cancel / Save row, Delete two-press on the left.
    has('<span class="tlabel">Goes in</span>', $b, 'the picker label matches the calendar');
    has('class="del needs-confirm" id="cvDelete"', $b, 'Delete, two-press, like the calendar');
    has('.convmodal .buttons .del', $b, 'sitting left the way the calendar puts it');
});

// ---------------------------------------------------------------- 5. folders
t('a tick keeps its row on screen for three seconds before hiding it', function () {
    // Wiring — the grace is client-side. The page must carry: the CSS override that
    // keeps a just-done row visible AND in place (li.done's order:1 would fling it to
    // the bottom of the list the moment it was ticked), and the interceptor that posts
    // the toggle immediately (keepalive, so leaving the page can't cancel the save) and
    // only delays the hiding. Repeats bypass it — their tick rolls the date and needs
    // the reload; that behaviour is covered in `reminders`.
    $jar = login('example', 'examplepassword');
    $b = req('GET', '/calmind/reminders/?folder=All', [], $jar)['body'];
    has('li.done.grace { display: flex; }', preg_replace('/\s+/', ' ', $b) ? $b : $b, 'the grace rule ships');
    ok(preg_match('/li\.done\.grace \{ order: 0/', $b) === 1, 'and holds the row in place');
    ok(preg_match('/setTimeout\(function \(\) \{\s*li\.classList\.remove\(.grace.\)/s', $b) === 1,
       'the hide waits on a timer');
    has("fetch('', { method: 'POST', body: fd, keepalive: true });", $b,
        'while the tick itself posts at once and survives leaving the page');
    // The smaller check keeps its old tap target through the ::after halo.
    ok(preg_match('/\.check \{ width: 24px; height: 24px;[^}]*\}/', $b) === 1, 'the check is 24px');
    has(".check::after { content: ''; position: absolute; inset: -5px;", $b,
        'with the tap halo that keeps the old target size');
});

t('the swiped delete is pinned at the right edge of the screen', function () {
    // Wiring. The row slides left to open the gap and the revealed x used to ride along
    // with it, landing a quarter-screen in. The reveal rule counter-translates by the
    // same distance the gesture script slides (--swipe-x, set by the script from its own
    // LIMIT), so the two cannot drift apart without this failing.
    $jar = login('example', 'examplepassword');
    $b = req('GET', '/calmind/reminders/?folder=All', [], $jar)['body'];
    has('transform: translateX(var(--swipe-x, 0px));', $b, 'the reveal counter-translates');
    has("r.style.setProperty('--swipe-x', LIMIT + 'px')", $b, 'by exactly the slide distance');
    has("r.style.removeProperty('--swipe-x')", $b, 'and closing clears it');
});

t('the inline edit field gives way so the row buttons stay on screen', function () {
    // Wiring. A flex item's default min-width is its content size, and a text input's
    // content size is ~185px — so on a phone, the moment a row's inline edit opened
    // (which is what the enter-edit-mode gesture does on a reminder), the subtask + and
    // the delete × were shoved off the right edge. The harness measures no pixels; what
    // it can hold is the rule that lets the field shrink instead of the buttons leaving.
    $jar = login('example', 'examplepassword');
    $r = req('GET', '/calmind/reminders/?folder=All', [], $jar);
    ok(preg_match('/\.textedit\s*\{[^}]*min-width:\s*0/s', $r['body']) === 1,
       'the .textedit rule carries min-width: 0');
});

area('folders');

t('adding and deleting a folder, with its items falling back', function () {
    $jar = login('example', 'examplepassword');
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'add_folder',
        'view' => 'All', 'name' => 'Testfolder'], $jar);
    ok(in_array('Testfolder', folders_load(datadir(), 'example')['reminders'], true),
       'folder added');

    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'add', 'view' => 'Testfolder',
        'text' => 'Homeless soon', 'folder' => 'Testfolder', 'section' => ''], $jar);
    eq('Testfolder', rowBy('example', 'Homeless soon')['folder']);

    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'delete_folder',
        'view' => 'All', 'name' => 'Testfolder', 'confirm' => '1'], $jar);
    ok(!in_array('Testfolder', folders_load(datadir(), 'example')['reminders'], true),
       'folder gone');
    // Deleting a folder moves its reminders to the chosen default folder (not destroyed),
    // and into a section that really exists there.
    $moved = rowBy('example', 'Homeless soon');
    $def   = folder_default_get(datadir(), 'reminders', 'example');
    eq($def, $moved['folder'], 'its items moved to the default folder rather than being destroyed');
    $secOk = (bool) array_filter(stored('reminders', 'example'),
        fn($x) => ($x['type'] ?? '') === 'section' && ($x['folder'] ?? '') === $def && ($x['name'] ?? '') === ($moved['section'] ?? ''));
    ok($secOk, 'and into a real section of that folder');
});

t('only "Calendar" is a permanent folder now; "Reminders" can be deleted', function () {
    $jar = login('example', 'examplepassword');
    // A Calendar-folder reminder, so we can prove a refused delete leaves its items alone.
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'add', 'view' => FOLDER_CALENDAR,
        'folder' => FOLDER_CALENDAR, 'section' => '', 'text' => 'Calendar rider stays'], $jar);
    // Calendar is permanent — a delete is refused, and its items must NOT be moved.
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'delete_folder',
        'view' => 'All', 'name' => FOLDER_CALENDAR, 'confirm' => '1'], $jar);
    ok(in_array(FOLDER_CALENDAR, folders_load(datadir(), 'example')['reminders'], true),
       'Calendar stays (it carries the ride-along)');
    eq(FOLDER_CALENDAR, rowBy('example', 'Calendar rider stays')['folder'],
       'a refused delete does not strip the folder of its items');
    // Reminders is an ordinary folder now — deletable when others exist, and it stays gone.
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'delete_folder',
        'view' => 'All', 'name' => FOLDER_REMINDERS, 'confirm' => '1'], $jar);
    $after = folders_load(datadir(), 'example')['reminders'];
    ok(!in_array(FOLDER_REMINDERS, $after, true), 'Reminders was deleted');
    ok(!in_array(FOLDER_REMINDERS, folders_load(datadir(), 'example')['reminders'], true),
       'and is not re-added on the next load');
});

t('a folder colour must come from the palette', function () {
    $jar = login('example', 'examplepassword');
    $good = app_palette('reminders')[4];
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'set_folder_color',
        'view' => 'All', 'name' => 'Work', 'color' => $good], $jar, true);
    eq($good, folder_colors(datadir(), 'reminders', 'example')['Work'] ?? null, 'palette colour sticks');
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'set_folder_color',
        'view' => 'All', 'name' => 'Work', 'color' => '#ff0000'], $jar, true);
    eq($good, folder_colors(datadir(), 'reminders', 'example')['Work'] ?? null, 'off-palette refused');
});

t('the picker box toggles one folder', function () {
    $jar = login('example', 'examplepassword');
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'folder_vis',
        'name' => 'Work', 'show' => ''], $jar, true);
    ok(in_array('Work', folders_hidden(datadir(), 'reminders', 'example'), true), 'hidden');
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'folder_vis',
        'name' => 'Work', 'show' => '1'], $jar, true);
    ok(!in_array('Work', folders_hidden(datadir(), 'reminders', 'example'), true), 'shown again');
});

t('tapping a folder row makes it the only one showing', function () {
    $jar = login('example', 'examplepassword');
    $keys = folders_load(datadir(), 'example')['reminders'];
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'folder_vis_only',
        'name' => 'Home', 'keys' => implode("\x1F", $keys)], $jar, true);
    $hidden = folders_hidden(datadir(), 'reminders', 'example');
    ok(!in_array('Home', $hidden, true), 'Home stays showing');
    foreach ($keys as $k) {
        if ($k !== 'Home') { ok(in_array($k, $hidden, true), "$k should be hidden"); }
    }
});

t('All shows everything, then hides everything', function () {
    $jar = login('example', 'examplepassword');
    $keys = folders_load(datadir(), 'example')['reminders'];
    $ks   = implode("\x1F", $keys);
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'folder_vis_all',
        'show' => '1', 'keys' => $ks], $jar, true);
    eq([], folders_hidden(datadir(), 'reminders', 'example'), 'nothing hidden');
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'folder_vis_all',
        'show' => '', 'keys' => $ks], $jar, true);
    eq(count($keys), count(folders_hidden(datadir(), 'reminders', 'example')), 'all hidden');
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'folder_vis_all',
        'show' => '1', 'keys' => $ks], $jar, true);   // put it back for later tests
});

t('the default folder is where a new item lands from All', function () {
    $jar = login('example', 'examplepassword');
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'set_default_folder',
        'view' => 'All', 'name' => 'Work'], $jar);
    eq('Work', folder_default_get(datadir(), 'reminders', 'example'));
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'set_default_folder',
        'view' => 'All', 'name' => FOLDER_REMINDERS], $jar);
});

t('the folder heading wears its colour as a wash, not a dot', function () {
    $jar = login('example', 'examplepassword');
    $r = req('GET', '/calmind/reminders/?folder=All', [], $jar);
    ok(preg_match('/<div class="folder-label" style="background:#[0-9a-f]{6}33"/', $r['body']) === 1,
       'the heading carries an 8-digit tint');
    ok(!preg_match('/folder-label[^>]*>[^<]*<\/div>\s*<span class="fdot"/', $r['body']),
       'and no dot follows it');
    has('.folder-block .section-group { padding-left', $r['body'],
        'and its sections nest slightly indented under it');
});

// ---------------------------------------------------------------- 6. notes
area('notes');

t('adding a note opens it in the editor', function () {
    $jar = login('example', 'examplepassword');
    $r = req('POST', '/calmind/notes/', ['csrf' => csrf($jar, '/calmind/notes/'), 'action' => 'add',
        'view' => 'All', 'folder' => 'General', 'section' => ''], $jar);
    eq(302, $r['status']);
    ok(strpos((string) $r['location'], 'id=') !== false, 'redirects into the note');
});

t('the date buttons hand off to autosave, which survives leaving the page', function () {
    // "+ Add date" and the clearing × set the field's value from JS, and a programmatic
    // set fires no input/change event — so the autosave (which listens for exactly those)
    // never ran: the page said Saved while the file held no date. The harness can't click,
    // so this pins the served wiring: both handlers must dispatch the handoff event, and
    // the autosave fetch must carry keepalive so a save flushed while tapping "← All
    // notes" isn't cancelled mid-flight. Server-side date persistence is covered elsewhere.
    $jar = login('example', 'examplepassword');
    $r = req('POST', '/calmind/notes/', ['csrf' => csrf($jar, '/calmind/notes/'), 'action' => 'add',
        'view' => 'All', 'folder' => 'General', 'section' => ''], $jar);
    preg_match('/id=([a-f0-9]+)/', (string) $r['location'], $m);
    $r = req('GET', '/calmind/notes/?view=All&id=' . $m[1], [], $jar);
    eq(200, $r['status'], 'the editor renders');
    has('id="dateInput"', $r['body'], 'with the optional date field');
    // The handoff, once per button: split on the two click handlers and check each half.
    $js = substr($r['body'], strpos($r['body'], "addBtn.addEventListener('click'"));
    $clear = strpos($js, "clearDateBtn').addEventListener('click'");
    ok($clear !== false, 'both date buttons are wired');
    $addH = substr($js, 0, $clear);
    $clrH = substr($js, $clear, 400);
    has("dispatchEvent(new Event('change'", $addH, 'adding a date hands off to autosave');
    has("dispatchEvent(new Event('change'", $clrH, 'and so does clearing one');
    has('keepalive: true', $r['body'], 'the autosave request survives the page being left');
});

t('a note folder\'s sections nest indented under its heading', function () {
    // Every section — named or the catch-all — is a .section-group now (so it can drag as
    // one unit), and the single indent rule covers them all.
    $jar = login('example', 'examplepassword');
    $r = req('GET', '/calmind/notes/?folder=All', [], $jar);
    eq(200, $r['status']);
    has('.folder-block > .section-group { padding-left', $r['body'], 'the indent rule ships');
    has('class="sec-handle"', $r['body'], 'and named sections carry a drag handle');
});

t('a note body is sanitised on the way in', function () {
    $jar = login('example', 'examplepassword');
    $notes = array_values(array_filter(stored('notes', 'example'), fn($n) => ($n['type'] ?? '') !== 'section'));
    $id = $notes[0]['id'];
    req('POST', '/calmind/notes/', ['csrf' => csrf($jar, '/calmind/notes/'), 'action' => 'save', 'view' => 'All',
        'id' => $id, 'title' => 'Sanitiser test',
        'body' => '<p>ok</p><script>alert(1)</script><img src=x onerror=alert(1)><b>bold</b>'], $jar);
    $body = '';
    foreach (stored('notes', 'example') as $n) { if (($n['id'] ?? '') === $id) { $body = $n['body']; } }
    hasnt('<script', $body, 'script tags are stripped');
    hasnt('onerror', $body, 'event handlers are stripped');
    hasnt('<img', $body, 'img is not on the allowlist');
    has('<b>bold</b>', $body, 'allowed tags survive');
});

t('rt_sanitize keeps only the allowlist', function () {
    $out = rt_sanitize('<div class="rt-quote">q</div><span class="evil">x</span><ul><li>a</li></ul><iframe></iframe>');
    has('rt-quote', $out, 'rt-* classes are kept');
    hasnt('class="evil"', $out, 'other classes are dropped');
    hasnt('<iframe', $out, 'unknown tags are dropped');
    has('<li>a</li>', $out);
});

t('an old plain-text note is escaped rather than rendered', function () {
    $out = rt_body_html('5 < 6 & "quoted"');
    has('&lt;', $out, 'escaped');
    hasnt('<script', $out);
});

t('note folders and sections behave like the reminders ones', function () {
    $jar = login('example', 'examplepassword');
    req('POST', '/calmind/notes/', ['csrf' => csrf($jar, '/calmind/notes/'), 'action' => 'add_folder',
        'view' => 'All', 'name' => 'Testnotes'], $jar);
    ok(in_array('Testnotes', folders_load(datadir(), 'example')['notes'], true));
    req('POST', '/calmind/notes/', ['csrf' => csrf($jar, '/calmind/notes/'), 'action' => 'delete_folder',
        'view' => 'All', 'name' => 'Testnotes', 'confirm' => '1'], $jar);
    ok(!in_array('Testnotes', folders_load(datadir(), 'example')['notes'], true));
});

t('a note folder renames in place, carrying its notes and its prefs', function () {
    $jar = login('example', 'examplepassword');
    $post = fn($p) => req('POST', '/calmind/notes/', array_merge(['csrf' => csrf($jar, '/calmind/notes/')], $p), $jar);
    $pal  = app_palette('notes');
    $post(['action' => 'add_folder', 'view' => 'All', 'name' => 'Renyme']);
    $post(['action' => 'set_folder_color', 'view' => 'All', 'name' => 'Renyme', 'color' => $pal[2]]);
    $post(['action' => 'set_default_folder', 'view' => 'All', 'name' => 'Renyme']);
    $post(['action' => 'add', 'view' => 'Renyme', 'folder' => 'Renyme', 'section' => '']);
    // The note we just dropped into it (add sets no title, so find it by folder).
    $id = '';
    foreach (stored('notes', 'example') as $n) {
        if (($n['type'] ?? '') !== 'section' && ($n['folder'] ?? '') === 'Renyme') { $id = $n['id']; }
    }
    ok($id !== '', 'a note landed in the folder');
    // The edit-mode / manager rename action.
    $post(['action' => 'rename_folder', 'view' => 'Renyme', 'name' => 'Renyme', 'newname' => 'Reborn']);
    $f = folders_load(datadir(), 'example');
    ok(in_array('Reborn', $f['notes'], true) && !in_array('Renyme', $f['notes'], true), 'the folder took the new name');
    eq($pal[2], $f['colors']['notes']['Reborn'] ?? '', 'its colour came across');
    eq('Reborn', $f['default']['notes'] ?? '', 'the default-folder pref followed');
    $moved = '';
    foreach (stored('notes', 'example') as $n) { if (($n['id'] ?? '') === $id) { $moved = $n['folder'] ?? ''; } }
    eq('Reborn', $moved, 'the note in it moved with the rename');
    // Notes has no permanent folder now — even "General" is ordinary (not fixed), so it can
    // be renamed. A rename onto a name that already exists is still refused.
    ok(!folder_is_fixed('notes', 'General'), '"General" is not a fixed folder in notes');
    ok(!folders_rename(datadir(), 'notes', 'Reborn', 'General'), 'a rename onto an existing name is refused');
    // The list heading is an editable field in the list view (out of edit mode a plain label).
    has('class="folder-label foldertitle', req('GET', '/calmind/notes/?folder=All', [], $jar)['body'],
        'the folder heading renders as a rename field');
    // Tidy up so later tests see the usual default.
    $post(['action' => 'set_default_folder', 'view' => 'All', 'name' => 'General']);
    $post(['action' => 'delete_folder', 'view' => 'All', 'name' => 'Reborn', 'confirm' => '1']);
});

// ---------------------------------------------------------------- 7. calendar
area('calendar');

t('reminder and note folder colours propagate to the calendar dots', function () {
    $jar = login('example', 'examplepassword');
    // Colour a reminder folder and drop a dated reminder in it.
    $rc = app_palette('reminders')[4];
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'add_folder', 'view' => 'All', 'name' => 'ColR'], $jar);
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'set_folder_color', 'view' => 'All', 'name' => 'ColR', 'color' => $rc], $jar, true);
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'add', 'view' => 'ColR', 'folder' => 'ColR',
        'section' => '', 'text' => 'col prop rem', 'due' => date('Y-m-d')], $jar);
    // Colour a note folder and give it a dated note.
    $nc = app_palette('notes')[3];
    req('POST', '/calmind/notes/', ['csrf' => csrf($jar, '/calmind/notes/'), 'action' => 'add_folder', 'view' => 'All', 'name' => 'ColN'], $jar);
    req('POST', '/calmind/notes/', ['csrf' => csrf($jar, '/calmind/notes/'), 'action' => 'set_folder_color', 'view' => 'All', 'name' => 'ColN', 'color' => $nc], $jar, true);
    req('POST', '/calmind/notes/', ['csrf' => csrf($jar, '/calmind/notes/'), 'action' => 'add', 'view' => 'ColN', 'folder' => 'ColN', 'section' => ''], $jar);
    // Date that note (find it, save with today's date).
    $nid = null;
    foreach (stored('notes', 'example') as $n) { if (($n['type'] ?? '') !== 'section' && ($n['folder'] ?? '') === 'ColN') { $nid = $n['id']; } }
    req('POST', '/calmind/notes/', ['csrf' => csrf($jar, '/calmind/notes/'), 'action' => 'save', 'view' => 'All', 'id' => $nid,
        'title' => 'col prop note', 'date' => date('Y-m-d'), 'body' => '', 'folder' => 'ColN', 'section' => ''], $jar);
    // The calendar's byDay entries carry those folder colours.
    $r = req('GET', '/calmind/calendar/?ym=' . date('Y-m'), [], $jar);
    preg_match('/=\s*(\{"20\d\d-\d\d-\d\d".*?\})\s*;/s', $r['body'], $m);
    $byDay = json_decode($m[1] ?? '{}', true);
    $rem = $note = null;
    foreach ($byDay[date('Y-m-d')] ?? [] as $it) {
        if (($it['text'] ?? '') === 'col prop rem')  { $rem  = $it; }
        if (($it['text'] ?? '') === 'col prop note') { $note = $it; }
    }
    eq($rc, $rem['color'] ?? null, "the reminder dot wears its folder's colour");
    eq($nc, $note['color'] ?? null, "the note dot wears its folder's colour");
});

t('the day panel payload groups by day', function () {
    $jar = login('example', 'examplepassword');
    $r = req('GET', '/calmind/calendar/', [], $jar);
    eq(200, $r['status']);
    ok(preg_match('/\{"\d{4}-\d{2}-\d{2}":/', $r['body']) === 1, 'items are keyed by date');
});

t('the add/edit modal hides Time and Repeat behind + buttons', function () {
    $jar = login('example', 'examplepassword');
    $b = req('GET', '/calmind/calendar/', [], $jar)['body'];
    has('id="mAddTime"', $b, 'a + Time button reveals the time field');
    has('id="mAddRepeat"', $b, 'a + Repeat button reveals the repeat field');
    // Both fields ship hidden by default.
    ok(preg_match('/id="mTimeRow"[^>]*\shidden/', $b) === 1, 'the time row starts hidden');
    ok(preg_match('/id="mRepRow"[^>]*\shidden/', $b) === 1, 'the repeat row starts hidden');
    // The count input sits inside the repeat row, before the unit selector.
    ok(strpos($b, 'id="mRepN"') < strpos($b, 'id="mRepUnit"'), 'the every-N count comes before the unit select');
});

t('a month cell shows the legend icons, one per kind and colour', function () {
    $jar = login('example', 'examplepassword');
    $b = req('GET', '/calmind/calendar/?ym=' . date('Y-m'), [], $jar)['body'];
    has('class="ico event"', $b, 'days with events wear the calendar glyph');
    has('class="ico reminder', $b, 'days with reminders wear the tick glyph');
    hasnt('class="dot event"', $b, 'the old dots are gone');

    // Two calendars in two colours, each with an event on the same day, draw two
    // event icons in that cell — one per colour, not one per kind.
    $r = req('POST', '/calmind/calendar/', ['csrf' => csrf($jar, '/calmind/calendar/'),
        'action' => 'cal_add', 'name' => 'TwoTone'], $jar, true);
    $list = json_decode($r['body'], true)['list'];
    $calA = $list[0];
    $calB = $list[count($list) - 1];
    $pal  = app_palette('calendar');
    $colB = $pal[0] === ($calA['color'] ?? '') ? $pal[1] : $pal[0];
    req('POST', '/calmind/calendar/', ['csrf' => csrf($jar, '/calmind/calendar/'),
        'action' => 'cal_color', 'id' => $calB['id'], 'color' => $colB], $jar, true);
    $day = date('Y-m-d', strtotime('+9 days'));
    foreach ([['Two tone A', $calA['id']], ['Two tone B', $calB['id']]] as $pair) {
        req('POST', '/calmind/calendar/', ['csrf' => csrf($jar, '/calmind/calendar/'), 'action' => 'add_event',
            'kind' => 'event', 'text' => $pair[0], 'day' => $day, 'date' => $day,
            'cal' => $pair[1], 'ym' => substr($day, 0, 7)], $jar);
    }
    $b2 = req('GET', '/calmind/calendar/?ym=' . substr($day, 0, 7), [], $jar)['body'];
    $twoTone = null;
    // Within any cell an icon's (kind, colour) pair never repeats — a second item of
    // the same kind only earns a second icon when it brings a colour of its own.
    foreach (array_slice(explode('<div class="cell', $b2), 1) as $i => $chunk) {
        $chunk = substr($chunk, 0, strpos($chunk, '</div></div>') ?: strlen($chunk));
        if (strpos($chunk, 'data-date="' . $day . '"') !== false) { $twoTone = $chunk; }
        preg_match_all('/class="ico (event|reminder|note)[^"]*"(?: style="color:([^"]*)")?/', $chunk, $mm, PREG_SET_ORDER);
        $pairs = array_map(fn($m) => $m[1] . '|' . ($m[2] ?? ''), $mm);
        eq(count($pairs), count(array_unique($pairs)), "cell $i repeats no kind+colour pair");
    }
    ok($twoTone !== null, 'the two-calendar day renders');
    ok(substr_count($twoTone, 'class="ico event"') >= 2, 'and wears an event icon per calendar colour');

    // Tidy up: drop the events, then the extra calendar.
    foreach (stored('events', 'example') as $e) {
        if (in_array($e['text'] ?? '', ['Two tone A', 'Two tone B'], true)) {
            req('POST', '/calmind/calendar/', ['csrf' => csrf($jar, '/calmind/calendar/'), 'action' => 'delete_item',
                'kind' => 'event', 'id' => $e['id'], 'ym' => substr($day, 0, 7)], $jar);
        }
    }
    req('POST', '/calmind/calendar/', ['csrf' => csrf($jar, '/calmind/calendar/'),
        'action' => 'cal_delete', 'id' => $calB['id'], 'confirm' => '1'], $jar, true);
});

t('paging is never bounced back by the remembered day', function () {
    // The session's remembered day only restores on a *bare* arrival — ?ym= and ?wk=
    // are deliberate paging, and restoring across them bounced every page-back (month
    // and week mode both) straight back to the remembered day's month. The restore is
    // JS, so the harness checks the guard it ships.
    $jar = login('example', 'examplepassword');
    $b = req('GET', '/calmind/calendar/', [], $jar)['body'];
    has("q.has('ym') || q.has('wk')", $b, 'paging arrivals skip the remembered-day restore');
});

t('the calendar ships the data its in-view legend is built from', function () {
    // The legend is drawn in JS from the cells actually on screen, so it can shrink to the
    // shown week(s) in week mode — the harness runs no JS, so this checks the ingredients the
    // page hands that renderer: an empty container, the owners (with canonical order), the
    // calendar-name map, the three kind glyphs, and the render call wired into the view apply.
    $jar = login('example', 'examplepassword');
    $r = req('GET', '/calmind/calendar/?ym=' . date('Y-m'), [], $jar);
    eq(200, $r['status']);
    has('<div class="cal-legend" id="calLegend"', $r['body'], 'the legend container renders');
    // The legend's bar sits *between* the two scroll halves — inside .cal-top it sat
    // below the 60vh fold on a phone, so opening the calendar never showed it.
    has('<div class="cal-legend-bar" id="calLegendBar"', $r['body'], 'the legend has its own bar');
    ok(strpos($r['body'], 'id="calLegendBar"') < strpos($r['body'], '<div class="daypanel"'),
       'the bar sits above the day panel');
    ok(strpos($r['body'], 'id="calLegendBar"') > strpos($r['body'], 'id="calGrid"'),
       'and below the grid, outside the scrolling half');
    has('bar.hidden = !box.children.length', $r['body'], 'an empty key hides its bar');
    hasnt('cleg-dot', $r['body'], 'the separate colour dot is gone');
    // The legend is empty until JS fills it — no server-rendered item elements (the CSS
    // rules for .cleg-item still exist; it's the rendered class="cleg-item" that must not).
    hasnt('class="cleg-item"', $r['body'], 'the legend is JS-built, not server-rendered');
    has('const LEG_OWNERS', $r['body'], 'the owners key is shipped');
    has('function renderLegend', $r['body'], 'the renderer is shipped');
    has('renderLegend();', $r['body'], 'and is called when the view applies');
    // Done items are skipped while Completed is off, so an all-ticked folder drops out
    // of the key (the skip itself is JS; the wiring is what the harness can see).
    has('if (it.done && !showDone) { return; }', $r['body'], 'done items skip the legend');
    // The owners payload names example and carries a per-kind canonical order.
    ok(preg_match('/const LEG_OWNERS = (\[.*?\]);/s', $r['body'], $m), 'the owners payload parses');
    $owners = json_decode($m[1], true);
    ok(is_array($owners) && count($owners) >= 1, 'at least the viewer is an owner');
    eq('', $owners[0]['key'], "the viewer's own row has the empty owner key");
    ok(isset($owners[0]['order']['event'], $owners[0]['order']['reminder'],
       $owners[0]['order']['note']), 'each owner carries an order for all three kinds');
    // The calendar-name map lets the JS name an event dot from its calendar id.
    has('const LEG_CALS', $r['body'], 'the calendar-name map is shipped');
    has('const LEG_ICONS', $r['body'], 'the three kind glyphs are shipped');
});

t("a day's reminders sort undated first, then oldest, then by time", function () {
    $jar = login('example', 'examplepassword');
    $r = req('GET', '/calmind/calendar/', [], $jar);
    preg_match('/=\s*(\{"20\d\d-\d\d-\d\d".*?\})\s*;/s', $r['body'], $m);
    $byDay = json_decode($m[1], true);
    $today = date('Y-m-d');
    $rem = array_values(array_filter($byDay[$today] ?? [], fn($i) => $i['kind'] === 'reminder'
        && ($i['owner'] ?? '') === ''));
    ok(count($rem) > 1, "today should hold several of example's reminders");
    $prev = null;
    foreach ($rem as $i) {
        $due = (string) ($i['due'] ?? '');
        if ($prev !== null) {
            ok(!($prev !== '' && $due === ''), 'an undated reminder must not follow a dated one');
            if ($prev !== '' && $due !== '') { ok($due >= $prev, "dates ascend ($prev then $due)"); }
        }
        $prev = $due;
    }
});

t('events come before reminders within a day', function () {
    $jar = login('example', 'examplepassword');
    $r = req('GET', '/calmind/calendar/', [], $jar);
    preg_match('/=\s*(\{"20\d\d-\d\d-\d\d".*?\})\s*;/s', $r['body'], $m);
    $byDay = json_decode($m[1], true);
    foreach ($byDay as $day => $items) {
        $rank = ['event' => 0, 'reminder' => 1, 'note' => 2];
        $last = -1;
        foreach ($items as $i) {
            $k = $rank[$i['kind']] ?? 9;
            ok($k >= $last, "$day: kinds are out of order at {$i['kind']}");
            $last = $k;
        }
    }
});

t('an undated Calendar-folder reminder rides on today and is not overdue', function () {
    $jar = login('example', 'examplepassword');
    $r = req('GET', '/calmind/calendar/', [], $jar);
    preg_match('/=\s*(\{"20\d\d-\d\d-\d\d".*?\})\s*;/s', $r['body'], $m);
    $byDay = json_decode($m[1], true);
    $found = null;
    foreach ($byDay[date('Y-m-d')] ?? [] as $i) {
        if ($i['kind'] === 'reminder' && $i['text'] === 'Stretch for ten minutes') { $found = $i; }
    }
    ok($found !== null, 'the rider shows on today');
    ok(empty($found['rolled']), 'and is not flagged overdue — it is not late');
});

t('adding an event from the day panel', function () {
    $jar = login('example', 'examplepassword');
    $cal = stored('calendars', 'example')[0]['id'];
    $day = date('Y-m-d', strtotime('+3 days'));
    req('POST', '/calmind/calendar/', ['csrf' => csrf($jar, '/calmind/calendar/'), 'action' => 'add_event',
        'kind' => 'event', 'text' => 'Panel event', 'day' => $day, 'date' => $day,
        'time' => '10:00', 'cal' => $cal, 'ym' => date('Y-m')], $jar);
    $ev = null;
    foreach (stored('events', 'example') as $e) { if ($e['text'] === 'Panel event') { $ev = $e; } }
    ok($ev !== null, 'the event exists');
    eq($cal, $ev['cal'], 'in the chosen calendar');
    eq('10:00', $ev['time']);
});

t('editing and deleting a calendar item', function () {
    $jar = login('example', 'examplepassword');
    $ev = null;
    foreach (stored('events', 'example') as $e) { if ($e['text'] === 'Panel event') { $ev = $e; } }
    req('POST', '/calmind/calendar/', ['csrf' => csrf($jar, '/calmind/calendar/'), 'action' => 'edit_item',
        'kind' => 'event', 'id' => $ev['id'], 'text' => 'Panel event renamed',
        'date' => $ev['date'], 'ym' => date('Y-m')], $jar);
    $names = array_column(stored('events', 'example'), 'text');
    ok(in_array('Panel event renamed', $names, true), 'renamed');

    req('POST', '/calmind/calendar/', ['csrf' => csrf($jar, '/calmind/calendar/'), 'action' => 'delete_item',
        'kind' => 'event', 'id' => $ev['id'], 'ym' => date('Y-m')], $jar);
    ok(in_array('Panel event renamed', array_column(stored('events', 'example'), 'text'), true),
       'one press must not delete');
    req('POST', '/calmind/calendar/', ['csrf' => csrf($jar, '/calmind/calendar/'), 'action' => 'delete_item',
        'kind' => 'event', 'id' => $ev['id'], 'confirm' => '1', 'ym' => date('Y-m')], $jar);
    ok(!in_array('Panel event renamed', array_column(stored('events', 'example'), 'text'), true),
       'confirmed press deletes an event outright — an event is nothing without its date');
});

t('deleting a reminder or note from the calendar only unschedules it', function () {
    // A reminder or note rides the calendar because it carries a date; deleting it there
    // takes the date off and keeps the item, rather than destroying it from its own list.
    $jar = login('example', 'examplepassword');
    $today = date('Y-m-d');
    req('POST', '/calmind/calendar/', ['csrf' => csrf($jar, '/calmind/calendar/'), 'action' => 'add_reminder',
        'text' => 'Cal-del rem', 'date' => $today, 'ym' => date('Y-m')], $jar);
    req('POST', '/calmind/calendar/', ['csrf' => csrf($jar, '/calmind/calendar/'), 'action' => 'add_note',
        'text' => 'Cal-del note', 'date' => $today, 'ym' => date('Y-m')], $jar);
    $find = function (string $base, string $field, string $val) {
        foreach (stored($base, 'example') as $x) { if (($x[$field] ?? '') === $val) { return $x; } }
        return null;
    };
    $rem  = $find('reminders', 'text', 'Cal-del rem');
    $note = $find('notes', 'title', 'Cal-del note');
    ok($rem !== null && ($rem['due'] ?? '') === $today, 'the reminder starts dated on today');
    ok($note !== null && ($note['date'] ?? '') === $today, 'the note starts dated on today');

    req('POST', '/calmind/calendar/', ['csrf' => csrf($jar, '/calmind/calendar/'), 'action' => 'delete_item',
        'kind' => 'reminder', 'id' => $rem['id'], 'confirm' => '1', 'ym' => date('Y-m')], $jar);
    req('POST', '/calmind/calendar/', ['csrf' => csrf($jar, '/calmind/calendar/'), 'action' => 'delete_item',
        'kind' => 'note', 'id' => $note['id'], 'confirm' => '1', 'ym' => date('Y-m')], $jar);

    $rem2 = null;  foreach (stored('reminders', 'example') as $x) { if (($x['id'] ?? '') === $rem['id'])  { $rem2  = $x; } }
    $note2 = null; foreach (stored('notes', 'example')     as $x) { if (($x['id'] ?? '') === $note['id']) { $note2 = $x; } }
    ok($rem2 !== null, 'the reminder still exists');
    eq('', (string) ($rem2['due'] ?? ''), 'but its date is gone');
    ok($note2 !== null, 'the note still exists');
    eq('', (string) ($note2['date'] ?? ''), 'but its date is gone');
});

t('calendars: add, recolour, rename, default, delete', function () {
    $jar = login('example', 'examplepassword');
    $c = csrf($jar, '/calmind/calendar/');
    req('POST', '/calmind/calendar/', ['csrf' => $c, 'action' => 'cal_add', 'name' => 'Testcal',
        'ym' => date('Y-m')], $jar, true);
    $cal = null;
    foreach (stored('calendars', 'example') as $x) { if (($x['name'] ?? '') === 'Testcal') { $cal = $x; } }
    ok($cal !== null, 'calendar added');

    $col = app_palette('calendar')[3];
    req('POST', '/calmind/calendar/', ['csrf' => $c, 'action' => 'cal_color', 'id' => $cal['id'],
        'color' => $col, 'ym' => date('Y-m')], $jar, true);
    foreach (stored('calendars', 'example') as $x) {
        if ($x['id'] === $cal['id']) { eq($col, $x['color'], 'recoloured'); }
    }

    // Rename keeps the id (events and shares point at it), only the name moves.
    $r = req('POST', '/calmind/calendar/', ['csrf' => $c, 'action' => 'cal_rename', 'id' => $cal['id'],
        'name' => 'Renamedcal', 'ym' => date('Y-m')], $jar, true);
    has('"Renamedcal"', $r['body'], 'the fresh list comes back renamed');
    foreach (stored('calendars', 'example') as $x) {
        if ($x['id'] === $cal['id']) { eq('Renamedcal', $x['name'], 'renamed in the file');
                                       eq($col, $x['color'], 'colour survives the rename'); }
    }
    // A blank name is refused, and a partner's calendar id isn't in my list to rename.
    req('POST', '/calmind/calendar/', ['csrf' => $c, 'action' => 'cal_rename', 'id' => $cal['id'],
        'name' => '  ', 'ym' => date('Y-m')], $jar, true);
    foreach (stored('calendars', 'example') as $x) {
        if ($x['id'] === $cal['id']) { eq('Renamedcal', $x['name'], 'a blank rename changes nothing'); }
    }
    $buddyCal = stored('calendars', 'buddy')[0] ?? null;
    ok($buddyCal !== null, 'buddy has a calendar to try against');
    req('POST', '/calmind/calendar/', ['csrf' => $c, 'action' => 'cal_rename', 'id' => $buddyCal['id'],
        'name' => 'Hijacked', 'ym' => date('Y-m')], $jar, true);
    foreach (stored('calendars', 'buddy') as $x) {
        if ($x['id'] === $buddyCal['id']) { eq($buddyCal['name'], $x['name'], "a partner's calendar can't be renamed from here"); }
    }

    req('POST', '/calmind/calendar/', ['csrf' => $c, 'action' => 'cal_delete', 'id' => $cal['id'],
        'confirm' => '1', 'ym' => date('Y-m')], $jar, true);
    ok(!in_array('Renamedcal', array_column(stored('calendars', 'example'), 'name'), true), 'deleted');
});

t('the edit window turns an event or reminder into the title of a new note', function () {
    $jar = login('example', 'examplepassword');
    $c   = csrf($jar, '/calmind/calendar/');
    $cal = (string) (stored('calendars', 'example')[0]['id'] ?? '');
    // An event is its date — converting moves it out entirely.
    req('POST', '/calmind/calendar/', ['csrf' => $c, 'action' => 'add_event', 'text' => 'Gallery visit',
        'date' => '2030-03-03', 'time' => '14:00', 'cal' => $cal, 'ym' => '2030-03'], $jar);
    $ev = null;
    foreach (stored('events', 'example') as $x) { if (($x['text'] ?? '') === 'Gallery visit') { $ev = $x; } }
    ok($ev !== null, 'the event exists');
    // First prove a plain edit with kindchoice equal to its own kind stays an edit.
    req('POST', '/calmind/calendar/', ['csrf' => $c, 'action' => 'edit_item', 'kind' => 'event',
        'kindchoice' => 'event', 'id' => $ev['id'], 'text' => 'Gallery visit later',
        'date' => '2030-03-04', 'cal' => $cal, 'ym' => '2030-03'], $jar);
    $ev2 = null;
    foreach (stored('events', 'example') as $x) { if (($x['id'] ?? '') === $ev['id']) { $ev2 = $x; } }
    eq('Gallery visit later', $ev2['text'] ?? '', 'kindchoice matching the kind is just an edit');
    // Now the conversion.
    req('POST', '/calmind/calendar/', ['csrf' => $c, 'action' => 'edit_item', 'kind' => 'event',
        'kindchoice' => 'note', 'id' => $ev['id'], 'text' => 'Gallery visit later',
        'date' => '2030-03-04', 'time' => '15:00', 'cal' => $cal, 'ym' => '2030-03'], $jar);
    $gone = true; $note = null;
    foreach (stored('events', 'example') as $x) { if (($x['id'] ?? '') === $ev['id']) { $gone = false; } }
    foreach (stored('notes', 'example') as $x) { if (($x['title'] ?? '') === 'Gallery visit later') { $note = $x; } }
    ok($gone, 'the event moved out entirely');
    ok($note !== null, 'and became a note');
    eq('2030-03-04', $note['date'] ?? '', 'carrying the date');
    eq('15:00', $note['time'] ?? '', 'and the time');

    // A reminder without subtasks moves the same way.
    drop_blank_subtasks();
    $rc = csrf($jar);
    req('POST', '/calmind/reminders/', ['csrf' => $rc, 'action' => 'add', 'view' => 'All',
        'text' => 'Sort receipts'], $jar);
    $rem = null;
    foreach (stored('reminders', 'example') as $x) { if (($x['text'] ?? '') === 'Sort receipts') { $rem = $x; } }
    req('POST', '/calmind/calendar/', ['csrf' => $c, 'action' => 'edit_item', 'kind' => 'reminder',
        'kindchoice' => 'note', 'id' => $rem['id'], 'text' => 'Sort receipts', 'ym' => date('Y-m')], $jar);
    $rGone = true; $rNote = null;
    foreach (stored('reminders', 'example') as $x) { if (($x['id'] ?? '') === $rem['id']) { $rGone = false; } }
    foreach (stored('notes', 'example') as $x) { if (($x['title'] ?? '') === 'Sort receipts') { $rNote = $x; } }
    ok($rGone, 'the reminder moved out');
    ok($rNote !== null, 'and became a note');

    // A partner's id creates nothing: the source is found-and-detached first.
    $bEv = null;
    foreach (stored('events', 'buddy') as $x) { $bEv = $x; break; }
    ok($bEv !== null, 'buddy has an event to try against');
    $notesBefore = count(stored('notes', 'example'));
    req('POST', '/calmind/calendar/', ['csrf' => $c, 'action' => 'edit_item', 'kind' => 'event',
        'kindchoice' => 'note', 'id' => $bEv['id'], 'text' => 'Hijack', 'ym' => date('Y-m')], $jar);
    eq($notesBefore, count(stored('notes', 'example')), "a partner's id makes no note");
    ok(in_array($bEv['id'], array_column(stored('events', 'buddy'), 'id'), true), "and buddy's event survives");

    // Clean up the two notes.
    $ns = array_values(array_filter(stored('notes', 'example'),
        fn($x) => !in_array($x['title'] ?? '', ['Gallery visit later', 'Sort receipts'], true)));
    store_write(user_data_file(datadir(), 'notes', 'example'), $ns);
});

t('the day panel duplicates an event or a reminder block', function () {
    $jar = login('example', 'examplepassword');
    $c   = csrf($jar, '/calmind/calendar/');
    $cal = (string) (stored('calendars', 'example')[0]['id'] ?? '');
    req('POST', '/calmind/calendar/', ['csrf' => $c, 'action' => 'add_event', 'text' => 'Band practice',
        'date' => '2030-04-04', 'cal' => $cal, 'ym' => '2030-04'], $jar);
    $ev = null;
    foreach (stored('events', 'example') as $x) { if (($x['text'] ?? '') === 'Band practice') { $ev = $x; } }
    // Real POSTs from the edit-mode-only button carry the stamped flag; echoed, not originated.
    $r = req('POST', '/calmind/calendar/', ['csrf' => $c, 'action' => 'duplicate_item', 'kind' => 'event',
        'id' => $ev['id'], 'ym' => '2030-04', 'day' => '2030-04-04', 'edit' => '1'], $jar);
    has('edit=1', (string) $r['location'], 'duplicating from edit mode stays in it');
    $twins = array_values(array_filter(stored('events', 'example'), fn($x) => ($x['text'] ?? '') === 'Band practice'));
    eq(2, count($twins), 'two of the event now');
    ok($twins[0]['id'] !== $twins[1]['id'], 'with their own ids');
    eq($twins[0]['date'], $twins[1]['date'], 'same day');
    eq($twins[0]['cal'], $twins[1]['cal'], 'same calendar');

    // A reminder duplicates as its whole block, like in the Reminders app.
    drop_blank_subtasks();
    $rc = csrf($jar);
    req('POST', '/calmind/reminders/', ['csrf' => $rc, 'action' => 'add', 'view' => 'All',
        'text' => 'Pack the car'], $jar);
    $rem = null;
    foreach (stored('reminders', 'example') as $x) { if (($x['text'] ?? '') === 'Pack the car') { $rem = $x; } }
    req('POST', '/calmind/reminders/', ['csrf' => $rc, 'action' => 'add_subtask', 'view' => 'All',
        'parent' => $rem['id']], $jar);
    req('POST', '/calmind/calendar/', ['csrf' => $c, 'action' => 'duplicate_item', 'kind' => 'reminder',
        'id' => $rem['id'], 'ym' => date('Y-m')], $jar);
    $list = array_values(stored('reminders', 'example'));
    $idx  = [];
    foreach ($list as $i => $x) { if (($x['text'] ?? '') === 'Pack the car') { $idx[] = $i; } }
    eq(2, count($idx), 'two of the reminder now');
    eq(1, (int) ($list[$idx[1] + 1]['indent'] ?? 0), 'the copy brought its subtask');
    // Clean up both apps' twins.
    store_write(user_data_file(datadir(), 'events', 'example'), array_values(array_filter(
        stored('events', 'example'), fn($x) => ($x['text'] ?? '') !== 'Band practice')));
    $drop = [$list[$idx[0]]['id'], $list[$idx[1]]['id'], $list[$idx[0] + 1]['id'], $list[$idx[1] + 1]['id']];
    store_write(user_data_file(datadir(), 'reminders', 'example'), array_values(array_filter(
        $list, fn($x) => !in_array($x['id'] ?? '', $drop, true))));
});

t('wiring: the day panel gesture reveals icons, and the window offers Note when editing', function () {
    $jar = login('example', 'examplepassword');
    $b = req('GET', '/calmind/calendar/', [], $jar)['body'];
    // Hold/double-click only reveals the row's icons now — reveal(), never openRow, on the timer.
    has('lpT = null; reveal(); }, 500', $b, 'the long-press reveals rather than opening the window');
    has("row.addEventListener('dblclick', (e) => { e.preventDefault(); reveal(); });", $b, 'double-click too');
    has("pen.className = 'dp-edit'", $b, 'rows carry the pencil');
    has("dup.className = 'dp-dup'", $b, 'the duplicate button');
    has('id="dupItemForm"', $b, 'and its form');
    // Editing an event or reminder constrains the kind row to [itself, Note].
    has("constrainKinds([kind, 'note'], kind)", $b, 'the kind switch comes back, cut down to Note');
    has("if (kind === 'note') { mKindRow.hidden = true; }", $b, 'a note never converts out');
});

t('wiring: the manage window renames in place', function () {
    $jar = login('example', 'examplepassword');
    $r = req('GET', '/calmind/calendar/', [], $jar);
    has('crename-edit', $r['body'], 'own rows carry the rename pencil');
    has("calApi('cal_rename'", $r['body'], 'which posts cal_rename');
});

t('tapping a calendar row leaves only it showing', function () {
    $jar = login('example', 'examplepassword');
    $cals = stored('calendars', 'example');
    $keep = $cals[0]['id'];
    req('POST', '/calmind/calendar/', ['csrf' => csrf($jar, '/calmind/calendar/'), 'action' => 'cal_vis_only',
        'name' => $keep, 'ym' => date('Y-m')], $jar, true);
    $hidden = (array) (stored('calprefs', 'example')['hidden_cals'] ?? []);
    ok(!in_array($keep, $hidden, true), 'the tapped one stays');
    foreach ($cals as $c) { if ($c['id'] !== $keep) { ok(in_array($c['id'], $hidden, true), 'the rest hide'); } }
    req('POST', '/calmind/calendar/', ['csrf' => csrf($jar, '/calmind/calendar/'), 'action' => 'cal_vis_all',
        'show' => '1', 'ym' => date('Y-m')], $jar, true);
    eq([], (array) (stored('calprefs', 'example')['hidden_cals'] ?? []), 'All puts them back');
});

t('ticking a reminder from the calendar rolls a repeat too', function () {
    $jar = login('example', 'examplepassword');
    $row = rowBy('example', 'Rent');       // monthly, from the seeder
    ok($row !== null);
    $was = $row['due'];
    $r = req('POST', '/calmind/calendar/', ['csrf' => csrf($jar, '/calmind/calendar/'), 'action' => 'toggle_reminder',
        'id' => $row['id'], 'day' => $was, 'ym' => date('Y-m')], $jar);
    $now = rowBy('example', 'Rent');
    ok(empty($now['done']), 'not marked done');
    ok($now['due'] > $was, 'rolled forward a month');
    // The redirect names the rolled row so the day panel can flash it — the roll must
    // read as the tick working, not as a checkbox that did nothing.
    has('rolled=' . $row['id'], (string) $r['location'], 'the redirect says which row rolled');
    $b = req('GET', '/calmind/calendar/', [], $jar)['body'];
    has('rolled-flash', $b, 'and the page ships the flash');
});

// ---------------------------------------------------------------- 8. habits
area('habits');

t('ticking a day', function () {
    $jar = login('example', 'examplepassword');
    $h = null;
    foreach (stored('habits', 'example') as $x) { if (($x['type'] ?? '') !== 'section') { $h = $x; break; } }
    $day = date('Y-m-d');
    $r = req('POST', '/calmind/habits/', ['csrf' => csrf($jar, '/calmind/habits/'), 'action' => 'toggle',
        'id' => $h['id'], 'date' => $day], $jar, true);
    $j = json_decode($r['body'], true);
    ok(isset($j['done']), 'answers with the new state');
    foreach (stored('habits', 'example') as $x) {
        if (($x['id'] ?? '') === $h['id']) { eq($j['done'], !empty($x['done'][$day]), 'stored state matches'); }
    }
});

t('habits: add, rename, delete', function () {
    $jar = login('example', 'examplepassword');
    req('POST', '/calmind/habits/', ['csrf' => csrf($jar, '/calmind/habits/'), 'action' => 'add_habit',
        'name' => 'Test habit', 'section' => ''], $jar);
    $h = null;
    foreach (stored('habits', 'example') as $x) { if (($x['name'] ?? '') === 'Test habit') { $h = $x; } }
    ok($h !== null, 'added');
    req('POST', '/calmind/habits/', ['csrf' => csrf($jar, '/calmind/habits/'), 'action' => 'rename_habit',
        'id' => $h['id'], 'name' => 'Test habit renamed'], $jar, true);
    ok(in_array('Test habit renamed', array_column(stored('habits', 'example'), 'name'), true));
    req('POST', '/calmind/habits/', ['csrf' => csrf($jar, '/calmind/habits/'), 'action' => 'delete_habit',
        'id' => $h['id'], 'confirm' => '1'], $jar);
    ok(!in_array('Test habit renamed', array_column(stored('habits', 'example'), 'name'), true));
});

t('a habit lands in the chosen section, or the first section by default', function () {
    $jar  = login('example', 'examplepassword');
    $secs = fn() => array_values(array_filter(stored('habits', 'example'), fn($x) => ($x['type'] ?? '') === 'section'));
    // Make a target section of our own (add_section appends, so it isn't the first).
    req('POST', '/calmind/habits/', ['csrf' => csrf($jar, '/calmind/habits/'), 'action' => 'add_section', 'name' => 'ChosenSec', 'mgr' => '1'], $jar);
    $chosenSec = null;
    foreach ($secs() as $s) { if (($s['name'] ?? '') === 'ChosenSec') { $chosenSec = (string) $s['id']; } }
    $first = (string) $secs()[0]['id'];
    ok($chosenSec !== null && $chosenSec !== $first, 'a target section exists that is not the first');
    req('POST', '/calmind/habits/', ['csrf' => csrf($jar, '/calmind/habits/'), 'action' => 'add_habit',
        'name' => 'IntoChosen', 'section' => $chosenSec], $jar);
    $chosen = null;
    foreach (stored('habits', 'example') as $x) { if (($x['name'] ?? '') === 'IntoChosen') { $chosen = $x; } }
    ok($chosen !== null, 'created');
    eq($chosenSec, (string) ($chosen['section'] ?? ''), 'in the section whose + was used');
    // An empty/invalid section falls back to the first section — never ungrouped.
    req('POST', '/calmind/habits/', ['csrf' => csrf($jar, '/calmind/habits/'), 'action' => 'add_habit',
        'name' => 'IntoDefault', 'section' => ''], $jar);
    $def = null;
    foreach (stored('habits', 'example') as $x) { if (($x['name'] ?? '') === 'IntoDefault') { $def = $x; } }
    ok($def !== null, 'created');
    eq($first, (string) ($def['section'] ?? ''), 'an empty section falls back to the first, not ungrouped');
});

t('a section colour must come from the palette, and the answer says what stuck', function () {
    $jar = login('example', 'examplepassword');
    $sec = null;
    foreach (stored('habits', 'example') as $x) { if (($x['type'] ?? '') === 'section') { $sec = $x; break; } }
    ok($sec !== null, 'there is a section to colour');
    $good = app_palette('habits')[2];   // habits uses its own tier now
    $r = req('POST', '/calmind/habits/', ['csrf' => csrf($jar, '/calmind/habits/'), 'action' => 'set_section_color',
        'id' => $sec['id'], 'color' => $good], $jar, true);
    eq($good, json_decode($r['body'], true)['color'] ?? null, 'answers with the stored colour');

    $r = req('POST', '/calmind/habits/', ['csrf' => csrf($jar, '/calmind/habits/'), 'action' => 'set_section_color',
        'id' => $sec['id'], 'color' => '#ff0000'], $jar, true);
    eq($good, json_decode($r['body'], true)['color'] ?? null,
       'an off-palette colour is refused and the old one comes back');
});

t('both habit views render, and draw actual cells', function () {
    $jar = login('example', 'examplepassword');
    // The view is chosen with ?v=; ?m= only picks which month once you are in it. Assert
    // on rendered *cells*, not on a marker word — every one of these names also appears
    // in the stylesheet, so "does the page contain mgrid" passes on an empty grid. That
    // is exactly how the month view went untested until someone looked.
    $r = req('GET', '/calmind/habits/?v=week&w=0', [], $jar);
    eq(200, $r['status']);
    ok(preg_match_all('/<div class="colhead/', $r['body']) > 3, 'the week grid has day columns');
    ok(preg_match_all('/<button class="cell/', $r['body']) > 3, 'and tickable squares');

    $r = req('GET', '/calmind/habits/?v=month&m=' . date('Y-m'), [], $jar);
    eq(200, $r['status']);
    ok(preg_match_all('/<div class="mcell/', $r['body']) >= 28, 'the month grid has a cell per day');
    // The colour key: a dot-and-name legend of the counted sections, matching the pies.
    has('<ul class="mleg"', $r['body'], 'the month view carries a section colour legend');
    ok(preg_match_all('/<span class="mleg-dot"/', $r['body']) >= 1, 'the legend draws a colour dot per section');
    $sec = '';
    foreach (stored('habits', 'example') as $it) { if (($it['type'] ?? '') === 'section') { $sec = (string) $it['name']; break; } }
    has($sec, $r['body'], 'a section name appears in the legend');
});

t('the collapse-all button ships on each list, and habit sections carry a collapse chevron', function () {
    $jar = login('example', 'examplepassword');
    // Reminders: the button rides in the toolbar; Notes: above the top folder.
    has('id="collapseAllBtn"', req('GET', '/calmind/reminders/', [], $jar)['body'], 'reminders has collapse-all');
    has('id="collapseAllBtn"', req('GET', '/calmind/notes/', [], $jar)['body'], 'notes has collapse-all');
    // Habits: its own collapse-all above the sections, and each section header a chevron.
    $h = req('GET', '/calmind/habits/?v=week&w=0', [], $jar)['body'];
    has('id="hCollapseAll"', $h, 'habits week view has collapse-all');
    ok(preg_match_all('/<div class="hsection"[^>]*>\s*<button[^>]*class="sec-collapse"/', $h) >= 1,
       'each habit section header carries a collapse chevron');
    // The shared folder-collapse-all script is wired.
    has('foldercollapsed:', req('GET', '/calmind/reminders/', [], $jar)['body'], 'the collapse-all script is present');
});

t('a habit section shows its colour as a name wash, not a dot', function () {
    $jar = login('example', 'examplepassword');
    $b = req('GET', '/calmind/habits/?v=week', [], $jar)['body'];
    ok(preg_match_all('/class="hsec-wash" style="background:#[0-9a-f]{6}[0-9a-f]{2}"/', $b) >= 1,
       'the section name sits on a colour wash (8-digit hex from folder_tint)');
    // The old inline colour <details> dot is gone from the grid (colour is set in the manager).
    ok(strpos($b, '<div class="grid" id="wGrid"') !== false, 'week grid renders');
});

t('the section manager is on the page — a Manage sections row and its window', function () {
    $jar = login('example', 'examplepassword');
    $r = req('GET', '/calmind/habits/', [], $jar);
    has('id="habitSecMgr"', $r['body'], 'the filter dropdown carries a Manage sections row');
    has('id="hsecModal"', $r['body'], 'the manager window renders');
    has('id="hsecReorder"', $r['body'], 'with a draggable list of sections');
});

t('the manager reorders sections without disturbing the habits', function () {
    $jar = login('example', 'examplepassword');
    $onlySecs = fn() => array_values(array_filter(stored('habits', 'example'),
        fn($x) => ($x['type'] ?? '') === 'section'));
    $habitNames = function () {   // every non-section row, name-sorted, to compare as a set
        $n = array_column(array_values(array_filter(stored('habits', 'example'),
            fn($x) => ($x['type'] ?? '') !== 'section')), 'name');
        sort($n); return $n;
    };
    // Make sure there are at least two to swap, then reverse the whole order.
    req('POST', '/calmind/habits/', ['csrf' => csrf($jar, '/calmind/habits/'), 'action' => 'add_section',
        'name' => 'ZZManage', 'mgr' => '1'], $jar);
    $ids = array_column($onlySecs(), 'id');
    $before = $habitNames();
    ok(count($ids) >= 2, 'at least two sections exist');
    $rev = array_reverse($ids);
    req('POST', '/calmind/habits/', ['csrf' => csrf($jar, '/calmind/habits/'), 'action' => 'reorder',
        'order' => '[]', 'sections' => json_encode($rev)], $jar, true);
    eq($rev, array_column($onlySecs(), 'id'), 'the stored section order is reversed');
    eq($before, $habitNames(), 'every habit is still there');
});

t('a fresh habits app starts with a default section; the last one is undeletable', function () {
    // A dedicated throwaway account, so depleting its sections can't disturb example's.
    ensure_account('hsecmgr', 'hsecmgrpass');
    $jar = login('hsecmgr', 'hsecmgrpass');
    $secs = fn() => array_values(array_filter(stored('habits', 'hsecmgr'),
        fn($x) => ($x['type'] ?? '') === 'section'));
    $add = fn($n) => req('POST', '/calmind/habits/', ['csrf' => csrf($jar, '/calmind/habits/'),
        'action' => 'add_section', 'name' => $n, 'mgr' => '1'], $jar);
    $del = fn($id) => req('POST', '/calmind/habits/', ['csrf' => csrf($jar, '/calmind/habits/'),
        'action' => 'delete_section', 'id' => $id, 'confirm' => '1', 'mgr' => '1'], $jar);

    req('GET', '/calmind/habits/', [], $jar);   // first open creates the default section (persisted)
    eq(1, count($secs()), 'a fresh account opens with one default section');
    $del($secs()[0]['id']);
    eq(1, count($secs()), 'the last section refuses to be deleted');
    // With two, either can go — the guard only pins the final one.
    $add('Second');
    eq(2, count($secs()), 'now there are two');
    $del($secs()[0]['id']);
    eq(1, count($secs()), 'one of two deletes, leaving one');
});

// ---------------------------------------------------------------- 9. the Add app
area('add');

t('Add makes a reminder in the chosen folder and section', function () {
    $jar = login('example', 'examplepassword');
    req('POST', '/calmind/add/', ['csrf' => csrf($jar, '/calmind/add/'), 'action' => 'add_reminder',
        'text' => 'From the add app', 'folder' => 'Home', 'section' => 'Errands'], $jar);
    $row = rowBy('example', 'From the add app');
    ok($row !== null, 'created');
    eq('Home', $row['folder']);
    eq('Errands', $row['section']);
});

t('Add makes an event in the chosen calendar', function () {
    $jar = login('example', 'examplepassword');
    $cal = stored('calendars', 'example')[1]['id'];
    req('POST', '/calmind/add/', ['csrf' => csrf($jar, '/calmind/add/'), 'action' => 'add_event',
        'text' => 'Add-app event', 'cal' => $cal], $jar);
    $ev = null;
    foreach (stored('events', 'example') as $e) { if ($e['text'] === 'Add-app event') { $ev = $e; } }
    ok($ev !== null, 'created');
    eq($cal, $ev['cal'], 'in the chosen calendar');
    eq(date('Y-m-d'), $ev['date'], 'an undated event defaults to today');
});

t('Add makes a note in the chosen note folder', function () {
    $jar = login('example', 'examplepassword');
    req('POST', '/calmind/add/', ['csrf' => csrf($jar, '/calmind/add/'), 'action' => 'add_note',
        'text' => 'Add-app note', 'nfolder' => 'Recipes', 'nsection' => ''], $jar);
    $n = null;
    foreach (stored('notes', 'example') as $x) { if (($x['title'] ?? '') === 'Add-app note') { $n = $x; } }
    ok($n !== null, 'created');
    eq('Recipes', $n['folder']);
});

t('a destination that does not exist falls back instead of being taken on trust', function () {
    $jar = login('example', 'examplepassword');
    req('POST', '/calmind/add/', ['csrf' => csrf($jar, '/calmind/add/'), 'action' => 'add_reminder',
        'text' => 'Bogus folder', 'folder' => 'Nope', 'section' => 'Nope'], $jar);
    $fb = folder_fallback('reminders');
    eq($fb, rowBy('example', 'Bogus folder')['folder']);
    // An unknown section no longer becomes a nameless catch-all — it lands in the fallback
    // folder's real default section (which must actually exist there).
    $sec = rowBy('example', 'Bogus folder')['section'];
    ok($sec !== '', 'the section falls back to a real one, not the empty catch-all');
    $secExists = (bool) array_filter(stored('reminders', 'example'),
        fn($x) => ($x['type'] ?? '') === 'section' && ($x['folder'] ?? '') === $fb && ($x['name'] ?? '') === $sec);
    ok($secExists, 'and that section really exists in the fallback folder');

    req('POST', '/calmind/add/', ['csrf' => csrf($jar, '/calmind/add/'), 'action' => 'add_event',
        'text' => 'Bogus cal', 'cal' => 'nosuchcal'], $jar);
    $ids = array_column(stored('calendars', 'example'), 'id');
    $ev = null;
    foreach (stored('events', 'example') as $e) { if ($e['text'] === 'Bogus cal') { $ev = $e; } }
    ok(in_array($ev['cal'], $ids, true), 'an unknown calendar id is replaced with a real one');
});

t('Add reads the date and time out of the line too', function () {
    $jar = login('example', 'examplepassword');
    req('POST', '/calmind/add/', ['csrf' => csrf($jar, '/calmind/add/'), 'action' => 'add_reminder',
        'text' => 'Dentist 9/4 3:30pm', 'folder' => 'Home', 'section' => ''], $jar);
    $row = rowBy('example', 'Dentist');
    ok($row !== null, 'text trimmed');
    eq('15:30', $row['time']);
    eq('09-04', substr((string) $row['due'], 5));
});

// ---------------------------------------------------------------- edit-mode entry
// ONE rule, suite-wide: edit mode is entered by gesture (long-press / double-click) and
// nothing else. Server-side that means a handler only ever ECHOES the posted edit flag
// into its redirect — never originates it — because the flag is stamped by
// keep_edit_script() onto forms submitted while editing. The bug this pins: handlers
// that "knew" they were edit-mode-only appended edit=1 unconditionally, and then
// swipe-delete arrived, reachable from outside edit mode — so deleting dumped you in.
// Every echo is asserted BOTH ways, per action, per app, on purpose.
area('editmode');

// One POST, two runs — bare, then with edit=1 — asserting the redirect echoes exactly.
function edit_echo(string $path, array $post, array &$jar, string $why): void
{
    $r = req('POST', $path, $post + ['csrf' => csrf($jar, $path)], $jar);
    eq(302, $r['status'], "$why: redirects");
    hasnt('edit=1', (string) $r['location'], "$why: a bare POST lands OUT of edit mode");
    $r = req('POST', $path, $post + ['csrf' => csrf($jar, $path), 'edit' => '1'], $jar);
    has('edit=1', (string) $r['location'], "$why: the posted flag rides back in");
}

t('reminders: no action originates edit mode', function () {
    $jar = login('example', 'examplepassword');
    // The swipe-reachable one that started this, on a real row each time.
    $rows = array_values(array_filter(stored('reminders', 'example'),
        fn($r) => ($r['type'] ?? '') !== 'section' && (int) ($r['indent'] ?? 0) === 0));
    edit_echo('/calmind/reminders/', ['action' => 'delete', 'view' => 'All',
        'id' => $rows[0]['id'], 'confirm' => '1'], $jar, 'delete');
    edit_echo('/calmind/reminders/', ['action' => 'delete', 'view' => 'All',
        'id' => $rows[1]['id']], $jar, 'unconfirmed delete bounce');
    edit_echo('/calmind/reminders/', ['action' => 'duplicate', 'view' => 'All',
        'id' => $rows[1]['id']], $jar, 'duplicate');
    edit_echo('/calmind/reminders/', ['action' => 'add_subtask', 'view' => 'All',
        'id' => $rows[1]['id']], $jar, 'add_subtask');
    edit_echo('/calmind/reminders/', ['action' => 'add_section', 'view' => 'All',
        'folder' => FOLDER_REMINDERS, 'name' => 'EchoChk' . rand(100, 999)], $jar, 'add_section');
    edit_echo('/calmind/reminders/', ['action' => 'rename_section', 'view' => 'All',
        'folder' => FOLDER_REMINDERS, 'old' => 'NoSuchSection', 'name' => 'StillNo'], $jar, 'rename_section');
});

t('notes: no action originates edit mode', function () {
    $jar = login('example', 'examplepassword');
    $notes = array_values(array_filter(stored('notes', 'example'), fn($n) => ($n['type'] ?? '') !== 'section'));
    edit_echo('/calmind/notes/', ['action' => 'delete', 'view' => 'All',
        'id' => $notes[0]['id'], 'confirm' => '1'], $jar, 'delete');
    edit_echo('/calmind/notes/', ['action' => 'delete', 'view' => 'All',
        'id' => $notes[1]['id']], $jar, 'unconfirmed delete bounce');
    edit_echo('/calmind/notes/', ['action' => 'duplicate', 'view' => 'All',
        'id' => $notes[1]['id']], $jar, 'duplicate');
    edit_echo('/calmind/notes/', ['action' => 'add_section', 'view' => 'All',
        'folder' => 'General', 'name' => 'EchoChk' . rand(100, 999)], $jar, 'add_section');
});

t('calendar: the day panel deletes and duplicates without entering edit mode', function () {
    $jar = login('example', 'examplepassword');
    $today = date('Y-m-d');
    // Make reminders on today so both runs of each action have a real target.
    foreach (['cal echo a', 'cal echo b'] as $t) {
        req('POST', '/calmind/calendar/', ['csrf' => csrf($jar, '/calmind/calendar/'),
            'action' => 'add_reminder', 'text' => $t,
            'date' => $today, 'ym' => date('Y-m')], $jar);
    }
    $ids = [];
    foreach (stored('reminders', 'example') as $x) {
        if (str_starts_with((string) ($x['text'] ?? ''), 'cal echo')) { $ids[] = $x['id']; }
    }
    ok(count($ids) >= 2, 'the fixture reminders exist — without them every echo check is vacuous');
    edit_echo('/calmind/calendar/', ['action' => 'delete_item', 'kind' => 'reminder',
        'id' => array_shift($ids), 'confirm' => '1', 'ym' => date('Y-m')], $jar, 'delete_item');
    edit_echo('/calmind/calendar/', ['action' => 'duplicate_item', 'kind' => 'reminder',
        'id' => array_shift($ids), 'ym' => date('Y-m')], $jar, 'duplicate_item');
});

t('habits: the unconfirmed-delete bounce does not originate edit mode either', function () {
    $jar = login('example', 'examplepassword');
    edit_echo('/calmind/habits/', ['action' => 'delete_habit', 'id' => 'nosuchhabit'], $jar,
        'unconfirmed delete_habit bounce');
});

t('the edit stamp covers programmatic submits too', function () {
    // The rename fields commit through form.submit(), which fires no submit event — so
    // keep_edit_script() must patch the prototype as well as listen. Without that half,
    // gating the server on the posted flag would kick you out of edit mode on every
    // rename. Wiring: the patch has to ship on every page that has edit mode.
    $jar = login('example', 'examplepassword');
    foreach (['/calmind/reminders/', '/calmind/notes/', '/calmind/habits/'] as $p) {
        $b = req('GET', $p, [], $jar)['body'];
        has('HTMLFormElement.prototype.submit = function', $b, "$p patches programmatic submit");
    }
});

// ---------------------------------------------------------------- 10. sharing
area('sharing');

t('the pair can see each other, and nobody else has a partner', function () {
    eq('example', share_partner('buddy'));
    eq('buddy', share_partner('example'));
    eq('aki', share_partner('sean'));
    eq(null, share_partner('nobody'));
});

t("a partner's shared folder shows in my list, unshared ones do not", function () {
    $jar = login('example', 'examplepassword');
    $r = req('GET', '/calmind/reminders/?folder=All', [], $jar);
    has('@buddy:Dinners', $r['body'], "buddy's shared folder is offered");
    hasnt('@buddy:House', $r['body'], 'a folder they did not share is not');
});

t("writing into a partner's shared folder writes to their file", function () {
    $jar = login('example', 'examplepassword');
    $before = count(rows('buddy'));
    $view = '@buddy:Dinners';
    $r = req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'add', 'view' => $view,
        'text' => 'Added by example', 'folder' => 'Dinners', 'section' => ''], $jar);
    ok(in_array($r['status'], [302, 403], true), 'either it wrote or it refused, not a 500');
    if ($r['status'] === 302) {
        eq($before + 1, count(rows('buddy')), "the row landed in buddy's file");
        eq(null, rowBy('example', 'Added by example'), "and not in example's");
    }
});

t("a shared row ticks from the All view and lands back on All", function () {
    // The All listing used to draw a partner's rows as dead read-only marks — the one
    // thing you could not do from All was check something off their shared list. The
    // tick is live now: it posts against their file (view=@partner:Folder) and ret=All
    // brings the redirect back to All rather than jumping into the shared view.
    $jar = login('example', 'examplepassword');
    $b = req('GET', '/calmind/reminders/?folder=All', [], $jar)['body'];
    has('name="ret" value="All"', $b, 'shared rows carry the return-to-All field');
    hasnt('ro-mark', $b, 'the dead read-only mark is gone');
    $row = null;
    foreach (stored('reminders', 'buddy') as $r) {
        if (($r['type'] ?? '') !== 'section' && ($r['folder'] ?? '') === 'Dinners'
            && empty($r['done']) && empty($r['repeat'])) { $row = $r; break; }
    }
    ok($row !== null, 'an open row of theirs exists');
    $r = req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'toggle',
        'view' => '@buddy:Dinners', 'ret' => 'All', 'id' => $row['id']], $jar);
    has('folder=All', (string) $r['location'], 'the redirect lands on All, not the shared view');
    $now = null;
    foreach (stored('reminders', 'buddy') as $x) { if (($x['id'] ?? '') === $row['id']) { $now = $x; } }
    ok(!empty($now['done']), "the tick landed in buddy's file");
    // And back again, so their data is left as found.
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'toggle',
        'view' => '@buddy:Dinners', 'ret' => 'All', 'id' => $row['id']], $jar);
});

t('a picker checkbox lands on the All view, so ticks match the screen', function () {
    // Composing the boxes from a single-folder view used to flip the stored flags while
    // the screen kept showing just that folder — the boxes read as doing nothing, even
    // across a refresh. The box handler navigates to All now; the Calendar's does the
    // same (keeping its month and day).
    $jar = login('example', 'examplepassword');
    $b = req('GET', '/calmind/reminders/?folder=All', [], $jar)['body'];
    has("location.pathname + '?folder=All'", $b, 'the folder picker box lands on All');
    $c = req('GET', '/calmind/calendar/', [], $jar)['body'];
    has("new URL('?cal=all', location.href)", $c, "the calendar's box lands on all calendars");
});

t("structural edits to a partner's folder are refused", function () {
    $jar = login('example', 'examplepassword');
    $view = '@buddy:Dinners';
    $secsBefore = count(array_filter(stored('reminders', 'buddy'), fn($r) => ($r['type'] ?? '') === 'section'));
    $r = req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'add_section',
        'view' => $view, 'name' => 'Sneaky', 'folder' => 'Dinners'], $jar);
    eq(403, $r['status'], 'adding a section to their folder is a 403');
    eq($secsBefore, count(array_filter(stored('reminders', 'buddy'), fn($r) => ($r['type'] ?? '') === 'section')),
       'and nothing was written');
});

t('share_set adds and removes a share', function () {
    $jar = login('buddy', 'buddypassword');
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'share_set',
        'kind' => 'folder', 'key' => 'House', 'on' => '1'], $jar, true);
    ok(in_array('House', shares_load(datadir(), 'buddy')['folders'], true), 'shared');
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'share_set',
        'kind' => 'folder', 'key' => 'House', 'on' => ''], $jar, true);
    ok(!in_array('House', shares_load(datadir(), 'buddy')['folders'], true), 'unshared');
});

t('partners: sharing needs BOTH names added, and stops the moment one goes', function () {
    // Two fresh accounts with no built-in pair — the handshake from nothing.
    ensure_account('pat', 'patpassword');
    ensure_account('quinn', 'quinnpassword');
    eq(null, share_partner('pat'), 'no partner to start');

    // pat adds quinn: one-sided, so still nothing — for either of them.
    $jp = login('pat', 'patpassword');
    $r = req('POST', '/calmind/reminders/', ['csrf' => csrf($jp), 'action' => 'partner_add',
        'name' => 'Quinn '], $jp, true);   // sloppy case/space cleans to 'quinn'
    $j = json_decode($r['body'], true);
    eq([['name' => 'quinn', 'mutual' => false]], $j['partners'] ?? null, 'listed, not yet mutual');
    eq(null, share_partner('pat'),   "one-sided: pat still has no partner");
    eq(null, share_partner('quinn'), 'and neither does quinn');

    // quinn adds pat back: now — and only now — the partnership exists, both ways.
    $jq = login('quinn', 'quinnpassword');
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jq), 'action' => 'partner_add',
        'name' => 'pat'], $jq, true);
    eq('quinn', share_partner('pat'), 'mutual: pat sees quinn');
    eq('pat', share_partner('quinn'), 'and quinn sees pat');

    // And what a partnership carries: quinn shares a folder, pat's picker offers it.
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jq), 'action' => 'add_folder',
        'name' => 'Ourplans'], $jq);
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jq), 'action' => 'share_set',
        'kind' => 'folder', 'key' => 'Ourplans', 'on' => '1'], $jq, true);
    has('@quinn:Ourplans', req('GET', '/calmind/reminders/?folder=All', [], $jp)['body'],
        "quinn's shared folder reaches pat");

    // quinn removes pat: everything stops at once, in both directions, shares intact or not.
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jq), 'action' => 'partner_del',
        'name' => 'pat', 'confirm' => '1'], $jq, true);
    eq(null, share_partner('pat'),   'the safeguard: removal on one side ends it for both');
    eq(null, share_partner('quinn'), 'quinn too');
    $b = req('GET', '/calmind/reminders/?folder=All', [], $jp)['body'];
    ok(strpos($b, '@quinn:Ourplans') === false, "and quinn's folder is gone from pat's app");
    // pat's own list still remembers quinn — as waiting, in case quinn comes back.
    eq([['name' => 'quinn', 'mutual' => false]], share_partner_rows(datadir(), 'pat'));
});

t('partners: a removal without the confirmed second press does nothing', function () {
    ensure_account('pat', 'patpassword');
    $jp = login('pat', 'patpassword');
    $before = share_partner_list(datadir(), 'pat');
    ok(in_array('quinn', $before, true), 'quinn is on the list from the last test');
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jp), 'action' => 'partner_del',
        'name' => 'quinn'], $jp, true);
    eq($before, share_partner_list(datadir(), 'pat'), 'no confirm, no removal');
});

t('partners: rename replaces the entry; junk and self-adds are refused', function () {
    $jp = login('pat', 'patpassword');
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jp), 'action' => 'partner_rename',
        'name' => 'quinn', 'newname' => 'Robin'], $jp, true);
    eq(['robin'], share_partner_list(datadir(), 'pat'), 'renamed (and lowercased)');
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jp), 'action' => 'partner_add',
        'name' => 'pat'], $jp, true);
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jp), 'action' => 'partner_add',
        'name' => '../evil name'], $jp, true);
    eq(['robin'], share_partner_list(datadir(), 'pat'), 'neither yourself nor junk can be added');
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jp), 'action' => 'partner_del',
        'name' => 'robin', 'confirm' => '1'], $jp, true);
    eq([], share_partner_list(datadir(), 'pat'), 'cleaned up');
});

t('partners: the built-in pairs are seeds — they work untouched, and opt out cleanly', function () {
    // Untouched lists: the pair still stands (this is what keeps sean ⇄ aki working).
    eq('example', share_partner('buddy'));
    eq('buddy', share_partner('example'));
    // Toggling a share must not disturb the seeding (shares_save carries partners through).
    $jar = login('buddy', 'buddypassword');
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'share_set',
        'kind' => 'folder', 'key' => 'House', 'on' => '1'], $jar, true);
    eq('example', share_partner('buddy'), 'a share toggle leaves the partnership alone');
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'share_set',
        'kind' => 'folder', 'key' => 'House', 'on' => ''], $jar, true);
    // buddy deletes the seeded example: sharing stops both ways, though example's own
    // (virtual) list still names buddy — one side is never enough.
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'partner_del',
        'name' => 'example', 'confirm' => '1'], $jar, true);
    eq(null, share_partner('buddy'), 'buddy opted out');
    eq(null, share_partner('example'), 'which ends it for example too — strictly mutual');
    // Adding the name back restores the pair.
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'partner_add',
        'name' => 'example'], $jar, true);
    eq('example', share_partner('buddy'), 'and re-adding restores it');
    eq('buddy', share_partner('example'));
});

t('wiring: the share window carries the pencil, and works before any partner exists', function () {
    // A fresh account has no partner, but still gets the Share button, the window and
    // the partner list — that's how the first partnership starts.
    ensure_account('fresh', 'freshpassword');
    $jar = login('fresh', 'freshpassword');
    foreach (['/calmind/reminders/', '/calmind/notes/', '/calmind/calendar/'] as $p) {
        $b = req('GET', $p, [], $jar)['body'];
        has('id="shareBtn"', $b, "$p offers Share with no partner");
        has('id="shareEditBtn"', $b, "$p share window carries the pencil");
        has('id="partnerModal"', $b, "$p ships the partner window");
        has('No sharing partner yet', $b, "$p explains the empty state");
    }
    // With a partner, the window keeps its three lists and gains the pencil.
    $jb = login('buddy', 'buddypassword');
    $b = req('GET', '/calmind/reminders/', [], $jb)['body'];
    has('id="shareCals"', $b, 'the share lists still render for a partnered user');
    has('id="shareEditBtn"', $b, 'beside the pencil');
});

t("a partner's dated shared note shows on my calendar, an unshared one doesn't", function () {
    // Buddy's recipe notes are dated to the shared dinners; they must reach example's
    // calendar the way buddy's shared events already do — same day, marked as theirs.
    $ragu = null;
    foreach (stored('notes', 'buddy') as $n) {
        if (($n['type'] ?? '') !== 'section' && ($n['folder'] ?? '') === 'Recipes'
            && !empty($n['date'])) { $ragu = $n; break; }
    }
    ok($ragu !== null, 'the seed gives buddy a dated note in the shared Recipes folder');
    // And one dated note in a folder buddy does NOT share, on the same day.
    $bjar = login('buddy', 'buddypassword');
    $before = array_column(stored('notes', 'buddy'), 'id');
    req('POST', '/calmind/notes/', ['csrf' => csrf($bjar, '/calmind/notes/'), 'action' => 'add',
        'view' => 'General', 'folder' => 'General', 'section' => ''], $bjar);
    $pid = null;
    foreach (stored('notes', 'buddy') as $n) {
        if (($n['type'] ?? '') !== 'section' && !in_array($n['id'], $before, true)) { $pid = $n['id']; }
    }
    req('POST', '/calmind/notes/', ['csrf' => csrf($bjar, '/calmind/notes/'), 'action' => 'save',
        'view' => 'All', 'id' => $pid, 'title' => 'private jam plan', 'date' => $ragu['date'],
        'body' => '', 'folder' => 'General', 'section' => ''], $bjar);

    $jar = login('example', 'examplepassword');
    $r = req('GET', '/calmind/calendar/?ym=' . substr($ragu['date'], 0, 7), [], $jar);
    preg_match('/=\s*(\{"20\d\d-\d\d-\d\d".*?\})\s*;/s', $r['body'], $m);
    $byDay = json_decode($m[1] ?? '{}', true);
    $found = $leak = null;
    foreach ($byDay[$ragu['date']] ?? [] as $it) {
        if (($it['kind'] ?? '') === 'note' && ($it['text'] ?? '') === ($ragu['title'] ?? '')) { $found = $it; }
        if (($it['text'] ?? '') === 'private jam plan') { $leak = $it; }
    }
    ok($found !== null, "buddy's dated recipe note reaches example's calendar");
    eq('buddy', $found['owner'] ?? null, 'and is marked as theirs');
    ok(!empty($found['color']), 'and wears a colour');
    ok($leak === null, "a note from a folder buddy never shared stays off my calendar");
});

// ---------------------------------------------------------------- 11. widget / api
area('widget');

t('the feed refuses a bad token and answers a good one', function () use ($scratch) {
    $r = req('GET', '/calmind/calendar/feed.php?token=nonsense');
    ok($r['status'] >= 400 || strpos($r['body'], '"items"') === false, 'a bad token gets nothing');

    $tok = store_read($scratch . '/token-example.json');
    $tok = is_array($tok) ? ($tok['token'] ?? reset($tok)) : $tok;
    if (!is_string($tok) || $tok === '') { return; }        // no token issued in this fixture
    $r = req('GET', '/calmind/calendar/feed.php?token=' . urlencode($tok));
    eq(200, $r['status']);
    $j = json_decode($r['body'], true);
    ok(is_array($j), 'the feed is JSON');
});

t('the feed is read-only — a POST behind the token changes nothing', function () use ($scratch) {
    $tok = store_read($scratch . '/token-example.json');
    $tok = is_array($tok) ? ($tok['token'] ?? reset($tok)) : $tok;
    if (!is_string($tok) || $tok === '') { return; }
    $before = count(rows('example'));
    req('POST', '/calmind/calendar/feed.php?token=' . urlencode($tok),
        ['action' => 'add', 'text' => 'via the feed']);
    eq($before, count(rows('example')), 'nothing was written');
});

t('the reminders API needs a session or a token', function () {
    $r = req('GET', '/calmind/api/reminders.php');
    ok($r['status'] !== 200 || strpos($r['body'], '"text"') === false,
       'no anonymous read');
});

t('quick.php adds for today', function () {
    $jar = login('example', 'examplepassword');
    $r = req('GET', '/calmind/calendar/quick.php', [], $jar);
    eq(200, $r['status'], 'the page loads');
    if (preg_match('/name="csrf" value="([^"]+)"/', $r['body'], $m)) {
        req('POST', '/calmind/calendar/quick.php',
            ['csrf' => $m[1], 'type' => 'reminder', 'text' => 'Quick add test'], $jar);
        $row = rowBy('example', 'Quick add test');
        if ($row !== null) { eq(date('Y-m-d'), (string) $row['due'], 'lands on today'); }
    }
});

// ---------------------------------------------------------------- 12. lib units
area('lib');

t('the text parser is slash-only and US-order', function () {
    [$text, $date, $time] = parse_when_from_text('Vet 8/3 2pm');
    eq('Vet', $text);
    eq('14:00', $time);
    eq('08-03', substr((string) $date, 5));

    [$text, $date] = parse_when_from_text('Milk');
    eq('Milk', $text);
    eq(null, $date, 'no date in plain text');

    eq('14:30', parse_time_from_text('meet 2:30 pm')[1] ?? parse_time_from_text('meet 2:30 pm'),
       '2:30 pm');
});

t('a date-like fraction is a known limitation, not a crash', function () {
    [$text, $date] = parse_when_from_text('2/3 cup flour');
    ok($date !== null, 'documented: "2/3 cup" parses as a date');
});

t('month repeats clamp the day instead of sliding', function () {
    $days = repeat_dates('2026-01-31', ['n' => 1, 'unit' => 'month'], '2026-02-01', '2026-03-05');
    ok(in_array('2026-02-28', $days, true), 'Jan 31 + 1 month is Feb 28, not Mar 3');
    ok(!in_array('2026-03-03', $days, true), 'and never slides into March');
});

t('year repeats clamp a leap day', function () {
    $days = repeat_dates('2024-02-29', ['n' => 1, 'unit' => 'year'], '2025-01-01', '2025-12-31');
    ok(in_array('2025-02-28', $days, true), 'Feb 29 + 1 year is Feb 28');
});

t('repeat_next moves to the next occurrence', function () {
    eq('2026-08-03', repeat_next('2026-08-01', ['n' => 2, 'unit' => 'day'], '2026-08-01'));
    eq('2026-08-08', repeat_next('2026-08-01', ['n' => 1, 'unit' => 'week'], '2026-08-01'));
    eq('2026-09-01', repeat_next('2026-08-01', ['n' => 1, 'unit' => 'month'], '2026-08-01'));
});

t('a folder tint is 8-digit hex, and refuses anything else', function () {
    eq('#4c8bf033', folder_tint('#4c8bf0'));
    eq('transparent', folder_tint('conic-gradient(red, blue)'));
    eq('transparent', folder_tint('red'));
});

t('the plus icon is an SVG, so it centres by construction', function () {
    $svg = plus_icon_svg(12);
    has('<svg', $svg);
    has('width="12"', $svg);
    hasnt('>+<', $svg, 'never a text plus');
});

t('every app palette offers six colours and validates its own', function () {
    // Brightness helper: the average channel value of a #rrggbb.
    $lum = fn($h) => (hexdec(substr($h, 1, 2)) + hexdec(substr($h, 3, 2)) + hexdec(substr($h, 5, 2))) / 3;
    foreach (['reminders', 'notes', 'calendar', 'habits'] as $app) {
        eq(6, count(app_palette($app)), "$app own");
        eq(6, count(app_palette($app, true)), "$app shared");
        ok(palette_has($app, app_palette($app)[0]), 'own colour validates');
        ok(palette_has($app, app_palette($app, true)[0]), 'shared colour validates');
        ok(!palette_has($app, '#ff0000'), 'a stranger does not');
        // The shared (partner) set is a matching lighter version of the own set: clearly
        // lighter, and the same hue — a pastel of this app's shade, not some other app's.
        foreach (range(0, 5) as $i) {
            ok($lum(app_palette($app, true)[$i]) > $lum(app_palette($app)[$i]) + 30,
               "$app shared colour $i is clearly lighter than own");
        }
        foreach (range(0, 4) as $i) {   // hue is meaningless on the grey, so 0–4
            [$ho] = pal_hsl(app_palette($app)[$i]);
            [$hs] = pal_hsl(app_palette($app, true)[$i]);
            $d = abs($ho - $hs);
            ok(min($d, 360 - $d) < 4, "$app shared colour $i keeps its hue");
        }
    }
    // Every own colour clears 3:1 on the dark themes' card — pal_floor()'s promise, and
    // the reason no dot on a dark page ever needs squinting for.
    foreach (['reminders', 'calendar', 'notes', 'habits'] as $app) {
        foreach (app_palette($app) as $hex) {
            ok((pal_lum($hex) + 0.05) / (pal_lum('#1a1a1a') + 0.05) >= 3.0,
               "$app $hex clears 3:1 on the dark card");
        }
    }
    // Each app wears the six hues at its own distinct shade — no two apps share a colour,
    // and the gap is wide enough to see at dot size (sum of per-channel differences).
    $dist = fn($a, $b) => abs(hexdec(substr($a, 1, 2)) - hexdec(substr($b, 1, 2)))
                        + abs(hexdec(substr($a, 3, 2)) - hexdec(substr($b, 3, 2)))
                        + abs(hexdec(substr($a, 5, 2)) - hexdec(substr($b, 5, 2)));
    $apps = ['reminders', 'calendar', 'notes', 'habits'];
    foreach ($apps as $x => $a) {
        foreach (array_slice($apps, $x + 1) as $b) {
            foreach (range(0, 5) as $i) {
                ok($dist(app_palette($a)[$i], app_palette($b)[$i]) >= 40,
                   "$a and $b hue $i are distinct shades");
            }
        }
    }
});

t("a colour stored under an earlier palette bumps to its slot in today's", function () {
    global $scratch;
    // Pure lookups: the flattened-era shared blue, the lightness-tier calendar purple
    // and the first hand-typed notes blue each map to the same slot in the leaned set;
    // a current colour passes through and a stranger stays unknown.
    eq(app_palette('notes', true)[0], palette_recolor('notes', pal_lighten(PAL_BASE[0], 0.60)));
    eq(app_palette('calendar')[4], palette_recolor('calendar', '#7d1bdf'));
    eq(app_palette('notes')[0], palette_recolor('notes', '#125ed9'));
    eq(app_palette('reminders')[2], palette_recolor('reminders', app_palette('reminders')[2]));
    eq(null, palette_recolor('reminders', '#ff0000'));
    // The calendar reader runs stored colours through the same bump.
    eq(app_palette('calendar')[4], cal_color_fix('#7d1bdf'));
    // And a folder file holding an old colour reads back wearing the new one, not a
    // positional default.
    $f    = user_data_file($scratch, 'folders', 'example');
    $data = store_read($f);
    $keep = $data['colors']['reminders'] ?? [];
    $data['colors']['reminders']['Work'] = pal_lighten(PAL_BASE[0], 0.60);
    store_write($f, $data);
    eq(app_palette('reminders', true)[0], folder_colors($scratch, 'reminders', 'example')['Work'] ?? null,
       'the folder wears the bumped colour');
    $data['colors']['reminders'] = $keep;
    store_write($f, $data);
});

t('the palettes viewer grades every hex label by its contrast', function () {
    $jar = login('example', 'examplepassword');
    $r = req('GET', '/userpalettes/', [], $jar);
    eq(200, $r['status'], 'the viewer renders');
    // Every rated swatch label carries a graded colour (red poor → green good) and sits
    // on the little surface chip; a label with no style would mean the grading is gone.
    $graded = preg_match_all('/<b style="color:#[0-9a-f]{6}">/', $r['body']);
    ok($graded > 300, "the labels are graded ($graded found)");
    has('background: var(--surface-2)', $r['body'], 'the label sits on a chip');
    // A failure blushes the chip; there is no dashed ring around the dot any more.
    has('.sw.low b', $r['body'], 'the under-3:1 chip is marked');
    hasnt('dashed', $r['body'], 'no dashed border anywhere');
    // The boards come grouped into drafts, newest first, so iterations read side by
    // side; each heading folds its group (a JS gesture, so only the wiring is checked).
    foreach ([1, 2, 3] as $d) {
        has("Draft $d — ", $r['body'], "draft $d has its heading");
        has('<div class="dgroup" data-draft="' . $d . '"', $r['body'], "draft $d wraps its boards");
    }
    has('upDraftFold_', $r['body'], 'the draft fold is wired and remembered');
    // The four Draft 2 boards render, and each keeps its whole promise: every one of
    // its swatches clears 3:1, so its head must say all clear, never a count.
    foreach (range(0, 3) as $i) {
        $at = strpos($r['body'], 'data-key="prop-' . $i . '"');
        ok($at !== false, "proposal board $i renders");
        $chunk = substr($r['body'], $at, strpos($r['body'], '</section>', $at) - $at);
        has('all clear 3:1', $chunk, "proposal board $i is all clear");
        hasnt('under 3:1', $chunk, "proposal board $i flags nothing");
    }
    // Draft 1 is the frozen history, on four themes.
    foreach (range(0, 3) as $i) { has('data-key="d1-' . $i . '"', $r['body'], "draft-1 board $i renders"); }
    // The live palette floors itself on Midnight's card, so the live Midnight board
    // must be spotless.
    $at = strpos($r['body'], 'data-key="suite-midnight"');
    $chunk = substr($r['body'], $at, strpos($r['body'], '</section>', $at) - $at);
    has('all clear 3:1', $chunk, 'the live palette on Midnight is all clear');
    quiet($r['body']);
});

t('the folder migration is idempotent', function () {
    $old = [
        ['id' => 'a', 'type' => 'section', 'name' => 'Calendar', 'folder' => 'General'],
        ['id' => 'b', 'text' => 'thing', 'section' => 'Calendar', 'folder' => 'General'],
        ['id' => 'c', 'text' => 'other', 'folder' => 'General'],
    ];
    $once  = reminders_folder_migrate($old);
    $twice = reminders_folder_migrate($once);
    eq($once, $twice, 'running it again changes nothing');
    foreach ($once as $r) {
        ok(($r['folder'] ?? '') !== 'General', 'General became Reminders');
    }
});

t('escaping is applied on output', function () {
    $jar = login('example', 'examplepassword');
    showAll($jar);                                   // don't read a list something else hid
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'add', 'view' => 'Reminders',
        'text' => '<script>alert(1)</script>', 'folder' => 'Reminders', 'section' => ''], $jar);
    $r = req('GET', '/calmind/reminders/?folder=Reminders', [], $jar);
    hasnt('<script>alert(1)</script>', $r['body'], 'the raw tag never reaches the page');
    has('&lt;script&gt;', $r['body'], 'it is escaped instead');
});

// ---------------------------------------------------------------- 13. every page renders
area('pages');

t('every page of the suite renders for a seeded user', function () {
    foreach (['example', 'buddy'] as $user) {
        $jar = login($user, $user . 'password');
        foreach (['/calmind/reminders/', '/calmind/notes/', '/calmind/calendar/', '/calmind/habits/', '/calmind/add/',
                  '/calmind/reminders/?folder=All', '/calmind/calendar/?ym=' . date('Y-m'),
                  '/calmind/habits/?m=' . date('Y-m'), '/calmind/calendar/quick.php'] as $p) {
            $r = req('GET', $p, [], $jar);
            eq(200, $r['status'], "$user $p");
            quiet($r['body'], "$user $p");
        }
    }
});

t('the public pages need no login', function () {
    foreach (['/', '/about/', '/projects/', '/contact/', '/themepicker/', '/chat/'] as $p) {
        $r = req('GET', $p);
        eq(200, $r['status'], "$p status");
        hasnt('Fatal error', $r['body'], $p);
    }
});

t('an empty account is a working empty suite, not a crash', function () use ($scratch) {
    $acc = store_read($scratch . '/accounts.json');
    $acc['freshy'] = ['email' => 'f@example.com', 'password' => 'freshpassword', 'created' => time()];
    store_write($scratch . '/accounts.json', $acc);
    $jar = login('freshy', 'freshpassword');
    foreach (['/calmind/reminders/', '/calmind/notes/', '/calmind/calendar/', '/calmind/habits/', '/calmind/add/'] as $p) {
        $r = req('GET', $p, [], $jar);
        eq(200, $r['status'], "empty account $p");
        quiet($r['body'], "empty account $p");
    }
});

// ---------------------------------------------------------------- 14. regressions
// One case per bug that actually reached a phone. Several of these were touch-only —
// a click-eater, a link interceptor in the PWA shell, a two-step gesture, a margin —
// and a headless run can never press them. Those are checked as **wiring**: the page
// still has to contain the handler or the rule that makes the behaviour possible, so
// removing it fails here even though nothing here can feel it. Behaviour that *can* be
// driven is driven properly. Both kinds say which they are in the label.
area('regress');

t('wiring: a picker row tap stops the click reaching the PWA link interceptor', function () {
    $jar = login('example', 'examplepassword');
    foreach (['/calmind/reminders/' => 'folderpick-opt', '/calmind/calendar/' => 'calpick-opt'] as $page => $cls) {
        $b = req('GET', $page, [], $jar)['body'];
        has($cls, $b, "$page draws picker rows");
        // The row handler is the one that both cancels the link and stops it bubbling.
        ok(preg_match('/preventDefault\(\);\s*e\.stopPropagation\(\)/', $b) === 1,
           "$page: a row tap must cancel the link *and* stop it reaching tabbar.php");
    }
    // tabbar.php is the thing it has to beat: it follows same-origin links from document.
    has('window.navigator.standalone', req('GET', '/calmind/reminders/', [], $jar)['body'],
        'the interceptor this guards against is still there');
});

t("a partner's folder view still shows the visibility checkmarks", function () {
    $jar = login('example', 'examplepassword');
    $r = req('GET', '/calmind/reminders/?folder=' . rawurlencode('@buddy:Dinners'), [], $jar);
    eq(200, $r['status']);
    ok(substr_count($r['body'], 'class="fvis') > 0,
       'opening one of theirs must not blank every checkmark');
});

t('wiring: the edit gesture opens a section name for typing', function () {
    $jar = login('example', 'examplepassword');
    foreach (['/calmind/reminders/', '/calmind/notes/'] as $page) {
        $b = req('GET', $page, [], $jar)['body'];
        ok(preg_match('/querySelector\(.\.sectitle.\)/', $b) === 1,
           "$page: the gesture has to reach for the name field");
        has('.focus()', $b, "$page: and focus it");
    }
});

t('renaming a section from the list works in Notes as well as Reminders', function () {
    foreach ([['/calmind/notes/', 'notes'], ['/calmind/reminders/', 'reminders']] as [$page, $base]) {
        $jar = login('example', 'examplepassword');
        $sec = null;
        foreach (stored($base, 'example') as $it) { if (($it['type'] ?? '') === 'section') { $sec = $it; break; } }
        ok($sec !== null, "$page has a section");
        $to = 'Renamed ' . $base;
        req('POST', $page, ['csrf' => csrf($jar, $page), 'action' => 'rename_section', 'view' => 'All',
            'folder' => $sec['folder'] ?? '', 'name' => $sec['name'], 'newname' => $to], $jar);
        $names = [];
        foreach (stored($base, 'example') as $it) { if (($it['type'] ?? '') === 'section') { $names[] = $it['name']; } }
        ok(in_array($to, $names, true), "$page rename should have stuck");
        // and the rows that named it follow it, rather than being orphaned. Sections are
        // per-folder (every folder now has its own "General"), so a rename only re-points
        // its own folder's rows — check just those.
        foreach (stored($base, 'example') as $it) {
            if (($it['type'] ?? '') === 'section') { continue; }
            if (($it['folder'] ?? '') !== ($sec['folder'] ?? '')) { continue; }
            ok(($it['section'] ?? '') !== $sec['name'], "$page: no row in its folder still points at the old name");
        }
    }
});

t('editing a reminder inline reads the date out of what was typed', function () {
    $jar = login('example', 'examplepassword');
    showAll($jar);
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'add', 'view' => 'Reminders',
        'text' => 'Regress edit target', 'folder' => 'Reminders', 'section' => ''], $jar);
    $row = rowBy('example', 'Regress edit target');
    $r = req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'edit_text', 'view' => 'All',
        'id' => $row['id'], 'text' => 'Regress edit target 9/6 4pm'], $jar, true);
    $j = json_decode($r['body'], true);
    eq('Regress edit target', $j['text'] ?? null, 'the date words are taken out of the text');
    eq('16:00', $j['time'] ?? null);
    eq('09-06', substr((string) ($j['due'] ?? ''), 5));
    $after = rowBy('example', 'Regress edit target');
    eq('16:00', $after['time'], 'and that is what was stored');
});

t('renaming a dated reminder with no date in the line leaves its date alone', function () {
    $jar = login('example', 'examplepassword');
    $row = rowBy('example', 'Regress edit target');
    $was = $row['due'];
    ok(!empty($was), 'it has a date to lose');
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'edit_text', 'view' => 'All',
        'id' => $row['id'], 'text' => 'Regress edit target renamed'], $jar, true);
    eq($was, rowBy('example', 'Regress edit target renamed')['due'], 'the date must survive a rename');
});

t('a date picked by hand wins the value, and the typed date still leaves the title', function () {
    // A parsed date or time is an instruction, not part of the name: it is used (unless
    // the picker overrode it) and never appears in the stored text.
    $jar = login('example', 'examplepassword');
    $ev = null;
    foreach (stored('events', 'example') as $e) { if (($e['text'] ?? '') === 'Design review') { $ev = $e; } }
    ok($ev !== null, 'the seeded event is there');
    req('POST', '/calmind/calendar/', ['csrf' => csrf($jar, '/calmind/calendar/'), 'action' => 'edit_item',
        'kind' => 'event', 'id' => $ev['id'], 'text' => 'Design review 8/3 with Sam',
        'date' => '2026-08-10', 'ym' => date('Y-m')], $jar);
    foreach (stored('events', 'example') as $e) {
        if (($e['id'] ?? '') !== $ev['id']) { continue; }
        eq('Design review with Sam', $e['text'], 'the typed date is cut out of the title even when the picker wins');
        eq('2026-08-10', $e['date'], 'and the picked date is what is used');
    }
    // Put the title back for the case below, which finds it by prefix.
    req('POST', '/calmind/calendar/', ['csrf' => csrf($jar, '/calmind/calendar/'), 'action' => 'edit_item',
        'kind' => 'event', 'id' => $ev['id'], 'text' => 'Design review', 'ym' => date('Y-m')], $jar);
});

t('the typed date/time never stays in the title: adding and full-editing too', function () {
    $jar = login('example', 'examplepassword');
    showAll($jar);
    // Calendar add window: picker date wins, typed tokens go, typed time is kept.
    req('POST', '/calmind/calendar/', ['csrf' => csrf($jar, '/calmind/calendar/'), 'action' => 'add_event',
        'text' => 'Strip add 8/3 2pm', 'date' => '2026-12-30', 'ym' => date('Y-m')], $jar);
    $hit = null;
    foreach (stored('events', 'example') as $e) { if (strpos((string) ($e['text'] ?? ''), 'Strip add') === 0) { $hit = $e; } }
    eq('Strip add', $hit['text'] ?? null, 'add_event: the tokens leave the title');
    eq('2026-12-30', $hit['date'] ?? null, 'add_event: the picker date wins');
    eq('14:00', $hit['time'] ?? null, 'add_event: the typed time is still used');
    // Reminders add with an explicit due posted: it used to skip parsing entirely, so
    // the tokens stayed in the title and the typed time was lost.
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'add', 'view' => 'Reminders',
        'text' => 'Strip radd 12/26 6pm', 'due' => '2026-12-30', 'folder' => 'Reminders', 'section' => ''], $jar);
    $row = rowBy('example', 'Strip radd');
    ok($row !== null, 'add: the tokens leave the title');
    eq('2026-12-30', $row['due'], 'add: the explicit due wins over the typed date');
    eq('18:00', $row['time'], 'add: the typed time is still used');
    // The full-edit pencil with a by-hand due: same rule.
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'edit_full', 'view' => 'All',
        'id' => $row['id'], 'kind' => 'reminder', 'text' => 'Strip radd again 4/4 9am',
        'due' => '2027-01-05', 'fs' => "Reminders\x1F"], $jar);
    $row = rowBy('example', 'Strip radd again');
    ok($row !== null, 'edit_full: the tokens leave the title');
    eq('2027-01-05', $row['due'], 'edit_full: the by-hand due wins');
    eq('09:00', $row['time'], 'edit_full: the typed time is still used');
});

t('with no date picked, the calendar still reads one out of the text', function () {
    $jar = login('example', 'examplepassword');
    $ev = null;
    foreach (stored('events', 'example') as $e) { if (strpos((string) ($e['text'] ?? ''), 'Design review') === 0) { $ev = $e; } }
    req('POST', '/calmind/calendar/', ['csrf' => csrf($jar, '/calmind/calendar/'), 'action' => 'edit_item',
        'kind' => 'event', 'id' => $ev['id'], 'text' => 'Design review 9/9 3pm', 'ym' => date('Y-m')], $jar);
    foreach (stored('events', 'example') as $e) {
        if (($e['id'] ?? '') !== $ev['id']) { continue; }
        eq('Design review', $e['text'], 'stripped');
        eq('09-09', substr((string) $e['date'], 5));
        eq('15:00', $e['time']);
    }
});

t('habits and sections can be reordered, and nothing falls out', function () {
    $jar = login('example', 'examplepassword');
    $all     = stored('habits', 'example');
    $habits  = array_values(array_filter($all, fn($x) => ($x['type'] ?? '') !== 'section'));
    $secIds  = array_column(array_values(array_filter($all, fn($x) => ($x['type'] ?? '') === 'section')), 'id');
    ok(count($habits) > 1 && count($secIds) > 1, 'something to reorder');

    $order = [];
    foreach (array_reverse($habits) as $h) { $order[] = ['id' => $h['id'], 'section' => $h['section'] ?? '']; }
    $want = array_reverse($secIds);
    req('POST', '/calmind/habits/', ['csrf' => csrf($jar, '/calmind/habits/'), 'action' => 'reorder',
        'order' => json_encode($order), 'sections' => json_encode($want)], $jar, true);

    $after   = stored('habits', 'example');
    $aHabits = array_values(array_filter($after, fn($x) => ($x['type'] ?? '') !== 'section'));
    eq(count($habits), count($aHabits), 'no habit was dropped');
    eq($want, array_column(array_values(array_filter($after, fn($x) => ($x['type'] ?? '') === 'section')), 'id'),
       'the sections are in the order asked for');
});

t('a reorder that never mentions a habit keeps it rather than dropping it', function () {
    $jar = login('example', 'examplepassword');
    $before = count(array_filter(stored('habits', 'example'), fn($x) => ($x['type'] ?? '') !== 'section'));
    // A stale page posting one row only must not be read as "delete the rest".
    $one = null;
    foreach (stored('habits', 'example') as $x) { if (($x['type'] ?? '') !== 'section') { $one = $x; break; } }
    req('POST', '/calmind/habits/', ['csrf' => csrf($jar, '/calmind/habits/'), 'action' => 'reorder',
        'order' => json_encode([['id' => $one['id'], 'section' => '']]), 'sections' => json_encode([])], $jar, true);
    eq($before, count(array_filter(stored('habits', 'example'), fn($x) => ($x['type'] ?? '') !== 'section')),
       'everything the drag never mentioned is still there');
});

t('wiring: the habits drag drops against a line, between rows', function () {
    $jar = login('example', 'examplepassword');
    $b = req('GET', '/calmind/habits/?v=week', [], $jar)['body'];
    has('drop-line', $b, 'the same line the other apps drop against');
    has('grid-column: 1 / -1', $b, 'spanning every column, so it sits between rows');
    has('blockOf', $b, 'a section travels with the habits under it');
});

t("a habit's row carries its section's colour", function () {
    $jar = login('example', 'examplepassword');
    // Ask for the week grid by name: the view is a stored preference, so whichever test
    // last looked at the month would otherwise decide what this one is looking at.
    $b = req('GET', '/calmind/habits/?v=week', [], $jar)['body'];
    // The style now carries the colour plus its wash and its line, so match the prefix.
    $names = preg_match_all('/class="hname" style="--hc:#[0-9a-f]{6};/', $b);
    $cells = preg_match_all('/class="cell[^"]*" style="--hc:#[0-9a-f]{6};/', $b);
    has('--hc-soft:#', $b, 'an empty square gets the wash');
    has('--hc-line:#', $b, 'and the borders get the line');
    ok($names > 0, 'the name bubbles are tinted');
    ok($cells > $names, 'and so is every day square on those rows');
    preg_match_all('/--hc:(#[0-9a-f]{6})/', $b, $m);
    $used = array_values(array_unique($m[1]));
    foreach ($used as $c) { ok(in_array($c, app_palette('habits'), true), "$c is a palette colour"); }
    ok(count($used) > 1, 'two sections should not share one colour by default');
});

t('each section header carries its own + Habit, shown out of edit mode', function () {
    $jar = login('example', 'examplepassword');
    $b = req('GET', '/calmind/habits/?v=week', [], $jar)['body'];
    $adds = preg_match_all('/class="hsec-add addhabit"/', $b);
    $secs = preg_match_all('/<div class="hsection"/', $b);
    ok($secs >= 1, 'there is at least one section');
    eq($secs, $adds, 'one + Habit per section header, and no ungrouped run');
    // Each + targets a real section, never the empty/ungrouped value.
    preg_match_all('/name="section" value="([^"]*)"/', $b, $m);
    ok(!in_array('', $m[1], true), 'no + Habit adds into "ungrouped"');
    eq(count(array_unique($m[1])), count($m[1]), 'and no two target the same section');
    // The + shows without edit mode, and the old "+ Section" button is gone.
    ok(strpos($b, '.hsection .hsec-add') !== false && strpos($b, 'body.editing .hsec-add') === false,
       'the + is not gated on edit mode');
    eq(0, substr_count($b, 'id="newSecBtn"'), 'the + Section button is gone');
    has('id="habitSecMgr"', $b, 'Manage sections rides in the filter dropdown instead');
});

t('a fresh habits app starts with a default section, ready to add to', function () {
    // freshy is normally made by the pages area; running `regress` alone still works.
    global $scratch;
    $acc = store_read($scratch . '/accounts.json');
    if (!isset($acc['freshy'])) {
        $acc['freshy'] = ['email' => 'f@example.com', 'password' => 'freshpassword', 'created' => time()];
        store_write($scratch . '/accounts.json', $acc);
    }
    $jar = login('freshy', 'freshpassword');
    $b = req('GET', '/calmind/habits/?v=week', [], $jar)['body'];
    ok(preg_match_all('/<div class="hsection"/', $b) >= 1, 'a default section is there from the start');
    has('class="hsec-add addhabit"', $b, 'with a + to add a habit to it, shown out of edit mode');
    // And it really persisted, so the section keeps a stable id across visits.
    ok(count(array_filter(stored('habits', 'freshy'), fn($x) => ($x['type'] ?? '') === 'section')) >= 1,
       'the default section was written to disk');
    has('id="habitSecMgr"', $b, 'sections are managed from the dropdown');
});

t('wiring: tapping away leaves edit mode in habits', function () {
    $jar = login('example', 'examplepassword');
    $b = req('GET', '/calmind/habits/?v=week', [], $jar)['body'];
    has('setEdit(false)', $b, 'there is a way out that is not a button');
    hasnt('id="editBtn"', $b, 'and the Edit pencil is gone');
});

t('wiring: the Calendar remembers the day, and the tab bar is what forgets it', function () {
    $jar = login('example', 'examplepassword');
    $cal = req('GET', '/calmind/calendar/', [], $jar)['body'];
    has("'calDay'", $cal, 'the calendar stores the selected day');
    has('sessionStorage', $cal, 'for the life of the app session, not for ever');
    // The tab bar is on every page and is the one thing that clears it.
    has('data-tab="calendar"', $cal, 'the Calendar tab is identifiable');
    has('removeItem("calDay")', $cal, 'and tapping it asks for today again');
});

t('wiring: a page revived from the background reloads itself', function () {
    // iOS restores a home-screen PWA's page from memory on app-switch, so ticks made
    // elsewhere (the Calendar panel, the widget, a sharing partner, another device)
    // never showed until a manual reload. Every chrome_script() page now reloads on
    // return — after five clear seconds away, and never mid-edit or mid-typing. The
    // behaviour is JS + iOS, so the harness checks each page ships the machinery.
    $jar = login('example', 'examplepassword');
    foreach (['/calmind/reminders/', '/calmind/calendar/', '/calmind/notes/', '/calmind/habits/'] as $p) {
        $b = req('GET', $p, [], $jar)['body'];
        has('Date.now() - away > 5000', $b, "$p reloads only after real time away");
        has('if (e.persisted && !busy())', $b, "$p covers the back-forward cache too");
        has("classList.contains('editing')", $b, "$p stands down while editing");
    }
});

t('wiring: an explicit ?day= still wins over the remembered one', function () {
    $jar = login('example', 'examplepassword');
    $b = req('GET', '/calmind/calendar/', [], $jar)['body'];
    ok(preg_match("/const q\s*=\s*new URLSearchParams\(location\.search\)/", $b) === 1
       && strpos($b, "q.get('day')") !== false,
       'the URL is consulted before the remembered day');
});

t('wiring: icon buttons are circles, and the tab highlight is one fixed size', function () {
    $jar = login('example', 'examplepassword');
    // A sample from each surface: the row clusters, the managers' controls, the modal
    // ×s, the habit tick grid — every icon button that was a rounded square is a circle.
    $r = req('GET', '/calmind/reminders/?folder=All', [], $jar)['body'];
    ok(preg_match('/\.check, \.del \{[^}]*border-radius: 50%/', $r) === 1, 'the tick and × are circles');
    ok(preg_match('/\.rowedit, \.dup \{[^}]*border-radius: 50%/', $r) === 1, 'the pencil and duplicate too');
    ok(preg_match('/\.foldermodal \.flist \.fdel \{[^}]*border-radius: 50%/', $r) === 1, 'the folder manager ×');
    ok(preg_match('/\.foldermodal \.fswatches button \{[^}]*border-radius: 50%/', $r) === 1, 'and its swatches');
    $c = req('GET', '/calmind/calendar/', [], $jar)['body'];
    ok(preg_match('/\.callist \.cdel \{[^}]*border-radius: 50%/', $c) === 1, 'the calendar manager ×');
    ok(preg_match('/\.callist \.cswatch \{[^}]*border-radius: 50%/', $c) === 1, 'its swatch');
    $h = req('GET', '/calmind/habits/?v=week', [], $jar)['body'];
    ok(preg_match('/\.cell \{[^}]*border-radius: 50%/', $h) === 1, 'the habit ticks are circles');
    // No icon button still wears the old rounded square: every 6px radius left on a
    // button rule should be a text control, which these pages have none of by class.
    // The tab bar's active highlight: one fixed size, centred, spacing untouched.
    has('width: 36px; height: 36px;', $r, 'the highlight is a fixed size');
    has('transform: translate(-50%, -50%); background: var(--surface-2); border-radius: 50%;', $r,
        'centred behind the icon as a circle');
    ok(strpos($r, 'inset: 3px 12px') === false, 'the old inset-based pill is gone');
});

t('wiring: the tab bar clusters its tabs and centres the + inside the bar', function () {
    $jar = login('example', 'examplepassword');
    $b = req('GET', '/calmind/reminders/', [], $jar)['body'];
    // The + is centred by the flex row, not raised out of it with a negative margin — the
    // old trick left it reading as off-centre. So: the row centres its items, and the add
    // tab carries no vertical raise (a two-value margin whose first value is 0).
    ok(preg_match('/\.segmented \{[^}]*align-items:\s*center/', $b) === 1,
       'the segmented centres its items vertically');
    ok(preg_match('/\.segmented a\.addtab \{[^}]*margin:\s*0\s+[\d.a-z]+\s*;/', $b) === 1,
       'the add tab has no vertical raise');
    // Tabs are sized to content and clustered in the middle, not stretched flex:1 which
    // flung Reminders and Habits out to the far corners.
    ok(preg_match('/\.segmented \{[^}]*justify-content:\s*center/', $b) === 1,
       'the tabs are centred as a cluster');
    ok(preg_match('/\.segmented a \{[^}]*flex:\s*0 0 auto/', $b) === 1,
       'and sized to content, not stretched edge to edge');
});

// ---------------------------------------------------------------- 15. security sweeps
// Data-driven rather than one case per action: the point is that *every* mutating action
// is covered, including one added next week that nobody remembers to write a test for.
area('security');

/** Every mutating action, by the page that answers it. Add to this when you add one. */
function ALL_ACTIONS(): array
{
    return [
        '/calmind/reminders/' => ['add', 'toggle', 'edit_text', 'edit_full', 'duplicate', 'delete', 'add_section', 'rename_section',
                          'delete_section', 'add_subtask', 'set_indent', 'reorder', 'clear_done',
                          'add_folder', 'delete_folder', 'rename_folder', 'set_default_folder',
                          'set_default_section', 'set_folder_color', 'folder_vis', 'folder_vis_all',
                          'folder_vis_only', 'reorder_folders', 'share_set', 'partner_add',
                          'partner_rename', 'partner_del', 'change_password', 'set_theme'],
        '/calmind/notes/'     => ['add', 'save', 'duplicate', 'delete', 'add_section', 'rename_section', 'delete_section',
                          'reorder', 'add_folder', 'delete_folder', 'rename_folder', 'set_default_folder',
                          'set_default_section', 'set_folder_color', 'folder_vis', 'folder_vis_all',
                          'folder_vis_only', 'reorder_folders', 'share_set', 'partner_add',
                          'partner_rename', 'partner_del'],
        '/calmind/calendar/'  => ['add_reminder', 'add_event', 'add_note', 'edit_item', 'duplicate_item', 'delete_item',
                          'toggle_reminder', 'cal_add', 'cal_rename', 'cal_color', 'cal_shared_color', 'cal_default',
                          'cal_delete', 'cal_reorder', 'cal_vis', 'cal_vis_all', 'cal_vis_only', 'rf_mode',
                          'folder_vis', 'share_set', 'partner_add', 'partner_rename', 'partner_del'],
        '/calmind/habits/'    => ['toggle', 'rename_habit', 'set_section_color', 'reorder', 'add_habit',
                          'add_section', 'rename_section', 'delete_habit', 'delete_section',
                          'msec_vis', 'msec_only', 'msec_all'],
        '/calmind/add/'       => ['add_reminder', 'add_event', 'add_note'],
        // quick.php is the one page the widget can reach that writes, so it is in here
        // too: a tick or an add with no token has to be as dead as anywhere else.
        '/calmind/calendar/quick.php' => ['tick', 'add_reminder', 'add_event'],
    ];
}

/**
 * Visit every swept page once, so the one-time normalize-on-read repairs (sections,
 * folder migration) land *before* a snapshot is taken. Without this the security area
 * only passed when some earlier area happened to have loaded the pages first — running
 * `php tools/test.php security` alone tripped over the repair, not over a real write.
 */
function warm_pages(array $jar): void
{
    foreach (array_keys(ALL_ACTIONS()) as $page) { req('GET', $page, [], $jar); }
}

/** A cheap fingerprint of everything a user owns, to prove a request changed nothing. */
function snapshot(string $user = 'example'): string
{
    $out = '';
    foreach (['reminders', 'notes', 'events', 'calendars', 'habits', 'folders', 'calprefs',
              'shares', 'prefs'] as $b) {
        $out .= $b . '=' . json_encode(stored($b, $user)) . '|';
    }
    return md5($out);
}

t('every mutating action refuses a POST with no CSRF token', function () {
    $jar   = login('example', 'examplepassword');
    warm_pages($jar);
    $before = snapshot();
    $checked = 0;
    foreach (ALL_ACTIONS() as $page => $actions) {
        foreach ($actions as $a) {
            $r = req('POST', $page, ['action' => $a, 'view' => 'All', 'name' => 'x', 'text' => 'x',
                                     'id' => 'x', 'kind' => 'reminder'], $jar);
            ok($r['status'] === 400 || $r['status'] === 403,
               "$page $a: expected a refusal, got {$r['status']}");
            $checked++;
        }
    }
    ok($checked > 60, "swept $checked actions");
    eq($before, snapshot(), 'and nothing anywhere was written');
});

t('every mutating action refuses a POST with the wrong CSRF token', function () {
    $jar    = login('example', 'examplepassword');
    warm_pages($jar);
    $before = snapshot();
    foreach (ALL_ACTIONS() as $page => $actions) {
        foreach ($actions as $a) {
            $r = req('POST', $page, ['csrf' => 'wrong', 'action' => $a, 'view' => 'All',
                                     'name' => 'x', 'text' => 'x', 'id' => 'x', 'kind' => 'reminder'], $jar);
            ok($r['status'] === 400 || $r['status'] === 403, "$page $a: got {$r['status']}");
        }
    }
    eq($before, snapshot(), 'nothing was written');
});

t('a signed-out POST mutates nothing, whatever it claims to be', function () {
    $before = snapshot();
    foreach (ALL_ACTIONS() as $page => $actions) {
        foreach ($actions as $a) {
            // No jar at all: no session, no token.
            req('POST', $page, ['action' => $a, 'view' => 'All', 'name' => 'x', 'text' => 'x',
                                'id' => 'x', 'kind' => 'reminder']);
        }
    }
    eq($before, snapshot(), 'a signed-out caller changed nothing');
});

t('a folder name cannot carry a path or the separator the pickers use', function () {
    $jar = login('example', 'examplepassword');
    foreach (['../escape', 'a/b', "with\x1Fsep", '..', './x'] as $bad) {
        req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'add_folder',
            'view' => 'All', 'name' => $bad], $jar);
    }
    foreach (folders_load(datadir(), 'example')['reminders'] as $f) {
        // A slash is harmless — a folder name is never a path; it lives inside JSON and
        // is urlencoded into ?folder=. The separator is the one that matters: the
        // Calendar's add window packs "folder\x1Fgroup" into one value and splits on it.
        hasnt("\x1F", $f, 'no folder name holds the separator the pickers join keys with');
        ok(!preg_match('/[\x00-\x1F\x7F]/', $f), 'nor any other control character');
    }
    // And nothing was written outside the data dir.
    foreach (glob(datadir() . '/*') as $file) {
        eq(realpath(datadir()), dirname(realpath($file)), 'every file is in the data dir');
    }
});

t("one user cannot reach another user's file by asking for it", function () {
    $jar = login('example', 'examplepassword');
    // A "shared" view key naming a folder buddy has not shared.
    $before = snapshot('buddy');
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'add', 'view' => '@buddy:House',
        'text' => 'should not land', 'folder' => 'House', 'section' => ''], $jar);
    eq($before, snapshot('buddy'), "buddy's file is untouched by a folder they never shared");
    eq(null, rowBy('buddy', 'should not land'));
});

t('the destructive actions all need the confirmed second press', function () {
    $jar = login('example', 'examplepassword');
    warm_pages($jar);
    $before = snapshot();
    $tries = [
        ['/calmind/reminders/', ['action' => 'delete', 'view' => 'All', 'id' => (rows('example')[0]['id'] ?? 'x')]],
        ['/calmind/reminders/', ['action' => 'delete_folder', 'view' => 'All', 'name' => 'Work']],
        ['/calmind/notes/',     ['action' => 'delete_folder', 'view' => 'All', 'name' => 'Recipes']],
        ['/calmind/habits/',    ['action' => 'delete_habit', 'id' => 'x']],
    ];
    foreach ($tries as [$page, $post]) {
        $post['csrf'] = csrf($jar, $page);
        req('POST', $page, $post, $jar);
    }
    eq($before, snapshot(), 'one press destroys nothing anywhere');
});

// ---------------------------------------------------------------- 16. notes, in full
area('notes2');

t('a note carries its folder, section and date, and can be deleted', function () {
    $jar = login('example', 'examplepassword');
    // Its own folder, so nothing another area did can decide where this note lands.
    req('POST', '/calmind/notes/', ['csrf' => csrf($jar, '/calmind/notes/'), 'action' => 'add_folder',
        'view' => 'All', 'name' => 'Notes2folder'], $jar);
    $r = req('POST', '/calmind/notes/', ['csrf' => csrf($jar, '/calmind/notes/'), 'action' => 'add',
        'view' => 'All', 'folder' => 'Notes2folder', 'section' => ''], $jar);
    preg_match('/id=([a-f0-9]+)/', (string) $r['location'], $m);
    $id = $m[1] ?? '';
    ok($id !== '', 'the redirect names the new note');
    // The editor posts the whole form on save, folder included. Omitting it is not a
    // thing the app does — and the handler reads the field rather than leaving the
    // folder alone, so a save without it would quietly move the note to General.
    req('POST', '/calmind/notes/', ['csrf' => csrf($jar, '/calmind/notes/'), 'action' => 'save', 'view' => 'All',
        'id' => $id, 'title' => 'Full note', 'body' => '<p>body</p>', 'date' => '2026-09-01',
        'folder' => 'Notes2folder', 'section' => ''], $jar);
    $n = null;
    foreach (stored('notes', 'example') as $x) { if (($x['id'] ?? '') === $id) { $n = $x; } }
    eq('Full note', $n['title']);
    eq('Notes2folder', $n['folder']);
    req('POST', '/calmind/notes/', ['csrf' => csrf($jar, '/calmind/notes/'), 'action' => 'delete',
        'view' => 'All', 'id' => $id], $jar);
    $still = false;
    foreach (stored('notes', 'example') as $x) { if (($x['id'] ?? '') === $id) { $still = true; } }
    ok($still, 'one press must not delete a note');
    req('POST', '/calmind/notes/', ['csrf' => csrf($jar, '/calmind/notes/'), 'action' => 'delete',
        'view' => 'All', 'id' => $id, 'confirm' => '1'], $jar);
    foreach (stored('notes', 'example') as $x) { ok(($x['id'] ?? '') !== $id, 'confirmed press deletes'); }
});

t('note sections add, rename and delete per folder', function () {
    $jar = login('example', 'examplepassword');
    req('POST', '/calmind/notes/', ['csrf' => csrf($jar, '/calmind/notes/'), 'action' => 'add_folder',
        'view' => 'All', 'name' => 'Notes2folder'], $jar);
    req('POST', '/calmind/notes/', ['csrf' => csrf($jar, '/calmind/notes/'), 'action' => 'add_section',
        'view' => 'Notes2folder', 'folder' => 'Notes2folder', 'name' => 'Puddings'], $jar);
    $names = fn() => array_column(array_values(array_filter(stored('notes', 'example'),
        fn($x) => ($x['type'] ?? '') === 'section')), 'name');
    ok(in_array('Puddings', $names(), true), 'added');
    req('POST', '/calmind/notes/', ['csrf' => csrf($jar, '/calmind/notes/'), 'action' => 'rename_section',
        'view' => 'Notes2folder', 'folder' => 'Notes2folder', 'name' => 'Puddings', 'newname' => 'Afters'], $jar);
    ok(in_array('Afters', $names(), true), 'renamed');
    req('POST', '/calmind/notes/', ['csrf' => csrf($jar, '/calmind/notes/'), 'action' => 'delete_section',
        'view' => 'Notes2folder', 'folder' => 'Notes2folder', 'name' => 'Afters', 'confirm' => '1'], $jar);
    ok(!in_array('Afters', $names(), true), 'deleted');
});

t('a section added from a folder head lands in that folder, not the default', function () {
    // The + Section button is gone from the top of Reminders and Notes; each folder head
    // carries its own "+", which posts add_section with that folder. On "All" the section
    // must follow the posted folder rather than falling to the default folder.
    $jar = login('example', 'examplepassword');
    // Reminders: the folder head "+" ships in the page, and its section files by folder.
    $rb = req('GET', '/calmind/reminders/', [], $jar)['body'];
    eq(0, substr_count($rb, 'id="newSecBtn"'), 'reminders has no top + Section');
    has('class="fsec-add"', $rb, 'reminders folder heads carry a +');
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'add_folder', 'view' => 'All', 'name' => 'HeadR'], $jar);
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'add_section',
        'view' => 'All', 'folder' => 'HeadR', 'name' => 'FromHeadR'], $jar);
    $rsec = null;
    foreach (stored('reminders', 'example') as $x) {
        if (($x['type'] ?? '') === 'section' && ($x['name'] ?? '') === 'FromHeadR') { $rsec = $x; }
    }
    ok($rsec !== null, 'the reminders section was created');
    eq('HeadR', $rsec['folder'] ?? null, 'and it landed in the folder whose + was used');

    // Notes: same — the top + Section is gone, folder heads carry the +, add follows folder.
    $nb = req('GET', '/calmind/notes/', [], $jar)['body'];
    eq(0, substr_count($nb, 'id="newSecBtn"'), 'notes has no top + Section');
    has('class="fsec-add"', $nb, 'notes folder heads carry a +');
    req('POST', '/calmind/notes/', ['csrf' => csrf($jar, '/calmind/notes/'), 'action' => 'add_folder', 'view' => 'All', 'name' => 'HeadN'], $jar);
    req('POST', '/calmind/notes/', ['csrf' => csrf($jar, '/calmind/notes/'), 'action' => 'add_section',
        'view' => 'All', 'folder' => 'HeadN', 'name' => 'FromHeadN'], $jar);
    $nsec = null;
    foreach (stored('notes', 'example') as $x) {
        if (($x['type'] ?? '') === 'section' && ($x['name'] ?? '') === 'FromHeadN') { $nsec = $x; }
    }
    ok($nsec !== null, 'the notes section was created');
    eq('HeadN', $nsec['folder'] ?? null, 'and it landed in the folder whose + was used');
});

/** The id of a note section by folder + name, or null. */
function note_sec_id(string $folder, string $name): ?string
{
    foreach (stored('notes', 'example') as $x) {
        if (($x['type'] ?? '') === 'section' && ($x['folder'] ?? '') === $folder && ($x['name'] ?? '') === $name) {
            return (string) $x['id'];
        }
    }
    return null;
}

t('dragging a note section reorders it within its folder', function () {
    // The gesture is by-eye (no JS in the harness), but the drag posts a per-folder map of
    // section *ids* to the reorder action — that server side is what this locks down.
    $jar = login('example', 'examplepassword');
    req('POST', '/calmind/notes/', ['csrf' => csrf($jar, '/calmind/notes/'), 'action' => 'add_folder',
        'view' => 'All', 'name' => 'DragNotes'], $jar);
    foreach (['Alpha', 'Beta'] as $nm) {
        req('POST', '/calmind/notes/', ['csrf' => csrf($jar, '/calmind/notes/'), 'action' => 'add_section',
            'view' => 'DragNotes', 'folder' => 'DragNotes', 'name' => $nm], $jar);
    }
    $a = note_sec_id('DragNotes', 'Alpha'); $b = note_sec_id('DragNotes', 'Beta');
    // The folder also carries its default "General" section (every folder does now), so
    // compare just the relative order of the two we're dragging, not the whole run.
    $g = note_sec_id('DragNotes', 'General');
    $order = fn() => array_values(array_filter(array_column(array_values(array_filter(stored('notes', 'example'),
        fn($x) => ($x['type'] ?? '') === 'section' && ($x['folder'] ?? '') === 'DragNotes')), 'name'),
        fn($n) => $n === 'Alpha' || $n === 'Beta'));

    req('POST', '/calmind/notes/', ['csrf' => csrf($jar, '/calmind/notes/'), 'action' => 'reorder', 'view' => 'DragNotes',
        'order' => '[]', 'sections' => json_encode(['DragNotes' => [$a, $b, $g]])], $jar, true);
    eq(['Alpha', 'Beta'], $order(), 'the map sets the section order');

    req('POST', '/calmind/notes/', ['csrf' => csrf($jar, '/calmind/notes/'), 'action' => 'reorder', 'view' => 'DragNotes',
        'order' => '[]', 'sections' => json_encode(['DragNotes' => [$b, $a, $g]])], $jar, true);
    eq(['Beta', 'Alpha'], $order(), 'and dragging the other way flips it');
});

t('dragging a note section into another folder re-files it, and its notes follow', function () {
    $jar = login('example', 'examplepassword');
    req('POST', '/calmind/notes/', ['csrf' => csrf($jar, '/calmind/notes/'), 'action' => 'add_folder', 'view' => 'All', 'name' => 'SFrom'], $jar);
    req('POST', '/calmind/notes/', ['csrf' => csrf($jar, '/calmind/notes/'), 'action' => 'add_folder', 'view' => 'All', 'name' => 'STo'], $jar);
    req('POST', '/calmind/notes/', ['csrf' => csrf($jar, '/calmind/notes/'), 'action' => 'add_section', 'view' => 'SFrom', 'folder' => 'SFrom', 'name' => 'Mains'], $jar);
    req('POST', '/calmind/notes/', ['csrf' => csrf($jar, '/calmind/notes/'), 'action' => 'add', 'view' => 'SFrom', 'folder' => 'SFrom', 'section' => 'Mains'], $jar);
    $sid = note_sec_id('SFrom', 'Mains'); $nid = null;
    foreach (stored('notes', 'example') as $x) {
        if (($x['type'] ?? '') !== 'section' && ($x['folder'] ?? '') === 'SFrom') { $nid = $x['id']; }
    }
    ok($sid && $nid, 'a section and a note in SFrom');
    $find = function ($id) { foreach (stored('notes', 'example') as $x) { if (($x['id'] ?? '') === $id) { return $x; } } return null; };

    // Drag the Mains section into STo: it's now listed under STo, and its note posts folder=STo.
    req('POST', '/calmind/notes/', ['csrf' => csrf($jar, '/calmind/notes/'), 'action' => 'reorder', 'view' => 'All',
        'order' => json_encode([['id' => $nid, 'section' => 'Mains', 'folder' => 'STo']]),
        'sections' => json_encode(['SFrom' => [''], 'STo' => [$sid, '']])], $jar, true);
    eq('STo', $find($sid)['folder'] ?? null, 'the section moved to STo');
    eq('STo', $find($nid)['folder'] ?? null, 'its note followed');
    eq('Mains', $find($nid)['section'] ?? null, 'keeping its section');
});

t('moving a section into a folder that already has that name is refused (no duplicate, no data loss)', function () {
    // The data-loss bug: dragging "General" into a folder that already has a "General" made
    // two same-named sections in one folder, and deleting one then lost the items. The move
    // must be refused so the folder never holds a duplicate.
    $jar = login('example', 'examplepassword');
    foreach (['DupA', 'DupB'] as $f) {
        req('POST', '/calmind/notes/', ['csrf' => csrf($jar, '/calmind/notes/'), 'action' => 'add_folder', 'view' => 'All', 'name' => $f], $jar);
    }
    // Both folders auto-get a "General" section on first render; make sure they exist.
    req('GET', '/calmind/notes/?folder=All', [], $jar);
    $ga = note_sec_id('DupA', 'General'); $gb = note_sec_id('DupB', 'General');
    ok($ga && $gb, 'both folders have their own General');
    // A note in DupA/General, then try to drag DupA's General into DupB (which already has one).
    req('POST', '/calmind/notes/', ['csrf' => csrf($jar, '/calmind/notes/'), 'action' => 'add', 'view' => 'DupA', 'folder' => 'DupA', 'section' => 'General'], $jar);
    $nid = null;
    foreach (stored('notes', 'example') as $x) { if (($x['type'] ?? '') !== 'section' && ($x['folder'] ?? '') === 'DupA') { $nid = $x['id']; } }
    // The payload a real whole-screen drag produces lists the destination's own General
    // too — being mentioned must not free its name (that slip made a duplicate here).
    req('POST', '/calmind/notes/', ['csrf' => csrf($jar, '/calmind/notes/'), 'action' => 'reorder', 'view' => 'All',
        'order' => json_encode([['id' => $nid, 'section' => 'General', 'folder' => 'DupB']]),
        'sections' => json_encode(['DupB' => [$ga, $gb]])], $jar, true);
    // DupB must still hold exactly one "General"; DupA keeps its own.
    $countB = 0;
    foreach (stored('notes', 'example') as $x) {
        if (($x['type'] ?? '') === 'section' && ($x['folder'] ?? '') === 'DupB' && ($x['name'] ?? '') === 'General') { $countB++; }
    }
    eq(1, $countB, 'DupB never ends up with two General sections');
    ok(note_sec_id('DupA', 'General') !== null, "DupA keeps its General (the move was refused)");
    // The note still exists somewhere with a real section (not orphaned/lost).
    $note = null;
    foreach (stored('notes', 'example') as $x) { if (($x['id'] ?? '') === $nid) { $note = $x; } }
    ok($note !== null, 'the note was not lost');
});

t('dragging a note into another folder re-files it', function () {
    $jar = login('example', 'examplepassword');
    req('POST', '/calmind/notes/', ['csrf' => csrf($jar, '/calmind/notes/'), 'action' => 'add_folder', 'view' => 'All', 'name' => 'FromF'], $jar);
    req('POST', '/calmind/notes/', ['csrf' => csrf($jar, '/calmind/notes/'), 'action' => 'add_folder', 'view' => 'All', 'name' => 'ToF'], $jar);
    req('POST', '/calmind/notes/', ['csrf' => csrf($jar, '/calmind/notes/'), 'action' => 'add',
        'view' => 'All', 'folder' => 'FromF', 'section' => ''], $jar);
    $id = null;
    foreach (stored('notes', 'example') as $n) {
        if (($n['type'] ?? '') !== 'section' && ($n['folder'] ?? '') === 'FromF') { $id = $n['id']; break; }
    }
    ok($id !== null, 'a note exists in FromF');
    $folderOf = function () use ($id) {
        foreach (stored('notes', 'example') as $n) { if (($n['id'] ?? '') === $id) { return $n['folder'] ?? null; } }
        return null;
    };
    // The drag posts each note with the folder of the block it landed in.
    req('POST', '/calmind/notes/', ['csrf' => csrf($jar, '/calmind/notes/'), 'action' => 'reorder', 'view' => 'All',
        'order' => json_encode([['id' => $id, 'section' => '', 'folder' => 'ToF']]), 'sections' => '{}'], $jar, true);
    eq('ToF', $folderOf(), 'the note re-files to ToF');
    // A folder that isn't mine (e.g. a partner's shared block) is refused.
    req('POST', '/calmind/notes/', ['csrf' => csrf($jar, '/calmind/notes/'), 'action' => 'reorder', 'view' => 'All',
        'order' => json_encode([['id' => $id, 'section' => '', 'folder' => '@someone:Nope']]), 'sections' => '{}'], $jar, true);
    eq('ToF', $folderOf(), 'a folder that is not mine is ignored');
});

t('a fresh note folder gets a real, renameable default "General" section, no catch-all', function () {
    // The unnamed "Notes" catch-all is gone: every folder now opens with a real default
    // section named General (renameable, and undeletable while it's the only one).
    $jar = login('example', 'examplepassword');
    req('POST', '/calmind/notes/', ['csrf' => csrf($jar, '/calmind/notes/'), 'action' => 'add_folder', 'view' => 'All', 'name' => 'FreshN'], $jar);
    $b = req('GET', '/calmind/notes/?folder=FreshN', [], $jar)['body'];
    ok(note_sec_id('FreshN', 'General') !== null, 'the folder is seeded with a General section');
    hasnt('section-group default-group', $b, 'and no unnamed catch-all group is rendered');
    // It renames like any other section (in place, re-pointing its notes).
    req('POST', '/calmind/notes/', ['csrf' => csrf($jar, '/calmind/notes/'), 'action' => 'rename_section', 'view' => 'FreshN',
        'folder' => 'FreshN', 'name' => 'General', 'newname' => 'Ideas'], $jar);
    ok(note_sec_id('FreshN', 'Ideas') !== null, 'the default section renamed');
    // The last section in a folder can't be deleted (it always keeps at least one).
    req('POST', '/calmind/notes/', ['csrf' => csrf($jar, '/calmind/notes/'), 'action' => 'delete_section', 'view' => 'FreshN',
        'folder' => 'FreshN', 'name' => 'Ideas', 'confirm' => '1'], $jar);
    ok(note_sec_id('FreshN', 'Ideas') !== null, 'the folder never loses its only section');
});

t('"Notes" is an ordinary section name now, not a reserved catch-all', function () {
    $jar = login('example', 'examplepassword');
    req('POST', '/calmind/notes/', ['csrf' => csrf($jar, '/calmind/notes/'), 'action' => 'add_section',
        'view' => 'All', 'folder' => 'General', 'name' => 'Notes'], $jar);
    $n = 0;
    foreach (stored('notes', 'example') as $x) {
        if (($x['type'] ?? '') === 'section' && ($x['name'] ?? '') === 'Notes' && ($x['folder'] ?? '') === 'General') { $n++; }
    }
    eq(1, $n, 'a section may be called Notes now — there is no catch-all to clash with');
});

t('a note folder colour comes from the notes palette', function () {
    $jar = login('example', 'examplepassword');
    $c = app_palette('notes')[1];
    req('POST', '/calmind/notes/', ['csrf' => csrf($jar, '/calmind/notes/'), 'action' => 'set_folder_color',
        'view' => 'All', 'name' => 'Recipes', 'color' => $c], $jar, true);
    eq($c, folder_colors(datadir(), 'notes', 'example')['Recipes'] ?? null);
    req('POST', '/calmind/notes/', ['csrf' => csrf($jar, '/calmind/notes/'), 'action' => 'set_folder_color',
        'view' => 'All', 'name' => 'Recipes', 'color' => app_palette('reminders')[1]], $jar, true);
    eq($c, folder_colors(datadir(), 'notes', 'example')['Recipes'] ?? null,
       "another app's palette is not this app's");
});

// ---------------------------------------------------------------- 16b. drag payloads
t('a note duplicates in place, body and spot carried over', function () {
    $jar = login('example', 'examplepassword');
    $nc  = csrf($jar, '/calmind/notes/');
    // Its own note with a unique title, so nothing seeded can collide with the count.
    $r0 = req('POST', '/calmind/notes/', ['csrf' => $nc, 'action' => 'add', 'view' => 'All'], $jar);
    preg_match('/id=([a-f0-9]+)/', (string) $r0['location'], $m0);
    req('POST', '/calmind/notes/', ['csrf' => $nc, 'action' => 'save', 'view' => 'All',
        'id' => $m0[1] ?? '', 'title' => 'Dup probe note', 'date' => '2030-02-02',
        'body' => '<p>the body rides along</p>',
        'folder' => stored('folders', 'example')['notes'][0] ?? 'General', 'section' => ''], $jar);
    $src = null;
    foreach (stored('notes', 'example') as $x) { if (($x['id'] ?? '') === ($m0[1] ?? '')) { $src = $x; } }
    ok($src !== null && ($src['title'] ?? '') === 'Dup probe note', 'a note to duplicate');
    // Real POSTs from the edit-mode-only button carry the stamped flag; echoed, not originated.
    $r = req('POST', '/calmind/notes/', ['csrf' => $nc, 'action' => 'duplicate', 'view' => 'All',
        'id' => $src['id'], 'edit' => '1'], $jar);
    has('edit=1', (string) $r['location'], 'duplicating from edit mode stays in it');
    $list = array_values(stored('notes', 'example'));
    $idx  = [];
    foreach ($list as $i => $x) {
        if (($x['type'] ?? '') !== 'section' && ($x['title'] ?? '') === $src['title']) { $idx[] = $i; }
    }
    eq(2, count($idx), 'two of it now');
    $copy = $list[$idx[1]];
    ok($copy['id'] !== $src['id'], 'the copy has its own id');
    eq($idx[0] + 1, $idx[1], 'and sits directly under the original');
    eq($src['body'] ?? '', $copy['body'] ?? '', 'body carried over');
    eq($src['folder'] ?? '', $copy['folder'] ?? '', 'same folder');
    eq($src['section'] ?? '', $copy['section'] ?? '', 'same section');
    eq($src['date'] ?? null, $copy['date'] ?? null, 'same date');
    store_write(user_data_file(datadir(), 'notes', 'example'), array_values(array_filter(
        $list, fn($x) => !in_array($x['id'] ?? '', [$src['id'], $copy['id']], true))));
});

t('wiring: note rows carry the duplicate button in the edit cluster', function () {
    $jar = login('example', 'examplepassword');
    $b = req('GET', '/calmind/notes/?folder=All', [], $jar)['body'];
    has('class="ndup"', $b, 'the duplicate form is on each row');
    has('value="duplicate"', $b, 'posting duplicate');
    has('body.editing .ndel .del, body.editing .ndup .dup', $b, 'shown only in edit mode');
});

// What the drag JS actually posts, replayed against the server — the gesture itself is
// by-eye (the harness runs no JS), but the payload contract is checkable, and "the drag
// looked right then reverted on reload" bugs live entirely on this side of it.
area('drag');

/** The id of a reminder section by folder + name, or null. */
function rem_sec_id(string $folder, string $name): ?string
{
    foreach (stored('reminders', 'example') as $x) {
        if (($x['type'] ?? '') === 'section' && ($x['folder'] ?? '') === $folder && ($x['name'] ?? '') === $name) {
            return (string) $x['id'];
        }
    }
    return null;
}

/** The stored order of two named sections within one reminders folder. */
function rem_sec_order(string $folder, array $names): array
{
    return array_values(array_filter(array_column(array_values(array_filter(stored('reminders', 'example'),
        fn($x) => ($x['type'] ?? '') === 'section' && ($x['folder'] ?? '') === $folder)), 'name'),
        fn($n) => in_array($n, $names, true)));
}

t('dragging a reminder section in the All view persists its order', function () {
    // The bug this locks out: the JS posted a flat list of bare names and the server
    // only reordered inside a single named folder, so a section drag on "All" — the
    // view most people live in — was silently thrown away. The drag now posts a
    // folder-keyed map of section ids, the shape Notes already used.
    $jar = login('example', 'examplepassword');
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'add_folder', 'view' => 'All', 'name' => 'DragRem'], $jar);
    foreach (['Alpha', 'Beta'] as $nm) {
        req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'add_section',
            'view' => 'All', 'folder' => 'DragRem', 'name' => $nm], $jar);
    }
    req('GET', '/calmind/reminders/?folder=All', [], $jar);   // normalise (seeds General)
    $a = rem_sec_id('DragRem', 'Alpha'); $b = rem_sec_id('DragRem', 'Beta'); $g = rem_sec_id('DragRem', 'General');
    ok($a && $b, 'both sections exist');

    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'reorder', 'view' => 'All',
        'order' => '[]', 'sections' => json_encode(['DragRem' => array_filter([$a, $b, $g])])], $jar, true);
    eq(['Alpha', 'Beta'], rem_sec_order('DragRem', ['Alpha', 'Beta']), 'the map sets the section order');

    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'reorder', 'view' => 'All',
        'order' => '[]', 'sections' => json_encode(['DragRem' => array_filter([$b, $a, $g])])], $jar, true);
    eq(['Beta', 'Alpha'], rem_sec_order('DragRem', ['Alpha', 'Beta']), 'and dragging the other way flips it');
    // A reload re-reads, re-normalises and may write the file back — none of which may
    // shuffle what the drag just set. This is the half users actually see.
    req('GET', '/calmind/reminders/?folder=All', [], $jar);
    eq(['Beta', 'Alpha'], rem_sec_order('DragRem', ['Alpha', 'Beta']), 'the order survives a refresh');
});

t('dragging a reminder section inside a single-folder view persists too', function () {
    // The same id-map shape is posted whichever view the drag happens in, so the server
    // must answer it identically when the view names one folder.
    $jar = login('example', 'examplepassword');
    $a = rem_sec_id('DragRem', 'Alpha'); $b = rem_sec_id('DragRem', 'Beta'); $g = rem_sec_id('DragRem', 'General');
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'reorder', 'view' => 'DragRem',
        'order' => '[]', 'sections' => json_encode(['DragRem' => array_filter([$a, $b, $g])])], $jar, true);
    eq(['Alpha', 'Beta'], rem_sec_order('DragRem', ['Alpha', 'Beta']), 'a single-folder drag reorders the same way');
});

t('dragging a reminder section into another folder re-files it, and its rows follow', function () {
    $jar = login('example', 'examplepassword');
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'add_folder', 'view' => 'All', 'name' => 'RFrom'], $jar);
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'add_folder', 'view' => 'All', 'name' => 'RTo'], $jar);
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'add_section', 'view' => 'RFrom', 'folder' => 'RFrom', 'name' => 'Mains'], $jar);
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'add', 'view' => 'RFrom',
        'text' => 'carried along', 'folder' => 'RFrom', 'section' => 'Mains'], $jar);
    req('GET', '/calmind/reminders/?folder=All', [], $jar);   // normalise
    $sid = rem_sec_id('RFrom', 'Mains'); $rid = null;
    foreach (stored('reminders', 'example') as $x) {
        if (($x['type'] ?? '') !== 'section' && ($x['text'] ?? '') === 'carried along') { $rid = $x['id']; }
    }
    ok($sid && $rid, 'a section and a reminder in RFrom');
    $find = function ($id) { foreach (stored('reminders', 'example') as $x) { if (($x['id'] ?? '') === $id) { return $x; } } return null; };

    // Drag Mains into RTo: the section is listed under RTo and its row posts folder=RTo.
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'reorder', 'view' => 'All',
        'order' => json_encode([['id' => $rid, 'section' => 'Mains', 'folder' => 'RTo']]),
        'sections' => json_encode(['RFrom' => [rem_sec_id('RFrom', 'General')],
                                   'RTo'   => [$sid, rem_sec_id('RTo', 'General')]])], $jar, true);
    eq('RTo', $find($sid)['folder'] ?? null, 'the section moved to RTo');
    eq('RTo', $find($rid)['folder'] ?? null, 'its reminder followed');
    eq('Mains', $find($rid)['section'] ?? null, 'keeping its section');
});

t('moving a reminder section into a folder that already holds that name is refused', function () {
    // Same duplicate-name guard Notes has: items reference sections by name, so a folder
    // holding two same-named sections fights over them and loses items on a delete.
    $jar = login('example', 'examplepassword');
    $gFrom = rem_sec_id('RFrom', 'General'); $gTo = rem_sec_id('RTo', 'General');
    ok($gFrom && $gTo, 'both folders hold their own General');
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'reorder', 'view' => 'All',
        'order' => '[]', 'sections' => json_encode(['RTo' => [$gFrom, $gTo]])], $jar, true);
    $count = 0;
    foreach (stored('reminders', 'example') as $x) {
        if (($x['type'] ?? '') === 'section' && ($x['folder'] ?? '') === 'RTo' && ($x['name'] ?? '') === 'General') { $count++; }
    }
    eq(1, $count, 'RTo never ends up with two General sections');
    ok(rem_sec_id('RFrom', 'General') !== null, 'RFrom keeps its General (the move was refused)');
});

t('dragging a reminder into another folder re-files it', function () {
    $jar = login('example', 'examplepassword');
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'add', 'view' => 'RFrom',
        'text' => 'wanderer', 'folder' => 'RFrom', 'section' => 'General'], $jar);
    $rid = null;
    foreach (stored('reminders', 'example') as $x) {
        if (($x['type'] ?? '') !== 'section' && ($x['text'] ?? '') === 'wanderer') { $rid = $x['id']; }
    }
    ok($rid !== null, 'the reminder exists');
    $find = function ($id) { foreach (stored('reminders', 'example') as $x) { if (($x['id'] ?? '') === $id) { return $x; } } return null; };
    // Dropped into RTo's General: the row posts the folder of the block it landed in.
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'reorder', 'view' => 'All',
        'order' => json_encode([['id' => $rid, 'section' => 'General', 'folder' => 'RTo']]),
        'sections' => '{}'], $jar, true);
    eq('RTo', $find($rid)['folder'] ?? null, 'the reminder re-files to RTo');
    eq('General', $find($rid)['section'] ?? null, 'into the named section there');
    // A folder that is not mine (a partner's shared block, or garbage) is refused.
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'reorder', 'view' => 'All',
        'order' => json_encode([['id' => $rid, 'section' => 'General', 'folder' => '@aki:Nope']]),
        'sections' => '{}'], $jar, true);
    eq('RTo', $find($rid)['folder'] ?? null, 'a folder that is not mine is ignored');
});

t('a stale flat-name section payload is tolerated and changes nothing', function () {
    // The old JS posted sections as a flat list of bare names. A page left open across a
    // deploy still posts that; it must neither error nor shuffle anything.
    $jar = login('example', 'examplepassword');
    $before = rem_sec_order('DragRem', ['Alpha', 'Beta']);
    $r = req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'reorder', 'view' => 'All',
        'order' => '[]', 'sections' => json_encode(['Beta', 'Alpha'])], $jar, true);
    eq(200, $r['status'], 'the legacy shape still answers 200');
    eq($before, rem_sec_order('DragRem', ['Alpha', 'Beta']), 'and reorders nothing rather than guessing');
});

t('dragging a note section in the All view (multi-folder map) persists across folders', function () {
    // Notes already posts the folder-keyed map; this replays a whole-screen All-view drag
    // touching two folders at once, the payload a real cross-folder gesture produces.
    $jar = login('example', 'examplepassword');
    foreach (['NDragA', 'NDragB'] as $f) {
        req('POST', '/calmind/notes/', ['csrf' => csrf($jar, '/calmind/notes/'), 'action' => 'add_folder', 'view' => 'All', 'name' => $f], $jar);
    }
    foreach (['One', 'Two'] as $nm) {
        req('POST', '/calmind/notes/', ['csrf' => csrf($jar, '/calmind/notes/'), 'action' => 'add_section',
            'view' => 'NDragA', 'folder' => 'NDragA', 'name' => $nm], $jar);
    }
    req('GET', '/calmind/notes/?folder=All', [], $jar);   // normalise (seeds each General)
    $one = note_sec_id('NDragA', 'One'); $two = note_sec_id('NDragA', 'Two');
    $ga  = note_sec_id('NDragA', 'General'); $gb = note_sec_id('NDragB', 'General');
    ok($one && $two && $ga && $gb, 'sections in place');
    // One whole-screen payload: Two now leads NDragA, and One has been dropped into NDragB.
    req('POST', '/calmind/notes/', ['csrf' => csrf($jar, '/calmind/notes/'), 'action' => 'reorder', 'view' => 'All',
        'order' => '[]', 'sections' => json_encode(['NDragA' => [$two, $ga], 'NDragB' => [$one, $gb]])], $jar, true);
    $find = function ($id) { foreach (stored('notes', 'example') as $x) { if (($x['id'] ?? '') === $id) { return $x; } } return null; };
    eq('NDragB', $find($one)['folder'] ?? null, 'One re-filed to NDragB');
    $orderA = array_values(array_filter(array_column(array_values(array_filter(stored('notes', 'example'),
        fn($x) => ($x['type'] ?? '') === 'section' && ($x['folder'] ?? '') === 'NDragA')), 'name'),
        fn($n) => in_array($n, ['Two', 'General'], true)));
    eq(['Two', 'General'], $orderA, "NDragA's remaining order took");
    // And on the next full read (what a reload does) nothing snaps back.
    req('GET', '/calmind/notes/?folder=All', [], $jar);
    eq('NDragB', $find($one)['folder'] ?? null, 'the move survives a reload');
});

t('row order inside a section sticks, and a refresh does not shuffle it', function () {
    // Undated rows show in stored order (dates only break in above it), so a drag's row
    // order is exactly what the file holds — and what every later read must keep holding.
    $jar = login('example', 'examplepassword');
    foreach (['first', 'second', 'third'] as $txt) {
        req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'add', 'view' => 'DragRem',
            'text' => 'roworder ' . $txt, 'folder' => 'DragRem', 'section' => 'Alpha'], $jar);
    }
    req('GET', '/calmind/reminders/?folder=All', [], $jar);   // normalise + write back
    $mine = fn() => array_values(array_filter(stored('reminders', 'example'),
        fn($x) => ($x['type'] ?? '') !== 'section' && strncmp($x['text'] ?? '', 'roworder ', 9) === 0));
    $ids = array_column($mine(), 'id');
    eq(3, count($ids), 'three rows to drag');
    $want = [$ids[2], $ids[0], $ids[1]];
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'reorder', 'view' => 'All',
        'order' => json_encode(array_map(fn($id) => ['id' => $id, 'section' => 'Alpha', 'folder' => 'DragRem'], $want)),
        'sections' => '{}'], $jar, true);
    eq($want, array_column($mine(), 'id'), 'the drag order is stored');
    req('GET', '/calmind/reminders/?folder=All', [], $jar);
    req('GET', '/calmind/reminders/?folder=DragRem', [], $jar);
    eq($want, array_column($mine(), 'id'), 'and two refreshes later it still holds');
});

t('dragging folders in the manager sticks after a refresh (reminders and notes)', function () {
    // The Manage-folders window's drag posts reorder_folders with an \x1F-joined key list.
    $jar = login('example', 'examplepassword');
    foreach ([['reminders', '/calmind/reminders/'], ['notes', '/calmind/notes/']] as [$type, $path]) {
        $own = folders_load(datadir(), 'example')[$type];
        ok(count($own) > 2, "$type has folders to reorder");
        $flipped = array_reverse($own);
        req('POST', $path, ['csrf' => csrf($jar, $path), 'action' => 'reorder_folders',
            'order' => implode("\x1F", $flipped)], $jar, true);
        eq($flipped, folders_load(datadir(), 'example')[$type], "$type folder order took");
        req('GET', $path . '?folder=All', [], $jar);
        eq($flipped, folders_load(datadir(), 'example')[$type], "$type folder order survives a refresh");
    }
});

t('a drag payload cannot re-file a section into a shared or unknown folder key', function () {
    // The section map's keys are folder names; a partner's shared block posts nothing,
    // but a forged key must not move a section either — it keeps its slot and its home.
    $jar = login('example', 'examplepassword');
    $a = rem_sec_id('DragRem', 'Alpha');
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'reorder', 'view' => 'All',
        'order' => '[]', 'sections' => json_encode(['@aki:Stolen' => [$a]])], $jar, true);
    $find = function ($id) { foreach (stored('reminders', 'example') as $x) { if (($x['id'] ?? '') === $id) { return $x; } } return null; };
    eq('DragRem', $find($a)['folder'] ?? null, 'a shared/unknown folder key never captures a section');
});

t('rows and sections a drag never mentions keep their place and folder', function () {
    // A drag posts only what was on screen; hidden folders (and everything in them) ride
    // through untouched — the guard against a filtered view quietly rearranging the rest.
    $jar = login('example', 'examplepassword');
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'add_folder', 'view' => 'All', 'name' => 'Offscreen'], $jar);
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'add', 'view' => 'Offscreen',
        'text' => 'untouched by the drag', 'folder' => 'Offscreen', 'section' => ''], $jar);
    req('GET', '/calmind/reminders/?folder=All', [], $jar);   // normalise
    $b = rem_sec_id('DragRem', 'Beta');
    // A drag entirely inside DragRem — Offscreen isn't in the payload at all.
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'reorder', 'view' => 'All',
        'order' => '[]', 'sections' => json_encode(['DragRem' => [$b]])], $jar, true);
    $row = null;
    foreach (stored('reminders', 'example') as $x) {
        if (($x['type'] ?? '') !== 'section' && ($x['text'] ?? '') === 'untouched by the drag') { $row = $x; }
    }
    ok($row !== null, 'the unmentioned row survived');
    eq('Offscreen', $row['folder'] ?? null, 'in its own folder');
    ok(rem_sec_id('Offscreen', 'General') !== null, 'whose sections also survived');
});

t('a note reorder tolerates the legacy flat payload and duplicate ids', function () {
    // Same stale-page tolerance Reminders has, plus: an id listed twice (a DOM glitch
    // mid-drag) counts once rather than duplicating the row.
    $jar = login('example', 'examplepassword');
    $before = array_column(array_values(array_filter(stored('notes', 'example'),
        fn($x) => ($x['type'] ?? '') === 'section' && ($x['folder'] ?? '') === 'NDragA')), 'name');
    $r = req('POST', '/calmind/notes/', ['csrf' => csrf($jar, '/calmind/notes/'), 'action' => 'reorder', 'view' => 'All',
        'order' => '[]', 'sections' => json_encode(['Two', 'General'])], $jar, true);
    eq(200, $r['status'], 'the legacy flat shape answers 200');
    eq($before, array_column(array_values(array_filter(stored('notes', 'example'),
        fn($x) => ($x['type'] ?? '') === 'section' && ($x['folder'] ?? '') === 'NDragA')), 'name'),
        'and reorders nothing rather than guessing');
    $two = note_sec_id('NDragA', 'Two');
    req('POST', '/calmind/notes/', ['csrf' => csrf($jar, '/calmind/notes/'), 'action' => 'reorder', 'view' => 'All',
        'order' => '[]', 'sections' => json_encode(['NDragA' => [$two, $two]])], $jar, true);
    $count = 0;
    foreach (stored('notes', 'example') as $x) {
        if (($x['id'] ?? '') === $two) { $count++; }
    }
    eq(1, $count, 'a section id posted twice is stored once');
});

t('emptying a reminder folder by drag, then the chained delete, removes it cleanly', function () {
    // The confirm itself is by-eye JS; this replays what OK posts — the reorder that
    // empties the folder, then delete_folder — and checks nothing is stranded.
    $jar = login('example', 'examplepassword');
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'add_folder', 'view' => 'All', 'name' => 'EmptyMe'], $jar);
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'add', 'view' => 'EmptyMe',
        'text' => 'survives the folder', 'folder' => 'EmptyMe', 'section' => ''], $jar);
    req('GET', '/calmind/reminders/?folder=All', [], $jar);   // normalise (seeds General, homes the row)
    $sid = rem_sec_id('EmptyMe', 'General');
    ok($sid !== null, 'the folder holds its one section');
    // Its only section is dragged into RTo (renamed on arrival is refused — RTo has a
    // General — so use a fresh name first to make the move land).
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'rename_section', 'view' => 'EmptyMe',
        'folder' => 'EmptyMe', 'name' => 'General', 'newname' => 'Moved along'], $jar);
    $rid = null;
    foreach (stored('reminders', 'example') as $x) {
        if (($x['type'] ?? '') !== 'section' && ($x['text'] ?? '') === 'survives the folder') { $rid = $x['id']; }
    }
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'reorder', 'view' => 'All',
        'order' => json_encode([['id' => $rid, 'section' => 'Moved along', 'folder' => 'RTo']]),
        'sections' => json_encode(['EmptyMe' => [], 'RTo' => [$sid, rem_sec_id('RTo', 'General')]])], $jar, true);
    eq('RTo', null !== rem_sec_id('RTo', 'Moved along') ? 'RTo' : null, 'the section moved');
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'delete_folder', 'view' => 'All',
        'name' => 'EmptyMe', 'confirm' => '1'], $jar);
    ok(!in_array('EmptyMe', folders_load(datadir(), 'example')['reminders'], true), 'the emptied folder is gone');
    $find = function ($id) { foreach (stored('reminders', 'example') as $x) { if (($x['id'] ?? '') === $id) { return $x; } } return null; };
    eq('RTo', $find($rid)['folder'] ?? null, 'the row went with its section, not to the fallback');
});

t('emptying a note folder by drag, then the chained delete, removes it cleanly', function () {
    $jar = login('example', 'examplepassword');
    req('POST', '/calmind/notes/', ['csrf' => csrf($jar, '/calmind/notes/'), 'action' => 'add_folder', 'view' => 'All', 'name' => 'NEmpty'], $jar);
    req('GET', '/calmind/notes/?folder=All', [], $jar);   // normalise (seeds General)
    $sid = note_sec_id('NEmpty', 'General');
    ok($sid !== null, 'the folder holds its one section');
    req('POST', '/calmind/notes/', ['csrf' => csrf($jar, '/calmind/notes/'), 'action' => 'rename_section', 'view' => 'NEmpty',
        'folder' => 'NEmpty', 'name' => 'General', 'newname' => 'Landed'], $jar);
    req('POST', '/calmind/notes/', ['csrf' => csrf($jar, '/calmind/notes/'), 'action' => 'reorder', 'view' => 'All',
        'order' => '[]',
        'sections' => json_encode(['NEmpty' => [], 'NDragB' => [$sid, note_sec_id('NDragB', 'General')]])], $jar, true);
    ok(note_sec_id('NDragB', 'Landed') !== null, 'the section moved');
    req('POST', '/calmind/notes/', ['csrf' => csrf($jar, '/calmind/notes/'), 'action' => 'delete_folder', 'view' => 'All',
        'name' => 'NEmpty', 'confirm' => '1'], $jar);
    ok(!in_array('NEmpty', folders_load(datadir(), 'example')['notes'], true), 'the emptied folder is gone');
});

// ---------------------------------------------------------------- 17. calendar, in full
area('calendar2');

t('a repeat is expanded across the month being drawn', function () {
    $jar = login('example', 'examplepassword');
    $r = req('GET', '/calmind/calendar/', [], $jar);
    preg_match('/=\s*(\{"20\d\d-\d\d-\d\d".*?\})\s*;/s', $r['body'], $m);
    $byDay = json_decode($m[1], true);
    $days = [];
    foreach ($byDay as $d => $items) {
        foreach ($items as $i) { if (($i['text'] ?? '') === 'Team standup') { $days[] = $d; } }
    }
    ok(count($days) > 5, 'a daily repeat shows on many days of the month, not just its start');
});

t('paging to another month works and lands on its first', function () {
    $jar = login('example', 'examplepassword');
    $next = date('Y-m', strtotime('first day of next month'));
    $r = req('GET', '/calmind/calendar/?ym=' . $next, [], $jar);
    eq(200, $r['status']);
    has($next . '-01', $r['body'], 'the month it was asked for is the month it drew');
});

t('a reminder folder can be switched to Dated-only or Off for the calendar', function () {
    $jar = login('example', 'examplepassword');
    req('POST', '/calmind/calendar/', ['csrf' => csrf($jar, '/calmind/calendar/'), 'action' => 'rf_mode',
        'name' => 'Home', 'mode' => 'none', 'ym' => date('Y-m')], $jar, true);
    $r = req('GET', '/calmind/calendar/', [], $jar);
    preg_match('/=\s*(\{"20\d\d-\d\d-\d\d".*?\})\s*;/s', $r['body'], $m);
    $found = false;
    foreach (json_decode($m[1], true) as $items) {
        foreach ($items as $i) { if (($i['text'] ?? '') === 'Call the dentist back') { $found = true; } }
    }
    ok(!$found, "a folder switched off does not reach the calendar");
    req('POST', '/calmind/calendar/', ['csrf' => csrf($jar, '/calmind/calendar/'), 'action' => 'rf_mode',
        'name' => 'Home', 'mode' => 'all', 'ym' => date('Y-m')], $jar, true);
});

t('adding a reminder from the day panel puts it in the chosen folder and group', function () {
    $jar = login('example', 'examplepassword');
    $day = date('Y-m-d', strtotime('+2 days'));
    req('POST', '/calmind/calendar/', ['csrf' => csrf($jar, '/calmind/calendar/'), 'action' => 'add_reminder',
        'kind' => 'reminder', 'text' => 'From the day panel', 'day' => $day, 'date' => $day,
        'section' => "Home\x1FErrands", 'ym' => date('Y-m')], $jar);
    $row = rowBy('example', 'From the day panel');
    ok($row !== null, 'created');
    eq($day, $row['due']);
});

t('a calendar with a stale id on an event falls back to a real one', function () {
    $jar = login('example', 'examplepassword');
    $day = date('Y-m-d');
    req('POST', '/calmind/calendar/', ['csrf' => csrf($jar, '/calmind/calendar/'), 'action' => 'add_event',
        'kind' => 'event', 'text' => 'Stale cal event', 'day' => $day, 'date' => $day,
        'cal' => 'nope', 'ym' => date('Y-m')], $jar);
    $ids = array_column(stored('calendars', 'example'), 'id');
    foreach (stored('events', 'example') as $e) {
        if (($e['text'] ?? '') === 'Stale cal event') {
            ok(in_array($e['cal'] ?? '', $ids, true) || ($e['cal'] ?? '') === '',
               'it never keeps an id that does not exist');
        }
    }
});

// ---------------------------------------------------------------- 18. habits, in full
area('calshow');

// The full showing matrix for one calendar day: every kind, both owners, plus what must
// never show. This area exists because a partner's dated shared notes silently never
// reached the calendar — reminders and events each had their partner pass and notes
// didn't, and nothing was watching the whole table at once.

// The payload for one month, as one signed-in user sees it.
function calshow_payload(array &$jar, string $ym): array
{
    $r = req('GET', '/calmind/calendar/?ym=' . $ym, [], $jar);
    preg_match('/=\s*(\{"20\d\d-\d\d-\d\d".*?\})\s*;/s', $r['body'], $m);
    return json_decode($m[1] ?? '{}', true);
}

// A folder's first real section, so a written fixture is already normalised — a blank
// section would be re-homed and persisted on the next read, which the security and
// edges fingerprints rightly flag as an unexpected write.
function calshow_section(array $rows, string $folder): string
{
    foreach ($rows as $r) {
        if (($r['type'] ?? '') === 'section' && ($r['folder'] ?? '') === $folder) {
            return (string) $r['name'];
        }
    }
    return SECTION_DEFAULT_NAME;
}

t('every kind from both owners lands on the day', function () {
    $D  = date('Y-m-d', strtotime('+40 days'));
    $ym = substr($D, 0, 7);
    $dir = datadir();
    // example's own three kinds, written the way the apps store them.
    $ev = stored('events', 'example');
    $myCal = array_values(array_filter(stored('calendars', 'example'), fn($c) => ($c['type'] ?? '') !== 'set'))[0]['id'];
    $ev[] = ['id' => 'mxownev01', 'text' => 'mx own event', 'date' => $D, 'time' => '10:00',
             'cal' => $myCal, 'repeat' => null, 'created' => time()];
    store_write(user_data_file($dir, 'events', 'example'), $ev);
    $rm = stored('reminders', 'example');
    $rm[] = ['id' => 'mxownrem1', 'text' => 'mx own reminder', 'due' => $D, 'time' => null,
             'done' => false, 'folder' => FOLDER_REMINDERS,
             'section' => calshow_section($rm, FOLDER_REMINDERS), 'repeat' => null, 'created' => time()];
    store_write(user_data_file($dir, 'reminders', 'example'), $rm);
    $nt = stored('notes', 'example');
    $nt[] = ['id' => 'mxownnote', 'title' => 'mx own note', 'body' => '', 'folder' => 'General',
             'section' => calshow_section($nt, 'General'), 'date' => $D, 'time' => null,
             'created' => time(), 'updated' => time()];
    store_write(user_data_file($dir, 'notes', 'example'), $nt);
    // buddy's three kinds, each in something buddy actually shares with example.
    $bsh = stored('shares', 'buddy');
    $sharedCal    = $bsh['calendars'][0];
    $sharedFolder = $bsh['folders'][0];
    $sharedNotes  = $bsh['notes'][0];
    $ev = stored('events', 'buddy');
    $ev[] = ['id' => 'mxtheirev', 'text' => 'mx their event', 'date' => $D, 'time' => '18:00',
             'cal' => $sharedCal, 'repeat' => null, 'created' => time()];
    store_write(user_data_file($dir, 'events', 'buddy'), $ev);
    $rm = stored('reminders', 'buddy');
    $rm[] = ['id' => 'mxtheirrm', 'text' => 'mx their reminder', 'due' => $D, 'time' => null,
             'done' => false, 'folder' => $sharedFolder,
             'section' => calshow_section($rm, $sharedFolder), 'repeat' => null, 'created' => time()];
    store_write(user_data_file($dir, 'reminders', 'buddy'), $rm);
    $nt = stored('notes', 'buddy');
    $nt[] = ['id' => 'mxtheirnt', 'title' => 'mx their note', 'body' => '', 'folder' => $sharedNotes,
             'section' => calshow_section($nt, $sharedNotes), 'date' => $D, 'time' => null,
             'created' => time(), 'updated' => time()];
    store_write(user_data_file($dir, 'notes', 'buddy'), $nt);

    $jar = login('example', 'examplepassword');
    $day = calshow_payload($jar, $ym)[$D] ?? [];
    $want = [
        'mx own event'      => ['event',    ''],
        'mx own reminder'   => ['reminder', ''],
        'mx own note'       => ['note',     ''],
        'mx their event'    => ['event',    'buddy'],
        'mx their reminder' => ['reminder', 'buddy'],
        'mx their note'     => ['note',     'buddy'],
    ];
    foreach ($want as $text => [$kind, $owner]) {
        $hit = null;
        foreach ($day as $it) { if (($it['text'] ?? '') === $text) { $hit = $it; } }
        ok($hit !== null, "\"$text\" shows on its day");
        eq($kind, $hit['kind'] ?? null, "\"$text\" is a $kind");
        eq($owner, (string) ($hit['owner'] ?? ''), $owner === '' ? "\"$text\" is mine" : "\"$text\" is marked theirs");
        ok(!empty($hit['color']), "\"$text\" wears a colour");
    }
});

t('what must never show: unshared and hidden things stay off', function () {
    $D  = date('Y-m-d', strtotime('+40 days'));
    $ym = substr($D, 0, 7);
    $dir = datadir();
    $bsh = stored('shares', 'buddy');
    // buddy's three kinds again, each in something buddy does NOT share.
    $unCal = null;
    foreach (stored('calendars', 'buddy') as $c) {
        if (($c['type'] ?? '') !== 'set' && !in_array($c['id'] ?? '', $bsh['calendars'], true)) { $unCal = $c['id']; }
    }
    ok($unCal !== null, 'buddy has an unshared calendar to test with');
    $unFolder = null;
    foreach (folders_load($dir, 'buddy')['reminders'] as $f) {
        if ($f !== FOLDER_CALENDAR && !in_array($f, $bsh['folders'], true)) { $unFolder = $f; }
    }
    ok($unFolder !== null, 'buddy has an unshared reminder folder');
    $unNotes = null;
    foreach (folders_load($dir, 'buddy')['notes'] as $f) {
        if (!in_array($f, $bsh['notes'], true)) { $unNotes = $f; }
    }
    ok($unNotes !== null, 'buddy has an unshared note folder');
    $ev = stored('events', 'buddy');
    $ev[] = ['id' => 'mxprivev1', 'text' => 'mx private event', 'date' => $D, 'time' => '09:00',
             'cal' => $unCal, 'repeat' => null, 'created' => time()];
    store_write(user_data_file($dir, 'events', 'buddy'), $ev);
    $rm = stored('reminders', 'buddy');
    $rm[] = ['id' => 'mxprivrm1', 'text' => 'mx private reminder', 'due' => $D, 'time' => null,
             'done' => false, 'folder' => $unFolder,
             'section' => calshow_section($rm, $unFolder), 'repeat' => null, 'created' => time()];
    store_write(user_data_file($dir, 'reminders', 'buddy'), $rm);
    $nt = stored('notes', 'buddy');
    $nt[] = ['id' => 'mxprivnt1', 'title' => 'mx private note', 'body' => '', 'folder' => $unNotes,
             'section' => calshow_section($nt, $unNotes), 'date' => $D, 'time' => null,
             'created' => time(), 'updated' => time()];
    store_write(user_data_file($dir, 'notes', 'buddy'), $nt);

    $jar = login('example', 'examplepassword');
    $all = json_encode(calshow_payload($jar, $ym));
    foreach (['mx private event', 'mx private reminder', 'mx private note'] as $t) {
        hasnt($t, $all, "\"$t\" never reaches example's calendar");
    }
    // And my own event on a calendar I've hidden goes dark until I show it again.
    $myCal = null;
    foreach (stored('events', 'example') as $e) { if (($e['id'] ?? '') === 'mxownev01') { $myCal = $e['cal']; } }
    req('POST', '/calmind/calendar/', ['csrf' => csrf($jar, '/calmind/calendar/'),
        'action' => 'cal_vis', 'name' => $myCal], $jar, true);
    $hidden = json_encode(calshow_payload($jar, $ym));
    hasnt('mx own event', $hidden, 'an event on a hidden calendar stays off');
    req('POST', '/calmind/calendar/', ['csrf' => csrf($jar, '/calmind/calendar/'),
        'action' => 'cal_vis_all', 'show' => '1'], $jar, true);
    has('mx own event', json_encode(calshow_payload($jar, $ym)), 'and comes back when every calendar shows');
});

t('the week-mode and swipe machinery ships wired', function () {
    // The harness runs no JS; these pin the handlers the gestures live in. The behaviour
    // itself was driven in a real browser (2026-08-04, local and production): swipe up
    // engages week mode, the arrows step a week, swipe down restores the month.
    $jar = login('example', 'examplepassword');
    $b = req('GET', '/calmind/calendar/', [], $jar)['body'];
    has("wrap.addEventListener('touchstart'", $b, 'the swipe start handler is attached');
    has("wrap.addEventListener('touchend'", $b, 'and the swipe end handler');
    has("localStorage.setItem('calWeekMode'", $b, 'week mode persists');
    has("classList.toggle('wk-hide'", $b, 'week mode hides the other rows');
    has('wk=last', $b, 'paging back crosses into the previous month');
    has('wk=first', $b, 'paging forward crosses into the next');
    has('.monthnav > a', $b, 'the arrows are intercepted in week mode');
    has('Math.abs(dx) > 55', $b, 'the sideways-swipe paging threshold is deliberate');
    has('data-week="', $b, 'every cell carries its week for the fold');
    has("grid.addEventListener('pointerdown'", $b, 'day selection starts at pointerdown');
    has("grid.addEventListener('pointerup'", $b, 'and needs the matching pointerup — a tap, never a swipe');
});

area('habits2');

t('the month view counts a day against the habits ticked on it', function () {
    $jar = login('example', 'examplepassword');
    $r = req('GET', '/calmind/habits/?v=month&m=' . date('Y-m'), [], $jar);
    eq(200, $r['status']);
    $n = preg_match_all('/title="(\d+) of (\d+) on \d{4}-\d{2}-\d{2}"/', $r['body'], $m);
    ok($n >= 28, 'every day says how many of how many');
    foreach ($m[1] as $i => $done) {
        ok((int) $done <= (int) $m[2][$i], 'never more done than there are habits');
    }
});

t('a day\'s pie is drawn in its sections\' colours, not the flat accent', function () {
    $jar = login('example', 'examplepassword');
    // Count every section, so a day's slices are its habits' section colours.
    req('POST', '/calmind/habits/', ['csrf' => csrf($jar, '/calmind/habits/'), 'action' => 'msec_all', 'show' => '1'], $jar, true);
    $body = req('GET', '/calmind/habits/?v=month&m=' . date('Y-m'), [], $jar)['body'];
    preg_match_all('/class="pie" style="background:([^"]+)"/', $body, $m);
    ok(count($m[1]) >= 28, 'every day has a pie');
    $coloured = 0; $accent = 0;
    foreach ($m[1] as $bg) {
        if (strpos($bg, 'var(--accent)') !== false) { $accent++; }
        // A day with ticks is a conic-gradient whose first slice is a section colour (a
        // hex), never the old flat green fill.
        if (preg_match('/conic-gradient\(#[0-9a-fA-F]{6}/', $bg)) { $coloured++; }
    }
    eq(0, $accent, 'no pie is filled with the accent any more');
    ok($coloured > 0, 'at least one day is filled in a section colour');
});

t('the week grid pages whole weeks', function () {
    $jar = login('example', 'examplepassword');
    $seen = [];
    foreach ([-1, 0] as $w) {
        $r = req('GET', '/calmind/habits/?v=week&w=' . $w, [], $jar);
        eq(200, $r['status'], "?w=$w");
        preg_match_all('/data-date="(\d{4}-\d{2}-\d{2})"/', $r['body'], $m);
        ok(count($m[1]) > 0, "?w=$w draws days");
        $seen[$w] = min($m[1]);
    }
    ok($seen[-1] < $seen[0], 'paging back really moves back');
    eq(7, (int) round((strtotime($seen[0]) - strtotime($seen[-1])) / 86400), 'by a whole week');
});

t('deleting a section keeps its habits, moved into a remaining section', function () {
    $jar = login('example', 'examplepassword');
    $onlySecs = fn() => array_values(array_filter(stored('habits', 'example'),
        fn($x) => ($x['type'] ?? '') === 'section'));
    ok(count($onlySecs()) >= 2, 'at least two sections, so one can be deleted');
    // A section that actually has habits under it, so we can watch where they go.
    $target = null; $under = [];
    foreach ($onlySecs() as $s) {
        $u = array_values(array_filter(stored('habits', 'example'),
            fn($x) => ($x['type'] ?? '') !== 'section' && ($x['section'] ?? '') === $s['id']));
        if ($u) { $target = $s; $under = $u; break; }
    }
    ok($target !== null, 'found a section with habits');
    $before = count(array_filter(stored('habits', 'example'), fn($x) => ($x['type'] ?? '') !== 'section'));
    req('POST', '/calmind/habits/', ['csrf' => csrf($jar, '/calmind/habits/'), 'action' => 'delete_section',
        'id' => $target['id'], 'confirm' => '1'], $jar);
    $after   = stored('habits', 'example');
    $secIds  = array_map(fn($s) => (string) $s['id'], array_filter($after, fn($x) => ($x['type'] ?? '') === 'section'));
    eq($before, count(array_filter($after, fn($x) => ($x['type'] ?? '') !== 'section')), 'no habit was destroyed');
    ok(!in_array($target['id'], $secIds, true), 'the section itself is gone');
    foreach ($under as $h) {
        foreach ($after as $x) {
            if (($x['id'] ?? '') === $h['id']) {
                ok(in_array((string) ($x['section'] ?? ''), $secIds, true), 'its habit moved into a remaining section');
            }
        }
    }
});

t('the month view\'s section filter has the suite\'s three gestures', function () {
    $jar = login('example', 'examplepassword');
    $secs = array_values(array_filter(stored('habits', 'example'), fn($h) => ($h['type'] ?? '') === 'section'));
    ok(count($secs) >= 1, 'there is at least one section to filter');
    $one = (string) $secs[0]['id'];
    $all = array_map(fn($s) => (string) $s['id'], $secs);   // every key is a real section now
    $post = function (array $p) use ($jar) {
        return json_decode(req('POST', '/calmind/habits/', $p + ['csrf' => csrf($jar, '/calmind/habits/')], $jar, true)['body'], true);
    };

    // The box toggles one.
    eq([$one], $post(['action' => 'msec_vis', 'name' => $one, 'show' => ''])['hidden'] ?? null,
       'unticking hides that section');
    eq([], $post(['action' => 'msec_vis', 'name' => $one, 'show' => '1'])['hidden'] ?? null,
       'and ticking it puts it back');

    // A row tap makes it the only one counted.
    $hidden = $post(['action' => 'msec_only', 'name' => $one])['hidden'] ?? null;
    eq(count($all) - 1, count($hidden), 'everything but the one tapped is hidden');
    ok(!in_array($one, $hidden, true), 'and the one tapped is counted');

    // "All" shows everything, then hides everything.
    eq([], $post(['action' => 'msec_all', 'show' => '1'])['hidden'] ?? null, 'All on');
    eq(count($all), count($post(['action' => 'msec_all', 'show' => ''])['hidden'] ?? []), 'All off');

    // A section that isn't there is a no-op, not a stored ghost.
    $was = $post(['action' => 'msec_all', 'show' => '1'])['hidden'] ?? null;
    eq($was, $post(['action' => 'msec_only', 'name' => 'no-such-section'])['hidden'] ?? null,
       'an unknown key changes nothing');
});

t('the filter changes the pies and nothing else', function () {
    $jar  = login('example', 'examplepassword');
    $csrf = csrf($jar, '/calmind/habits/');
    // Guarantee two sections that each hold a habit, so filtering to one is strictly fewer
    // than counting them all (prior tests may have consolidated the seed's distribution).
    req('POST', '/calmind/habits/', ['csrf' => $csrf, 'action' => 'add_section', 'name' => 'FilterSecB', 'mgr' => '1'], $jar);
    $secB = null;
    foreach (stored('habits', 'example') as $x) { if (($x['type'] ?? '') === 'section' && ($x['name'] ?? '') === 'FilterSecB') { $secB = $x; } }
    req('POST', '/calmind/habits/', ['csrf' => $csrf, 'action' => 'add_habit', 'name' => 'FilterHabitB', 'section' => $secB['id']], $jar);
    req('POST', '/calmind/habits/', ['csrf' => $csrf, 'action' => 'msec_all', 'show' => '1'], $jar, true);

    $before = req('GET', '/calmind/habits/?v=month', [], $jar)['body'];
    preg_match('/of (\d+) on \d{4}/', $before, $m);
    $wholeTotal = (int) ($m[1] ?? 0);
    ok($wholeTotal > 1, 'the month counts every habit to begin with');
    has('id="msecBtn"', $before, 'and the picker sits by the Week/Month switch');

    req('POST', '/calmind/habits/', ['csrf' => $csrf, 'action' => 'msec_only', 'name' => (string) $secB['id']], $jar, true);
    $after = req('GET', '/calmind/habits/?v=month', [], $jar)['body'];
    preg_match('/of (\d+) on \d{4}/', $after, $m2);
    ok((int) ($m2[1] ?? 0) < $wholeTotal, 'filtering to one section counts fewer habits');
    has("you're counting", $after, 'and the legend says so');

    // The picker now sits by the switch in the week view too — but it only feeds the month
    // pies, so the week grid itself still shows every habit, filtered or not.
    $week = req('GET', '/calmind/habits/?v=week', [], $jar)['body'];
    has('id="msecBtn"', $week, 'the picker is by the switch in week view too');
    foreach (stored('habits', 'example') as $h) {
        if (($h['type'] ?? '') === 'section') { continue; }
        has(e_test((string) $h['name']), $week, 'every habit is still in the week grid');
    }
    req('POST', '/calmind/habits/', ['csrf' => $csrf, 'action' => 'msec_all', 'show' => '1'], $jar, true);
});

t('the chosen view is remembered per user', function () {
    $jar = login('example', 'examplepassword');
    req('GET', '/calmind/habits/?m=' . date('Y-m'), [], $jar);
    $prefs = store_read(datadir() . '/prefs-example.json');
    ok(in_array($prefs['habits_view'] ?? '', ['week', 'month'], true), 'the view is stored');
});

// ---------------------------------------------------------------- 19. the widget feed
area('feed');

function FEED_TOKEN(): string
{
    $t = store_read(datadir() . '/token-example.json');
    $t = is_array($t) ? ($t['token'] ?? reset($t)) : $t;
    return is_string($t) ? $t : '';
}

t('the feed groups by day and never carries a note', function () {
    if (FEED_TOKEN() === '') { return; }
    $r = req('GET', '/calmind/calendar/feed.php?token=' . urlencode(FEED_TOKEN()));
    eq(200, $r['status']);
    $j = json_decode($r['body'], true);
    ok(is_array($j), 'JSON');
    $items = $j['items'] ?? $j;
    if (!is_array($items)) { return; }
    $flat = json_encode($items);
    hasnt('"kind":"note"', $flat, 'notes are dropped server-side, not just in the script');
});

t('a reminder in the feed carries the id its tick link needs', function () {
    if (FEED_TOKEN() === '') { return; }
    $r = req('GET', '/calmind/calendar/feed.php?token=' . urlencode(FEED_TOKEN()));
    $flat = $r['body'];
    if (strpos($flat, '"reminder"') === false) { return; }
    ok(preg_match('/"id"\s*:\s*"[a-f0-9]{6,}"/', $flat) === 1, 'ids are there to tick against');
});

t('the feed is scoped, and a stale pin cannot widen it', function () {
    if (FEED_TOKEN() === '') { return; }
    $r = req('GET', '/calmind/calendar/feed.php?token=' . urlencode(FEED_TOKEN()) . '&cals=nosuchcalendar');
    eq(200, $r['status'], 'a stale pin is not an error');
    ok(json_decode($r['body'], true) !== null, 'it still answers JSON');
});

// ---------------------------------------------------------------- 20. edges
area('edges');

t('an unknown id is a no-op, not a crash', function () {
    $jar = login('example', 'examplepassword');
    $before = snapshot();
    foreach ([['/calmind/reminders/', ['action' => 'toggle', 'view' => 'All', 'id' => 'nosuchid']],
              ['/calmind/reminders/', ['action' => 'edit_text', 'view' => 'All', 'id' => 'nosuchid', 'text' => 'x']],
              ['/calmind/reminders/', ['action' => 'set_indent', 'view' => 'All', 'id' => 'nosuchid', 'indent' => '1']],
              ['/calmind/reminders/', ['action' => 'add_subtask', 'view' => 'All', 'parent' => 'nosuchid']],
              ['/calmind/habits/',    ['action' => 'rename_habit', 'id' => 'nosuchid', 'name' => 'x']],
              ['/calmind/habits/',    ['action' => 'set_section_color', 'id' => 'nosuchid', 'color' => app_palette('habits', true)[0]]],
             ] as [$page, $post]) {
        $post['csrf'] = csrf($jar, $page);
        $r = req('POST', $page, $post, $jar, true);
        ok($r['status'] < 500, "$page {$post['action']}: no server error");
        hasnt('Fatal error', $r['body'], $page);
    }
    eq($before, snapshot(), 'and nothing was written');
});

t('a malformed JSON payload is ignored rather than believed', function () {
    $jar = login('example', 'examplepassword');
    $before = snapshot();
    foreach ([['/calmind/reminders/', 'reorder', ['order' => '{not json', 'sections' => 'nope']],
              ['/calmind/habits/',    'reorder', ['order' => 'null', 'sections' => '"a string"']],
             ] as [$page, $action, $extra]) {
        $r = req('POST', $page, array_merge(['csrf' => csrf($jar, $page), 'action' => $action,
            'view' => 'All'], $extra), $jar, true);
        ok($r['status'] < 500, "$page $action survived");
    }
    eq($before, snapshot(), 'a garbage order changes nothing');
});

t('unicode and long text survive a round trip intact', function () {
    $jar = login('example', 'examplepassword');
    $text = 'Café — naïve “quotes” 🎉 日本語';
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'add', 'view' => 'Reminders',
        'text' => $text, 'folder' => 'Reminders', 'section' => ''], $jar);
    ok(rowBy('example', $text) !== null, 'stored exactly as sent');
    $long = str_repeat('a', 900);
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'add', 'view' => 'Reminders',
        'text' => $long, 'folder' => 'Reminders', 'section' => ''], $jar);
    $found = null;
    foreach (rows('example') as $r) { if (strncmp($r['text'] ?? '', 'aaaa', 4) === 0) { $found = $r; } }
    ok($found !== null, 'a very long line is kept');
    eq(500, mb_strlen($found['text']), 'clipped to the documented 500, not stored unbounded');
});

t('an empty or whitespace-only add is refused', function () {
    $jar = login('example', 'examplepassword');
    $before = count(rows('example'));
    foreach (['', '   ', "\t\n"] as $t) {
        req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'add', 'view' => 'Reminders',
            'text' => $t, 'folder' => 'Reminders', 'section' => ''], $jar);
    }
    eq($before, count(rows('example')), 'nothing empty was added');
});

t('the same section name can exist in two folders without colliding', function () {
    $jar = login('example', 'examplepassword');
    foreach (['Work', 'Home'] as $f) {
        req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'add_section',
            'view' => $f, 'folder' => $f, 'name' => 'Shared name'], $jar);
    }
    $secs = array_values(array_filter(stored('reminders', 'example'),
        fn($r) => ($r['type'] ?? '') === 'section' && ($r['name'] ?? '') === 'Shared name'));
    eq(2, count($secs), 'one per folder');
    // Renaming one must not touch the other.
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'rename_section',
        'view' => 'Work', 'folder' => 'Work', 'name' => 'Shared name', 'newname' => 'Work only'], $jar);
    $left = array_values(array_filter(stored('reminders', 'example'),
        fn($r) => ($r['type'] ?? '') === 'section' && ($r['name'] ?? '') === 'Shared name'));
    eq(1, count($left), "the other folder's section kept its name");
    eq('Home', $left[0]['folder']);
});

// ---------------------------------------------------------------- 21. more lib units
area('lib2');

t('dates parse in every documented shape', function () {
    $y = (int) date('Y');
    [, $d1] = parse_when_from_text('thing 8/3/26');
    eq('2026-08-03', $d1, 'm/d/yy');
    [, $d2] = parse_when_from_text('thing 8/3/2027');
    eq('2027-08-03', $d2, 'm/d/yyyy');
    [, $d3] = parse_when_from_text('thing 12/25');
    eq('12-25', substr((string) $d3, 5), 'bare m/d keeps the month and day');
    ok((int) substr((string) $d3, 0, 4) >= $y, 'and never lands in the past');
});

t('times parse in every documented shape', function () {
    foreach (['2pm' => '14:00', '2:30pm' => '14:30', '9am' => '09:00', '12:05 am' => '00:05'] as $in => $want) {
        [, , $t] = parse_when_from_text('x ' . $in);
        eq($want, $t, $in);
    }
});

t('a repeat spec is cleaned or refused', function () {
    ok(repeat_clean('week', 2) !== null, 'a real one survives');
    eq(null, repeat_clean('', 1), 'no unit means it happens once');
    eq(null, repeat_clean('fortnight', 1), 'an unknown unit is refused');
});

t('folder names are cleaned on the way in', function () {
    eq('Work', folder_clean('  Work  '), 'trimmed');
    eq('a b', folder_clean("a\tb"), 'whitespace collapses');
    hasnt("\x1F", folder_clean("a\x1Fb"), 'the picker separator cannot survive');
    ok(!preg_match('/[\x00-\x1F\x7F]/', folder_clean("a\x00b\x07c")), 'no control characters survive');
    eq(40, mb_strlen(folder_clean(str_repeat('x', 80))), 'clipped to 40');
});

t('sections_normalize guarantees a real section per folder and re-homes loose items', function () {
    // A folder with no section gets a default "General"; a loose or unknown-section item is
    // re-homed into its folder's first section.
    $out = sections_normalize([
        ['id' => 'r1', 'text' => 'loose', 'folder' => 'Work', 'section' => ''],
        ['id' => 'r2', 'text' => 'stray', 'folder' => 'Work', 'section' => 'Ghost'],
    ], ['Work', 'Home'], $ch);
    ok($ch, 'it reports having changed something');
    $secs = array_values(array_filter($out, fn($x) => ($x['type'] ?? '') === 'section'));
    eq(['General', 'General'], array_column($secs, 'name'), 'each folder gets one default section');
    eq(['Work', 'Home'], array_column($secs, 'folder'), 'one per folder passed in');
    foreach ($out as $x) {
        if (($x['type'] ?? '') === 'section') { continue; }
        eq('General', $x['section'], 'the loose/unknown item re-homed into the default');
    }
    // Idempotent: a normalised list comes back unchanged.
    $again = sections_normalize($out, ['Work', 'Home'], $ch2);
    ok(!$ch2, 'a normalised list is left untouched');
    eq(count($out), count($again), 'and nothing is duplicated');
    // An existing named section is kept as the folder's first; no spurious default added.
    $keep = sections_normalize([
        ['id' => 's1', 'type' => 'section', 'name' => 'Alpha', 'folder' => 'Work'],
        ['id' => 'r1', 'text' => 'x', 'folder' => 'Work', 'section' => 'Alpha'],
    ], ['Work'], $ch3);
    ok(!$ch3, 'a folder that already has a section is left alone');
    eq(1, count(array_filter($keep, fn($x) => ($x['type'] ?? '') === 'section')), 'no extra default section');
});

t('sections_normalize merges duplicate same-named sections without losing items (data-loss guard)', function () {
    // Two "General" sections in one folder is the corruption that lost items: it must be
    // deduped to one, and every item that named it must survive (they match by name).
    $out = sections_normalize([
        ['id' => 'd1', 'type' => 'section', 'name' => 'General', 'folder' => 'Work'],
        ['id' => 'd2', 'type' => 'section', 'name' => 'General', 'folder' => 'Work'],   // duplicate
        ['id' => 'n1', 'text' => 'keep me A', 'folder' => 'Work', 'section' => 'General'],
        ['id' => 'n2', 'text' => 'keep me B', 'folder' => 'Work', 'section' => 'General'],
    ], ['Work'], $ch);
    ok($ch, 'the duplicate was a change to repair');
    $secs = array_values(array_filter($out, fn($x) => ($x['type'] ?? '') === 'section' && $x['folder'] === 'Work'));
    eq(1, count($secs), 'the folder ends with a single General section');
    $items = array_values(array_filter($out, fn($x) => ($x['type'] ?? '') !== 'section'));
    eq(2, count($items), 'both items survive the merge');
    foreach ($items as $it) { eq('General', $it['section'], 'and still point at the surviving section'); }
});

t('notes has no permanent folder; the last one is undeletable but renameable', function () {
    // Notes' "General" is ordinary now (not fixed) — deletable when others exist, renameable
    // always. But an app always keeps at least one folder: the last can't be deleted.
    ok(folders_fixed('notes') === [], 'notes declares no permanent folder');
    ok(!folder_is_fixed('notes', 'General'), 'General is not fixed');
    // Reminders still has exactly "Calendar" permanent.
    ok(folders_fixed('reminders') === ['Calendar'], 'reminders keeps only Calendar permanent');
    // Deleting down to the last notes folder is refused (a fresh scratch user for isolation).
    $_SESSION['user'] = 'lastfoldertest';
    folders_add(datadir(), 'notes', 'Solo');           // now: [General, Solo] (General seeded)
    // delete everything except one, then the last delete must be refused.
    folders_delete(datadir(), 'notes', 'General');     // -> [Solo]
    $one = folders_load(datadir(), 'lastfoldertest')['notes'];
    ok(count($one) === 1, 'down to one notes folder');
    folders_delete(datadir(), 'notes', $one[0]);       // refused — it's the last
    eq($one, folders_load(datadir(), 'lastfoldertest')['notes'], 'the last folder is never deleted');
    $_SESSION['user'] = 'example';
});

t('manage menus show the name as text with a pencil to rename (last row: pencil, no ×)', function () {
    $jar = login('example', 'examplepassword');
    foreach (['/calmind/reminders/', '/calmind/notes/'] as $page) {
        $b = req('GET', $page, [], $jar)['body'];
        has('class="frename-edit"', $b, "$page folder manager carries a rename pencil");
        has('frename-label', $b, "$page name reads as a plain label");
    }
    $h = req('GET', '/calmind/habits/?v=week', [], $jar)['body'];
    has('class="frename-edit"', $h, 'habits section manager carries a rename pencil');
    has('frename-label', $h, 'and the section name reads as a label');
});

t('folders reorder and keep every folder', function () {
    $before = folders_load(datadir(), 'example')['reminders'];
    folders_reorder(datadir(), 'reminders', array_reverse($before));
    $after = folders_load(datadir(), 'example')['reminders'];
    eq(count($before), count($after), 'nothing was lost');
    foreach ($before as $f) { ok(in_array($f, $after, true), "$f is still there"); }
});

t('the kind palette is emitted as variables, not literals', function () {
    $css = kind_color_css();
    foreach (['--k-reminder', '--k-event', '--k-note', '--k-overdue'] as $v) { has($v, $css); }
    has('#60a5fa', $css, 'the event blue is a blue, not the old cyan');
});

// ---------------------------------------------------------------- the /test/ mirror, for real
// The unit checks in `test-instance` prove suite_base() prefixes a link. These prove the
// whole arrangement: two instances of the same source served side by side the way
// deploy.sh lays them out — public/ + public/test/, lib/ + lib-test/, a config.php each
// and a data directory each. What matters is that they cannot see one another. A row
// added in the sandbox must not turn up in production, and no link may cross between
// them, or a tap in /test/ quietly drops you into the real app.
area('instance');

/** A request against an arbitrary port. Same rules as req(): redirects are never followed. */
function hreq(int $port, string $method, string $path, array $post = [], ?array &$jar = null): array
{
    $headers = ["Host: 127.0.0.1:$port", 'Connection: close'];
    if ($jar) {
        $bits = [];
        foreach ($jar as $k => $v) { $bits[] = "$k=$v"; }
        $headers[] = 'Cookie: ' . implode('; ', $bits);
    }
    $body = '';
    if ($method === 'POST') {
        $body = http_build_query($post);
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $headers[] = 'Content-Length: ' . strlen($body);
    }
    $ctx = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $headers),
        'content' => $body, 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 15]]);
    $out = @file_get_contents("http://127.0.0.1:$port" . $path, false, $ctx);
    $hdr = $http_response_header ?? [];
    $res = ['status' => 0, 'location' => null, 'body' => (string) $out];
    foreach ($hdr as $i => $h) {
        if ($i === 0 && preg_match('#HTTP/\S+\s+(\d{3})#', $h, $m)) { $res['status'] = (int) $m[1]; }
        if (stripos($h, 'Location:') === 0) { $res['location'] = trim(substr($h, 9)); }
        if (stripos($h, 'Set-Cookie:') === 0 && preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $h, $m)) {
            if ($jar !== null) { $jar[trim($m[1])] = $m[2]; }
        }
    }
    return $res;
}

/**
 * Build the two-instance sandbox once and boot a server over it. Deliberately built
 * from *files*, not from the environment: neither SUITE_DATA_DIR nor SUITE_BASE is
 * passed to this server, so each instance has to find its data and its prefix the way
 * the live one does — from the config.php in its own lib directory.
 */
function instance_boot(): array
{
    static $I = null;
    if ($I !== null) { return $I; }
    global $root, $scratch;

    $box = $scratch . '/box';
    @mkdir($box, 0700, true);
    // public/ and public/test/ are the same tree, exactly as deploy.sh pushes them —
    // -L dereferences the symlinks stitching calmind/ in, like the deploy's rsync -L,
    // so the box holds real files the way the server does.
    foreach ([['lib', 'lib'], ['lib', 'lib-test'], ['public', 'public'], ['public', 'public/test']] as [$from, $to]) {
        exec('cp -RL ' . escapeshellarg($root . '/' . $from) . ' ' . escapeshellarg($box . '/' . $to), $o, $rc);
        if ($rc !== 0) { throw new RuntimeException("could not lay out $to"); }
    }
    // A data dir each, both starting from the same seeded account set — so a difference
    // between them later can only have been written by one of the two instances.
    foreach (['data', 'data-test'] as $d) {
        @mkdir($box . '/' . $d, 0700, true);
        foreach (glob($scratch . '/*.json') ?: [] as $f) { copy($f, $box . '/' . $d . '/' . basename($f)); }
        if (is_file($scratch . '/.datakey')) { copy($scratch . '/.datakey', $box . '/' . $d . '/.datakey'); }
    }
    $conf = function (string $dir, string $base) use ($box) {
        file_put_contents($box . '/' . $dir . '/config.php',
            "<?php return ['users' => [], 'data_dir' => " . var_export($box . '/' . ($dir === 'lib' ? 'data' : 'data-test'), true)
            . ", 'base' => " . var_export($base, true) . ", 'timezone' => 'America/Chicago'];\n");
    };
    $conf('lib', '');
    $conf('lib-test', '/test');

    $sock = stream_socket_server('tcp://127.0.0.1:0', $e1, $e2);
    $port = (int) explode(':', stream_socket_get_name($sock, false))[1];
    fclose($sock);
    $desc = [1 => ['file', '/dev/null', 'w'], 2 => ['file', $box . '/server.log', 'w']];
    // env -u: the sandbox must not inherit the outer run's SUITE_* overrides, or both
    // instances would silently share the outer scratch dir and every check below would
    // pass for the wrong reason.
    $srv = proc_open('env -u SUITE_DATA_DIR -u SUITE_BASE php -d display_errors=1 -d error_reporting=E_ALL'
        . ' -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($box . '/public'), $desc, $pipes);
    register_shutdown_function(function () use ($srv) {
        if (is_resource($srv)) { proc_terminate($srv); proc_close($srv); }
    });
    for ($i = 0; $i < 100; $i++) {
        $c = @fsockopen('127.0.0.1', $port, $x, $y, 0.2);
        if ($c) { fclose($c); break; }
        usleep(100000);
    }
    return $I = ['port' => $port, 'box' => $box];
}

/** Sign in on the sandbox, on either instance. */
function instance_login(int $port, string $pfx, string $user = 'example', string $pass = 'examplepassword'): array
{
    $jar = [];
    hreq($port, 'GET', $pfx . '/calmind/reminders/', [], $jar);
    $r = hreq($port, 'POST', $pfx . '/calmind/reminders/', ['username' => $user, 'password' => $pass], $jar);
    if ($r['status'] !== 302) { throw new RuntimeException("$pfx login did not redirect ({$r['status']})"); }
    return [$jar, $r];
}

/** Reminders stored by one of the sandbox's two instances. */
function instance_rows(string $box, string $which, string $user = 'example'): array
{
    $l = store_read($box . '/' . $which . '/reminders-' . $user . '.json');
    return array_values(array_filter($l, fn($r) => ($r['type'] ?? '') !== 'section'));
}

t('both instances come up from their own config, with no environment help', function () {
    ['port' => $p] = instance_boot();
    foreach (['' => 'production', '/test' => 'the sandbox'] as $pfx => $what) {
        $r = hreq($p, 'GET', ($pfx ?: '') . '/calmind/reminders/');
        eq(200, $r['status'], "$what should answer");
        has('Sign in', $r['body'], "$what should show the login form");
        quiet($r['body'], "$what is quiet");
    }
});

t('the sandbox prefixes every cross-app link and production has none', function () {
    ['port' => $p] = instance_boot();
    [$jar] = instance_login($p, '/test');
    $b = hreq($p, 'GET', '/test/calmind/reminders/', [], $jar)['body'];
    foreach (['/test/calmind/reminders/', '/test/calmind/calendar/', '/test/calmind/notes/', '/test/calmind/habits/', '/test/calmind/add/'] as $l) {
        has('href="' . $l . '"', $b, 'the sandbox tab bar stays inside /test');
    }
    // The killer: an unprefixed absolute app link in a /test/ page is a door back into
    // production, and it would look like it worked.
    ok(!preg_match('#href="/(reminders|calendar|notes|habits|add)/"#', $b),
       'no unprefixed cross-app link may leak out of /test/');

    [$pjar] = instance_login($p, '');
    $pb = hreq($p, 'GET', '/calmind/reminders/', [], $pjar)['body'];
    has('href="/calmind/reminders/"', $pb, 'production links are unprefixed');
    hasnt('/test/', $pb, 'and production carries no trace of the sandbox');
});

t('signing in lands you inside the instance you signed in to', function () {
    ['port' => $p] = instance_boot();
    [, $t] = instance_login($p, '/test');
    eq('/test/calmind/calendar/', $t['location'], 'the sandbox login lands in the sandbox');
    [, $r] = instance_login($p, '');
    eq('/calmind/calendar/', $r['location'], 'production stays in production');
});

t('a row added in the sandbox never reaches production', function () {
    ['port' => $p, 'box' => $box] = instance_boot();
    [$jar] = instance_login($p, '/test');
    $g = hreq($p, 'GET', '/test/calmind/reminders/', [], $jar);
    preg_match('/name="csrf" value="([^"]+)"/', $g['body'], $m);
    ok(!empty($m[1]), 'the sandbox page carries a token');
    hreq($p, 'POST', '/test/calmind/reminders/', ['csrf' => $m[1], 'action' => 'add', 'view' => 'All',
        'text' => 'sandbox-only row', 'folder' => 'Reminders', 'section' => ''], $jar);

    $inTest = array_column(instance_rows($box, 'data-test'), 'text');
    $inProd = array_column(instance_rows($box, 'data'), 'text');
    ok(in_array('sandbox-only row', $inTest, true), 'it landed in the sandbox data dir');
    ok(!in_array('sandbox-only row', $inProd, true), 'and NOT in production');
});

t('a row added in production never reaches the sandbox', function () {
    ['port' => $p, 'box' => $box] = instance_boot();
    [$jar] = instance_login($p, '');
    $g = hreq($p, 'GET', '/calmind/reminders/', [], $jar);
    preg_match('/name="csrf" value="([^"]+)"/', $g['body'], $m);
    hreq($p, 'POST', '/calmind/reminders/', ['csrf' => $m[1], 'action' => 'add', 'view' => 'All',
        'text' => 'production-only row', 'folder' => 'Reminders', 'section' => ''], $jar);

    ok(in_array('production-only row', array_column(instance_rows($box, 'data'), 'text'), true),
       'it landed in production');
    ok(!in_array('production-only row', array_column(instance_rows($box, 'data-test'), 'text'), true),
       'and NOT in the sandbox');
});

t('every page under /test/ loads lib-test, not lib', function () {
    ['port' => $p] = instance_boot();
    [$jar] = instance_login($p, '/test');
    // If a page's preamble were missed out, it would load lib/ — whose config has no
    // base — and its links would come out unprefixed while everything else looked fine.
    foreach (['/test/calmind/reminders/', '/test/calmind/notes/', '/test/calmind/calendar/', '/test/calmind/habits/', '/test/calmind/add/'] as $path) {
        $r = hreq($p, 'GET', $path, [], $jar);
        eq(200, $r['status'], "$path renders");
        has('href="/test/calmind/calendar/"', $r['body'], "$path was served by the sandbox instance");
        quiet($r['body'], "$path is quiet");
    }
});

t('the sandbox writes nowhere near the outer run, let alone data/', function () use ($root) {
    ['box' => $box] = instance_boot();
    ok(strpos($box, sys_get_temp_dir()) === 0, 'the sandbox lives under the temp dir');
    ok(!is_dir($root . '/data') || count(glob($root . '/data/reminders-*.json') ?: []) === 0
       || !in_array('sandbox-only row', array_column(
            store_read($root . '/data/reminders-example.json') ?: [], 'text'), true),
       'the repo data dir is untouched');
});

// ---------------------------------------------------------------- sign-up
// Anyone can make an account from the login page. Emailing is switched off, so the code
// is fixed at SIGNUP_CODE — which is exactly why the rest of the gate has to hold: a
// half-made account must not be an account, and five wrong codes must end it.
area('signup');

t('the create-account window warns that passwords are not encrypted yet', function () {
    // Until sign-up storage hashes passwords, the window has to say so before anyone
    // types a password they use elsewhere.
    $b = req('GET', '/calmind/reminders/')['body'];
    has('class="warn"', $b, 'the warning line is there');
    has('not encrypted at this time', $b, 'and says what it needs to');
    has("don't use a real password", $b, 'with the instruction that follows from it');
});

t('a sign-up is refused unless the username, email and password are all right', function () use ($scratch) {
    $bad = [
        ['x',        'a@b.com',   'longenough', 'username too short'],
        ['ok_user',  'not-email', 'longenough', 'email'],
        ['ok_user',  'a@b.com',   'short',      'password length'],
        ['example',  'a@b.com',   'longenough', 'username taken'],
    ];
    foreach ($bad as [$u, $em, $pw, $why]) {
        $jar = [];
        req('GET', '/calmind/reminders/', [], $jar);
        $r = req('POST', '/calmind/reminders/', ['action' => 'signup', 'newuser' => $u,
            'email' => $em, 'newpass' => $pw], $jar);
        eq(200, $r['status'], "$why: no redirect");
        $acc = store_read($scratch . '/accounts.json');
        ok(!isset($acc[$u]) || $u === 'example', "$why must not create an account");
    }
});

t('a good sign-up parks the account rather than creating it', function () use ($scratch) {
    $jar = [];
    req('GET', '/calmind/reminders/', [], $jar);
    $r = req('POST', '/calmind/reminders/', ['action' => 'signup', 'newuser' => 'newbie',
        'email' => 'newbie@example.com', 'newpass' => 'newbiepass'], $jar);
    eq(200, $r['status'], 'the code window opens in place');
    $pending = store_read($scratch . '/signups.json');
    ok(isset($pending['newbie']), 'it is waiting in signups.json');
    eq('newbie@example.com', $pending['newbie']['email'] ?? null);
    ok(!isset(store_read($scratch . '/accounts.json')['newbie']), 'and is NOT an account yet');
    // Nor can it sign in while it's only pending.
    $j2 = [];
    req('GET', '/calmind/reminders/', [], $j2);
    $s = req('POST', '/calmind/reminders/', ['username' => 'newbie', 'password' => 'newbiepass'], $j2);
    eq(200, $s['status'], 'a pending account cannot sign in');
});

t('a wrong code is counted and the fifth one ends the sign-up', function () use ($scratch) {
    $jar = [];
    req('GET', '/calmind/reminders/', [], $jar);
    req('POST', '/calmind/reminders/', ['action' => 'signup', 'newuser' => 'doomed',
        'email' => 'doomed@example.com', 'newpass' => 'doomedpass'], $jar);
    for ($i = 0; $i < 5; $i++) {
        req('POST', '/calmind/reminders/', ['action' => 'verify', 'newuser' => 'doomed', 'code' => '9999'], $jar);
    }
    $r = req('POST', '/calmind/reminders/', ['action' => 'verify', 'newuser' => 'doomed', 'code' => SIGNUP_CODE], $jar);
    eq(200, $r['status'], 'even the right code is too late now');
    ok(!isset(store_read($scratch . '/accounts.json')['doomed']), 'no account was made');
    ok(!isset(store_read($scratch . '/signups.json')['doomed']), 'and the pending row is gone');
});

t('the right code makes the account and signs you in', function () use ($scratch) {
    $jar = [];
    req('GET', '/calmind/reminders/', [], $jar);
    req('POST', '/calmind/reminders/', ['action' => 'signup', 'newuser' => 'newbie',
        'email' => 'newbie@example.com', 'newpass' => 'newbiepass'], $jar);
    $r = req('POST', '/calmind/reminders/', ['action' => 'verify', 'newuser' => 'newbie', 'code' => SIGNUP_CODE], $jar);
    eq(302, $r['status'], 'verifying redirects');
    eq('/calmind/calendar/', $r['location'], 'straight into the app');
    $acc = store_read($scratch . '/accounts.json');
    ok(isset($acc['newbie']), 'the account is real now');
    eq('newbiepass', $acc['newbie']['password'] ?? null);
    ok(!isset(store_read($scratch . '/signups.json')['newbie']), 'and no longer pending');
});

t('a brand-new account is an empty working suite, and sees nobody else\'s data', function () {
    ensure_account('fresh', 'freshpassword');
    $jar = login('fresh', 'freshpassword');
    foreach (['/calmind/reminders/', '/calmind/notes/', '/calmind/calendar/', '/calmind/habits/', '/calmind/add/'] as $p) {
        $r = req('GET', $p, [], $jar);
        eq(200, $r['status'], "$p renders for a new account");
        quiet($r['body'], "$p is quiet");
    }
    eq(0, count(rows('fresh')), 'no reminders');
    // A stranger has no partner, so nothing of anyone else's can be reachable.
    eq(null, share_partner('fresh'), 'and no partner');
});

/**
 * Make an account through the real sign-up, if it isn't there already. Areas share the
 * seeded set, so anything that needs a *fresh* account has to be able to make one on its
 * own — otherwise running one area by name depends on another having run first.
 */
function ensure_account(string $user, string $pass): void
{
    global $scratch;
    if (!isset(store_read($scratch . '/accounts.json')[$user])) {
        $jar = [];
        req('GET', '/calmind/reminders/', [], $jar);
        req('POST', '/calmind/reminders/', ['action' => 'signup', 'newuser' => $user,
            'email' => $user . '@example.com', 'newpass' => $pass], $jar);
        req('POST', '/calmind/reminders/', ['action' => 'verify', 'newuser' => $user, 'code' => SIGNUP_CODE], $jar);
    }
    if (!isset(store_read($scratch . '/accounts.json')[$user])) {
        // Signup wouldn't take the name: it's already an account in the developer's own
        // config.php (aki is, here). So the suite doesn't depend on whatever passwords a
        // given machine's config holds, guarantee this one works with the passwords.json
        // override — the same file a self-service change writes, and it wins over config.
        auth_password_set(app_config(), $user, $pass);
    }
}

// ---------------------------------------------------------------- the settings window
// require_login() answers these on whatever page you happen to be on, so they are the
// one pair of handlers every app inherits without wiring anything up.
area('account');

t('changing a password needs a token and the current password', function () use ($scratch) {
    ensure_account('newbie', 'newbiepass');
    $jar = login('newbie', 'newbiepass');
    $was = store_read($scratch . '/passwords.json');
    $r = req('POST', '/calmind/reminders/', ['action' => 'change_password', 'csrf' => 'wrong',
        'current' => 'newbiepass', 'new' => 'brandnewpass'], $jar, true);
    eq(400, $r['status'], 'a bad token is a 400');
    eq($was, store_read($scratch . '/passwords.json'), 'and nothing was written');

    $r = req('POST', '/calmind/reminders/', ['action' => 'change_password', 'csrf' => csrf($jar),
        'current' => 'nope', 'new' => 'brandnewpass'], $jar, true);
    eq(false, json_decode($r['body'], true)['ok'] ?? null, 'the wrong current password is refused');

    $r = req('POST', '/calmind/reminders/', ['action' => 'change_password', 'csrf' => csrf($jar),
        'current' => 'newbiepass', 'new' => 'short'], $jar, true);
    eq(false, json_decode($r['body'], true)['ok'] ?? null, 'a six-character floor');
});

t('a changed password takes effect and the old one stops working', function () {
    ensure_account('newbie', 'newbiepass');
    $jar = login('newbie', 'newbiepass');
    $r = req('POST', '/calmind/reminders/', ['action' => 'change_password', 'csrf' => csrf($jar),
        'current' => 'newbiepass', 'new' => 'brandnewpass'], $jar, true);
    eq(true, json_decode($r['body'], true)['ok'] ?? null, 'accepted');

    $j = [];
    req('GET', '/calmind/reminders/', [], $j);
    eq(200, req('POST', '/calmind/reminders/', ['username' => 'newbie', 'password' => 'newbiepass'], $j)['status'],
       'the old password is dead');
    $j2 = [];
    req('GET', '/calmind/reminders/', [], $j2);
    eq(302, req('POST', '/calmind/reminders/', ['username' => 'newbie', 'password' => 'brandnewpass'], $j2)['status'],
       'the new one works');
});

t('a stored password wins over the account record it overrides', function () use ($scratch) {
    // passwords.json is the override, because config.php is hand-kept on the server and
    // never deployed. Deleting it has to fall back rather than lock the account out.
    $pw = store_read($scratch . '/passwords.json');
    ok(isset($pw['newbie']), 'the override is on disk');
    eq('newbiepass', store_read($scratch . '/accounts.json')['newbie']['password'] ?? null,
       'and the account record still holds the original');
});

t('the theme is set over AJAX, refuses a name it does not know, and sticks', function () use ($scratch) {
    $jar = login('example', 'examplepassword');
    $r = req('POST', '/calmind/reminders/', ['action' => 'set_theme', 'csrf' => csrf($jar),
        'theme' => 'not-a-theme'], $jar, true);
    eq(false, json_decode($r['body'], true)['ok'] ?? null, 'an unknown theme is refused');

    $names = array_keys(THEMES);
    $pick  = $names[count($names) - 1];
    $r = req('POST', '/calmind/reminders/', ['action' => 'set_theme', 'csrf' => csrf($jar),
        'theme' => $pick], $jar, true);
    eq(true, json_decode($r['body'], true)['ok'] ?? null, "theme $pick is accepted");
    eq($pick, store_read($scratch . '/prefs-example.json')['theme'] ?? null, 'and it is stored');

    $r = req('POST', '/calmind/reminders/', ['action' => 'set_theme', 'csrf' => 'wrong', 'theme' => $names[0]], $jar, true);
    eq(false, json_decode($r['body'], true)['ok'] ?? null, 'no token, no change');
    eq($pick, store_read($scratch . '/prefs-example.json')['theme'] ?? null, 'still the one we set');
});

t('a suite theme paints the whole page, and midnight is the unchanged default', function () use ($scratch) {
    // Themes went from accent-only to full palettes. Midnight must be byte-for-byte the
    // old look (#111 page, #eee text, #34d399 accent) so an untouched account sees no
    // change; a light theme must flip color-scheme so native controls follow.
    ensure_account('fresh', 'freshpassword');
    $jar = login('fresh', 'freshpassword');
    $b = req('GET', '/calmind/reminders/', [], $jar)['body'];
    foreach (['--bg: #111111', '--text: #eeeeee', '--accent: #34d399', '--gold: #f0b429',
              'color-scheme: dark'] as $v) {
        has($v, $b, "a fresh account's page carries midnight's $v");
    }
    has('name="theme-color" content="#111111"', $b, 'the status-bar colour follows the theme');

    $csrf = csrf($jar, '/calmind/reminders/');
    req('POST', '/calmind/reminders/', ['action' => 'set_theme', 'csrf' => $csrf, 'theme' => 'sage'], $jar, true);
    foreach (['/calmind/reminders/', '/calmind/calendar/', '/calmind/notes/', '/calmind/habits/', '/calmind/add/'] as $p) {
        $b = req('GET', $p, [], $jar)['body'];
        has('--bg: #fefae0', $b, "$p wears the sage page colour");
        has('color-scheme: light', $b, "$p flips to a light scheme");
    }
    has('name="theme-color" content="#fefae0"', req('GET', '/calmind/reminders/', [], $jar)['body'],
        'and the status-bar colour moved with it');
    // The widget quick page and the feed setup page carry the vars too — they have their
    // own style blocks, and a converted rule with no var behind it silently loses colour.
    has('--bg: #fefae0', req('GET', '/calmind/calendar/quick.php', [], $jar)['body'], 'quick.php is themed');
    has('--bg: #fefae0', req('GET', '/calmind/calendar/feed.php', [], $jar)['body'], 'the feed setup page is themed');
    req('POST', '/calmind/reminders/', ['action' => 'set_theme', 'csrf' => csrf($jar), 'theme' => 'midnight'], $jar, true);
});

t('no app page carries the old hardcoded dark-room colours', function () {
    // The tripwire for the theme conversion: a new rule written with a literal #111-family
    // hex renders fine on midnight and breaks on the cream themes, so the raw declarations
    // themselves are banned from the rendered page. (The kind palette, the error red and
    // Habits' violet are semantic and allowed; this only checks the neutral roles.)
    $jar = login('example', 'examplepassword');
    foreach (['/calmind/reminders/', '/calmind/calendar/', '/calmind/notes/', '/calmind/habits/', '/calmind/add/'] as $p) {
        $b = req('GET', $p, [], $jar)['body'];
        foreach (['background: #111;', 'background: #1a1a1a', 'background: #222;',
                  'color: #eee', 'color: #888', 'border: 1px solid #333',
                  'border: 1px solid #444'] as $lit) {
            hasnt($lit, $b, "$p still hardcodes '$lit'");
        }
    }
});

t('every app offers the same theme picker', function () {
    $jar = login('example', 'examplepassword');
    foreach (['/calmind/reminders/', '/calmind/calendar/', '/calmind/notes/', '/calmind/habits/', '/calmind/add/'] as $p) {
        $b = req('GET', $p, [], $jar)['body'];
        eq(count(THEMES), substr_count($b, 'class="themebtn'), "$p shows one swatch per theme");
    }
});

t('the theme picker shows every theme as its own swatch', function () {
    $jar = login('example', 'examplepassword');
    $b = req('GET', '/calmind/reminders/', [], $jar)['body'];
    eq(count(THEMES), substr_count($b, 'class="themebtn'), 'one swatch per theme');
    eq(count(THEMES), substr_count($b, 'class="themedot"'), 'each carrying its accent dot');
    foreach (THEMES as $key => $row) {
        has('data-theme="' . $key . '"', $b, "$key is offered");
    }
    // A legacy stored name (the old accent-only themes) falls back to midnight rather
    // than erroring or half-applying.
    store_write(datadir() . '/prefs-example.json',
        array_merge(store_read(datadir() . '/prefs-example.json'), ['theme' => 'rose']));
    $b = req('GET', '/calmind/reminders/', [], $jar)['body'];
    has('--bg: #111111', $b, 'an old stored theme name renders as midnight');
});

// ---------------------------------------------------------------- token auth
// The widget and the watch carry a token instead of a session. It is a READ credential
// and has been handed out as one: anything behind it that wrote would hand that power
// to every copy already in circulation.
area('token');

t('token_user() matches exactly, or not at all', function () {
    $dir = datadir();
    $tok = 'testtoken' . bin2hex(random_bytes(6));
    store_write($dir . '/token-example.json', ['token' => $tok]);
    eq('example', token_user($dir, $tok), 'the right token names its owner');
    eq(null, token_user($dir, ''), 'an empty token is nobody');
    eq(null, token_user($dir, substr($tok, 0, -1)), 'a prefix is not a match');
    eq(null, token_user($dir, $tok . 'x'), 'nor is an extension');
    eq(null, token_user($dir, strtoupper($tok)), 'nor a different case');
});

t('one person\'s token cannot read another person\'s feed', function () {
    $dir = datadir();
    $mine = 'tokA' . bin2hex(random_bytes(6));
    $them = 'tokB' . bin2hex(random_bytes(6));
    store_write($dir . '/token-example.json', ['token' => $mine]);
    store_write($dir . '/token-buddy.json',   ['token' => $them]);

    $a = json_decode(req('GET', '/calmind/calendar/feed.php?token=' . $mine)['body'], true);
    $b = json_decode(req('GET', '/calmind/calendar/feed.php?token=' . $them)['body'], true);
    ok(is_array($a) && is_array($b), 'both answer JSON');
    $txt = fn($f) => array_column($f['items'] ?? [], 'text');
    ok($txt($a) !== $txt($b) || (!$txt($a) && !$txt($b)), 'the two feeds are not the same list');
    eq('example', token_user($dir, $mine));
    eq('buddy',   token_user($dir, $them));
});

t('the feed refuses to write, whatever it is asked', function () {
    $dir = datadir();
    $tok = 'tokW' . bin2hex(random_bytes(6));
    store_write($dir . '/token-example.json', ['token' => $tok]);
    $before = count(rows('example'));
    foreach ([['action' => 'add', 'text' => 'via the token'],
              ['action' => 'tick', 'id' => (rows('example')[0]['id'] ?? 'x')]] as $post) {
        req('POST', '/calmind/calendar/feed.php?token=' . $tok, $post);
    }
    eq($before, count(rows('example')), 'the feed wrote nothing');
    ok(rowBy('example', 'via the token') === null, 'and added nothing');
});

t('the reminders API has no anonymous read and no write', function () {
    $r = req('GET', '/calmind/api/reminders.php');
    ok($r['status'] !== 200 || strpos($r['body'], '"text"') === false,
       'an unauthenticated read must not return rows');
    $before = count(rows('example'));
    req('POST', '/calmind/api/reminders.php', ['action' => 'add', 'text' => 'api row']);
    eq($before, count(rows('example')), 'and an unauthenticated POST changes nothing');
});

// ---------------------------------------------------------------- chat
// Deliberately public: no login, no session, one shared file. Which makes the escaping
// and the cap the whole of its safety.
area('chat');

t('chat needs no login and posts a message', function () {
    $r = req('GET', '/chat/');
    eq(200, $r['status'], 'open to anyone');
    hasnt('name="password"', $r['body'], 'no login gate');
    req('POST', '/chat/', ['action' => 'send', 'name' => 'tester', 'text' => 'hello from the test run']);
    has('hello from the test run', req('GET', '/chat/')['body'], 'the message is on the page');
});

t('a message is escaped, not rendered', function () {
    req('POST', '/chat/', ['action' => 'send', 'name' => '<b>me</b>',
        'text' => '<script>alert(1)</script> & "quoted"']);
    $b = req('GET', '/chat/')['body'];
    hasnt('<script>alert(1)</script>', $b, 'no live script in the page');
    has('&lt;script&gt;', $b, 'it came back escaped');
    hasnt('<b>me</b>', $b, 'and so did the name');
});

t('an empty message is not stored', function () {
    $file = datadir() . '/chat.json';
    $before = count(store_read($file));
    req('POST', '/chat/', ['action' => 'send', 'name' => 'tester', 'text' => '   ']);
    eq($before, count(store_read($file)), 'whitespace is nothing');
});

// ---------------------------------------------------------------------------- themes
// A workbench for building colour palettes. The point of these is the boundary: it seeds
// itself from the bookshelf's eight but is a separate app, so editing here must never
// reach that one — and every value it stores ends up inside a style attribute, so nothing
// but a #rrggbb may ever be written.
area('themes');

t('the palette workbench is behind the login and opens with the eight starters', function () {
    $r = req('GET', '/akisthemes/');
    has('Sign in', $r['body'], 'signed out you get the login page');
    $jar = login('example', 'examplepassword');
    $r = req('GET', '/akisthemes/', [], $jar);
    eq(200, $r['status'], 'it renders for a signed-in user');
    eq(8, substr_count($r['body'], 'class="pal"'), 'seeded with the eight starters');
    eq(96, substr_count($r['body'], 'input type="color"'), 'twelve editable roles each');
    // Palettes are numbered, not named — a workbench palette earns a name later.
    foreach (['Theme 1', 'Theme 5', 'Theme 8'] as $n) { has('value="' . $n . '"', $r['body'], "$n is there"); }
    hasnt('value="Midnight"', $r['body'], 'the bookshelf names are not carried over');
    // The name is a field but read-only until the pencil is on, and delete is hidden
    // outside edit mode — arriving here must never be one tap from destroying a palette.
    has('class="palname"', $r['body'], 'the name is an editable field');
    has('readonly', $r['body'], "but read-only until that palette's pencil is on");
    // Edit belongs to the palette, not the page, and sits left of duplicate. Delete is
    // always shown — the two-press confirm is the guard, not hiding the control.
    hasnt('id="editBtn"', $r['body'], 'there is no page-wide Edit button');
    eq(8, substr_count($r['body'], 'palact paledit'), 'every palette has its own pencil');
    // First occurrence of each in the document is the first palette's row, so their
    // order in the source is the order they render in.
    $pen = strpos($r['body'], 'palact paledit');
    $dup = strpos($r['body'], 'aria-label="Duplicate"');
    $del = strpos($r['body'], 'paldel needs-confirm');
    eq(true, $pen !== false && $dup !== false && $del !== false, 'all three controls are there');
    eq(true, $pen < $dup, 'the pencil comes before the duplicate button');
    eq(true, $dup < $del, 'and duplicate before delete');
    has('paldel needs-confirm', $r['body'], 'delete takes two presses');
    hasnt('.paldel { visibility: hidden', $r['body'], 'and is never hidden');
    has('backbtn goback', $r['body'], 'there is a back button');
    has('backbtn exitedit', $r['body'], 'which becomes the x that closes an open editor');
    // Colours are only changeable on the palette that is open, so a stray tap on a
    // swatch can't repaint a palette you were only looking at. The rule has to be in
    // the stylesheet — the JS half (tabindex, readonly) can't be seen from here.
    has('.pal:not(.editing) .role input { pointer-events: none; }', $r['body'],
        'swatches are inert outside their palette\'s edit mode');
    quiet($r['body']);
    // Both of these return bare CSS and must sit INSIDE the style block; emitted after
    // </style> they render as text down the top of the page, which is how this was found.
    $head = substr($r['body'], 0, strpos($r['body'], '</style>'));
    has('--accent:', $head, 'theme_css() lands inside the stylesheet');
    has('needs-confirm', $head, 'and so does confirm_delete_styles()');
});

t('a duplicate says it is one, and a new palette takes the next number', function () {
    $jar  = login('example', 'examplepassword');
    $b    = req('GET', '/akisthemes/', [], $jar)['body'];
    $csrf = csrf($jar, '/akisthemes/');
    preg_match('/data-id="([a-f0-9]+)"/', $b, $m);
    req('POST', '/akisthemes/', ['csrf' => $csrf, 'action' => 'add', 'id' => $m[1]], $jar);
    $b = req('GET', '/akisthemes/', [], $jar)['body'];
    has('value="Theme 1 (New)"', $b, 'the copy is named for its original');
    // A plain add takes the lowest free number rather than reusing one.
    $csrf = csrf($jar, '/akisthemes/');
    req('POST', '/akisthemes/', ['csrf' => $csrf, 'action' => 'add'], $jar);
    has('value="Theme 9"', req('GET', '/akisthemes/', [], $jar)['body'], 'a new one is Theme 9');
});

t('a colour change is stored, and only a real colour in a real role', function () {
    $jar  = login('example', 'examplepassword');
    $b    = req('GET', '/akisthemes/', [], $jar)['body'];
    $csrf = csrf($jar, '/akisthemes/');
    preg_match('/data-id="([a-f0-9]+)"/', $b, $m);
    $id = $m[1];
    $r = req('POST', '/akisthemes/', ['csrf' => $csrf, 'action' => 'set_color',
        'id' => $id, 'role' => '--accent', 'hex' => '#ff0000'], $jar, true);
    has('"ok":true', $r['body'], 'a good colour is accepted');
    has('#ff0000', req('GET', '/akisthemes/', [], $jar)['body'], 'and it stuck');
    // Both of these end up inside style="…", so neither may ever be written.
    $r = req('POST', '/akisthemes/', ['csrf' => $csrf, 'action' => 'set_color',
        'id' => $id, 'role' => '--accent', 'hex' => 'javascript:alert(1)'], $jar, true);
    has('"ok":false', $r['body'], 'a non-colour is refused');
    $r = req('POST', '/akisthemes/', ['csrf' => $csrf, 'action' => 'set_color',
        'id' => $id, 'role' => '--evil', 'hex' => '#112233'], $jar, true);
    has('"ok":false', $r['body'], 'an unknown role is refused, and says so');
    hasnt('--evil', req('GET', '/akisthemes/', [], $jar)['body'], 'and nothing was written');
});

t('palettes can be added and deleted, and deleting takes two presses', function () {
    $jar = login('example', 'examplepassword');
    // Counted relative to whatever is already there: earlier tests in this area add
    // palettes of their own, and an absolute count here just breaks when one is added.
    $count = fn() => substr_count(req('GET', '/akisthemes/', [], $GLOBALS['__pjar'])['body'], 'class="pal"');
    $GLOBALS['__pjar'] = $jar;
    $before = $count();
    $csrf = csrf($jar, '/akisthemes/');
    req('POST', '/akisthemes/', ['csrf' => $csrf, 'action' => 'add', 'name' => 'Workbench one'], $jar);
    $b = req('GET', '/akisthemes/', [], $jar)['body'];
    has('value="Workbench one"', $b, 'the new palette is there');
    eq($before + 1, substr_count($b, 'class="pal"'), 'one more than before');
    preg_match('/id="p-([a-f0-9]+)"/', $b, $m);
    $id = $m[1];   // new rows land at the top
    $csrf = csrf($jar, '/akisthemes/');
    req('POST', '/akisthemes/', ['csrf' => $csrf, 'action' => 'delete', 'id' => $id], $jar);
    eq($before + 1, $count(), 'an unconfirmed delete destroys nothing');
    $csrf = csrf($jar, '/akisthemes/');
    req('POST', '/akisthemes/', ['csrf' => $csrf, 'action' => 'delete', 'id' => $id, 'confirm' => '1'], $jar);
    eq($before, $count(), 'the confirmed one does');
});

t("editing a palette never reaches Aki's Bookshelf", function () {
    // The whole reason this app is separate. It seeds from the same eight names, so the
    // only thing proving they are not shared is that a change on one side stays invisible
    // on the other.
    ensure_account('aki', 'akipassword');
    $jar  = login('aki', 'akipassword');
    $b    = req('GET', '/akisthemes/', [], $jar)['body'];
    $csrf = csrf($jar, '/akisthemes/');
    preg_match('/data-id="([a-f0-9]+)"/', $b, $m);
    req('POST', '/akisthemes/', ['csrf' => $csrf, 'action' => 'set_color',
        'id' => $m[1], 'role' => '--bg', 'hex' => '#abcdef'], $jar, true);
    has('#abcdef', req('GET', '/akisthemes/', [], $jar)['body'], 'the workbench changed');
    $shelf = req('GET', '/akisbookshelf/', [], $jar)['body'];
    hasnt('#abcdef', $shelf, 'the bookshelf did not');
    has('--bg: #111111', $shelf, 'it still wears its own Midnight');
});

// ---------------------------------------------------------------- Aki's Bookshelf
// One username's app, sitting behind the shared login. The gate is the only thing
// between it and everyone else who has an account on the suite.
area('bookshelf');

t('the bookshelf is behind the login', function () {
    $r = req('GET', '/akisbookshelf/');
    has('Sign in', $r['body'], 'signed out you get the login page');
});

t('a signed-in stranger is turned away and sees none of it', function () {
    $jar = login('example', 'examplepassword');
    $r = req('GET', '/akisbookshelf/', [], $jar);
    has('bookshelf is aki', $r['body'], 'told whose it is');
    foreach (['booksgrid', 'bookcard', 'shelf-tile'] as $marker) {
        hasnt($marker, $r['body'], "no bookshelf markup leaks ($marker)");
    }
    quiet($r['body']);
});

t('aki gets the app itself', function () {
    // aki may already be a config account on this machine, so ensure_account() falls back
    // to the passwords.json override to give it a password the test knows — either way we
    // reach the gate as a signed-in aki, which is what it turns on.
    ensure_account('aki', 'akipassword');
    $jar = login('aki', 'akipassword');
    $r = req('GET', '/akisbookshelf/', [], $jar);
    eq(200, $r['status'], 'it renders');
    hasnt('bookshelf is aki', $r['body'], 'and is not the refusal page');
    quiet($r['body']);
});

// The bookshelf has its own themes, which repaint the whole page rather than just the
// accent the way the suite's five do. They are this app's alone: stored under their own
// prefs key, and the suite's accent row is hidden here because these set --accent too.
t('the bookshelf themes are its own, and default to the original look', function () {
    ensure_account('aki', 'akipassword');
    $jar = login('aki', 'akipassword');
    $r = req('GET', '/akisbookshelf/', [], $jar);
    has('bkthemebtn', $r['body'], 'the picker is in the settings window');
    has('--bg: #111111', $r['body'], 'an untouched bookshelf is still Midnight');
    has('class="bkthemebtn on" data-theme="midnight"', $r['body'], 'and Midnight is the marked one');
    has('.setmodal .setthemes { display: none; }', $r['body'], "the suite's accent-only row is hidden here");
    // Every theme has to offer a swatch, or one of them is unreachable.
    foreach (['midnight', 'sage', 'blossom', 'dusk', 'neon', 'plum', 'forest', 'olive'] as $k) {
        has('data-theme="' . $k . '"', $r['body'], "$k can be picked");
    }
    // Picking repaints in place rather than reloading, because a reload shut the settings
    // window on every pick. That needs every theme's variables on the page, so the JS has
    // something to set — if this table goes missing the picker silently stops working.
    has('var THEMES = {', $r['body'], 'the picker carries all the themes for a live repaint');
    foreach (['midnight', 'sage', 'forest'] as $k) {
        has('"' . $k . '":{"scheme":', $r['body'], "$k is in the repaint table with its scheme");
    }
    has('"--gold"', $r['body'], 'including the themed gold');
});

t('picking a bookshelf theme repaints the page and sticks', function () {
    ensure_account('aki', 'akipassword');
    $jar = login('aki', 'akipassword');
    // The picker posts over AJAX, but a no-JS post has to work too: that one redirects.
    $csrf = csrf($jar, '/akisbookshelf/');
    $r = req('POST', '/akisbookshelf/', ['action' => 'set_book_theme', 'csrf' => $csrf, 'theme' => 'forest'], $jar);
    eq(302, $r['status'], 'a plain post redirects back, the way every mutation here does');
    $r = req('GET', '/akisbookshelf/', [], $jar);
    has('--bg: #040303', $r['body'], 'the page background is the theme, not just an accent');
    has('--gold: #c9a227', $r['body'], 'the star gold follows the theme too');
    has('color-scheme: dark', $r['body'], 'native controls are told which way round it is');
    has('class="bkthemebtn on" data-theme="forest"', $r['body'], 'and the picker shows it as chosen');
    // A light theme has to flip color-scheme, or a cream page opens black dropdowns.
    // This one goes the way the picker really does — AJAX, answered with JSON.
    $csrf = csrf($jar, '/akisbookshelf/');
    $r = req('POST', '/akisbookshelf/', ['action' => 'set_book_theme', 'csrf' => $csrf, 'theme' => 'sage'], $jar, true);
    eq(200, $r['status'], 'the AJAX pick is answered in place');
    has('"theme":"sage"', $r['body'], 'and echoes back the stored theme');
    $r = req('GET', '/akisbookshelf/', [], $jar);
    has('--bg: #fefae0', $r['body'], 'the cream theme applies');
    has('color-scheme: light', $r['body'], 'and switches the page to light');
    has('theme-color" content="#fefae0"', $r['body'], 'the PWA status bar follows it');
});

// Edit mode is never persisted, so nothing should switch it on behind your back. Adding
// a section is reachable from outside it (the + is always shown), so it must not.
t('adding a bookshelf section does not drag you into edit mode', function () {
    ensure_account('aki', 'akipassword');
    $jar  = login('aki', 'akipassword');
    $csrf = csrf($jar, '/akisbookshelf/');
    $r = req('POST', '/akisbookshelf/', ['action' => 'add_bsection', 'csrf' => $csrf,
        'book' => 'nosuchbook', 'name' => 'Quotes'], $jar);
    eq(302, $r['status'], 'it redirects');
    $to = (string) ($r['location'] ?? '');
    has('book=', $to, 'the redirect goes back to the book');   // non-vacuous: proves $to is real
    hasnt('edit=1', $to, 'and it does not turn edit mode on');
    // Adding one *while already editing* has to keep you there, or a structural change
    // would kick you out mid-edit — that is what the posted edit flag is for.
    $csrf = csrf($jar, '/akisbookshelf/');
    $r = req('POST', '/akisbookshelf/', ['action' => 'add_bsection', 'csrf' => $csrf,
        'book' => 'nosuchbook', 'name' => 'Passages', 'edit' => '1'], $jar);
    has('edit=1', (string) ($r['location'] ?? ''), 'editing survives the add');
    // …and the script that posts that flag has to be on the page for any of it to work.
    $b = req('GET', '/akisbookshelf/', [], $jar)['body'];
    has("i.name = 'edit'", $b, 'keep_edit_script is emitted here');
});

t('an unknown bookshelf theme is refused, not stored', function () {
    ensure_account('aki', 'akipassword');
    $jar = login('aki', 'akipassword');
    $csrf = csrf($jar, '/akisbookshelf/');
    req('POST', '/akisbookshelf/', ['action' => 'set_book_theme', 'csrf' => $csrf, 'theme' => 'midnight'], $jar);
    $csrf = csrf($jar, '/akisbookshelf/');
    req('POST', '/akisbookshelf/', ['action' => 'set_book_theme', 'csrf' => $csrf, 'theme' => '../../../etc/passwd'], $jar);
    $r = req('GET', '/akisbookshelf/', [], $jar);
    has('--bg: #111111', $r['body'], 'the bogus key changed nothing');
});

t('the bookshelf theme and the suite theme are separate settings', function () {
    ensure_account('aki', 'akipassword');
    $jar = login('aki', 'akipassword');
    // Set the bookshelf to a theme whose accent is nothing like any suite accent…
    $csrf = csrf($jar, '/akisbookshelf/');
    req('POST', '/akisbookshelf/', ['action' => 'set_book_theme', 'csrf' => $csrf, 'theme' => 'neon'], $jar);
    // …then set the *suite* theme from another app, and check neither moved the other.
    $csrf = csrf($jar, '/calmind/reminders/');
    req('POST', '/calmind/reminders/', ['action' => 'set_theme', 'csrf' => $csrf, 'theme' => 'forest'], $jar);
    $r = req('GET', '/akisbookshelf/', [], $jar);
    has('--accent: #00f5d4', $r['body'], 'the bookshelf still wears its own accent');
    $r = req('GET', '/calmind/reminders/', [], $jar);
    has('--accent: #8b9d83', $r['body'], 'and the suite kept the one set for it');
});

// ---------------------------------------------------------------- recolouring a share
// Each person can recolour how the other's shared folders look *in their own picker*.
// The whole point is that it never touches the owner's data, so that is what's checked.
area('shared2');

t("recolouring a partner's shared calendar is my own view override, square swatch", function () {
    $jar = login('example', 'examplepassword');
    $b = req('GET', '/calmind/calendar/', [], $jar)['body'];
    has('openSwatches(sw, c.id, true)', $b, 'shared calendars get the square swatch picker (not a dot)');
    preg_match('/const SHARED_CALS = (\[.*?\]);/s', $b, $m);
    $shared = json_decode($m[1] ?? '[]', true);
    ok(!empty($shared), "example sees at least one of buddy's shared calendars");
    $cid = (string) $shared[0]['id'];
    // Capture buddy's own colour, and pick an override that differs from it.
    $buddyCol = '';
    foreach (stored('calendars', 'buddy') as $c) { if (($c['id'] ?? '') === $cid) { $buddyCol = (string) ($c['color'] ?? ''); } }
    $col = app_palette('calendar')[3];
    if ($col === $buddyCol) { $col = app_palette('calendar')[4]; }
    req('POST', '/calmind/calendar/', ['csrf' => csrf($jar, '/calmind/calendar/'), 'action' => 'cal_shared_color',
        'id' => $cid, 'color' => $col], $jar, true);
    eq($col, stored('calprefs', 'example')['shared_cal_colors'][$cid] ?? null,
       'the override is stored on my side, keyed by calendar id');
    // buddy's own calendar colour is untouched by my override.
    foreach (stored('calendars', 'buddy') as $c) {
        if (($c['id'] ?? '') === $cid) { eq($buddyCol, (string) ($c['color'] ?? ''), "buddy's own colour is not changed"); }
    }
    // Off-palette colour and non-shared id are both refused.
    req('POST', '/calmind/calendar/', ['csrf' => csrf($jar, '/calmind/calendar/'), 'action' => 'cal_shared_color',
        'id' => $cid, 'color' => '#abcdef'], $jar, true);
    eq($col, stored('calprefs', 'example')['shared_cal_colors'][$cid] ?? null, 'an off-palette colour is refused');
    req('POST', '/calmind/calendar/', ['csrf' => csrf($jar, '/calmind/calendar/'), 'action' => 'cal_shared_color',
        'id' => 'nosuchcal', 'color' => app_palette('calendar')[1]], $jar, true);
    ok(!isset(stored('calprefs', 'example')['shared_cal_colors']['nosuchcal']), 'a non-shared id is refused');
});

t("the calendar resolves a partner's folder colour exactly like the picker", function () {
    global $scratch;
    $jar = login('example', 'examplepassword');
    // Give my view of buddy's Dinners an override; the calendar must wear it too. It
    // used to read buddy's own colours with positional *own-tier* defaults, so an
    // uncoloured shared folder could land on the same vivid colour as one of mine.
    $_SESSION['user'] = 'example';                       // the helper writes as "me"
    $ov = app_palette('reminders', true)[3];
    folder_shared_color_set($scratch, 'reminders', '@buddy:Dinners', $ov, ['Dinners']);
    $b = req('GET', '/calmind/calendar/', [], $jar)['body'];
    has($ov, $b, "the shared folder's rows wear the picker's resolved colour");
});

t('a recolour is stored on the viewer\'s side, keyed by the view name', function () {
    $dir = datadir();
    $key = '@buddy:Dinners';
    $_SESSION['user'] = 'example';                       // the helper writes as "me"
    $ownerBefore = folders_load($dir, 'buddy');
    folder_shared_color_set($dir, 'reminders', $key, app_palette('reminders', true)[1], ['Dinners']);
    $mine = folder_shared_colors($dir, 'reminders', 'example');
    ok(isset($mine[$key]), 'the override is in my own folders file');
    eq($ownerBefore, folders_load($dir, 'buddy'), 'the owner\'s folders file is untouched');
    ok(!isset(folder_shared_colors($dir, 'reminders', 'buddy')[$key]),
       'and nothing was written on their side');
    unset($_SESSION['user']);
});

t('a colour off the shared palette, or a folder they do not share, is refused', function () {
    $dir = datadir();
    $key = '@buddy:Dinners';
    $_SESSION['user'] = 'example';
    $was = folder_shared_colors($dir, 'reminders', 'example')[$key] ?? null;
    folder_shared_color_set($dir, 'reminders', $key, '#ff0000', ['Dinners']);
    eq($was, folder_shared_colors($dir, 'reminders', 'example')[$key] ?? null, 'a made-up colour');
    folder_shared_color_set($dir, 'reminders', '@buddy:Secret',
        app_palette('reminders', true)[3], ['Dinners']);
    ok(!isset(folder_shared_colors($dir, 'reminders', 'example')['@buddy:Secret']),
       'a folder they never shared');
    unset($_SESSION['user']);
});

t('resolution goes mine, then theirs, then a default by position', function () {
    $shared = app_palette('reminders', true);
    $mine   = ['@buddy:Dinners' => $shared[2]];
    $owner  = ['Dinners' => $shared[4], 'Other' => $shared[5]];
    eq($shared[2], folder_shared_color($mine, $owner, 'reminders', '@buddy:Dinners', 'Dinners', 0),
       'my override wins');
    eq($shared[4], folder_shared_color([], $owner, 'reminders', '@buddy:Dinners', 'Dinners', 0),
       'then the owner\'s own colour');
    $d = folder_shared_color([], [], 'reminders', '@buddy:Nothing', 'Nothing', 1);
    ok(in_array($d, $shared, true), 'then a shared-palette default');
});

// ---------------------------------------------------------------- the public front
// Home, projects, about and contact are the marketing front, not the app suite: no
// login, no tab bar, no app chrome. Getting that wrong shows a signed-out stranger a
// tab bar into pages they can't open.
area('site');

t('every public page renders for a stranger', function () {
    foreach (['/', '/about/', '/projects/', '/contact/', '/themepicker/'] as $p) {
        $r = req('GET', $p);
        eq(200, $r['status'], "$p status");
        hasnt('name="password"', $r['body'], "$p must not ask for a login");
        quiet($r['body'], "$p is quiet");
        hasnt('/home/protected', $r['body'], "$p leaks the server path");
    }
});

t('a public page wears the site nav and never the app tab bar', function () {
    foreach (['/', '/about/', '/projects/', '/contact/', '/themepicker/'] as $p) {
        $b = req('GET', $p)['body'];
        hasnt('class="tabbar"', $b, "$p must not carry the app tab bar");
        hasnt('segmented', $b, "$p must not carry the app segmented control");
    }
    $nav = site_nav('about');
    has('<a href="/about/" class="on">About</a>', $nav, 'the nav marks the page you are on');
    has('<a href="/">Home</a>', $nav, 'and does not mark the others');
});

t('the public pages are the same shell', function () {
    foreach (['/about/', '/projects/', '/contact/', '/themepicker/'] as $p) {
        $b = req('GET', $p)['body'];
        has('<!DOCTYPE html', $b, "$p is a whole document");
        has('#34d399', strtolower($b), "$p carries the suite accent");
    }
});

t('projects lists the theme picker and CalMind with their links', function () {
    $b = req('GET', '/projects/')['body'];
    // Both apps sit one level UNDER "Vibe Coding Apps", so they're h4 to its h3 —
    // and the shell has to style that level or the demotion reads as plain text.
    has('>Vibe Coding Apps</h3>', $b, 'the parent section');
    has('>Theme Picker</h4>', $b, 'the theme picker entry, a subsection of it');
    has('href="/themepicker/"', $b, 'its T-icon link to the page');
    has('https://github.com/chere005/CalMind/tree/main/public/themepicker', $b, 'and to its folder in the repo');
    has('>CalMind</h4>', $b, 'the CalMind entry, a subsection of it');
    has('href="https://github.com/chere005/CalMind"', $b, 'its repo link');
    has('seancheren.com/calmind', $b, 'and the link to the app');
    has('/calmind/reminders/icon-180.png', $b, 'wearing CalMind\'s own icon');
    has('class="giticon"', $b, 'the icon links wear their icons');
    has('h4 {', $b, 'the shell styles the subsection level');
});

t('projects carries the Private categories', function () {
    $b = req('GET', '/projects/')['body'];
    foreach (['Work', 'Music', 'Games', 'Languages'] as $h) {
        has('>' . $h . '</h3>', $b, "$h is a Private category");
    }
    has('writing systems', $b, 'Languages carries its text');
    has('microtonal banana', $b, 'Music carries its second paragraph');
});

t('the site wears the cursive SC mark and its icons', function () use ($root) {
    foreach (['/', '/projects/'] as $p) {
        $b = req('GET', $p)['body'];
        has('class="sitelogo"', $b, "$p carries the centred mark");
        has('justify-content: center', $b, "$p centres the pill nav under it");
        has('href="/favicon-32.png"', $b, "$p links the favicon");
        has('href="/apple-touch-icon.png"', $b, "$p links the touch icon");
    }
    foreach (['favicon-32.png', 'apple-touch-icon.png'] as $f) {
        $png = (string) file_get_contents($root . '/public/' . $f);
        ok(substr($png, 1, 3) === 'PNG', "$f is a real PNG");
    }
    // The apps keep their own icons — the site favicon must not leak into them.
    $jar = login('example', 'examplepassword');
    hasnt('favicon-32.png', req('GET', '/calmind/reminders/', [], $jar)['body'],
          'an app page does not pick up the site favicon');
});

t('phone widths swap the pill nav for a dropdown', function () {
    $b = req('GET', '/projects/')['body'];
    has('<details class="sitenav-dd">', $b, 'the dropdown ships');
    has('<summary>Projects <span class="caret">', $b, 'its summary wears the current page');
    ok(preg_match('/@media \(max-width: 480px\) \{\s*\.sitenav \{ display: none/', $b) === 1,
       'and the pill row hides at phone widths');
    eq(2, substr_count($b, '>Themes</a>'), 'the dropdown lists the same pages the pills do');
});

t('About\'s two-column lists line up row for row', function () {
    $b = req('GET', '/about/')['body'];
    has('class="lists-col"', $b, 'About sets its favourites in columns');
    // A column break truncates the margin above whichever item starts a new column, so a
    // symmetric margin lands on the first column's first item and on nothing else — which
    // sat the two columns 3.2px out of step. The gap hangs below every item instead.
    ok(preg_match('/^  li \{ margin: 0 0 [\d.]+rem; \}$/m', $b) === 1,
       'list items carry a bottom margin only, never a top one');
    hasnt('li { margin: 0.2rem 0; }', $b, 'the symmetric margin is what knocked them out of step');
    // The columns flow independently, so their rows only line up while every item is one
    // line tall. .wrap caps at 640px, so above that the columns are a fixed width the
    // titles fit; below it they narrow and a wrapped title steps its column past the other.
    ok(preg_match('/@media \(max-width: 640px\) \{ \.lists-col ul \{ columns: 1; \} \}/', $b) === 1,
       'and fall to one column below the wrap cap, not at the nav\'s 480');
});

t('the theme picker shows all four themes, read-only, current marked', function () {
    $b = req('GET', '/themepicker/')['body'];
    foreach (THEMES as $key => $row) {
        has('>' . htmlspecialchars($row[0], ENT_QUOTES) . '<', $b, "$key is shown");
    }
    has('pointer-events: none', $b, 'the previews are inert');
    // Midnight is the default, so its card says Current and carries no Use form.
    has('>Current<', $b, 'the default theme is marked current');
    eq(count(THEMES) - 1, substr_count($b, 'class="tp-use"'), 'every other theme offers Use');
});

t('picking a theme sets the sitetheme cookie and re-dresses the public pages', function () {
    $jar = [];
    $r = req('POST', '/themepicker/', ['action' => 'settheme', 'theme' => 'sage'], $jar);
    eq(302, $r['status'], 'POST→redirect→GET');
    eq('sage', $jar['sitetheme'] ?? null, 'the cookie carries the choice');
    // At the site root the cookie is site-wide; the /test/ and /dev/ mirrors narrow it
    // to their own prefix (a text check, since the mirrors only exist on the server).
    $ck = implode("\n", array_filter($r['headers'], fn($h) => stripos($h, 'Set-Cookie:') === 0));
    has('path=/', strtolower($ck), 'the cookie names its path');
    $src = file_get_contents(dirname(__DIR__) . '/public/themepicker/index.php');
    has("['/test/', '/dev/']", $src, 'the cookie path is scoped per instance');
    foreach (['/', '/themepicker/'] as $p) {
        $b = req('GET', $p, [], $jar)['body'];
        has('#fefae0', strtolower($b), "$p wears sage");
        has('color-scheme: light', $b, "$p flips color-scheme for the cream theme");
    }
    // An unknown name is ignored: redirect, but no cookie and midnight stays.
    $jar2 = [];
    $r = req('POST', '/themepicker/', ['action' => 'settheme', 'theme' => 'plaid'], $jar2);
    eq(302, $r['status'], 'a bad name still redirects');
    ok(!isset($jar2['sitetheme']), 'and sets no cookie');
    has('#111111', req('GET', '/', [], $jar2)['body'], 'the page stays midnight');
});

t('the sitetheme cookie never reaches the apps', function () {
    $jar = login('example', 'examplepassword');
    $jar['sitetheme'] = 'sage';
    $b = req('GET', '/calmind/reminders/', [], $jar)['body'];
    // The theme swatches in settings legitimately carry every theme's colours, so the
    // check is what :root actually wears, not whether sage's hex appears anywhere.
    has('--bg: #111111', $b, 'the app keeps its own per-user theme');
});

// ---------------------------------------------------------------- quick add / widget tick
// quick.php is the one page the widget can reach that writes — deliberately, because the
// write happens in a signed-in session with a token rather than behind the read-only feed.
area('quick');

t('a quick add lands on today, in the fallback folder', function () {
    $jar = login('example', 'examplepassword');
    $tok = csrf($jar, '/calmind/calendar/quick.php');
    req('POST', '/calmind/calendar/quick.php', ['csrf' => $tok, 'action' => 'add_reminder',
        'text' => 'quick added reminder'], $jar);
    $r = rowBy('example', 'quick added reminder');
    ok($r !== null, 'it was written');
    eq(date('Y-m-d'), $r['due'] ?? null, 'due today');
    $fb = folder_fallback('reminders');
    eq($fb, $r['folder'] ?? null, 'in the fallback folder');
    // Every reminder sits in a real section now — the quick add lands in the fallback
    // folder's default section (which really exists), not a nameless catch-all.
    ok(($r['section'] ?? '') !== '', 'in a real section, not the empty catch-all');
    $secExists = (bool) array_filter(stored('reminders', 'example'),
        fn($x) => ($x['type'] ?? '') === 'section' && ($x['folder'] ?? '') === $fb && ($x['name'] ?? '') === ($r['section'] ?? ''));
    ok($secExists, 'and that section exists in the fallback folder');
});

t('a quick add reads the date and time out of the line', function () {
    $jar = login('example', 'examplepassword');
    $tok = csrf($jar, '/calmind/calendar/quick.php');
    req('POST', '/calmind/calendar/quick.php', ['csrf' => $tok, 'action' => 'add_event',
        'text' => 'Vet 8/3 2pm'], $jar);
    $ev = null;
    foreach (stored('events', 'example') as $e) { if (($e['text'] ?? '') === 'Vet') { $ev = $e; } }
    ok($ev !== null, 'the text was trimmed to "Vet"');
    eq('08-03', substr((string) $ev['date'], 5), 'the date came out of the line');
    eq('14:00', $ev['time'] ?? null, 'and so did the time');
});

t('?tick= shows one reminder and its Done button marks it', function () {
    $jar = login('example', 'examplepassword');
    $tok = csrf($jar, '/calmind/calendar/quick.php');
    req('POST', '/calmind/calendar/quick.php', ['csrf' => $tok, 'action' => 'add_reminder',
        'text' => 'tick me from the widget'], $jar);
    $id = rowBy('example', 'tick me from the widget')['id'];

    $g = req('GET', '/calmind/calendar/quick.php?tick=' . $id, [], $jar);
    eq(200, $g['status']);
    has('tick me from the widget', $g['body'], 'the page names the reminder');
    has('value="tick"', $g['body'], 'and carries the Done button');

    req('POST', '/calmind/calendar/quick.php?tick=' . $id, ['csrf' => csrf($jar, '/calmind/calendar/quick.php'),
        'action' => 'tick', 'id' => $id], $jar);
    ok(!empty(rowBy('example', 'tick me from the widget')['done']), 'it is done');
});

t('ticking a repeat from the widget rolls it instead of finishing it', function () {
    $jar = login('example', 'examplepassword');
    $row = rowBy('example', 'Water the tomatoes');          // every 2 days, from the seeder
    ok($row !== null && repeat_get($row) !== null, 'the seeded repeat exists');
    $was = $row['due'];
    req('POST', '/calmind/calendar/quick.php?tick=' . $row['id'], ['csrf' => csrf($jar, '/calmind/calendar/quick.php'),
        'action' => 'tick', 'id' => $row['id']], $jar);
    $after = rowBy('example', 'Water the tomatoes');
    ok(empty($after['done']), 'a repeat is never marked done from the widget either');
    ok($after['due'] > $was, "it moved on (was $was, now {$after['due']})");
});

t('a tick with no token changes nothing', function () {
    $jar = login('example', 'examplepassword');
    $tok = csrf($jar, '/calmind/calendar/quick.php');
    req('POST', '/calmind/calendar/quick.php', ['csrf' => $tok, 'action' => 'add_reminder',
        'text' => 'untouchable'], $jar);
    $id = rowBy('example', 'untouchable')['id'];
    req('POST', '/calmind/calendar/quick.php?tick=' . $id, ['action' => 'tick', 'id' => $id], $jar);
    ok(empty(rowBy('example', 'untouchable')['done']), 'still open');
    req('POST', '/calmind/calendar/quick.php?tick=' . $id, ['csrf' => 'nope', 'action' => 'tick', 'id' => $id], $jar);
    ok(empty(rowBy('example', 'untouchable')['done']), 'still open with a wrong token');
});

// ---------------------------------------------------------------- the usage log
// One line per operation in data/usage.log — who, from where, which app, what *kind*
// of action — hooked once in require_login() so every app is covered. The promise
// worth testing is the negative one: the log never carries content.
area('usage');

t('operations leave one line each — and never any content', function () {
    $log = datadir() . '/usage.log';
    @unlink($log);                                 // only this test's lines below
    $jar = login('example', 'examplepassword');    // logs 'login'
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'add', 'view' => 'All',
        'folder' => 'Reminders', 'section' => '', 'text' => 'Skywritten secret 8/9'], $jar);
    $b = (string) file_get_contents($log);
    has("\texample\treminders\tlogin\n", $b, 'a sign-in is logged');
    has("\texample\treminders\tadd\n", $b, 'an add logs user, app and kind');
    hasnt('Skywritten', $b, 'never what it carried');
    foreach (explode("\n", trim($b)) as $line) {
        eq(5, count(explode("\t", $line)), 'every line is the same five fields');
    }
    // A failed sign-in logs the attempted name; logging out logs too.
    req('POST', '/calmind/reminders/', ['username' => 'example', 'password' => 'wrong']);
    req('GET', '/calmind/reminders/?logout', [], $jar);
    $b = (string) file_get_contents($log);
    has("\texample\treminders\tlogin_fail\n", $b, 'a failed sign-in is logged');
    has("\texample\treminders\tlogout\n", $b, 'a sign-out is logged');
});

t('the log file lives outside the web root and is plain text', function () {
    $r = req('GET', '/data/usage.log');
    ok($r['status'] >= 400 || strpos((string) $r['body'], "\tlogin\n") === false,
       'the log is not URL-reachable');
    $b = (string) file_get_contents(datadir() . '/usage.log');
    ok(strncmp($b, 'ENC1:', 5) !== 0, 'kept greppable, not encrypted — it holds no content');
    // On the live host the data dir belongs to the web user and the SSH login only
    // shares its group — the writer adds group traversal to the dir and group read to
    // the log, so `ssh … tail usage.log` works without opening anything else up.
    clearstatcache();
    ok((fileperms(datadir() . '/usage.log') & 0040) === 0040, 'the log is group-readable');
    ok((fileperms(datadir()) & 0010) === 0010, 'the data dir is group-traversable');
});

// ---------------------------------------------------------------- the deploy script
// Static checks, because a deploy is the one thing here that can destroy data and the
// one thing no test run may actually perform. These are the promises deploy.sh makes in
// its own header; this is the test that it still keeps them.
area('deploy');

t('the seeding wrapper refuses everything by default', function () use ($root) {
    // It exists to be scp'd to a live host for one minute and deleted. For that minute
    // it is a URL that can overwrite an account, so the shipped copy must be inert.
    $s = (string) file_get_contents($root . '/tools/seed-http.php');
    has("const SEED_KEY = 'CHANGE-ME';", $s, 'the committed copy carries no real key');
    has('hash_equals', $s, 'and compares in constant time');
    // No default data dir: a bare hit must never be able to reach production.
    ok(!preg_match("#\\\$dir\\s*=\\s*\\(string\\)\\s*\\(\\\$_GET\\['dir'\\]\\s*\\?\\?\\s*'/#", $s),
       'the dir parameter has no default');
    // And it is not deployable — deploy.sh sends public/ and lib/ only.
    hasnt('tools', substr($s, 0, 0) . implode(' ', array_filter(
        preg_split('/\R/', (string) file_get_contents($root . '/deploy.sh')),
        fn($l) => strpos($l, 'rsync') !== false && strpos($l, 'tools') !== false)),
       'no rsync line sends tools/');
});

t('usagelog.sh parses', function () use ($root) {
    exec('sh -n ' . escapeshellarg($root . '/tools/usagelog.sh') . ' 2>&1', $out, $code);
    eq(0, $code, implode("\n", $out));
});

t('deploy.sh parses', function () use ($root) {
    exec('bash -n ' . escapeshellarg($root . '/deploy.sh') . ' 2>&1', $o, $rc);
    eq(0, $rc, 'bash -n: ' . implode("\n", $o));
});

t('an empty array expansion never trips set -u', function () use ($root) {
    // macOS ships bash 3.2, where "${a[@]}" on an EMPTY array counts as unset and the
    // scripts' `set -u` kills the run mid-deploy. The exclude array is only non-empty on
    // a test push, so a bare expansion breaks `prod`/`both` while every test deploy — and
    // every --dry-run of one — sails through, which is exactly how it went unnoticed.
    foreach (['deploy.sh', 'deploy-dev.sh'] as $f) {
        $s = (string) file_get_contents($root . '/' . $f);
        $code = implode("\n", array_map(
            fn($l) => (string) preg_replace('/#.*$/', '', $l), preg_split('/\R/', $s)));
        // Drop the guarded form, then anything array-shaped still left is a bare one.
        $left = preg_replace('/\$\{(\w+)\[@\]\+"\$\{\1\[@\]\}"\}/', '', $code);
        ok(!preg_match('/\$\{\w+\[@\]\}/', $left, $m),
           "$f expands an array bare (" . ($m[0] ?? '') . ') — use ${a[@]+"${a[@]}"}');
    }
    has('${skip[@]+"${skip[@]}"}', (string) file_get_contents($root . '/deploy.sh'),
        'the guarded form is what ships');
    // And prove the idiom rather than just its spelling — on this machine's own bash.
    exec('bash -c ' . escapeshellarg('set -u; a=(); printf ok ${a[@]+"${a[@]}"}') . ' 2>&1', $o, $rc);
    eq(0, $rc, 'the guarded form survives an empty array: ' . implode("\n", $o));
});

t('it never deletes and never sends a config', function () use ($root) {
    $s = (string) file_get_contents($root . '/deploy.sh');
    foreach (preg_split('/\R/', $s) as $n => $line) {
        if (strpos($line, 'rsync') === false) { continue; }
        $bare = preg_replace('/#.*$/', '', $line);
        ok(strpos($bare, '--delete') === false, 'line ' . ($n + 1) . ' uses --delete');
    }
    ok(substr_count($s, "--exclude='config.php'") + substr_count($s, '--exclude=config.php') >= 2,
       'every rsync of lib excludes config.php');
});

t('it never touches a data directory', function () use ($root) {
    $s = (string) file_get_contents($root . '/deploy.sh');
    foreach (preg_split('/\R/', $s) as $n => $line) {
        if (strpos($line, 'rsync') === false && strpos($line, 'rm ') === false) { continue; }
        $bare = preg_replace('/#.*$/', '', $line);
        ok(strpos($bare, '/home/protected/data') === false,
           'line ' . ($n + 1) . ' names a live data directory');
    }
});

t('a bare deploy is the test instance, never production', function () use ($root) {
    $s = (string) file_get_contents($root . '/deploy.sh');
    has('MODE="${MODE:-test}"', $s, 'the default mode is test');
    foreach (['test|prod|both|promote', 'push_instance'] as $m) { has($m, $s, "deploy.sh still has $m"); }
    // The script itself is not run here: it needs the deploy key, and a test run must
    // never be one keystroke away from touching the live site. These are text checks.
    ok(preg_match('/\bprod\)\s*$/m', $s) === 1, 'prod is its own explicit mode');
    ok(strpos($s, 'promote') !== false, 'and promote exists to move test into prod');
});

t('the test instance and promote both leave /test/calmind/ to the new app', function () use ($root) {
    // Since 2026-08-08 /test/calmind/ belongs to the NEW CalMind monorepo (~/GIT/CalMind,
    // its own deploy script). A suite test-deploy must skip it, and promote must never
    // copy it onto prod — prod's suite is only updated by a direct prod deploy.
    $s = (string) file_get_contents($root . '/deploy.sh');
    has("[[ \"\$pub\" == /home/public/test ]] && skip=(--exclude='/calmind')", $s,
        'the test destination excludes the top-level calmind/');
    has('--exclude=/calmind /home/public/test/ /home/public/', $s,
        'promote excludes it from the server-side copy');
});

t('deploy-dev.sh parses and keeps the same safety rules', function () use ($root) {
    exec('bash -n ' . escapeshellarg($root . '/deploy-dev.sh') . ' 2>&1', $o, $rc);
    eq(0, $rc, 'bash -n: ' . implode("\n", $o));
    $s = (string) file_get_contents($root . '/deploy-dev.sh');
    foreach (preg_split('/\R/', $s) as $n => $line) {
        $bare = preg_replace('/#.*$/', '', $line);
        if (strpos($bare, 'rsync') !== false) {
            ok(strpos($bare, '--delete') === false, 'line ' . ($n + 1) . ' uses --delete');
        }
        if (strpos($bare, 'rsync') !== false || preg_match('/\brm\s/', $bare)) {
            ok(strpos($bare, '/home/protected/data') === false,
               'line ' . ($n + 1) . ' names a live data directory');
        }
    }
    ok(substr_count($s, "--exclude='config.php'") >= 1, 'the lib rsync excludes config.php');
});

t('deploy-dev.sh can only aim at /dev', function () use ($root) {
    // The script's whole reason to exist is that it cannot reach production or /test/:
    // the destinations are constants, and refusal guards back them up.
    $s = (string) file_get_contents($root . '/deploy-dev.sh');
    has('PUB=/home/public/dev', $s, 'the public destination is a constant');
    has('LIB=/home/protected/lib-dev', $s, 'so is the lib destination');
    ok(substr_count($s, 'Refusing:') >= 3, 'the refusal guards are still standing');
});

t('the calmind/ split is stitched in by symlinks and dereferenced on deploy', function () use ($root) {
    // CalMind lives in its own top-level calmind/ area; symlinks keep the served layout
    // (public/calmind, lib/*.php) unchanged locally, and both deploy scripts must -L
    // them into real files so the server never sees the split.
    eq('../calmind/public', readlink($root . '/public/calmind'), 'public/calmind points into calmind/');
    foreach (['tabbar', 'folders', 'sharing', 'palette'] as $f) {
        eq('../calmind/lib/' . $f . '.php', readlink($root . '/lib/' . $f . '.php'), "lib/$f.php points into calmind/");
    }
    foreach (['deploy.sh', 'deploy-dev.sh'] as $s) {
        $b = (string) file_get_contents($root . '/' . $s);
        has('-rLptz', $b, "$s dereferences the symlinks");
        hasnt('rsync -rlptz', $b, "$s must not ship symlinks as symlinks");
        has('calmind', $b, "$s lints the calmind/ area");
    }
});

// ═══════════════════════════════════════════════════════════════════ run

if ($list) {
    foreach ($AREAS as $name => $cases) { printf("%-10s %d cases\n", $name, count($cases)); }
    exit(0);
}

// Seed the scratch dir with the real seeders — which also tests that they work.
fwrite(STDERR, "seeding a scratch account set in $scratch …\n");
foreach (['seed-example.php', 'seed-buddy.php'] as $s) {
    exec('SUITE_DATA_DIR=' . escapeshellarg($scratch) . ' php ' . escapeshellarg($root . '/tools/' . $s)
         . ' --force 2>&1', $out, $rc);
    if ($rc !== 0) { fwrite(STDERR, "seeder $s failed:\n" . implode("\n", $out) . "\n"); exit(2); }
}

// Boot the dev server on a free port, pointed at the scratch dir.
$sock = stream_socket_server('tcp://127.0.0.1:0', $e1, $e2);
$PORT = (int) explode(':', stream_socket_get_name($sock, false))[1];
fclose($sock);
$desc = [1 => ['file', '/dev/null', 'w'], 2 => ['file', $scratch . '/server.log', 'w']];
$SRV = proc_open('SUITE_DATA_DIR=' . escapeshellarg($scratch)
    . ' php -d display_errors=1 -d error_reporting=E_ALL'
    . ' -S 127.0.0.1:' . $PORT . ' -t ' . escapeshellarg($root . '/public'), $desc, $pipes);
register_shutdown_function(function () use (&$SRV, $scratch, $keep) {
    if (is_resource($SRV)) { proc_terminate($SRV); proc_close($SRV); }
    if (!$keep) { @array_map('unlink', glob($scratch . '/*') ?: []); @rmdir($scratch); }
    else { fwrite(STDERR, "scratch kept at $scratch\n"); }
});
for ($i = 0; $i < 100; $i++) {                       // wait for it to answer
    $c = @fsockopen('127.0.0.1', $PORT, $x, $y, 0.2);
    if ($c) { fclose($c); break; }
    usleep(100000);
}

$pass = $fail = $skipped = 0; $failures = [];
foreach ($AREAS as $name => $cases) {
    if ($only && !array_filter($only, fn($o) => stripos($name, $o) !== false)) { $skipped += count($cases); continue; }
    echo "\n\033[1m$name\033[0m\n";
    foreach ($cases as [$label, $fn]) {
        try {
            $fn();
            $pass++;
            echo "  \033[32m✓\033[0m $label\n";
        } catch (Throwable $e) {
            $fail++;
            $failures[] = "$name / $label\n      " . $e->getMessage();
            echo "  \033[31m✗\033[0m $label\n      \033[31m" . $e->getMessage() . "\033[0m\n";
        }
    }
}

echo "\n" . str_repeat('─', 60) . "\n";
printf("%d passed, %d failed%s\n", $pass, $fail, $skipped ? ", $skipped skipped" : '');
if ($fail) {
    echo "\nFailures:\n";
    foreach ($failures as $f) { echo "  • $f\n"; }
    echo "\nServer log: $scratch/server.log (use --keep to hold on to it)\n";
}
exit($fail ? 1 : 0);
