# Testing

> **2026-08-22.** The plain-PHP CalMind suite (Reminders · Calendar · Add · Notes ·
> Habits, plus its `api/`, widget feed and sharing) was deleted from this repo, superseded
> by the CalMind monorepo. Twenty-two areas covering it went with it, along with
> `spec/`, `ios/`, `android/` and the seeders that built its demo data. What is documented
> below is what is left: the login, the site shell, Chat, Aki's Bookshelf, the themes
> workbench and the status page.

```sh
php tools/test.php              # everything — about 15 seconds; --list for the current counts
php tools/test.php bookshelf    # one area, by name
php tools/test.php --list       # the area names and their case counts
php tools/test.php --keep       # keep the scratch data dir and the server log
```

Exit code is 0 when everything passed, 1 when anything didn't. `--keep` prints the
scratch directory it used; `server.log` in there has anything PHP wrote to stderr.

## The bargain

**Change a feature, change its test in the same commit. Add a feature, add a test with
it. Fix a bug, add the case that would have caught it before you fix it.**

That last one is not ceremony. The `regress` area held one case per bug that had already
shipped once, so none of them could ship twice; it went with the app suite those bugs
were in. The habit is what mattered, and it still applies to everything left.

This file is the other half of the bargain. It is the map of what the suite covers and
what it can't, so:

- **Adding an area or a case?** Add it to *What is covered* below, under its area name.
  The area names in `tools/test.php` and the headings here are meant to line up.
- **Adding something only a finger can check** — a gesture, an animation, anything you
  judge by eye? Add it to *What only eyes can check*. A thing that is in neither list is
  a thing nobody is looking at.
- **Removing a feature?** Remove its cases and its line here in the same commit, or the
  next person spends an afternoon working out whether the test or the app is wrong.

## How the harness works

There is no framework, for the same reason there isn't one anywhere else in this repo.
`tools/test.php`:

1. makes a scratch directory under the system temp dir and points `SUITE_DATA_DIR` at it;
2. seeds it with the **real** seeder (`tools/seed-accounts.php`), which is itself a test
   of it;
3. boots `php -S` against `public/` with that environment;
4. drives the real pages over real HTTP — sessions, cookies, redirects, CSRF, AJAX
   headers, the lot — and asserts on what came back and on what landed in storage;
5. runs the unit-level checks in-process against `lib/`;
6. tears the server down and deletes the scratch directory.

The `instance` area boots a **second** server on top of that, over a throwaway two-instance
layout built inside the scratch dir, with `SUITE_DATA_DIR` and `SUITE_BASE` explicitly
unset — so the two instances can only find their data and their prefix through their own
`config.php`, which is the thing being tested.

`SUITE_DATA_DIR` is read in exactly one place, `app_config()` in `lib/auth.php`, and
nothing else here sets it. **A test run cannot touch `data/`.** It also never reads
`lib/config.php` for credentials: it signs in as the accounts it just seeded.

Two kinds of assertion, and the labels say which:

- **Behaviour** — a request is made and the result is checked. Most cases.
- **Wiring** — the page has to still *contain* the handler, rule or attribute that makes
  a behaviour possible. Used where the behaviour itself needs a finger. A wiring case
  can't tell you the gesture feels right; it can tell you someone deleted the line that
  makes it work, which is how most of these broke in the first place.

## What is covered

### `test-instance`
Each instance gets its own session cookie name, so being signed into production is not
being signed into `/test/`. A sandbox config does not inherit production's
accounts. `suite_base()` normalises a messy prefix (`test/` → `/test`). What this cannot see:
the actual `/test/` URL on the live server, the isolation of `data-test/`, and
`deploy.sh promote` — those are in *What only eyes can check*.

### `auth`
Signed-out visitors get the login page and never a leak of app markup. A wrong password
is refused; a right one redirects to `/` from whichever page asked. (It was the suite's
Calendar until the suite went; with the login now guarding four unrelated pages, home is
the one that links to all of them.) The login page is sized to `100svh` and draws no
scrollbar. Logout ends the session. A POST with a missing or wrong CSRF token is a 400
and writes nothing.

### `storage`
Files are `ENC1:`-prefixed and the plaintext is not readable in them. Legacy plaintext
JSON still reads. `user_data_file()` keeps one person's data out of another's.

### `usage`
Every operation leaves one five-field line (time, IP, user, app, action) in
`data/usage.log`: sign-in, failed sign-in, sign-out and a POST action are all logged, and
the log **never carries what an action posted** — that negative is the promise under test.
The file sits outside the web root (a fetch of `/data/usage.log` finds nothing) and stays
plain text, not `ENC1:`; the writer leaves it group-readable and the data dir
group-traversable, which is what lets the SSH login tail it on the live host.
*(By eye: nothing — there is no UI for this log; `tools/usagelog.sh` reads it over SSH.)*

### `hits`
One line per page view in `hits.log`, **seven** tab-separated fields (time, instance,
app, method, user, agent, IP), and the NEGATIVES that are still the promise: no query
string, no path below the first segment. **The address is asserted present, not absent**
— that flipped on 2026-08-23 on Sean's instruction, and the field count went five to
seven with it. A request carrying `X-Status-Probe` leaves no line — the status page
probes every endpoint on this host every 45s, and without that guard most of the hits it
reports would be its own. A public page logs `-` for a stranger and **names a signed-in
visitor**, which is the case that catches the session never being read on a page with no
login of its own. An agent files itself as `claudio` by header or by a command-line user
agent, and the half worth testing is the other one: a browser-shaped UA never lands in
that lane. `hit_counts()` counts only inside its window and counts signed-in visitors
apart, **and splits by instance** — the status page's one prod/test/dev picker claims to
scope the whole page, and that strip used to answer "the whole host" whichever button was
lit. `hit_app()` strips **both** sandbox prefixes; `/test` was stripped and `/dev` was
not, which filed a whole sandbox under an app called "dev".

The Usage tab's aggregation is asserted here rather than by eye, because every number on
that tab is derived from it. **Anonymous visitors are ONE row per lane and instance**
(Sean, 2026-09-03: "group together anonymous requests, don't list hundreds of anon-xxxx")
carrying the address count and the busiest `HIT_ADDR_TOP` of them for the fold-out — the
old per-address `anon-N` rows were over a thousand table rows, a thousand chart lines and
a thousand entries in the JSON the page ships. **The person key carries the instance**, so
Claude's lane — the one lane that spans all three — narrows with the picker instead of
pooling. And the invariant the tab actually broke: **`hit_usage_total()` for a lane equals
the sum of the rows shown under it**, per window and per app. The page's JavaScript
mirrors that one function; nothing else on the tab adds a number up. **Datacenter traffic is a bot, not a person** (Sean, 2026-09-06: the last-3-days count was not 600 people, it was scanners): an anonymous prod request whose address geoip has resolved as `hosting`/`proxy` is filed in a `bots` lane, so "Other people" means people — a signed-in session from a datacenter address is still that person. `hit_lane()` is the rule and takes the datacenter map as an argument, proven both with the map (bots) and without it (other).
*(By eye: the KPI row on the status page's Live tab, and the whole Usage tab — the
instance/app pickers, the **Show / filter-out toggles** (Me · Anonymous · Bots · Claudio,
which drop a category from the headlines, the tables and the chart together), the
fold-out, the column sort and the chart are all JS the harness never runs.)*

### `lib`
Output is escaped: a palette named `<script>alert(1)</script>` comes back as
`&lt;script&gt;` and never as the raw tag. The rest of this area went with `lib/util.php`
and `lib/palette.php` — the parser, repeat clamping, folder tints, the six-colour app
palettes and the palette-generation bump were all the app suite's, and so was
`/userpalettes/`, the viewer that graded them.

### `pages`
Every page renders for two seeded users with no fatal, warning, notice or deprecation.
The sweep (`quiet()`) matches PHP's HTML-mode spelling too — `<b>Warning</b>:` — because
the plain `Warning:` needle never matched a real displayed warning, which is how the
folder manager's `$fixed[0]` crash reached a phone with the suite green. The public
pages need no login. **An empty brand-new account is a working empty suite, not a crash** —
the case that catches "works for me, my account has data".

### `security`
Data-driven over **every mutating action in the suite**, so an action added next week is
covered whether or not anyone remembers to write a case for it: each must refuse a POST
with no CSRF token, refuse one with a wrong token, and — signed out entirely — write
nothing at all. A fingerprint of everything the user owns is compared before and after,
so "refused" means *nothing moved*, not just "returned 400". Also: no folder name can
carry the `\x1F` the pickers split on or any other control character, nothing is ever
written outside the data dir, one user cannot reach another's file by naming a folder
they were never shared, and the destructive actions all need the confirmed second press.

**When you add a mutating action, add it to `ALL_ACTIONS()`.** That list is the sweep.

### `instance`
The `/test/` mirror for real: two instances of the same source booted side by side the
way `deploy.sh` lays them out — `public/` + `public/test/`, `lib/` + `lib-test/`, a
`config.php` each and a data directory each — with **no `SUITE_*` in the environment**, so
each has to find its data and its prefix from its own config the way the live one does.
Both come up quiet. Every cross-app link in a `/test/` page carries the prefix and no
unprefixed one leaks out; production carries no trace of `/test/`. Signing in lands you in
the instance you signed in to. **A row added on one side never appears on the other**,
either way round. Every app page under `/test/` is proved to have loaded `lib-test` — the
case that catches a page whose preamble was forgotten, which would otherwise render fine
and quietly link back into production.

### `signup`
The create-account window carries the development warning that passwords aren't encrypted
and a real one shouldn't be used — it stays until sign-up storage hashes them.
A short username, a bad email, a short password and a taken name are all refused and none
of them creates an account. A good sign-up **parks** the account in `signups.json` and it
cannot sign in while it's pending. Five wrong codes end it, and the right code afterwards
is too late. The right code makes the account, signs you in and clears the pending row. A
brand-new account is an empty working suite with no partner.

### `account`
The settings window's two handlers, which `require_login()` answers on whatever page
you're on. Changing a password needs the token *and* the current password, and has a
six-character floor; a bad token is a 400 and writes nothing. A changed password takes
effect and the old one stops working, with the override in `passwords.json` rather than
the account record. The theme is set over AJAX, refuses a name it doesn't know, sticks in
`prefs-<user>.json`, and a bad token is a 400 there too. **The suite themes**: a fresh
account renders midnight exactly (the old `#111`/`#eee`/`#34d399` values, dark scheme,
`theme-color` meta); sage flips every app page to the cream palette and a light scheme,
`quick.php` and the feed setup page included; a legacy stored name falls back to
midnight; every app offers the full swatch picker; and no app page may render the old
hardcoded dark-room declarations — the tripwire that keeps a new rule from being written
with a literal neutral that only works on midnight. What the harness can't see — whether
the paint actually reads on a cream page — is in the Themes pass under *What only eyes
can check*. **The themes bench's gate lives here too**: `themes_users()` / `themes_may()`
are checked with the run's own `SUITE_THEMES_USERS=*` override unset, so the list proved
is production's (`aki`, `sean`, nobody else, never signed out) — and the override is shown
to be ignored outside a scratch instance, because a gate an env var alone could widen
would not be a gate.

### `chat`
Open to anyone, no login. A message posts and shows. A message and a name are escaped
rather than rendered. Whitespace is not a message.

### `themes`
The palette workbench (`public/akisthemes/`). Behind the login; opens seeded with the eight
starters, twelve editable roles each. **This run widens the bench's gate to everyone**
(`SUITE_THEMES_USERS=*`) because it uses this page as its stand-in for "any page behind
the login"; production admits only `aki` and `sean`, and *that* list is checked directly
in the `account` area, with the override unset — a gate a test quietly disables is a gate
nothing tests. A colour is stored only when it is a real `#rrggbb` in
a real role — a `javascript:` value and an unknown role are both refused, and the refusal is
reported rather than being reported as success. Add works, delete takes two presses. The one
that matters most: **editing a palette here leaves Aki's Bookshelf untouched**, which is the
entire reason the app is separate. **Not covered by the harness, checked in a browser instead:** editing is per palette and
only one opens at a time (opening another closes the first and makes it inert again);
a swatch is only changeable on the open palette; clicking away closes the editor and
drops focus, which is what dismisses the native colour picker; the first press of a
delete arms it red (#b3261e) and writes nothing, the second injects `confirm=1`. The
live preview card and the contrast chips are JS too, so how they *look* is still by eye.

### `bookshelf`
Behind the shared login. A signed-in stranger gets the refusal page and none of the app's
markup. Aki — made through the real sign-up — gets the app.

Its **themes** are covered too: all eight offer a swatch, an untouched bookshelf is still
Midnight, and the suite's accent-only row is hidden here. Picking one repaints the page
(`--bg`, `--gold`) rather than just the accent, flips `color-scheme` for the two light
themes, and follows through to the PWA `theme-color`; a plain post redirects, the AJAX one
the picker actually uses answers JSON. An unknown key changes nothing. The page also has to
carry every theme's variables (`var THEMES = {…}`) because picking one **repaints in place
instead of reloading** — a reload shut the settings window on every pick; that table is
asserted, but the repaint itself is JS and so is by eye. The bookshelf theme
and the suite theme are set independently and neither moves the other. **Not covered:** how
any of it *looks* — the contrast figures were computed once when the palettes were chosen
(everything clears 4.5:1 on its own background), but nothing re-checks them, so a new or
edited theme needs that done by hand. Nor does anything drive the picker's click.

### `site`
Home, projects, about, contact and the theme picker render for a stranger, ask for no
login, leak nothing, and carry the site nav — never the app tab bar. Projects links the
CalMind repo with its git icon. The theme picker shows all four suite themes as inert
previews with the current one marked; picking one sets the `sitetheme` cookie
(POST→redirect) and re-dresses the public pages — a bad name sets nothing — and the
cookie never reaches the apps, which keep their own per-user theme. Every public page
wears the centred cursive SC mark and links the site's own favicon/touch icons (real
PNGs), which never leak into the apps — they keep their own. The pill nav is centred, and
phone widths swap it for a no-JS `<details>` dropdown whose summary names the current
page (the swap and both page lists are pinned; how it opens is by eye). Projects nests Theme
Picker and CalMind as subsections (h4) under Vibe Coding Apps — the shell must style that
level — and lists the Private categories (Work, Music, Games, Languages). About's two
favourites lists run in two columns whose rows have to line up: list items carry a bottom
margin only (a column break truncates the margin over whichever item starts a column, so a
top margin lands on the first column's first item alone and sits the two 3.2px out of
step), and the lists fall to one column below 640px — the width `.wrap` caps at, under
which the columns narrow and a wrapped title steps its column past the other's. Both are
pinned as CSS text; the alignment itself is by eye, the harness running no layout.

### `deploy`
Static checks on `deploy.sh`, because a deploy is the one thing here that can destroy data
and the one thing a test run may never actually perform. It parses; no `rsync` line uses
`--delete`; every `lib` push excludes `config.php`; nothing names a live data directory; a
bare deploy is the test instance and production needs saying out loud. **`/test/calmind/`
belongs to the NEW CalMind monorepo (`~/GIT/CalMind`) as of 2026-08-08**: a suite test
deploy must exclude the top-level `calmind/`, and `promote` must exclude it from the
server-side copy — prod's suite is only ever updated by a direct prod deploy, and the
suite's own pre-promote review happens on `/test/`. Also
`tools/seed-http.php`: the committed copy carries no key, compares in constant time, has
no default data directory, and is never deployed. The `calmind/` repo split is guarded
here too: `public/calmind` and the four CalMind-only lib files must be symlinks into the
top-level `calmind/` area, and both deploy scripts must rsync with `-L` so the server
always receives real files in the pre-split layout. The same static
treatment: it parses, no rsync line uses `--delete`, its lib rsync excludes `config.php`,
no rsync/rm line names a live data directory, and its destinations stay the /dev
constants with the refusal guards standing — the script's whole reason to exist is that
it cannot reach production or `/test/`. Neither script may expand an array bare
(`"${a[@]}"`): macOS ships bash 3.2, where an *empty* array counts as unset and `set -u`
kills the run — and since the only such array is non-empty on a test push and empty on a
prod one, a bare expansion breaks `prod`/`both` while every test deploy, dry run included,
sails through. The guarded `${a[@]+"${a[@]}"}` is pinned by text, and the idiom itself is
proved against the machine's own bash.

## What only eyes can check

Everything below is real and none of it is automated. **Every bug reported in the session
that created this file was in this column** — a click-eater, a link interceptor, a
two-step gesture, a negative margin. A green run says the data model and the request
handling are sound. It does not say the app feels right on a phone.

Check these on the **installed home-screen app**, not in desktop Safari — several of the
failures only exist in standalone mode.

**Every deploy**

- [ ] Every `+` and icon button is visually centred (the standing rule in CLAUDE.md).
      Check any button the diff touched, on the screen it lives on.
- [ ] The top bar is on the same line in every app, with the same gap under its rule.
- [ ] `/calmind/` still serves the CalMind monorepo's build, not a 404 — this repo no
      longer holds anything at that path and must never start sending one again.
- [ ] Nothing is clipped by the notch or the home indicator (`env(safe-area-inset-*)`).
- [ ] Tapping a link doesn't kick you out to Safari with browser chrome.

**The `/test/` sandbox** (after touching a deploy script or any cross-app link)

- [ ] `./deploy.sh test` publishes to `seancheren.com/test/`; the pages open there and the
      site nav, the logo and the login all stay inside `/test/` (never jump to the root).
- [ ] Signing in on `/test/` lands on `/test/`, and the data you add there does **not**
      appear in production (and vice versa) — `data-test/` is separate.
- [ ] `./deploy.sh promote` leaves prod running what test ran; production's data and both
      `config.php` files are untouched.

**Gestures** — the apps that still have them

- [ ] Aki's Bookshelf: the Edit pencil reveals the edit-mode-only controls, and the back
      button becomes a black × that leaves edit mode.
- [ ] Two-press delete fills red on the first press and only deletes on the second —
      bookshelf rows and sections, and a palette in the themes workbench.
- [ ] Adding a bookshelf section does not drag you into edit mode.
- [ ] Drag to reorder a book's notes; the order sticks across a reload.

**Lists**

- [ ] A section you just created is visible while editing even though it holds no rows.
- [ ] Scroll position survives a POST — ticking or editing something halfway down a long
      book-notes list does not jump you back to the top.

**Themes** — one pass per theme worth checking (the harness sees the vars, not the paint)

- [ ] The themes workbench (`/akisthemes/`) repaints, including its status-bar colour, and
      a cream theme opens native dropdowns light rather than black.
- [ ] The bookshelf's own themes are separate from the suite theme: changing one leaves
      the other alone, and the settings window is repainted to the theme rather than
      opening as a black slab on a cream page.
- [ ] The public pages follow the `sitetheme` cookie the theme picker sets, and that
      cookie never re-dresses a signed-in app page.

**Keyboard and input**

- [ ] Inputs are `font-size: 16px`, so iOS does not zoom the page on focus.
- [ ] The login page draws no scrollbar at any phone height.


**Things the harness deliberately doesn't do**

- No browser: no JavaScript is executed, so anything JS-only is wiring at best.
- Beware a marker word that also appears in the stylesheet. Asserting that a page
  "contains mgrid" once passed on a page with no month grid at all, because `.mgrid` was
  in the CSS — that view went untested for a while behind a green tick. Assert on
  *rendered elements* (`<div class="mcell`), not on a word.
- No screenshots and no layout assertions — nothing here measures a pixel.
- Aki's Bookshelf is covered at the gate, for its themes and for adding a section; the
  books, covers, notes and shelves are not — it gates on one username and is its own app.
- The chat app is only checked for rendering, posting and escaping.
- The status page (`/status/`) is not covered at all beyond the login redirect: it is one
  read-only page gated to a single account, and its reachability panel makes live outbound
  requests the harness must not fire.
- Nothing tests the live server, TLS or the deploy.
- The CalMind, ChefMind, AcctMind and MyCalMind apps live in their own repos with their
  own suites; nothing here drives them, and `/calmind/` on the live host is theirs.

## Adding a test

Areas are declared with `area('name')` and cases with `t('label', function () { … });`.
Assertions are `ok`, `eq`, `has`, `hasnt` — each throws a message the runner prints.

```php
area('themes');

t('a thing does what it should', function () {
    $jar = login('example', 'examplepassword');
    req('POST', '/akisthemes/', ['csrf' => csrf($jar), 'action' => 'add', 'name' => 'x'], $jar);
    has('x', json_encode(stored('palettes', 'example')), 'it was written');
});
```

Helpers: `login()`, `csrf()`, `req()`, `stored()`, `datadir()`, `ensure_account()`.
Cases in an area run in order and share the seeded accounts, so a case that *changes*
something another case reads has to put it back — the theme case restores `midnight`,
and anything adding a palette to `example` breaks the themes area's count. Prefer
`ensure_account()` and a name of your own. That is the one sharp edge in here.
