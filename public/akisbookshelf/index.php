<?php
// A page served under /test/ (the sandbox mirror) loads lib-test/ instead of lib/,
// isolated in code, config and data. Links stay root-relative — the sandbox is a
// subdomain and .htaccess maps test.seancheren.com/X to /test/X — so nothing here
// prefixes a href. Keep this preamble identical when adding a page.
//
// THREE signals, and all three are needed. __DIR__ with a bare strpos for '/test/'
// missed the instance's OWN top-level page — /home/public/test/index.php sits in
// /home/public/test, with no trailing slash — so the sandbox home silently loaded
// production's lib AND production's data. The host check is what the subdomain
// routing needs: test.seancheren.com/X is rewritten to /test/X internally, so
// REQUEST_URI never says /test/ there either.
$__host   = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
// Which sandbox, if any — '' is production. dev came back on 2026-08-23
// ("deploy a clone from prod to test and dev"), same three signals as test.
$__inst   = '';
foreach (['test', 'dev'] as $__i) {
    if (preg_match('#/' . $__i . '(/|$)#', __DIR__) === 1
        || strncmp($_SERVER['REQUEST_URI'] ?? '', '/' . $__i . '/', strlen($__i) + 2) === 0
        || strncmp($__host, $__i . '.', strlen($__i) + 1) === 0) { $__inst = $__i; break; }
}
$__libDir = null;
$__cands  = $__inst !== ''
    ? [__DIR__ . '/../../../lib-' . $__inst, '/home/protected/lib-' . $__inst]
    : [__DIR__ . '/../../lib',      '/home/protected/lib'];
foreach ($__cands as $__c) {
    if (is_file($__c . '/auth.php')) { $__libDir = $__c; break; }
}
require_once $__libDir . '/auth.php';
require_once $__libDir . '/chrome.php';     // two-press delete + the settings window
require_once $__libDir . '/richtext.php';   // note-body toolbar + sanitiser
require_login("Aki's Bookshelf");
// Standalone, private app — only aki may use it. The site login session is
// shared, so we don't destroy it; we just refuse others and offer a log out.
if (current_user() !== 'aki') {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>Aki\'s Bookshelf</title>'
       . '<body style="font-family:system-ui,sans-serif;background:#111;color:#eee;display:flex;min-height:100vh;align-items:center;justify-content:center;text-align:center;padding:2rem;margin:0">'
       . '<div><p style="font-size:1.15rem;margin:0 0 1rem">This bookshelf is aki\'s.</p>'
       . '<p style="margin:0"><a href="?logout" style="color:var(--accent)">Log out</a> and sign in as aki.</p></div></body>';
    exit;
}

$cfg       = app_config();
$booksFile = user_data_file($cfg['data_dir'], 'books');       // array of book cards
$notesFile = user_data_file($cfg['data_dir'], 'booknotes');   // map: bookId => [notes]
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }

function e(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES); }

function books_load(string $f): array { return store_read($f); }
function books_save(string $f, array $b): void { store_write($f, array_values($b)); }
function bnotes_load(string $f): array { return store_read($f); }        // map keyed by bookId
function bnotes_save(string $f, array $m): void { store_write($f, $m); }

/** A stored book-notes entry that is a section header rather than a note. */
function is_bsection(array $it): bool { return ($it['type'] ?? '') === 'section'; }

/** Open Library cover URL for a numeric cover id ('S' | 'M' | 'L'). */
function cover_url(?int $id, string $size = 'M'): string
{
    return $id ? "https://covers.openlibrary.org/b/id/{$id}-{$size}.jpg" : '';
}

/** External cover source: a hand-picked URL wins (that's what "Set cover" is for), then
 *  the Open Library cover id, then by ISBN. */
function book_cover_source(array $b, string $size = 'M'): string
{
    if (!empty($b['cover_url'])) { return (string) $b['cover_url']; }
    if (!empty($b['cover']))     { return 'https://covers.openlibrary.org/b/id/' . ((int) $b['cover']) . "-{$size}.jpg"; }
    if (!empty($b['isbn']))      { return 'https://covers.openlibrary.org/b/isbn/' . rawurlencode((string) $b['isbn']) . "-{$size}.jpg?default=false"; }
    return '';
}

/** Filenames in the local WebP cover cache (read once per request). */
function cached_cover_set(): array
{
    static $set = null;
    if ($set === null) {
        $set = [];
        foreach (@scandir(__DIR__ . '/covers') ?: [] as $f) {
            if (substr($f, -5) === '.webp') { $set[$f] = true; }
        }
    }
    return $set;
}

/** Cover URL for display: the locally cached WebP if we have it, else the external source. */
function book_cover(array $b, string $size = 'M'): string
{
    $id = (string) ($b['id'] ?? '');
    if ($id !== '' && isset(cached_cover_set()[$id . '.webp'])) {
        return '/akisbookshelf/covers/' . $id . '.webp';
    }
    return book_cover_source($b, $size);
}

/** Build a sort/filter URL preserving the current shelf + folder. */
function sf_url(string $base, string $sort, string $min): string
{
    return $base . '&sort=' . $sort . '&min=' . rawurlencode($min);
}

/** Echo one book card. Covers load eagerly so the whole shelf fills on open. */
function render_book_card(array $b, string $csrf, string $shelf): void
{
    ?>
    <div class="bookcard" data-id="<?= e($b['id']) ?>">
      <a class="booklink" href="?book=<?= urlencode($b['id']) ?>">
        <span class="coverbox">
          <span class="ph"><?= e($b['title'] ?? '') ?></span>
          <?php $cu = book_cover($b, 'M'); if ($cu !== ''): ?>
            <img src="<?= e($cu) ?>" alt="" loading="eager" onerror="this.remove()">
          <?php endif; ?>
        </span>
        <span class="btitle"><?= e($b['title'] ?? 'Untitled') ?></span>
        <?php if (!empty($b['author'])): ?><span class="bauthor"><?= e($b['author']) ?></span><?php endif; ?>
      </a>
      <div class="cardmeta">
        <?= stars_html((int) ($b['rating'] ?? 0), false, $b['id'], 'cardrate') ?>
      </div>
      <form method="post" action="">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="delete_book">
        <input type="hidden" name="book" value="<?= e($b['id']) ?>">
        <input type="hidden" name="shelf" value="<?= e($shelf) ?>">
        <button class="bdel needs-confirm" type="submit" title="Remove book">&times;</button>
      </form>
    </div>
    <?php
}

/** 5-star rating: filled up to $rating. Editable version is wired up in JS. */
function stars_html(int $rating, bool $editable, string $bookId = '', string $extraClass = ''): string
{
    $cls = 'stars' . ($editable ? ' editable' : '') . ($extraClass !== '' ? ' ' . $extraClass : '');
    $out = '<span class="' . $cls . '"'
         . ($bookId !== '' ? ' data-book="' . e($bookId) . '"' : '')
         . ' data-rating="' . $rating . '">';
    for ($i = 1; $i <= 5; $i++) {
        $out .= '<span class="star' . ($i <= $rating ? ' on' : '') . '" data-v="' . $i . '">&#9733;</span>';
    }
    return $out . '</span>';
}

/** GET a URL with a short timeout (curl, then a file_get_contents fallback). */
function http_get(string $url): string
{
    $ua = 'seancheren-books/1.0 (personal reading list)';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => $ua,
        ]);
        $r = curl_exec($ch);
        curl_close($ch);
        if ($r !== false) { return (string) $r; }
    }
    $ctx = stream_context_create(['http' => ['timeout' => 8, 'header' => "User-Agent: {$ua}\r\n"]]);
    return (string) @file_get_contents($url, false, $ctx);
}

/**
 * Search Open Library — an accepted Goodreads source for covers + book data
 * (help.goodreads.com Librarian Manual). Returns compact matches that HAVE a
 * cover, so the user picks from a list of cover thumbnails.
 */
function ol_search(string $q): array
{
    $q = trim($q);
    if ($q === '') { return []; }
    $url = 'https://openlibrary.org/search.json?q=' . rawurlencode($q)
         . '&fields=key,title,author_name,cover_i,first_publish_year&limit=25';
    $data = json_decode(http_get($url), true);
    $out  = [];
    foreach (($data['docs'] ?? []) as $d) {
        if (empty($d['cover_i'])) { continue; }              // this feature is about covers
        $out[] = [
            'key'    => (string) ($d['key'] ?? ''),
            'title'  => (string) ($d['title'] ?? 'Untitled'),
            'author' => implode(', ', array_slice($d['author_name'] ?? [], 0, 2)),
            'cover'  => (int) $d['cover_i'],
            'year'   => isset($d['first_publish_year']) ? (int) $d['first_publish_year'] : null,
        ];
        if (count($out) >= 15) { break; }
    }
    return $out;
}

// --- AJAX: cover/book search (GET) ---
if (($_GET['action'] ?? '') === 'search') {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'results' => ol_search((string) ($_GET['q'] ?? ''))]);
    exit;
}

/**
 * Bookshelf themes — this app only. The suite's five (THEMES, lib/auth.php) swap the
 * accent and nothing else, so every app stays the same dark room with a new highlight;
 * these repaint the whole page, background and rules and text together. "Midnight" is
 * the original look and is the default, so an untouched bookshelf looks exactly as it
 * did. Stored under its own prefs key, so picking one here never disturbs the suite
 * theme (or the other way round), and nothing outside this file reads them.
 *
 * Where a palette had nothing that could carry the job, a member was deepened or
 * lightened rather than dropped in flat: an all-pastel set has no colour dark enough
 * to be text, and its mid-tones fail as an accent on their own pale background. Every
 * theme's accent, muted text and gold clear roughly 4.5:1 on that theme's background.
 * --gold is the star rating and section headings; it has to be themed because the
 * original #f0b429 is barely visible on a cream page. The rating stars, the error red
 * and the quote purple stay literal — like the suite's reminder/event/note colours,
 * they say what a thing *is*, not which theme you like.
 */
const BOOK_THEMES = [
    //             label            bg         surface    surface-2  line       line-soft  text       text-dim   muted      accent     accent-ink accent-soft gold
    'midnight' => ['Midnight',      '#111111', '#1a1a1a', '#2a2a2a', '#333333', '#262626', '#eeeeee', '#cccccc', '#888888', '#34d399', '#06251b', '#14332a', '#f0b429'],
    'sage'     => ['Sage & Cream',  '#fefae0', '#faedcd', '#e9edc9', '#ccd5ae', '#e4e7c9', '#3f3a2e', '#5c5545', '#776e56', '#96632f', '#fefae0', '#efe2c2', '#8a5a12'],
    'blossom'  => ['Blossom',       '#fdf4f9', '#ffe9f2', '#ffc8dd', '#cdb4db', '#bde0fe', '#3f2e47', '#6a5273', '#7d6486', '#7b4e96', '#fff5fa', '#f0e2f6', '#8a5a12'],
    'dusk'     => ['Dusk',          '#22223b', '#2e2e4d', '#4a4e69', '#4a4e69', '#34345a', '#f2e9e4', '#c9ada7', '#9a8c98', '#c9ada7', '#22223b', '#33324f', '#e0b877'],
    'neon'     => ['Neon',          '#12101a', '#1c1830', '#2a2444', '#3a3160', '#241f3c', '#f5f0ff', '#c9bee6', '#9086b0', '#00f5d4', '#072b25', '#10302b', '#fee440'],
    'plum'     => ['Plum & Mint',   '#2a1327', '#3a1b35', '#4e2a47', '#6b3f60', '#43203d', '#efe4ec', '#b5d8cc', '#a086a6', '#72e1d1', '#10302b', '#1d3b36', '#f0b429'],
    'forest'   => ['Forest',        '#040303', '#16201d', '#3a4e48', '#3a4e48', '#263230', '#e4ddd6', '#beb0a7', '#6a7b76', '#8b9d83', '#0a0f0d', '#1c2a25', '#c9a227'],
    'olive'    => ['Olive & Slate', '#241e2d', '#332a3e', '#443850', '#564a62', '#3b3247', '#eaf0ce', '#c0c5c1', '#848b98', '#bbbe64', '#241e2d', '#3a3448', '#d8c46a'],
];

/** The themes whose page is lighter than their ink; they need color-scheme: light. */
const BOOK_THEMES_LIGHT = ['sage', 'blossom'];

/** The bookshelf's own theme key, from the same prefs file the suite theme lives in. */
function book_theme_get(): string
{
    $t = (string) (store_read(theme_file())['book_theme'] ?? '');
    return isset(BOOK_THEMES[$t]) ? $t : 'midnight';
}

/** Store a pick. An unknown key is refused rather than written. */
function book_theme_set(string $name): bool
{
    if (!isset(BOOK_THEMES[$name])) { return false; }
    $p = store_read(theme_file());
    $p['book_theme'] = $name;
    store_write(theme_file(), $p);
    return true;
}

/**
 * One theme as the custom properties it sets, plus which way round the page is. The
 * single place the columns of BOOK_THEMES are named: the stylesheet and the picker's
 * live repaint both read this, so they can't drift into disagreeing about a colour.
 */
function book_theme_vars(string $key): array
{
    [, $bg, $sf, $sf2, $ln, $lns, $tx, $dim, $mut, $ac, $ink, $soft, $gold] = BOOK_THEMES[$key];
    // Native controls (selects, scrollbars, date pickers) need telling which way round the
    // page is, or a cream theme draws a black dropdown over it.
    $scheme = in_array($key, BOOK_THEMES_LIGHT, true) ? 'light' : 'dark';
    return ['scheme' => $scheme, 'vars' => [
        '--bg' => $bg, '--surface' => $sf, '--surface-2' => $sf2, '--line' => $ln,
        '--line-soft' => $lns, '--text' => $tx, '--text-dim' => $dim, '--muted' => $mut,
        '--accent' => $ac, '--accent-ink' => $ink, '--accent-soft' => $soft,
        '--gold' => $gold, '--scheme' => $scheme,
    ]];
}

/**
 * The chosen theme as variables. Emitted *after* theme_css() so --accent is this app's
 * rather than the suite's — the bookshelf hides the suite picker for that reason.
 */
function book_theme_css(): string
{
    $t   = book_theme_vars(book_theme_get());
    $out = '';
    foreach ($t['vars'] as $k => $v) { $out .= " $k: $v;"; }
    return "    :root {{$out} color-scheme: {$t['scheme']}; }\n";
}

/** Every theme's variables, for the picker's repaint-in-place. */
function book_themes_js(): string
{
    $all = [];
    foreach (array_keys(BOOK_THEMES) as $k) { $all[$k] = book_theme_vars($k); }
    return json_encode($all, JSON_UNESCAPED_SLASHES);
}

/** The page background, for the iOS status bar / PWA chrome. */
function book_theme_bg(): string
{
    return BOOK_THEMES[book_theme_get()][1];
}

/**
 * The picker, handed to settings_modal_html()'s $extra slot. Each swatch previews the
 * theme it picks — the page background with the accent as a dot on it — since a single
 * accent circle can't tell "Midnight" from "Forest".
 */
function book_theme_picker_html(): string
{
    $now  = book_theme_get();
    $btns = '';
    foreach (BOOK_THEMES as $key => [$label, $bg, , , $ln, , , , , $ac]) {
        $on = $key === $now ? ' on' : '';
        $btns .= '<button type="button" class="bkthemebtn' . $on . '" data-theme="' . e($key) . '"'
               . ' style="background:' . $bg . ';border-color:' . $ln . '"'
               . ' title="' . e($label) . '" aria-label="' . e($label) . '">'
               . '<span style="background:' . $ac . '"></span></button>';
    }
    return '<div class="bkthemes"><p class="setlabel">Bookshelf theme</p>'
         . '<div class="bkthemerow">' . $btns . '</div></div>';
}

// --- Mutations (POST -> redirect -> GET), CSRF protected ---
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['action'])) {
    if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Bad request (invalid CSRF token).');
    }
    $action   = (string) $_POST['action'];
    $bookId   = (string) ($_POST['book'] ?? '');
    $shelf    = (string) ($_POST['shelf'] ?? 'library');
    if (!in_array($shelf, ['library', 'read', 'want'], true)) { $shelf = 'library'; }
    $listUrl  = _self_path();
    $shelfUrl = $listUrl . '?shelf=' . urlencode($shelf);

    // The theme touches no book, so it answers before any of the book plumbing below.
    // The picker posts in the background and reloads itself, the way the suite's does.
    if ($action === 'set_book_theme') {
        book_theme_set((string) ($_POST['theme'] ?? ''));
        if (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'theme' => book_theme_get()]);
            exit;
        }
        header('Location: ' . $listUrl);
        exit;
    }

    // Nothing destructive happens without the confirmed second press.
    if (in_array($action, ['delete_book', 'delete_note', 'delete_bsection'], true)
        && empty($_POST['confirm'])) {
        header('Location: ' . ($action === 'delete_book' ? $shelfUrl : $listUrl . '?book=' . urlencode($bookId) . '&edit=1'));
        exit;
    }
    $bookUrl  = $listUrl . '?book=' . urlencode($bookId);

    // Anything that changes a book — including writing its notes — counts as editing it,
    // which is what the "Recently edited" sort orders by. Stamped in one place so no
    // handler can quietly forget to.
    if ($bookId !== '' && $action !== 'add_book' && $action !== 'delete_book') {
        $bl      = books_load($booksFile);
        $touched = false;
        foreach ($bl as &$tb) {
            if (($tb['id'] ?? '') === $bookId) { $tb['updated'] = time(); $touched = true; break; }
        }
        unset($tb);
        if ($touched) { books_save($booksFile, $bl); }
    }

    // ----- Book cards -----
    if ($action === 'add_book') {
        $title = trim((string) ($_POST['title'] ?? ''));
        if ($title !== '') {
            $books   = books_load($booksFile);
            $cover   = (int) ($_POST['cover'] ?? 0);
            $books[] = [
                'id'      => bin2hex(random_bytes(6)),
                'title'   => mb_substr($title, 0, 300),
                'author'  => mb_substr(trim((string) ($_POST['author'] ?? '')), 0, 200),
                'cover'   => $cover > 0 ? $cover : null,
                'key'     => mb_substr(trim((string) ($_POST['key'] ?? '')), 0, 60),
                'rating'  => 0,
                'read_at' => null,   // set when a rating is first given (a rating = "read")
                'want'    => $shelf === 'want',
                'past'    => false,
                'created' => time(),
                'updated' => time(),
            ];
            books_save($booksFile, $books);
        }
        header('Location: ' . $shelfUrl);
        exit;
    }
    if ($action === 'delete_book') {
        $books = books_load($booksFile);
        $bk = null;
        foreach ($books as $b) { if (($b['id'] ?? '') === $bookId) { $bk = $b; break; } }
        $books = array_values(array_filter($books, fn($b) => ($b['id'] ?? '') !== $bookId));
        books_save($booksFile, $books);
        // Its notes go with it.
        $nmap    = bnotes_load($notesFile);
        $bknotes = $nmap[$bookId] ?? null;
        if ($bknotes !== null) { unset($nmap[$bookId]); bnotes_save($notesFile, $nmap); }
        header('Location: ' . $shelfUrl . '&edit=1');
        exit;
    }
    // ----- Rating (= "read") and shelf flags — AJAX from the stars and the book-page checkboxes -----
    if ($action === 'set_rating') {
        $r     = max(0, min(5, (int) ($_POST['rating'] ?? 0)));
        $books = books_load($booksFile);
        foreach ($books as &$b) {
            if (($b['id'] ?? '') === $bookId) {
                $b['rating'] = $r;
                // A rating means it's been read: stamp the date on the first one, and clear
                // it again if the rating is removed, so re-rating later gives a fresh date.
                $b['read_at'] = $r > 0 ? ((int) ($b['read_at'] ?? 0) ?: time()) : null;
                break;
            }
        }
        unset($b);
        books_save($booksFile, $books);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'rating' => $r]);
        exit;
    }
    if (in_array($action, ['set_want', 'set_past'], true)) {
        $field = ['set_want' => 'want', 'set_past' => 'past'][$action];
        $val   = !empty($_POST['value']);
        $books = books_load($booksFile);
        foreach ($books as &$b) {
            if (($b['id'] ?? '') === $bookId) { $b[$field] = $val; break; }
        }
        unset($b);
        books_save($booksFile, $books);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'value' => $val]);
        exit;
    }
    // ----- Set (or clear) a hand-picked cover image URL, overriding the Open Library one -----
    if ($action === 'set_cover') {
        $url   = trim((string) ($_POST['cover_url'] ?? ''));
        // Only a plain http(s) image URL; an empty value clears it back to the default cover.
        if ($url !== '' && !preg_match('#^https?://#i', $url)) { $url = ''; }
        $books = books_load($booksFile);
        foreach ($books as &$b) {
            if (($b['id'] ?? '') === $bookId) { $b['cover_url'] = mb_substr($url, 0, 500); break; }
        }
        unset($b);
        books_save($booksFile, $books);
        header('Location: ' . $listUrl . '?book=' . urlencode($bookId) . '&edit=1');
        exit;
    }

    // ----- Per-book notes (completely separate from the Notes tab) -----
    $map   = bnotes_load($notesFile);
    $notes = $map[$bookId] ?? [];

    // ----- Note sections (bold headers grouping a book's notes) -----
    if ($action === 'add_bsection') {
        $name = trim(preg_replace('/\s+/', ' ', (string) ($_POST['name'] ?? '')));
        $name = mb_substr($name, 0, 60);
        $dup  = false;
        foreach ($notes as $it) {
            if (is_bsection($it) && strcasecmp((string) ($it['name'] ?? ''), $name) === 0) { $dup = true; break; }
        }
        if ($name !== '' && !$dup) {
            $notes[] = ['id' => bin2hex(random_bytes(6)), 'type' => 'section', 'name' => $name, 'created' => time()];
            $map[$bookId] = $notes;
            bnotes_save($notesFile, $map);
        }
        // Adding a section is reachable from *outside* edit mode, so it must not switch
        // edit mode on — it only stays on if you were already in it, which keep_edit_script()
        // says by posting an `edit` flag. (The destructive actions above and below can only
        // be reached while editing, so they carry edit=1 back unconditionally.)
        header('Location: ' . $bookUrl . (!empty($_POST['edit']) ? '&edit=1' : ''));
        exit;
    }
    if ($action === 'delete_bsection') {
        $name  = (string) ($_POST['name'] ?? '');
        // The header goes; its notes stay, dropping back to the ungrouped list.
        $notes = array_values(array_filter($notes, fn($it) => !(is_bsection($it) && ($it['name'] ?? '') === $name)));
        foreach ($notes as &$n) {
            if (!is_bsection($n) && ($n['section'] ?? '') === $name) { $n['section'] = ''; }
        }
        unset($n);
        $map[$bookId] = $notes;
        bnotes_save($notesFile, $map);
        header('Location: ' . $bookUrl . '&edit=1');
        exit;
    }
    // ----- Drag reorder (AJAX). order = [{id, section}, …] top-to-bottom across the groups. -----
    if ($action === 'reorder_notes') {
        $order = json_decode((string) ($_POST['order'] ?? '[]'), true);
        if (!is_array($order)) { $order = []; }
        // Section headers keep their stored order; notes are re-placed per the drag and
        // re-pointed at whatever section they were dropped into (blank if it's gone).
        $sectionRows = [];
        $secExists   = [];
        $byId        = [];
        foreach ($notes as $it) {
            if (is_bsection($it)) { $sectionRows[] = $it; $secExists[$it['name']] = true; }
            else { $byId[$it['id']] = $it; }
        }
        $newNotes = [];
        $used     = [];
        foreach ($order as $o) {
            $id = (string) ($o['id'] ?? '');
            if ($id === '' || !isset($byId[$id]) || isset($used[$id])) { continue; }
            $row = $byId[$id];
            $sec = (string) ($o['section'] ?? '');
            if ($sec !== '' && !isset($secExists[$sec])) { $sec = ''; }
            $row['section'] = $sec;
            $newNotes[]     = $row;
            $used[$id]      = true;
        }
        // Notes the drag never saw (chapters live in another view) keep their place after.
        foreach ($notes as $it) {
            if (!is_bsection($it) && !isset($used[$it['id']])) { $newNotes[] = $it; }
        }
        $map[$bookId] = array_merge($sectionRows, $newNotes);
        bnotes_save($notesFile, $map);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'add_note') {
        $nid     = bin2hex(random_bytes(6));
        $notes[] = ['id' => $nid, 'title' => date('m/d/Y h:i a') . ' - Note', 'body' => '',
                    'created' => time(), 'updated' => time()];
        $map[$bookId] = $notes;
        bnotes_save($notesFile, $map);
        header('Location: ' . $bookUrl . '&note=' . $nid);
        exit;
    }
    if ($action === 'add_chapter') {
        // Auto-number: one past the highest existing "Chapter N" (or the count).
        $max = 0; $cnt = 0;
        foreach ($notes as $it) {
            if (is_bsection($it) || empty($it['chapter'])) { continue; }
            $cnt++;
            if (preg_match('/^Chapter\s+(\d+)$/', (string) ($it['title'] ?? ''), $mm)) { $max = max($max, (int) $mm[1]); }
        }
        $n       = max($max, $cnt) + 1;
        $nid     = bin2hex(random_bytes(6));
        $notes[] = ['id' => $nid, 'title' => 'Chapter ' . $n, 'body' => '', 'chapter' => true,
                    'section' => '', 'created' => time(), 'updated' => time()];
        $map[$bookId] = $notes;
        bnotes_save($notesFile, $map);
        header('Location: ' . $bookUrl . '&view=chapters');
        exit;
    }
    if ($action === 'save_note') {
        $nid     = (string) ($_POST['id'] ?? '');
        $title   = trim((string) ($_POST['title'] ?? ''));
        $body    = (string) ($_POST['body'] ?? '');
        $section = (string) ($_POST['section'] ?? '');
        $secSet  = [];
        foreach ($notes as $it) { if (is_bsection($it)) { $secSet[$it['name']] = true; } }
        if ($section !== '' && !isset($secSet[$section])) { $section = ''; }
        foreach ($notes as &$n) {
            if (!is_bsection($n) && ($n['id'] ?? '') === $nid) {
                $n['title']   = $title === '' ? (date('m/d/Y h:i a', (int) ($n['created'] ?? time())) . ' - Note') : mb_substr($title, 0, 200);
                // The body is HTML now, so it only ever gets stored sanitised.
                $n['body']    = mb_substr(rt_sanitize($body), 0, 20000);
                $n['section'] = $section;
                $n['updated'] = time();
                break;
            }
        }
        unset($n);
        $map[$bookId] = $notes;
        bnotes_save($notesFile, $map);
        if (!empty($_POST['ajax'])) {
            $saved = null;
            foreach ($notes as $x) { if (($x['id'] ?? '') === $nid) { $saved = $x; break; } }
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'title' => $saved['title'] ?? '']);
            exit;
        }
        header('Location: ' . $bookUrl . '&note=' . urlencode($nid));
        exit;
    }
    if ($action === 'delete_note') {
        $nid = (string) ($_POST['id'] ?? '');
        $ret = ($_POST['ret'] ?? '') === 'chapters' ? '&view=chapters' : '';
        $notes = array_values(array_filter($notes, fn($n) => ($n['id'] ?? '') !== $nid));
        $map[$bookId] = $notes;
        bnotes_save($notesFile, $map);
        header('Location: ' . $bookUrl . $ret . '&edit=1');
        exit;
    }
    header('Location: ' . $listUrl);
    exit;
}

// --- Which view? ---
$books = books_load($booksFile);
$csrf  = htmlspecialchars($_SESSION['csrf'], ENT_QUOTES);

// One-time greeting on a fresh login (GET only — POSTs redirect before here).
$greet = false;
if (empty($_SESSION['aki_greeted'])) { $_SESSION['aki_greeted'] = true; $greet = true; }

$shelf = (string) ($_GET['shelf'] ?? 'library');
if (!in_array($shelf, ['library', 'read', 'want', 'data'], true)) { $shelf = 'library'; }

$bookId = (string) ($_GET['book'] ?? '');
$book   = null;
foreach ($books as $b) { if (($b['id'] ?? '') === $bookId) { $book = $b; break; } }

$noteId    = (string) ($_GET['note'] ?? '');
$bookNotes = $book ? (bnotes_load($notesFile)[$bookId] ?? []) : [];
$curNote   = null;
if ($book && $noteId !== '') {
    foreach ($bookNotes as $n) { if (!is_bsection($n) && ($n['id'] ?? '') === $noteId) { $curNote = $n; break; } }
}

// Folders (a book's Goodreads shelves become folders inside Library).
$folder     = (string) ($_GET['folder'] ?? '');
$allFolders = [];
foreach ($books as $b) {
    foreach (($b['folders'] ?? []) as $fn) {
        $fn = (string) $fn;
        if ($fn !== '' && !in_array($fn, $allFolders, true)) { $allFolders[] = $fn; }
    }
}
natcasesort($allFolders);
$allFolders = array_values($allFolders);

// Sort + rating filter (applies to the shelf/folder being viewed).
$curSort = in_array((string) ($_GET['sort'] ?? ''), ['stars', 'title', 'author', 'added', 'rated', 'edited'], true) ? (string) $_GET['sort'] : 'edited';
$curMin  = (string) ($_GET['min'] ?? '');
if (!in_array($curMin, ['', '5', '4', '3', '2', '1', 'unrated'], true)) { $curMin = ''; }
$sfBase  = '?shelf=' . urlencode($shelf) . (($shelf === 'library' && $folder !== '') ? '&folder=' . urlencode($folder) : '');

/** Consistent header: ‹ back (top-left) + title, and a username dropdown on the right. */
/**
 * The top bar. $withEdit puts the Edit toggle next to the username, the same size as
 * it — the screens that have nothing to edit (the note editor) leave it off.
 */
function books_header(string $titleHtml, bool $withEdit = false): void
{
    ?>
    <header>
      <div class="hleft">
        <?php // Same two-buttons-in-one-slot as the rest of the suite: "<" normally,
              // a black × that leaves edit mode while editing. ?>
        <button type="button" class="backbtn goback" onclick="history.back()" aria-label="Back">&lsaquo;</button>
        <button type="button" class="backbtn exitedit" id="exitEditBtn"
                title="Done editing" aria-label="Leave edit mode">&times;</button>
        <div class="htitle"><?= $titleHtml ?></div>
      </div>
      <div class="hright">
        <?php if ($withEdit): ?>
          <button type="button" class="hedit" id="editBtn" title="Edit" aria-label="Edit">&#9998;&#65038;</button>
        <?php endif; ?>
        <?php // Preferences live in the username's dropdown here too — same menu, no ⋮. ?>
        <div class="usercol">
          <div class="usermenu">
            <button type="button" class="who" id="userBtn"><span><?= e(current_user() ?? '') ?></span><span class="caret" aria-hidden="true">&#9662;</span></button>
            <div class="menu" id="userMenu" hidden>
              <button type="button" id="setBtn">Settings</button>
              <a href="?logout">Log out</a>
            </div>
          </div>
        </div>
        <?= settings_modal_html(book_theme_picker_html()) ?>
      </div>
    </header>
    <?php
}
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title>Aki&#39;s Bookshelf</title>
  <meta name="theme-color" content="<?= e(book_theme_bg()) ?>">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black">
  <meta name="apple-mobile-web-app-title" content="Aki&#39;s Bookshelf">
  <link rel="apple-touch-icon" href="<?= suite_base() ?>/akisbookshelf/icon-180.png">
  <link rel="icon" href="<?= suite_base() ?>/akisbookshelf/icon-192.png">
  <link rel="manifest" href="<?= suite_base() ?>/akisbookshelf/manifest.webmanifest">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: system-ui, sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; padding: 1.5rem 1rem; }
    .wrap { max-width: 760px; margin: 0 auto; }
    /* Same top bar as the rest of the suite: everything 32px on one line, rule under it. */
    header {
      display: flex; align-items: center; justify-content: space-between; gap: 0.5rem;
      margin-bottom: 0.5rem; padding-bottom: 0.7rem; border-bottom: 1px solid var(--line-soft);
    }
    .hleft { display: flex; align-items: center; gap: 0.75rem; min-width: 0; }
    .backbtn, .usermenu .who {
      height: 32px; display: inline-flex; align-items: center; justify-content: center;
      border: 1px solid var(--line); border-radius: 999px; background: none; color: var(--text-dim);
      font-family: inherit; line-height: 1; cursor: pointer; flex: 0 0 auto;
    }
    .backbtn { width: 32px; background: var(--surface); font-size: 1.35rem; padding: 0; }
    .backbtn:hover { border-color: var(--muted); color: var(--text); }
    .backbtn.exitedit { display: none; background: var(--bg); border-color: var(--line); color: var(--text); font-size: 1.2rem; }
    body.editing .backbtn.exitedit { display: inline-flex; }
    body.editing .backbtn.goback { display: none; }
    .htitle h1 { font-size: 1.35rem; }
    .htitle .ht-sub { font-size: 1.05rem; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 52vw; }

    /* Username dropdown */
    .usermenu { position: relative; flex: 0 0 auto; }
    .usermenu .who { padding: 0 0.8rem; color: var(--accent); font-size: 0.85rem; border-color: var(--line);
                     gap: 0.35rem; }
    .usermenu .who .caret { font-size: 0.7em; line-height: 1; }
    .hright { display: flex; align-items: center; gap: 0.75rem; flex: 0 0 auto; }
    /* The "⋮" sits against the username, not at the header's own gap. */
    .usercol { display: flex; align-items: center; gap: 0.35rem; flex: 0 0 auto; }
    /* Edit (a pencil) beside the username, the same pill and the same size. */
    .hedit {
      height: 32px; display: inline-flex; align-items: center; justify-content: center;
      padding: 0 0.6rem; background: none; border: 1px solid var(--line); border-radius: 999px;
      color: var(--text-dim); font-size: 0.95rem; font-family: inherit; line-height: 1;
      cursor: pointer; flex: 0 0 auto;
    }
    .hedit:hover { border-color: var(--muted); color: var(--text); }
    body.editing .hedit { background: var(--accent); border-color: var(--accent); color: var(--accent-ink); font-weight: 700; }
    .usermenu .who:hover { border-color: var(--accent); }
    .usermenu .menu {
      position: absolute; right: 0; top: calc(100% + 6px); z-index: 40;
      background: var(--surface); border: 1px solid var(--line); border-radius: 8px; min-width: 120px;
      box-shadow: 0 8px 20px rgba(0,0,0,0.5); overflow: hidden;
    }
    /* Settings and Log out, same as the rest of the suite; the button is dressed to be
       indistinguishable from the link beside it. */
    .usermenu .menu a, .usermenu .menu button {
      display: block; width: 100%; margin: 0; padding: 0.6rem 0.9rem; color: var(--text);
      text-decoration: none; font-size: 0.9rem; text-align: left; background: none;
      border: none; border-radius: 0; font-family: inherit; cursor: pointer;
    }
    .usermenu .menu a:hover, .usermenu .menu button:hover { background: var(--surface-2); }
    .usermenu .menu button { border-bottom: 1px solid var(--line); }

    /* Bottom main menu bar (standalone app): Library / Read / Want To Read / Data */
    body { padding-bottom: calc(70px + env(safe-area-inset-bottom, 0px)); }
    .shelfbar {
      position: fixed; left: 0; right: 0; bottom: 0; z-index: 50;
      background: var(--surface); border-top: 1px solid var(--surface-2);
      padding: 0.5rem 1rem calc(0.5rem + env(safe-area-inset-bottom, 0px));
    }
    .shelfbar .inner {
      display: flex; gap: 3px; width: 100%; max-width: 480px; margin: 0 auto;
      background: var(--bg); border: 1px solid var(--surface-2); border-radius: 10px; padding: 3px;
    }
    .shelfbar a {
      flex: 1; text-align: center; padding: 0.55rem 0.3rem; text-decoration: none; color: var(--muted);
      font-size: 0.82rem; font-weight: 600; border-radius: 8px;
    }
    .shelfbar a:hover { color: var(--text-dim); }
    .shelfbar a.active { background: var(--surface-2); color: var(--accent); }
    @media (max-width: 400px) { .shelfbar a { font-size: 0.7rem; } }

    /* Top bar */
    .bar { display: flex; align-items: center; justify-content: flex-end; gap: 0.5rem; margin-bottom: 1.25rem; }
    .bar .addbook {
      padding: 0.5rem 1rem; background: var(--accent); color: var(--accent-ink); border: none;
      border-radius: 999px; font-size: 0.95rem; font-weight: 700; cursor: pointer; white-space: nowrap;
    }
    .bar .addbook:hover { background: var(--accent); }
    .bar .editbtn {
      padding: 0.5rem 1rem; background: none; border: 1px solid var(--line); color: var(--text-dim);
      border-radius: 999px; font-size: 0.95rem; cursor: pointer;
    }
    .bar .editbtn:hover { border-color: var(--muted); color: var(--text); }
    .listbar .sortwrap { position: relative; margin-right: auto; }   /* sort/filter off to the left */
    #sortBtn { white-space: nowrap; }
    .sortmenu {
      position: absolute; left: 0; top: calc(100% + 6px); z-index: 40; min-width: 190px;
      background: var(--surface); border: 1px solid var(--line); border-radius: 8px; padding: 0.3rem;
      box-shadow: 0 8px 20px rgba(0,0,0,0.5);
    }
    .sortmenu .smhead { font-size: 0.66rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted); padding: 0.45rem 0.6rem 0.2rem; }
    .sortmenu a { display: block; padding: 0.45rem 0.6rem; color: var(--text); text-decoration: none; font-size: 0.88rem; border-radius: 6px; white-space: nowrap; }
    .sortmenu a:hover { background: var(--surface-2); }
    .sortmenu a.on { color: var(--accent); font-weight: 700; }
    /* Set cover sits to the right of the book's details, level with the middle of the
       cover, and only while editing. */
    .bh-cover-edit { display: none; margin-left: auto; align-self: flex-start; }
    /* Spans exactly the cover's height (84px wide at 2/3), so the button lands level
       with the middle of the cover rather than the middle of the taller text column. */
    body.editing .bh-cover-edit {
      display: flex; align-items: center; height: calc(84px * 3 / 2);
    }
    .bh-cover-edit .editbtn {
      padding: 0.5rem 1rem; background: none; border: 1px solid var(--line); color: var(--text-dim);
      border-radius: 999px; font-size: 0.95rem; cursor: pointer; font-family: inherit; white-space: nowrap;
    }
    .bh-cover-edit .editbtn:hover { border-color: var(--muted); color: var(--text); }
    .setcoverform { display: flex; gap: 0.5rem; margin: -0.6rem 0 1.25rem; }
    .setcoverform[hidden] { display: none; }   /* make the hidden attribute win over display:flex */
    .setcoverform input[type=url] { flex: 1; min-width: 0; padding: 0.5rem 0.7rem; background: var(--surface); border: 1px solid var(--line); border-radius: 6px; color: var(--text); font-size: 16px; }
    .setcoverform input[type=url]:focus { outline: none; border-color: var(--muted); }

    /* Book cards grid */
    .shelf { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 1rem; }
    .bookcard { position: relative; }
    .booklink { display: flex; flex-direction: column; text-decoration: none; color: var(--text); }
    .coverbox {
      position: relative; width: 100%; aspect-ratio: 2 / 3; border-radius: 8px; overflow: hidden;
      background: var(--surface); border: 1px solid var(--surface-2); display: flex; align-items: center; justify-content: center;
      box-shadow: 0 4px 12px rgba(0,0,0,0.4);
    }
    .coverbox .ph {
      position: absolute; inset: 0; display: flex; align-items: center; justify-content: center;
      padding: 0.6rem; text-align: center; font-size: 0.8rem; color: var(--muted); font-weight: 600;
    }
    .coverbox img { position: relative; width: 100%; height: 100%; object-fit: cover; }
    .booklink .btitle { margin-top: 0.5rem; font-size: 0.92rem; font-weight: 600; line-height: 1.25; }
    .booklink .bauthor { margin-top: 0.15rem; font-size: 0.78rem; color: var(--muted); }
    .cardmeta { display: flex; align-items: center; justify-content: space-between; margin-top: 0.35rem; gap: 0.4rem; }

    /* Stars */
    .stars { font-size: 0.95rem; letter-spacing: 1px; line-height: 1; white-space: nowrap; }
    /* The unfilled star reads as an outline, so it follows the rule colour rather than a
       surface — on a cream theme a surface-coloured star is invisible against the page. */
    .stars .star { color: var(--line); }
    .stars .star.on { color: var(--gold); }
    .stars.editable { font-size: 1.5rem; letter-spacing: 3px; }
    .stars.editable .star { cursor: pointer; }
    .stars.editable .star:hover { color: var(--gold); }
    /* Card stars: read-only until Edit mode, then tappable to set the rating (= read). */
    .stars.cardrate .star { cursor: default; }
    body.editing .stars.cardrate { font-size: 1.15rem; outline: 1px dashed var(--surface-2); border-radius: 5px; padding: 2px 4px; }
    body.editing .stars.cardrate .star { cursor: pointer; }
    body.editing .stars.cardrate .star:hover { color: var(--gold); }

    /* Read indicator (cards = disabled) */
    .readchk { display: inline-flex; align-items: center; gap: 0.3rem; font-size: 0.72rem; color: var(--muted); }
    .readchk input { width: 15px; height: 15px; accent-color: var(--accent); }

    .bookcard .bdel {
      position: absolute; top: 6px; right: 6px; display: none; z-index: 2;
      background: rgba(0,0,0,0.7); border: 1px solid var(--muted); color: var(--text); border-radius: 6px;
      width: 26px; height: 26px; font-size: 1rem; line-height: 1; cursor: pointer;
    }
    .bookcard .bdel:hover { border-color: #f66; color: #f66; }
    body.editing .bookcard .bdel { display: block; }

    .empty { color: var(--muted); text-align: center; padding: 2.5rem 0; }
    .empty strong { color: var(--accent); }

    /* Data tab */
    .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 1rem; }
    .stat { display: block; background: var(--surface); border: 1px solid var(--surface-2); border-radius: 12px; padding: 1.4rem 1rem; text-align: center; text-decoration: none; color: inherit; cursor: pointer; }
    a.stat:hover { border-color: var(--accent); }
    .stat .num { font-size: 2.4rem; font-weight: 800; color: var(--accent); line-height: 1; }
    .stat .lbl { margin-top: 0.5rem; font-size: 0.85rem; color: var(--muted); }
    .stat .lbl span { display: block; font-size: 0.72rem; color: var(--muted); margin-top: 0.15rem; }

    /* Folders (Goodreads shelves) inside Library */
    .folders { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 1rem; margin-bottom: 1.25rem; }
    .foldertile {
      display: flex; flex-direction: column; justify-content: center; gap: 0.2rem;
      aspect-ratio: 3 / 2; padding: 0.9rem; text-decoration: none; color: var(--text);
      background: var(--surface); border: 1px solid var(--line); border-radius: 10px;
    }
    .foldertile:hover { border-color: var(--gold); }
    .foldertile .ficon { font-size: 1.6rem; }
    .foldertile .fname { font-weight: 700; font-size: 0.95rem; word-break: break-word; }
    .foldertile .fcount { font-size: 0.75rem; color: var(--muted); }
    .folderback { display: flex; align-items: center; gap: 0.8rem; margin-bottom: 1rem; }
    .folderback a { color: var(--accent); text-decoration: none; font-size: 0.9rem; }
    .folderback .folder-h { font-weight: 700; color: var(--gold); }

    /* Search modal */
    .modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.65); z-index: 60; display: none; align-items: flex-start; justify-content: center; padding: 1.2rem 1rem; }
    .modal-backdrop.open { display: flex; }
    .modal { background: var(--surface); border: 1px solid var(--line); border-radius: 12px; width: 100%; max-width: 520px; margin-top: 4vh; max-height: 88vh; display: flex; flex-direction: column; overflow: hidden; }
    .modal .mhead { display: flex; align-items: center; gap: 0.5rem; padding: 1rem 1rem 0.75rem; }
    .modal .mhead h2 { font-size: 1.05rem; flex: 1; }
    .modal .mhead .mclose { background: none; border: none; color: var(--muted); font-size: 1.4rem; line-height: 1; cursor: pointer; }
    .modal .mhead .mclose:hover { color: var(--text); }
    .modal .msearch { padding: 0 1rem 0.75rem; }
    .modal .msearch input { width: 100%; padding: 0.6rem 0.75rem; background: var(--surface-2); border: 1px solid var(--surface-2); border-radius: 8px; color: var(--text); font-size: 1rem; }
    .modal .msearch input:focus { outline: none; border-color: var(--accent); }
    .results { overflow-y: auto; padding: 0 0.5rem 0.5rem; }
    .results .hint, .results .loading { color: var(--muted); font-size: 0.9rem; text-align: center; padding: 1.5rem 0; }
    .rrow { display: flex; gap: 0.75rem; align-items: center; padding: 0.5rem; border-radius: 8px; cursor: pointer; }
    .rrow:hover { background: var(--surface-2); }
    .rrow .rcover { width: 44px; height: 66px; flex: 0 0 auto; border-radius: 4px; object-fit: cover; background: var(--line-soft); border: 1px solid var(--line); }
    .rrow .rmeta { flex: 1; min-width: 0; }
    .rrow .rtitle { font-size: 0.95rem; font-weight: 600; }
    .rrow .rauthor { font-size: 0.8rem; color: var(--muted); margin-top: 0.1rem; }
    .rrow .radd { flex: 0 0 auto; background: var(--accent-soft); color: var(--accent); border: 1px solid var(--line); border-radius: 999px; padding: 0.3rem 0.7rem; font-size: 0.8rem; font-weight: 700; }

    /* ---- Book page (notes) ---- */
    .bookhead { display: flex; gap: 0.9rem; align-items: flex-start; margin-bottom: 1.25rem; }
    .bookhead .coverbox { width: 84px; flex: 0 0 auto; }
    .bookhead .bh-title { font-size: 1.2rem; font-weight: 700; line-height: 1.2; }
    .bookhead .bh-author { font-size: 0.85rem; color: var(--muted); margin-top: 0.2rem; }
    .bookhead .bh-dates { display: flex; flex-wrap: wrap; gap: 0.15rem 0.9rem; margin-top: 0.35rem; font-size: 0.72rem; color: var(--muted); }
    .bookhead .bh-stars { margin-top: 0.5rem; }
    .bookhead .bh-flags { display: flex; flex-direction: column; gap: 0.35rem; margin-top: 0.6rem; }
    .bookhead .bh-flags .flagrow { display: flex; gap: 1rem; flex-wrap: wrap; }
    .bookhead .chk { display: inline-flex; align-items: center; gap: 0.45rem; font-size: 0.9rem; color: var(--text-dim); cursor: pointer; }
    .bookhead .chk input { width: 18px; height: 18px; accent-color: var(--accent); cursor: pointer; }
    .bookhead .flaghint { font-size: 0.72rem; color: var(--muted); }

    ul.nlist { list-style: none; margin-bottom: 0.5rem; }
    ul.nlist li { border-bottom: 1px solid var(--surface-2); display: flex; align-items: center; }
    .noteitem { flex: 1; display: flex; align-items: center; gap: 0.6rem; padding: 0.85rem 0.25rem; text-decoration: none; color: var(--text); }
    .noteitem:hover { background: var(--surface); }
    .noteitem .ntitle { flex: 1; font-size: 1.02rem; word-break: break-word; }
    .noteitem .nchev { color: var(--muted); font-size: 1.1rem; }
    .ndel .del { display: none; background: none; border: 1px solid var(--line); color: var(--text-dim); cursor: pointer; margin-left: 0.5rem; border-radius: 6px; padding: 0.3rem 0.55rem; font-size: 0.95rem; line-height: 1; }
    body.editing .ndel .del { display: inline-block; }
    .ndel .del:hover { border-color: #f66; color: #f66; }

    /* Drag-to-reorder notes (edit mode) */
    /* Hidden, not gone: taking the handle out of the flow shifted every title sideways. */
    .nlist .drag-handle { visibility: hidden; flex: 0 0 auto; width: 1rem; display: inline-flex; align-items: center; justify-content: center; color: var(--muted); font-size: 0.9rem; cursor: grab; touch-action: none; user-select: none; }
    body.editing .nlist .drag-handle { visibility: visible; }
    .nlist .drag-handle:active { cursor: grabbing; color: #8b6ef0; }
    .nlist li.dragging { background: var(--surface-2); border-radius: 6px; box-shadow: 0 4px 14px rgba(0,0,0,0.45); }
    body.editing #bnotes-root ul.nlist:empty { min-height: 1.5rem; border: 1px dashed var(--line); border-radius: 6px; margin: 0.3rem 0; }
    /* Hold-to-drag: stop iOS text selection / callout on the rows while editing. */
    body.editing #bnotes-root li { -webkit-touch-callout: none; -webkit-user-select: none; user-select: none; }

    /* Chapters (purple) */
    .chaptersbtn {
      display: inline-flex; align-items: center; gap: 0.45rem; background: #8b6ef0; color: var(--text);
      text-decoration: none; font-weight: 700; font-size: 0.95rem; padding: 0.55rem 1rem;
      border-radius: 8px; margin-bottom: 1rem;
    }
    .chaptersbtn:hover { background: #a288f5; }
    .chaptersbtn .chev { font-size: 1.15rem; line-height: 1; }
    .addchapter { background: #8b6ef0; color: var(--text); border: none; border-radius: 999px; padding: 0.5rem 1rem; font-weight: 700; font-size: 0.95rem; cursor: pointer; }
    .addchapter:hover { background: #a288f5; }
    .chapters-h { color: #b9a7f5 !important; }

    /* Book-note sections */
    .newsection-form { margin-bottom: 0.75rem; }
    .newsection-form input { width: 190px; max-width: 100%; padding: 0.35rem 0.8rem; background: var(--surface); border: 1px dashed var(--line); border-radius: 999px; color: var(--gold); font-size: 16px; }
    .newsection-form input::placeholder { color: var(--gold); opacity: 0.85; }
    .newsection-form input:focus { outline: none; border-style: solid; border-color: var(--gold); }
    /* Same side padding as a note row, so the section's X sits under the rows' Xs. */
    .section-head { display: flex; align-items: center; gap: 0.5rem; margin: 1.4rem 0 0.3rem; padding: 0 0.25rem; }
    .section-head form { margin-left: auto; }
    .section-title { font-weight: 700; font-size: 1.15rem; color: var(--gold); }
    /* A section can't be dragged here, but it keeps the handle's slot so its name
       starts level with the note titles under it. */
    .section-head .sec-handle { flex: 0 0 auto; width: 1rem; margin-right: -0.5rem; }
    .section-del { display: none; background: none; border: 1px solid var(--line); color: var(--text-dim); border-radius: 6px; padding: 0.3rem 0.55rem; font-size: 0.95rem; line-height: 1; cursor: pointer; font-family: inherit; }
    body.editing .section-del { display: inline-block; }
    .section-del:hover { border-color: #f66; color: #f66; }
    .editor select.secsel { padding: 0.5rem 0.6rem; background: var(--surface); border: 1px solid var(--line); border-radius: 6px; color: var(--gold); font-size: 0.9rem; color-scheme: var(--scheme); cursor: pointer; align-self: flex-start; }
    .editor select.secsel:focus { outline: none; border-color: var(--gold); }

    /* ---- Note editor ---- */
    .editor { display: flex; flex-direction: column; gap: 0.6rem; }
    .editor input[type=text] { padding: 0.6rem 0.75rem; background: var(--surface); border: 1px solid var(--line); border-radius: 6px; color: var(--text); font-size: 1.05rem; font-weight: 600; }
    .editor input:focus, .editor textarea:focus { outline: none; border-color: var(--muted); }
    .editor textarea { width: 100%; min-height: 320px; resize: vertical; padding: 0.8rem; background: var(--surface); border: 1px solid var(--line); border-radius: 6px; color: var(--text); font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 0.95rem; line-height: 1.5; }
    .editor .actions { display: flex; align-items: center; gap: 0.75rem; }
    .editor .meta { font-size: 0.72rem; color: var(--muted); }
    .editor button.del { margin-left: auto; background: none; border: none; color: var(--muted); font-size: 0.8rem; cursor: pointer; }
    .editor button.del:hover { color: #f66; }

    /* One-time login greeting */
    .lovebanner {
      position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%) scale(0.9);
      z-index: 200; background: linear-gradient(135deg, #d6336c, #8b6ef0); color: var(--text);
      font-weight: 700; font-size: 1.25rem; padding: 1rem 1.6rem; border-radius: 999px;
      box-shadow: 0 12px 32px rgba(0,0,0,0.55); opacity: 0; transition: opacity 0.4s ease, transform 0.4s ease;
      white-space: nowrap; pointer-events: none; text-align: center;
    }
    .lovebanner.show { opacity: 1; transform: translate(-50%, -50%) scale(1); }
<?= settings_modal_styles() ?>
<?= theme_css() ?>
<?= book_theme_css() ?>
    /* The suite's Theme row only swaps an accent, and this app's own themes set --accent
       themselves — leaving both on screen would offer a control that appears to do
       nothing here. Hidden in this app only; every other app keeps it. */
    .setmodal .setthemes { display: none; }
    .setmodal .bkthemes { margin-top: 1.1rem; }
    .setmodal .bkthemerow { display: flex; flex-wrap: wrap; gap: 0.5rem; }
    /* Each swatch is the theme's own page with its accent as a dot, because eight
       accent-only circles can't tell Midnight from Forest. */
    .setmodal .bkthemebtn {
      width: 30px; height: 30px; border-radius: 50%; border: 1px solid;
      display: inline-flex; align-items: center; justify-content: center;
      cursor: pointer; padding: 0; outline: 2px solid transparent; outline-offset: 1px;
    }
    .setmodal .bkthemebtn span { width: 11px; height: 11px; border-radius: 50%; display: block; }
    .setmodal .bkthemebtn.on { outline-color: var(--text); }
    /* The settings window is the suite's shared one (lib/chrome.php) and is painted for a
       dark app. Repainting it there would change every app, so the bookshelf re-points
       just its surfaces at the theme here — otherwise a cream theme opens a black window
       over a light page. Same selectors, this app only. */
    .setmodal { background: var(--surface); border-color: var(--line); color: var(--text); }
    .setmodal h2 { color: var(--text); }
    .setmodal .setwho, .setmodal .setlabel { color: var(--muted); }
    .setmodal label { color: var(--text-dim); }
    .setmodal input[type=password] { background: var(--surface-2); border-color: var(--line); color: var(--text); }
    .setmodal input[type=password]:focus { border-color: var(--muted); }
    .setmodal .setsave:hover { background: var(--accent); }
    .setmodal .setpwtoggle, .setmodal .setact { border-color: var(--line); color: var(--text-dim); }
    .setmodal .setpwtoggle:hover, .setmodal .setact:hover { border-color: var(--muted); color: var(--text); }
<?= confirm_delete_styles() ?>
<?= rt_styles() ?>
  </style>
</head>
<body>
<?php if ($greet): ?><div class="lovebanner" id="loveBanner">I love you, baby! &#10084;&#65039;</div><?php endif; ?>
<div class="wrap">
<?php if (!$book): ?>
  <!-- ===================== BOOKS LIST ===================== -->
  <?php books_header('<h1>Aki&rsquo;s Bookshelf</h1>', true); ?>

  <?php if ($shelf === 'data'): ?>
    <?php
      $monthStart = mktime(0, 0, 0, (int) date('n'), 1, (int) date('Y'));
      $yearStart  = mktime(0, 0, 0, 1, 1, (int) date('Y'));
      $isRead = fn($b) => ((int) ($b['rating'] ?? 0)) > 0 && empty($b['past']);   // rated & not "past"
      $metricBooks = [
          'month'   => array_values(array_filter($books, fn($b) => $isRead($b) && (int) ($b['read_at'] ?? 0) >= $monthStart)),
          'year'    => array_values(array_filter($books, fn($b) => $isRead($b) && (int) ($b['read_at'] ?? 0) >= $yearStart)),
          'want'    => array_values(array_filter($books, fn($b) => !empty($b['want']))),
          'library' => $books,
      ];
      $metricLabels = ['month' => 'Read this month', 'year' => 'Read this year', 'want' => 'Want to read', 'library' => 'Books in library'];
      $metric = (string) ($_GET['metric'] ?? '');
      if (!isset($metricBooks[$metric])) { $metric = ''; }
    ?>
    <?php if ($metric !== ''): ?>
      <div class="folderback"><a href="?shelf=data">&larr; Data</a><span class="folder-h"><?= e($metricLabels[$metric]) ?> &middot; <?= count($metricBooks[$metric]) ?></span></div>
      <?php
        $mb = $metricBooks[$metric];
        usort($mb, fn($a, $b) => ((int) ($b['rating'] ?? 0)) <=> ((int) ($a['rating'] ?? 0)) ?: strcasecmp((string) ($a['title'] ?? ''), (string) ($b['title'] ?? '')));
      ?>
      <?php if (!$mb): ?>
        <p class="empty">No books here yet.</p>
      <?php else: ?>
        <div class="shelf"><?php foreach ($mb as $b) { render_book_card($b, $csrf, 'data'); } ?></div>
      <?php endif; ?>
    <?php else: ?>
      <div class="stats">
        <a class="stat" href="?shelf=data&amp;metric=month"><div class="num"><?= count($metricBooks['month']) ?></div><div class="lbl">Read this month<span><?= date('F Y') ?></span></div></a>
        <a class="stat" href="?shelf=data&amp;metric=year"><div class="num"><?= count($metricBooks['year']) ?></div><div class="lbl">Read this year<span><?= date('Y') ?></span></div></a>
        <a class="stat" href="?shelf=data&amp;metric=want"><div class="num"><?= count($metricBooks['want']) ?></div><div class="lbl">Want to read</div></a>
        <a class="stat" href="?shelf=data&amp;metric=library"><div class="num"><?= count($metricBooks['library']) ?></div><div class="lbl">Books in library</div></a>
      </div>
    <?php endif; ?>
  <?php else: ?>

  <div class="bar listbar">
    <div class="sortwrap">
      <button type="button" id="sortBtn" class="editbtn">&#8645; Sort / Filter</button>
      <div class="sortmenu" id="sortMenu" hidden>
        <div class="smhead">Sort by</div>
        <a href="<?= e(sf_url($sfBase, 'edited', $curMin)) ?>" class="<?= $curSort === 'edited' ? 'on' : '' ?>">Recently edited</a>
        <a href="<?= e(sf_url($sfBase, 'stars', $curMin)) ?>" class="<?= $curSort === 'stars' ? 'on' : '' ?>">&#9733; Stars</a>
        <a href="<?= e(sf_url($sfBase, 'title', $curMin)) ?>" class="<?= $curSort === 'title' ? 'on' : '' ?>">Title</a>
        <a href="<?= e(sf_url($sfBase, 'author', $curMin)) ?>" class="<?= $curSort === 'author' ? 'on' : '' ?>">Author</a>
        <a href="<?= e(sf_url($sfBase, 'added', $curMin)) ?>" class="<?= $curSort === 'added' ? 'on' : '' ?>">Date added</a>
        <a href="<?= e(sf_url($sfBase, 'rated', $curMin)) ?>" class="<?= $curSort === 'rated' ? 'on' : '' ?>">Date rated</a>
        <div class="smhead">Show</div>
        <a href="<?= e(sf_url($sfBase, $curSort, '')) ?>" class="<?= $curMin === '' ? 'on' : '' ?>">All ratings</a>
        <a href="<?= e(sf_url($sfBase, $curSort, '5')) ?>" class="<?= $curMin === '5' ? 'on' : '' ?>">&#9733;&#9733;&#9733;&#9733;&#9733; only</a>
        <a href="<?= e(sf_url($sfBase, $curSort, '4')) ?>" class="<?= $curMin === '4' ? 'on' : '' ?>">&#9733;&#9733;&#9733;&#9733; &amp; up</a>
        <a href="<?= e(sf_url($sfBase, $curSort, '3')) ?>" class="<?= $curMin === '3' ? 'on' : '' ?>">&#9733;&#9733;&#9733; &amp; up</a>
        <a href="<?= e(sf_url($sfBase, $curSort, 'unrated')) ?>" class="<?= $curMin === 'unrated' ? 'on' : '' ?>">Unrated</a>
      </div>
    </div>
    <button type="button" id="addBookBtn" class="addbook">+ Add book</button>
  </div>

  <?php
    $inFolder  = ($shelf === 'library' && $folder !== '' && in_array($folder, $allFolders, true));
    $showTiles = ($shelf === 'library' && !$inFolder);
    if ($shelf === 'read') {
        $shown = array_values(array_filter($books, fn($b) => ((int) ($b['rating'] ?? 0)) > 0 || !empty($b['past'])));
    } elseif ($shelf === 'want') {
        $shown = array_values(array_filter($books, fn($b) => !empty($b['want'])));
    } elseif ($inFolder) {
        $shown = array_values(array_filter($books, fn($b) => in_array($folder, $b['folders'] ?? [], true)));
    } else {
        $shown = array_values(array_filter($books, fn($b) => empty($b['folders'])));   // Library top level: loose books only
    }
    // Rating filter
    if ($curMin === 'unrated') {
        $shown = array_values(array_filter($shown, fn($b) => ((int) ($b['rating'] ?? 0)) === 0));
    } elseif ($curMin !== '') {
        $shown = array_values(array_filter($shown, fn($b) => ((int) ($b['rating'] ?? 0)) >= (int) $curMin));
    }
    // Sort (default: recently edited, newest first)
    usort($shown, function ($a, $b) use ($curSort) {
        if ($curSort === 'title')  { return strcasecmp((string) ($a['title'] ?? ''), (string) ($b['title'] ?? '')); }
        if ($curSort === 'author') { $c = strcasecmp((string) ($a['author'] ?? ''), (string) ($b['author'] ?? '')); return $c !== 0 ? $c : strcasecmp((string) ($a['title'] ?? ''), (string) ($b['title'] ?? '')); }
        if ($curSort === 'rated')  { $c = ((int) ($b['read_at'] ?? 0)) <=> ((int) ($a['read_at'] ?? 0)); return $c !== 0 ? $c : strcasecmp((string) ($a['title'] ?? ''), (string) ($b['title'] ?? '')); }
        if ($curSort === 'added')  { return ((int) ($b['created'] ?? 0)) <=> ((int) ($a['created'] ?? 0)); }
        if ($curSort === 'stars')  {
            $r = ((int) ($b['rating'] ?? 0)) <=> ((int) ($a['rating'] ?? 0));
            return $r !== 0 ? $r : strcasecmp((string) ($a['title'] ?? ''), (string) ($b['title'] ?? ''));
        }
        // Books from before this field existed fall back to when they were added.
        $au = (int) ($a['updated'] ?? $a['created'] ?? 0);
        $bu = (int) ($b['updated'] ?? $b['created'] ?? 0);
        return $bu <=> $au ?: strcasecmp((string) ($a['title'] ?? ''), (string) ($b['title'] ?? ''));
    });
  ?>
  <?php if ($inFolder): ?>
    <div class="folderback"><a href="?shelf=library">&larr; Library</a><span class="folder-h">&#128193; <?= e($folder) ?></span></div>
  <?php endif; ?>
  <?php if ($showTiles && $allFolders): ?>
    <div class="folders">
      <?php foreach ($allFolders as $fn): ?>
        <?php $fcnt = count(array_filter($books, fn($b) => in_array($fn, $b['folders'] ?? [], true))); ?>
        <a class="foldertile" href="?shelf=library&amp;folder=<?= urlencode($fn) ?>">
          <span class="ficon">&#128193;</span>
          <span class="fname"><?= e($fn) ?></span>
          <span class="fcount"><?= $fcnt ?> book<?= $fcnt === 1 ? '' : 's' ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?php if (!$shown && !($showTiles && $allFolders)): ?>
    <p class="empty">
      <?php if ($shelf === 'read'): ?>No books rated yet — rate a book to mark it read.
      <?php elseif ($shelf === 'want'): ?>Nothing on your Want&nbsp;To&nbsp;Read shelf yet.
      <?php elseif ($inFolder): ?>No books in this folder.
      <?php else: ?>No books yet. Tap <strong>+ Add book</strong> to search and pick a cover.<?php endif; ?>
    </p>
  <?php endif; ?>
  <?php if ($shown): ?>
    <div class="shelf">
      <?php foreach ($shown as $b) { render_book_card($b, $csrf, $shelf); } ?>
    </div>
  <?php endif; ?>


  <!-- Hidden form that actually adds the chosen search result -->
  <form id="addForm" method="post" action="" style="display:none">
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <input type="hidden" name="action" value="add_book">
    <input type="hidden" name="shelf" value="<?= e($shelf) ?>">
    <input type="hidden" name="title"  id="afTitle">
    <input type="hidden" name="author" id="afAuthor">
    <input type="hidden" name="cover"  id="afCover">
    <input type="hidden" name="key"    id="afKey">
  </form>

  <!-- Search modal -->
  <div class="modal-backdrop" id="searchModal">
    <div class="modal">
      <div class="mhead">
        <h2>Add a book</h2>
        <button type="button" class="mclose" id="mClose">&times;</button>
      </div>
      <div class="msearch">
        <input type="text" id="q" placeholder="Search title or author…" autocomplete="off">
      </div>
      <div class="results" id="results">
        <p class="hint">Type a title or author to find cover matches.</p>
      </div>
    </div>
  </div>
  <?php endif; ?>

<?php elseif ($book && $curNote === null && ($_GET['view'] ?? '') === 'chapters'): ?>
  <!-- ===================== CHAPTERS VIEW ===================== -->
  <?php
    $chapters = array_values(array_filter($bookNotes, fn($it) => !is_bsection($it) && !empty($it['chapter'])));
    usort($chapters, function ($a, $b) {
        $an = preg_match('/^Chapter\s+(\d+)$/', (string) ($a['title'] ?? ''), $m) ? (int) $m[1] : PHP_INT_MAX;
        $bn = preg_match('/^Chapter\s+(\d+)$/', (string) ($b['title'] ?? ''), $m) ? (int) $m[1] : PHP_INT_MAX;
        return $an !== $bn ? $an <=> $bn : (($a['created'] ?? 0) <=> ($b['created'] ?? 0));
    });
  ?>
  <?php books_header('<div class="ht-sub">' . e($book['title'] ?? 'Book') . '</div>', true); ?>
  <div class="folderback">
    <a href="?book=<?= urlencode($book['id']) ?>">&larr; <?= e($book['title'] ?? 'Book') ?></a>
    <span class="folder-h chapters-h">&#128278; Chapters</span>
  </div>

  <div class="bar">
    <form method="post" action="" style="margin:0">
      <input type="hidden" name="csrf" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="add_chapter">
      <input type="hidden" name="book" value="<?= e($book['id']) ?>">
      <button class="addchapter" type="submit">+ Chapter</button>
    </form>
  </div>

  <?php if (!$chapters): ?>
    <p class="empty">No chapters yet. Tap <strong>+ Chapter</strong> to add Chapter 1.</p>
  <?php else: ?>
    <ul class="nlist">
      <?php foreach ($chapters as $n): ?>
        <li>
          <a class="noteitem" href="?book=<?= urlencode($book['id']) ?>&amp;note=<?= e($n['id']) ?>">
            <span class="ntitle"><?= e($n['title'] ?? 'Chapter') ?></span>
            <span class="nchev">&rsaquo;</span>
          </a>
          <form method="post" action="" class="ndel">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="delete_note">
            <input type="hidden" name="book" value="<?= e($book['id']) ?>">
            <input type="hidden" name="id" value="<?= e($n['id']) ?>">
            <input type="hidden" name="ret" value="chapters">
            <button class="del needs-confirm" type="submit" title="Delete chapter">&times;</button>
          </form>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>


<?php elseif ($book && $curNote === null): ?>
  <!-- ===================== BOOK PAGE (rating + notes) ===================== -->
  <?php books_header('<div class="ht-sub">' . e($book['title'] ?? 'Book') . '</div>', true); ?>
  <div class="bookhead">
    <span class="coverbox">
      <span class="ph"><?= e($book['title'] ?? '') ?></span>
      <?php $cu = book_cover($book, 'M'); if ($cu !== ''): ?>
        <img src="<?= e($cu) ?>" alt="" onerror="this.remove()">
      <?php endif; ?>
    </span>
    <div>
      <div class="bh-title"><?= e($book['title'] ?? 'Untitled') ?></div>
      <?php if (!empty($book['author'])): ?><div class="bh-author"><?= e($book['author']) ?></div><?php endif; ?>
      <div class="bh-dates">
        <?php if (!empty($book['created'])): ?><span>Added <?= date('M j, Y', (int) $book['created']) ?></span><?php endif; ?>
        <?php if (!empty($book['read_at'])): ?><span>Rated <?= date('M j, Y', (int) $book['read_at']) ?></span><?php endif; ?>
      </div>
      <div class="bh-stars"><?= stars_html((int) ($book['rating'] ?? 0), true, $book['id']) ?></div>
      <div class="bh-flags" data-book="<?= e($book['id']) ?>">
        <div class="flagrow">
          <label class="chk"><input type="checkbox" id="pastChk" <?= !empty($book['past']) ? 'checked' : '' ?>> Past?</label>
          <label class="chk"><input type="checkbox" id="wantChk" <?= !empty($book['want']) ? 'checked' : '' ?>> Want to read</label>
        </div>
        <div class="flaghint">Rate it above to mark it read.</div>
      </div>
    </div>
    <?php // Only while editing, and centred against the cover rather than the text. ?>
    <div class="bh-cover-edit">
      <button type="button" id="setCoverBtn" class="editbtn">Set cover</button>
    </div>
  </div>

  <div class="bar">
    <form method="post" action="" style="margin:0">
      <input type="hidden" name="csrf" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="add_note">
      <input type="hidden" name="book" value="<?= e($book['id']) ?>">
      <button class="addbook" type="submit">+ Note</button>
    </form>
  </div>
  <form class="setcoverform" id="setCoverForm" method="post" action="" hidden>
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <input type="hidden" name="action" value="set_cover">
    <input type="hidden" name="book" value="<?= e($book['id']) ?>">
    <input type="url" name="cover_url" placeholder="Paste a cover image URL (Amazon, publisher, Archive.org…)" value="<?= e($book['cover_url'] ?? '') ?>">
    <button type="submit" class="addbook">Save</button>
  </form>

  <?php
    // Split book notes into sections (bold headers) + notes grouped under them.
    // Chapters live in their own view, so they're excluded from the normal list.
    $bSections = [];
    foreach ($bookNotes as $it) { if (is_bsection($it) && !in_array($it['name'], $bSections, true)) { $bSections[] = $it['name']; } }
    $chapCount = count(array_filter($bookNotes, fn($it) => !is_bsection($it) && !empty($it['chapter'])));
    // Stored array order = the manual (drag) order.
    $bNoteRows = array_values(array_filter($bookNotes, fn($it) => !is_bsection($it) && empty($it['chapter'])));
    $ungroupedN = []; $groupedN = [];
    foreach ($bNoteRows as $n) {
        $s = (string) ($n['section'] ?? '');
        if ($s !== '' && in_array($s, $bSections, true)) { $groupedN[$s][] = $n; } else { $ungroupedN[] = $n; }
    }
    /** Echo a <ul> of book-note rows. Always emitted (empty = a drag drop target). */
    $renderBNotes = function (array $rows, string $section = '') use ($book, $csrf) {
        echo '<ul class="nlist" data-section="' . e($section) . '">';
        foreach ($rows as $n) { ?>
          <li data-id="<?= e($n['id']) ?>">
            <span class="drag-handle" title="Drag to reorder" aria-hidden="true">&#9776;</span>
            <a class="noteitem" href="?book=<?= urlencode($book['id']) ?>&amp;note=<?= e($n['id']) ?>">
              <span class="ntitle"><?= e($n['title'] ?? 'Untitled note') ?></span>
              <span class="nchev">&rsaquo;</span>
            </a>
            <form method="post" action="" class="ndel">
              <input type="hidden" name="csrf" value="<?= $csrf ?>">
              <input type="hidden" name="action" value="delete_note">
              <input type="hidden" name="book" value="<?= e($book['id']) ?>">
              <input type="hidden" name="id" value="<?= e($n['id']) ?>">
              <button class="del needs-confirm" type="submit" title="Delete note">&times;</button>
            </form>
          </li>
        <?php }
        echo '</ul>';
    };
  ?>

  <a class="chaptersbtn" href="?book=<?= urlencode($book['id']) ?>&amp;view=chapters">
    Chapters<?= $chapCount ? ' &middot; ' . $chapCount : '' ?> <span class="chev">&rsaquo;</span>
  </a>

  <form method="post" action="" class="newsection-form" onsubmit="return this.name.value.trim()!==''">
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <input type="hidden" name="action" value="add_bsection">
    <input type="hidden" name="book" value="<?= e($book['id']) ?>">
    <input type="text" name="name" placeholder="+ Section" maxlength="60" autocomplete="off">
  </form>

  <?php if (!$bNoteRows && !$bSections): ?>
    <p class="empty">No notes for this book yet. Tap <strong>+ Note</strong> to start.</p>
  <?php else: ?>
   <div id="bnotes-root">
    <?php $renderBNotes($ungroupedN, ''); ?>
    <?php foreach ($bSections as $sname): ?>
      <div class="section-head">
        <span class="sec-handle" aria-hidden="true"></span>
        <span class="section-title"><?= e($sname) ?></span>
        <form method="post" action="" style="display:inline">
          <input type="hidden" name="csrf" value="<?= $csrf ?>">
          <input type="hidden" name="action" value="delete_bsection">
          <input type="hidden" name="book" value="<?= e($book['id']) ?>">
          <input type="hidden" name="name" value="<?= e($sname) ?>">
          <button class="section-del needs-confirm" type="submit" title="Delete section">&times;</button>
        </form>
      </div>
      <?php $renderBNotes($groupedN[$sname] ?? [], $sname); ?>
    <?php endforeach; ?>
   </div>
  <?php endif; ?>


<?php else: ?>
  <!-- ===================== BOOK NOTE EDITOR ===================== -->
  <?php
    $noteDefault = date('m/d/Y h:i a', (int) ($curNote['created'] ?? time())) . ' - Note';
    $editSections = [];
    if (empty($curNote['chapter'])) {   // chapters aren't grouped into sections
        foreach ($bookNotes as $it) { if (is_bsection($it) && !in_array($it['name'], $editSections, true)) { $editSections[] = $it['name']; } }
    }
  ?>
  <?php books_header('<div class="ht-sub">' . e($book['title'] ?? 'Book') . '</div>'); ?>
  <form class="editor" method="post" action="">
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <input type="hidden" name="action" value="save_note">
    <input type="hidden" name="book" value="<?= e($book['id']) ?>">
    <input type="hidden" name="id" value="<?= e($curNote['id']) ?>">
    <input type="text" name="title" placeholder="Title" maxlength="200"
           value="<?= e($curNote['title'] ?? '') ?>" data-default="<?= e($noteDefault) ?>">
    <?php if ($editSections): ?>
      <select name="section" class="secsel" title="Section">
        <option value="">No section</option>
        <?php foreach ($editSections as $sname): ?>
          <option value="<?= e($sname) ?>" <?= ($curNote['section'] ?? '') === $sname ? 'selected' : '' ?>><?= e($sname) ?></option>
        <?php endforeach; ?>
      </select>
    <?php else: ?>
      <input type="hidden" name="section" value="<?= e($curNote['section'] ?? '') ?>">
    <?php endif; ?>
    <?= rt_toolbar_html(true) ?>
    <div class="rt-body" contenteditable="true" data-placeholder="Notes on this book&hellip;"><?= rt_body_html($curNote['body'] ?? '') ?></div>
    <input type="hidden" class="rt-value" name="body" value="<?= e(rt_body_html($curNote['body'] ?? '')) ?>">
    <div class="actions">
      <span class="meta" id="saveStatus">Saved</span>
      <button class="del needs-confirm" type="submit" name="action" value="delete_note">Delete</button>
    </div>
  </form>
  <?= rt_entry_modal_html() ?>
<?php endif; ?>
</div>
<nav class="shelfbar">
  <div class="inner">
    <a href="?shelf=library" class="<?= (!$book && $shelf === 'library') ? 'active' : '' ?>">Library</a>
    <a href="?shelf=read" class="<?= (!$book && $shelf === 'read') ? 'active' : '' ?>">Read</a>
    <a href="?shelf=want" class="<?= (!$book && $shelf === 'want') ? 'active' : '' ?>">Want To Read</a>
    <a href="?shelf=data" class="<?= (!$book && $shelf === 'data') ? 'active' : '' ?>">Data</a>
  </div>
</nav>
<script>
  // ---- One-time login greeting: show for ~2s, then fade out ----
  const loveBanner = document.getElementById('loveBanner');
  if (loveBanner) {
    requestAnimationFrame(() => loveBanner.classList.add('show'));
    setTimeout(() => loveBanner.classList.remove('show'), 2000);
    setTimeout(() => loveBanner.remove(), 2500);
  }

  // ---- The black × in the back button's slot: leaves edit mode ----
  const exitEditBtn = document.getElementById('exitEditBtn');
  if (exitEditBtn) {
    exitEditBtn.addEventListener('click', (e) => {
      e.preventDefault(); e.stopPropagation();
      document.body.classList.remove('editing');
    });
  }

  // ---- Username dropdown ----
  const userBtn = document.getElementById('userBtn');
  const userMenu = document.getElementById('userMenu');
  if (userBtn && userMenu) {
    userBtn.addEventListener('click', (e) => { e.stopPropagation(); userMenu.hidden = !userMenu.hidden; });
    document.addEventListener('click', (e) => { if (!userMenu.hidden && !userMenu.contains(e.target)) userMenu.hidden = true; });
  }

  // ---- Sort / Filter dropdown ----
  const sortBtn = document.getElementById('sortBtn');
  const sortMenu = document.getElementById('sortMenu');
  if (sortBtn && sortMenu) {
    sortBtn.addEventListener('click', (e) => { e.stopPropagation(); sortMenu.hidden = !sortMenu.hidden; });
    document.addEventListener('click', (e) => { if (!sortMenu.hidden && !sortMenu.contains(e.target) && e.target !== sortBtn) sortMenu.hidden = true; });
  }

  // ---- Set cover (paste an image URL) ----
  const setCoverBtn = document.getElementById('setCoverBtn');
  const setCoverForm = document.getElementById('setCoverForm');
  if (setCoverBtn && setCoverForm) {
    setCoverBtn.addEventListener('click', () => {
      setCoverForm.hidden = !setCoverForm.hidden;
      if (!setCoverForm.hidden) { const i = setCoverForm.querySelector('input[type=url]'); if (i) i.focus(); }
    });
  }


  // ---- Edit mode (reveals delete controls) ----
  const editBtn = document.getElementById('editBtn');
  if (editBtn) {
    const setEdit = (on) => {
      document.body.classList.toggle('editing', on);
      editBtn.textContent = on ? 'Done' : 'Edit';
    };
    // Always starts off; a structural change redirects back with ?edit=1 to keep it on.
    setEdit(new URLSearchParams(location.search).get('edit') === '1');
    editBtn.addEventListener('click', () => setEdit(!document.body.classList.contains('editing')));
  }
  // Don't navigate into a book while editing (so the × can be tapped).
  document.querySelectorAll('.booklink').forEach(a => {
    a.addEventListener('click', e => { if (document.body.classList.contains('editing')) e.preventDefault(); });
  });

  // ---- Star rating (= "read"). Editable on the book page, and on the book
  //      cards while Edit mode is on. ----
  const CSRF = '<?= $csrf ?>';
  const postRating = (book, val) => {
    const body = new URLSearchParams({ csrf: CSRF, action: 'set_rating', book, rating: val });
    fetch('', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body }).catch(() => {});
  };
  const wireStars = (wrap, requireEdit) => {
    wrap.querySelectorAll('.star').forEach(st => {
      st.addEventListener('click', (e) => {
        if (requireEdit && !document.body.classList.contains('editing')) return;   // cards: only in Edit
        e.preventDefault(); e.stopPropagation();
        const v = +st.dataset.v, cur = +wrap.dataset.rating;
        const val = (v === cur) ? 0 : v;               // click the current rating to clear it
        wrap.dataset.rating = val;
        wrap.querySelectorAll('.star').forEach(s => s.classList.toggle('on', +s.dataset.v <= val));
        postRating(wrap.dataset.book, val);
      });
    });
  };
  const pageStars = document.querySelector('.stars.editable');
  if (pageStars) wireStars(pageStars, false);
  document.querySelectorAll('.stars.cardrate').forEach(w => wireStars(w, true));

  // ---- Past / Want-to-read flags (book page) ----
  const flags = document.querySelector('.bh-flags');
  if (flags) {
    const book = flags.dataset.book;
    const post = (action, value) => {
      const body = new URLSearchParams({ csrf: CSRF, action, book, value: value ? '1' : '' });
      fetch('', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body }).catch(() => {});
    };
    const wantChk = document.getElementById('wantChk');
    const pastChk = document.getElementById('pastChk');
    if (wantChk) wantChk.addEventListener('change', () => post('set_want', wantChk.checked));
    if (pastChk) pastChk.addEventListener('change', () => post('set_past', pastChk.checked));
  }

  // ---- Search modal (books list only) ----
  const modal = document.getElementById('searchModal');
  if (modal) {
    const openBtn = document.getElementById('addBookBtn');
    const closeBtn = document.getElementById('mClose');
    const q = document.getElementById('q');
    const results = document.getElementById('results');
    const addForm = document.getElementById('addForm');

    const open = () => { modal.classList.add('open'); setTimeout(() => q.focus(), 30); };
    const close = () => { modal.classList.remove('open'); };
    openBtn.addEventListener('click', open);
    closeBtn.addEventListener('click', close);
    modal.addEventListener('click', e => { if (e.target === modal) close(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });

    const esc = s => (s || '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
    const pick = (r) => {
      document.getElementById('afTitle').value  = r.title || '';
      document.getElementById('afAuthor').value = r.author || '';
      document.getElementById('afCover').value  = r.cover || '';
      document.getElementById('afKey').value    = r.key || '';
      addForm.submit();
    };

    let timer = null, seq = 0;
    const run = () => {
      const term = q.value.trim();
      if (term.length < 2) { results.innerHTML = '<p class="hint">Type a title or author to find cover matches.</p>'; return; }
      results.innerHTML = '<p class="loading">Searching…</p>';
      const mine = ++seq;
      fetch('?action=search&q=' + encodeURIComponent(term))
        .then(r => r.json())
        .then(d => {
          if (mine !== seq) return;   // ignore stale responses
          const list = (d && d.results) || [];
          if (!list.length) { results.innerHTML = '<p class="hint">No covers found. Try a different search.</p>'; return; }
          results.innerHTML = '';
          list.forEach(r => {
            const row = document.createElement('div');
            row.className = 'rrow';
            const cov = r.cover ? 'https://covers.openlibrary.org/b/id/' + r.cover + '-M.jpg' : '';
            row.innerHTML =
              '<img class="rcover" loading="lazy" src="' + cov + '" alt="" onerror="this.style.visibility=\'hidden\'">' +
              '<div class="rmeta"><div class="rtitle">' + esc(r.title) + '</div>' +
              '<div class="rauthor">' + esc(r.author) + (r.year ? ' &middot; ' + r.year : '') + '</div></div>' +
              '<span class="radd">Add</span>';
            row.addEventListener('click', () => pick(r));
            results.appendChild(row);
          });
        })
        .catch(() => { if (mine === seq) results.innerHTML = '<p class="hint">Search failed. Check your connection and try again.</p>'; });
    };
    q.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(run, 350); });
  }

  // ---- Note editor autosave ----
  const noteForm = document.querySelector('form.editor');
  if (noteForm) {
    const titleInput = noteForm.querySelector('input[name=title]');
    const status = document.getElementById('saveStatus');
    const DEF = titleInput ? (titleInput.dataset.default || '') : '';
    if (titleInput) {
      titleInput.addEventListener('focus', () => { if (titleInput.value === DEF) titleInput.select(); });
      titleInput.addEventListener('blur', () => { if (titleInput.value.trim() === '') titleInput.value = DEF; });
      titleInput.addEventListener('keydown', e => { if (e.key === 'Enter') e.preventDefault(); });
    }
    let timer = null;
    const doSave = () => {
      if (status) status.textContent = 'Saving…';
      const fd = new FormData(noteForm);
      fd.set('action', 'save_note'); fd.set('ajax', '1');
      fetch('', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
          if (status) status.textContent = 'Saved';
          if (d && d.title && titleInput && document.activeElement !== titleInput) titleInput.value = d.title;
        })
        .catch(() => { if (status) status.textContent = 'Save failed'; });
    };
    const schedule = () => { if (status) status.textContent = 'Editing…'; clearTimeout(timer); timer = setTimeout(doSave, 800); };
    noteForm.querySelectorAll('input, textarea').forEach(el => el.addEventListener('input', schedule));
    document.addEventListener('visibilitychange', () => { if (document.hidden) { clearTimeout(timer); doSave(); } });
  }

  // ---- Drag to reorder book notes (edit mode). Hold anywhere on a row to
  //      pick it up (or use the ☰ handle for an immediate grab). ----
  (function () {
    const root = document.getElementById('bnotes-root');
    if (!root) return;
    const BOOK_ID = '<?= e($book['id'] ?? '') ?>';
    let dragLi = null, pressTimer = null, armedLi = null, pid = null, sx = 0, sy = 0, suppressClick = false;

    const persist = () => {
      const order = [];
      root.querySelectorAll('ul.nlist').forEach(ul => {
        const section = ul.dataset.section || '';
        ul.querySelectorAll(':scope > li[data-id]').forEach(li => order.push({ id: li.dataset.id, section }));
      });
      const body = new URLSearchParams({ csrf: CSRF, action: 'reorder_notes', book: BOOK_ID, order: JSON.stringify(order) });
      fetch('', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body }).catch(() => location.reload());
    };
    const begin = (li) => {
      dragLi = li; li.classList.add('dragging');
      try { li.setPointerCapture(pid); } catch (_) {}
      if (navigator.vibrate) navigator.vibrate(12);
    };
    const cancelPress = () => { if (pressTimer) { clearTimeout(pressTimer); pressTimer = null; } armedLi = null; };

    root.addEventListener('pointerdown', (e) => {
      if (!document.body.classList.contains('editing')) return;
      const li = e.target.closest('li[data-id]'); if (!li || !root.contains(li)) return;
      if (e.target.closest('.ndel')) return;            // let the delete button work
      pid = e.pointerId; sx = e.clientX; sy = e.clientY;
      if (e.target.closest('.drag-handle')) { e.preventDefault(); begin(li); }   // handle = grab now
      else { armedLi = li; pressTimer = setTimeout(() => { pressTimer = null; begin(li); }, 280); }  // hold = grab
    });
    document.addEventListener('pointermove', (e) => {
      if (pressTimer) {                                 // still waiting: a real move = scroll/tap, so cancel
        if (Math.abs(e.clientX - sx) > 10 || Math.abs(e.clientY - sy) > 10) cancelPress();
        return;
      }
      if (!dragLi) return;
      e.preventDefault();
      const under = document.elementFromPoint(e.clientX, e.clientY); if (!under) return;
      const overLi = under.closest('li[data-id]');
      if (overLi && overLi !== dragLi && root.contains(overLi)) {
        const r = overLi.getBoundingClientRect();
        overLi.parentNode.insertBefore(dragLi, (e.clientY > r.top + r.height / 2) ? overLi.nextSibling : overLi);
      } else {
        const ul = under.closest('ul.nlist');
        if (ul && root.contains(ul) && ul !== dragLi.parentNode) ul.appendChild(dragLi);
      }
    }, { passive: false });
    const end = () => {
      cancelPress();
      if (!dragLi) return;
      dragLi.classList.remove('dragging'); dragLi = null;
      suppressClick = true;                             // swallow the click that follows a drag
      setTimeout(() => { suppressClick = false; }, 350);
      persist();
    };
    document.addEventListener('pointerup', end);
    document.addEventListener('pointercancel', end);
    root.addEventListener('click', (e) => { if (suppressClick) { e.preventDefault(); e.stopPropagation(); } }, true);
  })();
</script>
<?= keep_edit_script() ?>
<?= settings_modal_script() ?>
<script>
  // The bookshelf theme picker, in the settings window's $extra slot. A theme is only
  // custom properties, so it repaints in place — set them on :root and every rule that
  // reads them follows. It deliberately does NOT reload: reloading closed the settings
  // window on every pick, so comparing two themes meant re-opening it each time. The
  // swatch posts in the background to remember the choice, the same way the folder
  // manager's colour swatch does.
  (function () {
    var csrf   = <?= json_encode($_SESSION['csrf'] ?? '') ?>;
    var THEMES = <?= book_themes_js() ?>;
    var root   = document.documentElement;
    var meta   = document.querySelector('meta[name="theme-color"]');
    document.querySelectorAll('.bkthemebtn').forEach(function (b) {
      b.addEventListener('click', function () {
        var t = THEMES[b.dataset.theme];
        if (t) {
          // Inline on :root, so it wins over the stylesheet's own :root block.
          for (var k in t.vars) { root.style.setProperty(k, t.vars[k]); }
          root.style.colorScheme = t.scheme;   // native controls follow the page
          if (meta) { meta.setAttribute('content', t.vars['--bg']); }
          document.querySelectorAll('.bkthemebtn').forEach(function (x) {
            x.classList.toggle('on', x === b);
          });
        }
        var body = new URLSearchParams({ csrf: csrf, action: 'set_book_theme', theme: b.dataset.theme });
        fetch('', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: body });
      });
    });
  })();
</script>
<?= confirm_delete_script() ?>
<?= rt_script() ?>
</body>
</html>
