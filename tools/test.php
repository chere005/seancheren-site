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
// The themes bench gates on aki and sean in production (2026-08-23). This page
// stands in for "any page behind the login" throughout this suite, so a scratch
// run names its own testers — and the gate itself is proven below, against a
// user who is on nobody's list.
putenv('SUITE_THEMES_USERS=*');   // any signed-in account, in scratch only
@mkdir($scratch, 0700, true);

require_once $root . '/lib/auth.php';
require_once $root . '/lib/richtext.php';
require_once $root . '/lib/site.php';
// geoip.php gives hit_usage() its datacenter map (the bots lane). The status
// page always loads it before using the hit log; the unit checks must too, or
// dc_all() is absent and every request looks like a person.
require_once $root . '/lib/geoip.php';

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
function req(string $method, string $path, array $post = [], ?array &$jar = null, bool $ajax = false, array $extraHeaders = []): array
{
    global $PORT;
    $headers = ["Host: 127.0.0.1:$PORT", 'Connection: close'];
    foreach ($extraHeaders as $h) { $headers[] = $h; }
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
    // The themes workbench, because it is the surviving page that requires a login
    // without gating on a particular name. It used to be the suite's Reminders app.
    req('GET', '/akisthemes/', [], $jar);                              // pick up a session cookie
    $r = req('POST', '/akisthemes/', ['username' => $user, 'password' => $pass], $jar);
    if ($r['status'] !== 302) {
        throw new RuntimeException("login as $user did not redirect (status {$r['status']})");
    }
    return $jar;
}

/** The CSRF token the app would have put in the page. */
function csrf(array $jar, string $path = '/akisthemes/'): string
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


/** htmlspecialchars, for asserting on rendered text. */
function e_test(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }


// ═══════════════════════════════════════════════════════════════════ THE TESTS
// Each area matches a heading in TESTING.md. Keep the two in step.
area('test-instance');



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



t('an instance marker matches a directory that ENDS in the slug, not only one inside it',
  function () use ($root) {
    // The preamble used to test strpos(__DIR__, '/test/'), which needs a trailing
    // slash. The instance's own top-level page is /home/public/test/index.php, whose
    // __DIR__ is /home/public/test — no trailing slash, no match. That one page loaded
    // production's lib and, through it, production's DATA, while every page one level
    // down was correctly isolated. It went unseen because nobody opens a sandbox to
    // look at its home page.
    $m = fn(string $dir, string $slug) => preg_match('#/' . $slug . '(/|$)#', $dir) === 1;
    ok($m('/home/public/test', 'test'), "the sandbox's own directory matches");
    ok($m('/home/public/test/about', 'test'), 'and so does a page inside it');
    ok(!$m('/home/public', 'test'), 'production does not');
    ok(!$m('/home/public/akisthemes', 'test'), 'nor does an unrelated page');

    // And the host, which is the only signal left once .htaccess routes the sandboxes
    // as subdomains: test.seancheren.com/X is rewritten to /test/X internally, so
    // REQUEST_URI never says /test/ there either.
    foreach (glob($root . '/public/*/index.php') ?: [] as $f) {
        $b = (string) file_get_contents($f);
        if (strpos($b, '$__test') === false) { continue; }   // no preamble, no promise
        $rel = substr($f, strlen($root) + 1);
        has("preg_match('#/test(/|$)#', __DIR__)", $b, "$rel matches the slug at the end");
        has("strncmp(\$__host, 'test.', 5) === 0", $b, "$rel knows the test subdomain");

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
    foreach (['/akisthemes/', '/akisthemes/', '/akisthemes/', '/akisthemes/', '/akisthemes/'] as $p) {
        $r = req('GET', $p);
        eq(200, $r['status'], "$p status");
        has('Sign in', $r['body'], "$p should show the login form");
        has('CalMind</h1>', $r['body'], "$p wears the suite's name on the card");
        hasnt('rlist-root', $r['body'], "$p must not leak the app");
    }
});



t('a wrong password is refused', function () {
    $jar = [];
    req('GET', '/akisthemes/', [], $jar);
    $r = req('POST', '/akisthemes/', ['username' => 'example', 'password' => 'nope'], $jar);
    eq(200, $r['status'], 'no redirect');
    has('Invalid username or password', $r['body']);
});

t('a good password lands you back on the page you signed in from', function () {
    // '/' was the landing until 2026-08-31, a leftover from when the login had
    // one front door. Signing in ON /status and landing on home read as a
    // broken page; every guarded page now gets you back to itself.
    foreach (['/akisthemes/', '/akisbookshelf/', '/status/'] as $from) {
        $jar = [];
        req('GET', $from, [], $jar);
        $r = req('POST', $from, ['username' => 'example', 'password' => 'examplepassword'], $jar);
        eq(302, $r['status'], "$from status");
        eq($from, $r['location'], "signing in from $from returns to it");
    }
});

t('the login page draws no scrollbar', function () {
    $r = req('GET', '/akisthemes/');
    has('100svh', $r['body'], 'sized to the small viewport');
    has('scrollbar-width: none', $r['body']);
});

t('logging out ends the session', function () {
    $jar = login('example', 'examplepassword');
    req('GET', '/akisthemes/?logout=1', [], $jar);
    $r = req('GET', '/akisthemes/', [], $jar);
    has('Sign in', $r['body'], 'should be signed out again');
});

t('a POST with no CSRF token is refused', function () {
    $jar = login('example', 'examplepassword');
    $r = req('POST', '/akisthemes/', ['action' => 'add', 'name' => 'csrfless'], $jar);
    eq(400, $r['status']);
    hasnt('csrfless', json_encode(stored('palettes', 'example')), 'nothing should have been written');
});

t('a POST with the wrong CSRF token is refused', function () {
    $jar = login('example', 'examplepassword');
    $r = req('POST', '/akisthemes/', ['csrf' => 'not-the-token', 'action' => 'add', 'name' => 'badcsrf'], $jar);
    eq(400, $r['status']);
    hasnt('badcsrf', json_encode(stored('palettes', 'example')), 'nothing should have been written');
});


// ---------------------------------------------------------------- 3. storage
area('storage');

t('data is encrypted at rest', function () use ($scratch) {
    // buddy, not example: the themes area counts example's palettes, and a test that
    // adds one to that account fails a later one for a reason nobody would look for.
    $jar = login('buddy', 'buddypassword');
    req('POST', '/akisthemes/', ['csrf' => csrf($jar), 'action' => 'add', 'name' => 'Ciphertext check'], $jar);
    $raw = file_get_contents(user_data_file($scratch, 'palettes', 'buddy'));
    eq('ENC1:', substr($raw, 0, 5), 'files carry the ENC1 prefix');
    hasnt('Ciphertext check', $raw, 'plaintext must not be readable in the file');
    has('Ciphertext check', json_encode(stored('palettes', 'buddy')), 'and it still reads back');
});

t('legacy plaintext JSON still reads', function () use ($scratch) {
    $f = $scratch . '/legacy-test.json';
    file_put_contents($f, json_encode([['id' => 'x', 'text' => 'old row']]));
    $got = store_read($f);
    eq('old row', $got[0]['text'] ?? null, 'plaintext should be accepted');
});

t('a user only ever reads their own file', function () use ($scratch) {
    eq($scratch . '/palettes-buddy.json', user_data_file($scratch, 'palettes', 'buddy'));
    $jar = login('buddy', 'buddypassword');
    req('POST', '/akisthemes/', ['csrf' => csrf($jar), 'action' => 'add', 'name' => 'Buddys own'], $jar);
    has('Buddys own', json_encode(stored('palettes', 'buddy')), 'buddy has their own palettes');
    hasnt('Buddys own', json_encode(stored('palettes', 'example')), "and they are not in example's");
});
area('lib');












t('escaping is applied on output', function () {
    ensure_account('escaper', 'escaperpassword');
    $jar = login('escaper', 'escaperpassword');
    req('POST', '/akisthemes/', ['csrf' => csrf($jar), 'action' => 'add',
        'name' => '<script>alert(1)</script>'], $jar);
    $r = req('GET', '/akisthemes/', [], $jar);
    hasnt('<script>alert(1)</script>', $r['body'], 'the raw tag never reaches the page');
    has('&lt;script&gt;', $r['body'], 'it is escaped instead');
});

// ---------------------------------------------------------------- 13. every page renders
area('pages');


t('the public pages need no login', function () {
    foreach (['/', '/about/', '/projects/', '/contact/', '/themepicker/', '/chat/'] as $p) {
        $r = req('GET', $p);
        eq(200, $r['status'], "$p status");
        hasnt('Fatal error', $r['body'], $p);
    }
});

area('security');

/** Every mutating action, by the page that answers it. Add to this when you add one.
 *  This used to be five suite pages; they went with the suite on 2026-08-22, and what
 *  is left is what still answers a POST. */
function ALL_ACTIONS(): array
{
    return [
        '/akisthemes/' => ['add', 'rename', 'delete', 'set_color', 'change_password', 'set_theme'],
        '/akisbookshelf/' => ['add_book', 'delete_book', 'set_rating', 'set_cover', 'set_book_theme',
                              'add_bsection', 'delete_bsection', 'add_chapter', 'add_note',
                              'save_note', 'delete_note', 'reorder_notes',
                              'change_password', 'set_theme'],
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
    foreach (['palettes', 'prefs', 'books', 'booknotes'] as $b) {
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
    ok($checked >= 18, "swept $checked actions");
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


t("one user cannot reach another user's file by asking for it", function () {
    // The suite's shared-folder view keys (@buddy:Folder) were the way in and went with
    // it. What is left is the plainer promise underneath: a signed-in user's writes only
    // ever reach their own file, whatever the request says.
    ensure_account('prowler', 'prowlerpassword');
    $jar = login('prowler', 'prowlerpassword');
    $before = snapshot('buddy');
    req('POST', '/akisthemes/', ['csrf' => csrf($jar), 'action' => 'add',
        'name' => 'should not land', 'user' => 'buddy'], $jar);
    eq($before, snapshot('buddy'), "buddy's file is untouched by a request naming them");
    hasnt('should not land', json_encode(stored('palettes', 'buddy')));
});

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
    hreq($port, 'GET', $pfx . '/akisthemes/', [], $jar);
    $r = hreq($port, 'POST', $pfx . '/akisthemes/', ['username' => $user, 'password' => $pass], $jar);
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
        $r = hreq($p, 'GET', ($pfx ?: '') . '/akisthemes/');
        eq(200, $r['status'], "$what should answer");
        has('Sign in', $r['body'], "$what should show the login form");
        quiet($r['body'], "$what is quiet");
    }
});


t('signing in lands you back on the page that asked, in its own instance', function () {
    ['port' => $p] = instance_boot();
    // Landing changed 2026-08-31: '/' as a fixed landing made signing in ON
    // /status read as a redirect-to-nowhere. You now return to the guarded
    // page itself — which also proves the instance never leaks, since the
    // page's own path carries the prefix.
    [, $t] = instance_login($p, '/test');
    eq('/test/akisthemes/', $t['location'], 'the sandbox login lands on the sandbox page that asked');
    [, $r] = instance_login($p, '');
    eq('/akisthemes/', $r['location'], 'production lands on the page that asked');
});



t('every page under /test/ loads lib-test, not lib', function () {
    ['port' => $p] = instance_boot();
    [$jar] = instance_login($p, '/test');
    // If a page's preamble were missed out, it would load lib/ — whose config has no
    // base — and its links would come out unprefixed while everything else looked fine.
    // A page whose preamble were missed out would load lib/ — whose config has no base —
    // and log out to the wrong instance while everything else looked fine.
    foreach (['/test/akisthemes/', '/test/akisbookshelf/', '/test/status/'] as $path) {
        $r = hreq($p, 'GET', $path, [], $jar);
        ok($r['status'] === 200 || $r['status'] === 403, "$path answers ($path is gated by name)");
        quiet($r['body'], "$path is quiet");
    }
    // The instance's OWN top-level page is the one the old __DIR__ check missed:
    // /home/public/test/index.php sits in a directory ending '/test', with no trailing
    // slash, so the sandbox home loaded production's lib and production's DATA while
    // every page one level down was correctly isolated.
    $r = hreq($p, 'GET', '/test/', [], $jar);
    eq(200, $r['status'], 'the sandbox home renders');
    quiet($r['body'], 'the sandbox home is quiet');
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

t('the create-account window no longer warns, because it no longer has to', function () {
    // It used to say "not encrypted at this time during development — don't use a
    // real password", and that was the honest thing to print while sign-up stored
    // the password as typed. Storage hashes now (auth_password_set), so the warning
    // would be a lie, and a stale warning about passwords is worse than none: it
    // teaches people to ignore the next one.
    $b = req('GET', '/akisthemes/')['body'];
    ok(strpos($b, 'not encrypted at this time') === false, 'the warning is gone');
    ok(strpos($b, "don't use a real password") === false, 'and so is its instruction');
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
        req('GET', '/akisthemes/', [], $jar);
        $r = req('POST', '/akisthemes/', ['action' => 'signup', 'newuser' => $u,
            'email' => $em, 'newpass' => $pw], $jar);
        eq(200, $r['status'], "$why: no redirect");
        $acc = store_read($scratch . '/accounts.json');
        ok(!isset($acc[$u]) || $u === 'example', "$why must not create an account");
    }
});

t('a good sign-up parks the account rather than creating it', function () use ($scratch) {
    $jar = [];
    req('GET', '/akisthemes/', [], $jar);
    $r = req('POST', '/akisthemes/', ['action' => 'signup', 'newuser' => 'newbie',
        'email' => 'newbie@example.com', 'newpass' => 'newbiepass'], $jar);
    eq(200, $r['status'], 'the code window opens in place');
    $pending = store_read($scratch . '/signups.json');
    ok(isset($pending['newbie']), 'it is waiting in signups.json');
    eq('newbie@example.com', $pending['newbie']['email'] ?? null);
    ok(!isset(store_read($scratch . '/accounts.json')['newbie']), 'and is NOT an account yet');
    // Nor can it sign in while it's only pending.
    $j2 = [];
    req('GET', '/akisthemes/', [], $j2);
    $s = req('POST', '/akisthemes/', ['username' => 'newbie', 'password' => 'newbiepass'], $j2);
    eq(200, $s['status'], 'a pending account cannot sign in');
});

t('a wrong code is counted and the fifth one ends the sign-up', function () use ($scratch) {
    $jar = [];
    req('GET', '/akisthemes/', [], $jar);
    req('POST', '/akisthemes/', ['action' => 'signup', 'newuser' => 'doomed',
        'email' => 'doomed@example.com', 'newpass' => 'doomedpass'], $jar);
    for ($i = 0; $i < 5; $i++) {
        req('POST', '/akisthemes/', ['action' => 'verify', 'newuser' => 'doomed', 'code' => '9999'], $jar);
    }
    $r = req('POST', '/akisthemes/', ['action' => 'verify', 'newuser' => 'doomed', 'code' => SIGNUP_CODE], $jar);
    eq(200, $r['status'], 'even the right code is too late now');
    ok(!isset(store_read($scratch . '/accounts.json')['doomed']), 'no account was made');
    ok(!isset(store_read($scratch . '/signups.json')['doomed']), 'and the pending row is gone');
});

t('the right code makes the account and signs you in', function () use ($scratch) {
    $jar = [];
    req('GET', '/akisthemes/', [], $jar);
    req('POST', '/akisthemes/', ['action' => 'signup', 'newuser' => 'newbie',
        'email' => 'newbie@example.com', 'newpass' => 'newbiepass'], $jar);
    $r = req('POST', '/akisthemes/', ['action' => 'verify', 'newuser' => 'newbie', 'code' => SIGNUP_CODE], $jar);
    eq(302, $r['status'], 'verifying redirects');
    eq('/akisthemes/', $r['location'], 'straight in, back on the page that asked');
    $acc = store_read($scratch . '/accounts.json');
    ok(isset($acc['newbie']), 'the account is real now');
    // HASHED, not stored. This assertion read `eq('newbiepass', …)` until
    // 2026-08-20 and passed for months, which is precisely what it was telling
    // anyone who looked: the account file held the real password. It now proves
    // the opposite in both directions — the stored value is not the password,
    // and it verifies against it.
    $stored = (string) ($acc['newbie']['password'] ?? '');
    ok($stored !== 'newbiepass', 'the account record does not hold the password');
    ok(password_verify('newbiepass', $stored), 'but it verifies against it');
    ok(!isset(store_read($scratch . '/signups.json')['newbie']), 'and no longer pending');
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
        req('GET', '/akisthemes/', [], $jar);
        req('POST', '/akisthemes/', ['action' => 'signup', 'newuser' => $user,
            'email' => $user . '@example.com', 'newpass' => $pass], $jar);
        req('POST', '/akisthemes/', ['action' => 'verify', 'newuser' => $user, 'code' => SIGNUP_CODE], $jar);
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
    $r = req('POST', '/akisthemes/', ['action' => 'change_password', 'csrf' => 'wrong',
        'current' => 'newbiepass', 'new' => 'brandnewpass'], $jar, true);
    eq(400, $r['status'], 'a bad token is a 400');
    eq($was, store_read($scratch . '/passwords.json'), 'and nothing was written');

    $r = req('POST', '/akisthemes/', ['action' => 'change_password', 'csrf' => csrf($jar),
        'current' => 'nope', 'new' => 'brandnewpass'], $jar, true);
    eq(false, json_decode($r['body'], true)['ok'] ?? null, 'the wrong current password is refused');

    $r = req('POST', '/akisthemes/', ['action' => 'change_password', 'csrf' => csrf($jar),
        'current' => 'newbiepass', 'new' => 'short'], $jar, true);
    eq(false, json_decode($r['body'], true)['ok'] ?? null, 'a six-character floor');
});

t('a changed password takes effect and the old one stops working', function () {
    ensure_account('newbie', 'newbiepass');
    $jar = login('newbie', 'newbiepass');
    $r = req('POST', '/akisthemes/', ['action' => 'change_password', 'csrf' => csrf($jar),
        'current' => 'newbiepass', 'new' => 'brandnewpass'], $jar, true);
    eq(true, json_decode($r['body'], true)['ok'] ?? null, 'accepted');

    $j = [];
    req('GET', '/akisthemes/', [], $j);
    eq(200, req('POST', '/akisthemes/', ['username' => 'newbie', 'password' => 'newbiepass'], $j)['status'],
       'the old password is dead');
    $j2 = [];
    req('GET', '/akisthemes/', [], $j2);
    eq(302, req('POST', '/akisthemes/', ['username' => 'newbie', 'password' => 'brandnewpass'], $j2)['status'],
       'the new one works');
});

t('the themes bench admits aki and sean, and nobody else', function () {
    // The PRODUCTION rule, checked directly — this suite runs with the list
    // widened to "*" so the page can stand in for any logged-in page, which
    // would otherwise mean the gate Sean asked for was never tested at all.
    // Unsetting the override is what puts the real rule back.
    $was = getenv('SUITE_THEMES_USERS');
    putenv('SUITE_THEMES_USERS');
    try {
        eq(['aki', 'sean'], themes_users(), 'the production list');
        ok(themes_may('aki'), 'aki may');
        ok(themes_may('sean'), 'sean may');
        ok(!themes_may('example'), 'a demo account may not');
        ok(!themes_may('admin'), 'nor may an admin account — the list is the list');
        ok(!themes_may(null), 'and signed out never may');
    } finally {
        putenv('SUITE_THEMES_USERS=' . $was);
    }
    // …and the widening only works inside a scratch instance.
    $wasDir = getenv('SUITE_DATA_DIR');
    putenv('SUITE_DATA_DIR');
    try {
        ok(!themes_may('example'), 'the override is ignored outside a scratch run');
    } finally {
        putenv('SUITE_DATA_DIR=' . $wasDir);
    }
});

t('a PLAINTEXT password on disk authenticates nobody', function () use ($scratch) {
    // The migration is over. Every store on the server was converted and
    // verified on 2026-08-23, and Sean asked for exactly this: "are all auth
    // for all app capable of only dealing with hashed passwords from now on?"
    //
    // The old code accepted a stored plaintext and upgraded it on the way
    // through, which was right while real accounts were still in that shape
    // and wrong the moment none were: it left one hand-edited config line able
    // to silently re-open plaintext logins for ever. A value that is not a
    // hash now fails, loudly and safely, even when it is the right password.
    ensure_account('legacy', 'legacypassword');
    $file = $scratch . '/passwords.json';
    $pw = store_read($file);
    $pw['legacy'] = 'legacypassword';          // exactly what the old code wrote
    store_write($file, $pw);

    [$ok, ] = auth_password_check('legacypassword', 'legacypassword');
    ok(!$ok, 'the right password against a plaintext store still fails');
    [$ok2, ] = auth_password_check(password_hash('legacypassword', PASSWORD_DEFAULT), 'legacypassword');
    ok($ok2, 'and the same password against a hash succeeds');

    // Nothing rewrote the file behind our back: a rejected login is not an
    // upgrade path, and must not look like one.
    ok(store_read($file)['legacy'] === 'legacypassword', 'the bad value is left alone, not silently fixed');
});

t('a stored password wins over the account record it overrides', function () use ($scratch) {
    // passwords.json is the override, because config.php is hand-kept on the server and
    // never deployed. Deleting it has to fall back rather than lock the account out.
    $pw = store_read($scratch . '/passwords.json');
    ok(isset($pw['newbie']), 'the override is on disk');
    // Both files hold HASHES now, and neither holds the password. What this test
    // is really about is that the override exists and the account record was not
    // rewritten under it — so check the shape, not the string.
    $acct = (string) (store_read($scratch . '/accounts.json')['newbie']['password'] ?? '');
    ok($acct !== '' && $acct !== 'newbiepass', 'the account record still has its own value');
    ok((string) $pw['newbie'] !== $acct, 'and the override is a different one');
    ok(password_verify('brandnewpass', (string) $pw['newbie']), 'the override is the NEW password');
});

t('the theme is set over AJAX, refuses a name it does not know, and sticks', function () use ($scratch) {
    $jar = login('example', 'examplepassword');
    $r = req('POST', '/akisthemes/', ['action' => 'set_theme', 'csrf' => csrf($jar),
        'theme' => 'not-a-theme'], $jar, true);
    eq(false, json_decode($r['body'], true)['ok'] ?? null, 'an unknown theme is refused');

    $names = array_keys(THEMES);
    $pick  = $names[count($names) - 1];
    $r = req('POST', '/akisthemes/', ['action' => 'set_theme', 'csrf' => csrf($jar),
        'theme' => $pick], $jar, true);
    eq(true, json_decode($r['body'], true)['ok'] ?? null, "theme $pick is accepted");
    eq($pick, store_read($scratch . '/prefs-example.json')['theme'] ?? null, 'and it is stored');

    $r = req('POST', '/akisthemes/', ['action' => 'set_theme', 'csrf' => 'wrong', 'theme' => $names[0]], $jar, true);
    eq(false, json_decode($r['body'], true)['ok'] ?? null, 'no token, no change');
    eq($pick, store_read($scratch . '/prefs-example.json')['theme'] ?? null, 'still the one we set');
    // Put it back: a later test asks what example's :root actually wears, and would read
    // whichever theme happened to be last in the table as a bug in something else.
    req('POST', '/akisthemes/', ['action' => 'set_theme', 'csrf' => csrf($jar), 'theme' => 'midnight'], $jar, true);
});

t('a suite theme paints the whole page, and midnight is the unchanged default', function () use ($scratch) {
    // Themes went from accent-only to full palettes. Midnight must be byte-for-byte the
    // old look (#111 page, #eee text, #34d399 accent) so an untouched account sees no
    // change; a light theme must flip color-scheme so native controls follow.
    ensure_account('fresh', 'freshpassword');
    $jar = login('fresh', 'freshpassword');
    $b = req('GET', '/akisthemes/', [], $jar)['body'];
    foreach (['--bg: #111111', '--text: #eeeeee', '--accent: #34d399', '--gold: #f0b429',
              'color-scheme: dark'] as $v) {
        has($v, $b, "a fresh account's page carries midnight's $v");
    }
    has('name="theme-color" content="#111111"', $b, 'the status-bar colour follows the theme');

    $csrf = csrf($jar, '/akisthemes/');
    req('POST', '/akisthemes/', ['action' => 'set_theme', 'csrf' => $csrf, 'theme' => 'sage'], $jar, true);
    $b = req('GET', '/akisthemes/', [], $jar)['body'];
    has('--bg: #fefae0', $b, 'the page wears the sage colour');
    has('color-scheme: light', $b, 'and flips to a light scheme');
    has('name="theme-color" content="#fefae0"', $b, 'and the status-bar colour moved with it');
    req('POST', '/akisthemes/', ['action' => 'set_theme', 'csrf' => csrf($jar), 'theme' => 'midnight'], $jar, true);
});



t('the theme picker shows every theme as its own swatch', function () {
    // The bookshelf is the app that still renders the shared settings window; the five
    // suite apps that also did are gone.
    ensure_account('aki', 'akipassword');
    $jar = login('aki', 'akipassword');
    $b = req('GET', '/akisbookshelf/', [], $jar)['body'];
    eq(count(THEMES), substr_count($b, 'class="themebtn'), 'one swatch per theme');
    eq(count(THEMES), substr_count($b, 'class="themedot"'), 'each carrying its accent dot');
    foreach (THEMES as $key => $row) {
        has('data-theme="' . $key . '"', $b, "$key is offered");
    }
    // A legacy stored name (the old accent-only themes) falls back to midnight rather
    // than erroring or half-applying.
    store_write(datadir() . '/prefs-aki.json',
        array_merge(store_read(datadir() . '/prefs-aki.json'), ['theme' => 'rose']));
    $b = req('GET', '/akisbookshelf/', [], $jar)['body'];
    has('--bg: #111111', $b, 'an old stored theme name renders as midnight');
});
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
    $csrf = csrf($jar, '/akisthemes/');
    req('POST', '/akisthemes/', ['action' => 'set_theme', 'csrf' => $csrf, 'theme' => 'forest'], $jar);
    $r = req('GET', '/akisbookshelf/', [], $jar);
    has('--accent: #00f5d4', $r['body'], 'the bookshelf still wears its own accent');
    $r = req('GET', '/akisthemes/', [], $jar);
    has('--accent: #8b9d83', $r['body'], 'and the suite kept the one set for it');
});
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
    // The picker's source is in THIS repo, not CalMind — the old link 404'd (fixed 2026-09-06).
    has('https://github.com/chere005/seancheren-site/tree/main/public/themepicker', $b, 'and to its folder in the repo');
    hasnt('CalMind/tree/main/public/themepicker', $b, 'and not the dead CalMind path it used to point at');
    has('>CalMind</h4>', $b, 'the CalMind entry, a subsection of it');
    has('href="https://github.com/chere005/CalMind"', $b, 'its repo link');
    has('seancheren.com/calmind', $b, 'and the link to the app');
    has('/calmind/icon-192.png', $b, 'wearing CalMind\'s own icon');
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
    hasnt('favicon-32.png', req('GET', '/akisthemes/', [], $jar)['body'],
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
    has("['/test/']", $src, 'the cookie path is scoped per instance');
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
    $b = req('GET', '/akisthemes/', [], $jar)['body'];
    // The theme swatches in settings legitimately carry every theme's colours, so the
    // check is what :root actually wears, not whether sage's hex appears anywhere.
    has('--bg: #111111', $b, 'the app keeps its own per-user theme');
});
area('usage');

t('operations leave one line each — and never any content', function () {
    $log = datadir() . '/usage.log';
    @unlink($log);                                 // only this test's lines below
    ensure_account('logger', 'loggerpassword');
    $jar = login('logger', 'loggerpassword');      // logs 'login'
    req('POST', '/akisthemes/', ['csrf' => csrf($jar), 'action' => 'add',
        'name' => 'Skywritten secret'], $jar);
    $b = (string) file_get_contents($log);
    has("\tlogger\tthemes\tlogin\n", $b, 'a sign-in is logged');
    has("\tlogger\tthemes\tadd\n", $b, 'an action logs user, app and kind');
    hasnt('Skywritten', $b, 'never what it carried');
    foreach (explode("\n", trim($b)) as $line) {
        eq(5, count(explode("\t", $line)), 'every line is the same five fields');
    }
    // A failed sign-in logs the attempted name; logging out logs too.
    req('POST', '/akisthemes/', ['username' => 'logger', 'password' => 'wrong']);
    req('GET', '/akisthemes/?logout', [], $jar);
    $b = (string) file_get_contents($log);
    has("\tlogger\tthemes\tlogin_fail\n", $b, 'a failed sign-in is logged');
    has("\tlogger\tthemes\tlogout\n", $b, 'a sign-out is logged');
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
area('hits');

t('a page view writes one line, with the address and no path', function () {
    $log = datadir() . '/hits.log';
    @unlink($log);
    req('GET', '/about/');
    req('GET', '/projects/?utm=somewhere');
    $b = (string) @file_get_contents($log);
    $lines = array_values(array_filter(explode("\n", trim($b))));
    eq(2, count($lines), 'one line per page view');
    foreach ($lines as $l) {
        eq(7, count(explode("\t", $l)), 'seven fields: time, instance, app, method, user, agent, ip');
    }
    // The address IS kept, on Sean's instruction (2026-08-23). What stays out
    // is the PATH and everything hanging off it — the difference between "how
    // busy is this" and "who went where" is still the point of the file.
    has('127.0.0.1', $b, 'the client address is recorded');
    hasnt('utm', $b, 'no query string');
    hasnt('/projects/', $b, 'no path — the app name only');
    has("\tabout\t", $b, 'the app is the first path segment');
    has("\tprojects\t", $b, 'and nothing below it');
});

t('a signed-in visitor is named even on a page with no login', function () {
    // Sean, 2026-08-23: "i want to see actual usernames, not (signed out)".
    // Public pages never start a session, so current_user() saw nothing and
    // every visit by a signed-in person was logged as anonymous — which put
    // Sean's own browsing in the "other people" lane on the Usage tab.
    $log = datadir() . '/hits.log';
    @unlink($log);
    $jar = login('example', 'examplepassword');
    req('GET', '/about/', [], $jar);              // a page with NO login of its own
    $b = (string) @file_get_contents($log);
    has("\texample\t", $b, 'the public page names the signed-in visitor');

    // …and a genuine stranger is still anonymous. Reading the session must not
    // invent one.
    @unlink($log);
    $none = null;
    req('GET', '/about/', [], $none);
    has("\tGET\t-\t", (string) @file_get_contents($log), 'no session, no name');
});

t('the status page does not count its own probes', function () {
    // Without this the reported hits would mostly be the reachability sweep,
    // and the number would climb the more often the page was looked at.
    $log = datadir() . '/hits.log';
    @unlink($log);
    req('GET', '/about/', [], $jar, false, ['X-Status-Probe: 1']);
    eq('', trim((string) @file_get_contents($log)), 'a probe leaves no line');
    req('GET', '/about/');
    ok(trim((string) @file_get_contents($log)) !== '', 'an ordinary visit still does');
});

t('a signed-in view names the user; a public one does not', function () {
    $log = datadir() . '/hits.log';
    @unlink($log);
    req('GET', '/about/');
    $jar = login('example', 'examplepassword');
    req('GET', '/akisthemes/', [], $jar);
    $b = (string) @file_get_contents($log);
    has("\thome\tGET\t-\t", str_replace("\tabout\t", "\thome\t", $b), 'a public page logs no user');
    has("\texample\t", $b, 'a signed-in page names who');
});

t('an agent names itself; a browser is never filed as one', function () {
    // Sean, 2026-08-23: "separate all your traffic and make it called claudio
    // in usage". The lane is only useful if it cannot swallow a real visitor,
    // so the negative half is the half worth testing.
    $log = datadir() . '/hits.log';
    @unlink($log);
    req('GET', '/about/', [], $jar, false, ['X-Claudio: 1']);
    req('GET', '/about/', [], $jar, false, ['User-Agent: curl/8.7.1']);
    req('GET', '/about/', [], $jar, false,
        ['User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/126 Safari/537.36']);
    $lines = array_values(array_filter(explode("\n", trim((string) @file_get_contents($log)))));
    eq(3, count($lines), 'all three are logged');
    // Field 6 of 7 — the address follows it, so "last" stopped being the agent.
    $agent = function (string $line): string { $f = explode("\t", $line); return (string) ($f[5] ?? ''); };
    eq('claudio', $agent($lines[0]), 'the header wins');
    eq('claudio', $agent($lines[1]), 'a command-line client is caught without it');
    eq('-', $agent($lines[2]), 'a browser is not');
});

t('hit_counts only counts inside its window', function () {
    $log = datadir() . '/hits.log';
    $old = time() - 7 * 86400;
    file_put_contents($log, implode("\n", [
        "$old\tprod\tabout\tGET\t-",
        (time() - 30) . "\tprod\tabout\tGET\t-\t-",
        (time() - 30) . "\tprod\tchat\tGET\tsomebody",
    ]) . "\n");
    $c = hit_counts(['hour' => 3600, 'week' => 8 * 86400]);
    eq(2, $c['hour']['hits'], 'the week-old line is outside the hour');
    eq(3, $c['week']['hits'], 'and inside the week');
    eq(1, $c['hour']['people'], 'signed-in visitors are counted apart');
});

t('hit_counts splits by instance, and the parts make the whole', function () {
    // The status page's one instance picker claims to scope the whole page.
    // This strip answered "the whole host" whichever button was lit.
    $log = datadir() . '/hits.log';
    $t = time() - 30;
    file_put_contents($log, implode("\n", [
        "$t\tprod\tabout\tGET\t-\t-\t1.2.3.4",
        "$t\tprod\tabout\tGET\tsean\t-\t1.2.3.4",
        "$t\ttest\tabout\tGET\t-\t-\t1.2.3.5",
        "$t\tdev\tabout\tGET\t-\t-\t1.2.3.6",
        "$t\tdev\tabout\tGET\t-\t-\t1.2.3.6",
    ]) . "\n");
    $c = hit_counts(['hour' => 3600])['hour'];
    eq(2, $c['by_inst']['prod']['hits'], 'production');
    eq(1, $c['by_inst']['test']['hits'], 'the test sandbox');
    eq(2, $c['by_inst']['dev']['hits'], 'the dev sandbox');
    eq(5, $c['hits'], 'and the total is still the whole host');
    eq(1, $c['by_inst']['prod']['people'], 'the signed-in visitor is production’s');
    eq(0, $c['by_inst']['dev']['people'], 'not the sandbox’s');
});

t('anonymous visitors are one row, not one row each', function () {
    // Sean, 2026-09-03: "group together anonymous requests, don't list hundreds
    // of anon-xxxx". Three days of scanner traffic was over a thousand rows —
    // and a thousand lines on the chart, burying the named accounts the table
    // exists to show.
    $log = datadir() . '/hits.log';
    $t = time() - 30;
    $lines = [];
    foreach (range(1, 40) as $i) { $lines[] = "$t\tprod\thome\tGET\t-\t-\t9.9.9.$i"; }
    $lines[] = "$t\tprod\thome\tGET\tsean\t-\t9.9.9.1";
    file_put_contents($log, implode("\n", $lines) . "\n");

    $u = hit_usage();
    $names = array_column($u['people'], 'name');
    eq(['sean', 'anonymous'], $names, 'forty addresses are one row, behind the named account');
    eq(0, count(array_filter($names, fn($n) => strncmp($n, 'anon-', 5) === 0)), 'and none of them is an anon-N');

    $anon = $u['people']['other|prod|anonymous'];
    eq(40, $anon['counts']['hour'], 'the row carries every one of their requests');
    eq(40, $anon['addresses'], 'and says how many addresses that was');
    eq(HIT_ADDR_TOP, count($anon['top']), 'the busiest handful ride along for the fold-out');
    ok(count($anon['top']) < $anon['addresses'], 'capped — the point is not to ship the wall');
});

t('a lane total is the sum of the rows under it', function () {
    // The invariant the Usage tab broke: the headline said 9 over a table whose
    // rows added to 0, because each worked its own total out separately.
    $log = datadir() . '/hits.log';
    $t = time() - 30;
    file_put_contents($log, implode("\n", [
        "$t\tprod\thome\tGET\tsean\t-\t1.2.3.4",
        "$t\tprod\thome\tGET\t-\t-\t5.6.7.8",
        "$t\tprod\tabout\tGET\t-\t-\t5.6.7.9",
        "$t\tdev\thome\tGET\t-\t-\t5.6.7.9",
    ]) . "\n");
    $u = hit_usage();
    foreach (['sean' => 1, 'other' => 2, 'dev' => 1] as $lane => $want) {
        $inst = $lane === 'dev' ? 'dev' : 'prod';
        $sum = 0;
        foreach ($u['people'] as $p) {
            if ($p['lane'] === $lane && $p['inst'] === $inst) { $sum += $p['counts']['hour']; }
        }
        eq($want, hit_usage_total($u['people'], $lane, $inst, 'hour'), $lane . ' headline');
        eq($want, $sum, $lane . ' rows add to the same');
    }
    // …and per app, which is what the app filter rewrites the table with.
    eq(1, hit_usage_total($u['people'], 'other', 'prod', 'hour', 'about'), 'one of the two was /about');
    eq(0, hit_usage_total($u['people'], 'other', 'prod', 'hour', 'nosuchapp'), 'an app nobody hit is zero, not everything');
});

t("Claude's traffic is split by instance, not pooled across them", function () {
    // The lane is one across all three instances by design, so without the
    // instance in the key its row could never narrow to the chosen one — and
    // the Claudio card is the one card the picker leaves on screen.
    $log = datadir() . '/hits.log';
    $t = time() - 30;
    file_put_contents($log, implode("\n", [
        "$t\tprod\thome\tGET\t-\tclaudio\t1.2.3.4",
        "$t\tprod\thome\tGET\t-\tclaudio\t1.2.3.4",
        "$t\ttest\thome\tGET\t-\tclaudio\t1.2.3.4",
        "$t\tdev\thome\tGET\t-\tclaudio\t1.2.3.4",
    ]) . "\n");
    $u = hit_usage();
    eq(2, hit_usage_total($u['people'], 'claudio', 'prod', 'hour'), 'production');
    eq(1, hit_usage_total($u['people'], 'claudio', 'test', 'hour'), 'the test sandbox');
    eq(1, hit_usage_total($u['people'], 'claudio', 'dev', 'hour'), 'the dev sandbox');
    eq(3, count(array_filter($u['people'], fn($p) => $p['lane'] === 'claudio')), 'one row per instance');
});

t('datacenter traffic is a bot, not another person', function () {
    // Sean, 2026-09-06: "i don't buy that last 3 days has been consistently
    // over 600 requests from random different addresses". It was scanners from
    // hosting providers, not people. An anonymous prod request from an address
    // geoip has resolved as hosting is filed in its own lane, so "Other people"
    // means people.
    $log = datadir() . '/hits.log';
    $t = time() - 30;
    file_put_contents($log, implode("\n", [
        "$t\tprod\thome\tGET\t-\t-\t9.9.9.9",     // a datacenter address (fixture below)
        "$t\tprod\thome\tGET\t-\t-\t5.6.7.8",     // a residential visitor
        "$t\tprod\thome\tGET\tsean\t-\t9.9.9.9",  // even from a bot address, a session is a person
    ]) . "\n");
    // The dc map geoip.php would have built from the sweep.
    file_put_contents(datadir() . '/dc.json', json_encode(['9.9.9.9' => true]));

    $u = hit_usage();
    eq(1, hit_usage_total($u['people'], 'bots', 'prod', 'hour'), 'the datacenter GET is a bot');
    eq(1, hit_usage_total($u['people'], 'other', 'prod', 'hour'), 'the residential GET is a person');
    eq(1, hit_usage_total($u['people'], 'sean', 'prod', 'hour'), 'a signed-in session stays a person, bot address or not');

    // hit_lane is the rule, and it holds without the map too: no map, no bots.
    eq('bots', hit_lane(['instance' => 'prod', 'user' => '-', 'ip' => '9.9.9.9'], ['9.9.9.9' => true]));
    eq('other', hit_lane(['instance' => 'prod', 'user' => '-', 'ip' => '9.9.9.9'], []));
    eq('sean', hit_lane(['instance' => 'prod', 'user' => 'sean', 'ip' => '9.9.9.9'], ['9.9.9.9' => true]));
    @unlink(datadir() . '/dc.json');
});

t('a known account with no traffic is still listed, at zero', function () {
    $log = datadir() . '/hits.log';
    file_put_contents($log, (time() - 30) . "\tprod\thome\tGET\t-\t-\t1.2.3.4\n");
    $u = hit_usage(['sean', 'aki']);
    ok(isset($u['people']['sean|prod|sean']), 'sean is on the roster');
    ok(isset($u['people']['other|prod|aki']), 'and so is aki');
    eq(0, $u['people']['other|prod|aki']['counts']['year'], 'silence reads as zero, not as absence');
    eq(0, $u['people']['other|prod|aki']['addresses'], 'and carries no address it never had');
});

t('a sandbox prefix is stripped from the app name, for BOTH sandboxes', function () {
    // /test was stripped and /dev was not, so a request that did arrive with
    // the prefix filed a whole sandbox under an app called "dev".
    foreach (['/test/contact/' => 'contact', '/dev/contact/' => 'contact',
              '/contact/' => 'contact', '/test/' => 'home', '/dev/' => 'home',
              '/' => 'home', '/devious/' => 'devious'] as $uri => $want) {
        $_SERVER['REQUEST_URI'] = $uri;
        eq($want, hit_app(), $uri);
    }
});

area('deploy');


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
    foreach (['deploy.sh'] as $f) {
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
    has('push_instance', $s, 'deploy.sh still has push_instance');
    // THE USAGE LINE MUST NAME EVERY MODE THE CASE ARM ACCEPTS. This used to
    // assert the literal string 'test|prod|both|promote', which is why nobody
    // noticed the two had drifted: the script accepted six modes and told
    // anyone who mistyped that four existed — omitting `all`, which
    // tools/dtp.sh runs on every release. Comparing them to each other is the
    // check that cannot go stale the same way.
    preg_match('/^\s*(\S+)\)\s*MODE="\$arg"/m', $s, $arm);
    preg_match('/Usage: \.\/deploy\.sh \[([^\]]+)\]/', $s, $usage);
    ok(!empty($arm[1]) && !empty($usage[1]), 'found both the case arm and the usage line');
    $accepted = explode('|', $arm[1]);
    $offered  = explode('|', $usage[1]);
    sort($accepted); sort($offered);
    eq($accepted, $offered, 'usage names exactly the modes the script accepts');
    // The script itself is not run here: it needs the deploy key, and a test run must
    // never be one keystroke away from touching the live site. These are text checks.
    ok(preg_match('/\bprod\)\s*$/m', $s) === 1, 'prod is its own explicit mode');
    ok(strpos($s, 'promote') !== false, 'and promote exists to move test into prod');
});

t('EVERY instance leaves calmind/ to the new app, prod included', function () use ($root) {
    // /test/calmind/ has belonged to the CalMind monorepo since 2026-08-08, and
    // /home/public/calmind since 2026-08-20, when the new app took over prod and this
    // suite's pages moved to /home/protected/suite-retired.
    //
    // The exclusion used to be test-only, under a comment saying "Prod still gets the
    // suite" — true when it was written, and false the moment the cutover happened. A
    // prod deploy would have rsynced the retired pages straight back over the live app,
    // and nothing here would have objected: this test only ever asked about test.
    $s = (string) file_get_contents($root . '/deploy.sh');
    has("local skip=(--exclude='/calmind')", $s,
        'the exclusion is unconditional, not per-destination');
    ok(strpos($s, '== /home/public/test ]] && skip=') === false,
        'and no longer keyed to the test destination alone');
    has('--exclude=/calmind /home/public/test/ /home/public/', $s,
        'promote excludes it from the server-side copy');
});



t('nothing is left pointing into the deleted calmind/ area', function () use ($root) {
    // The old plain-PHP CalMind suite lived in a top-level calmind/ area, stitched into
    // the served layout by symlinks (public/calmind, lib/tabbar.php, …). It was deleted
    // on 2026-08-22, superseded by the CalMind repo. A leftover symlink would deploy as
    // a dangling link or, with -L, fail the rsync outright.
    ok(!file_exists($root . '/calmind'), 'the calmind/ area is gone');
    ok(!file_exists($root . '/public/calmind'), 'and so is the symlink into it');
    foreach (['tabbar', 'folders', 'sharing', 'palette', 'util'] as $f) {
        ok(!file_exists($root . '/lib/' . $f . '.php'), "lib/$f.php belonged to the suite and is gone");
    }
    foreach (['deploy.sh'] as $sh) {
        $b = (string) file_get_contents($root . '/' . $sh);
        hasnt('$SRC/calmind', $b, "$sh no longer lints a calmind/ area");
        has("--exclude='/calmind'", $b, "$sh still refuses to send anything at that path");
    }
});

// ═══════════════════════════════════════════════════════════════════ run

if ($list) {
    foreach ($AREAS as $name => $cases) { printf("%-10s %d cases\n", $name, count($cases)); }
    exit(0);
}

// Seed the scratch dir with the real seeder — which also tests that it works.
fwrite(STDERR, "seeding a scratch account set in $scratch …\n");
exec('SUITE_DATA_DIR=' . escapeshellarg($scratch) . ' php ' . escapeshellarg($root . '/tools/seed-accounts.php')
     . ' --force 2>&1', $out, $rc);
if ($rc !== 0) { fwrite(STDERR, "seeder failed:\n" . implode("\n", $out) . "\n"); exit(2); }

// Boot the dev server on a free port, pointed at the scratch dir.
$sock = stream_socket_server('tcp://127.0.0.1:0', $e1, $e2);
$PORT = (int) explode(':', stream_socket_get_name($sock, false))[1];
fclose($sock);
$desc = [1 => ['file', '/dev/null', 'w'], 2 => ['file', $scratch . '/server.log', 'w']];
$SRV = proc_open('SUITE_DATA_DIR=' . escapeshellarg($scratch)
    . ' SUITE_THEMES_USERS=' . escapeshellarg((string) getenv('SUITE_THEMES_USERS'))
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
