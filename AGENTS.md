# Working in seancheren-site

The baseline for all of Sean's repos lives in ~/GIT/AgentSuite/AGENTS.md
and is imported here; this file holds only what is true of THIS repo.
@../AgentSuite/AGENTS.md

## What this is

**seancheren.com** — the hosting account and the small set of pages that live directly on it, in plain PHP on NearlyFreeSpeech.NET. No framework, no build step, no database, no dependencies. Each page is essentially one self-contained `index.php` that renders its own HTML/CSS/JS inline and posts back to itself.

What's here:

- **The public front** — Home, Projects, About, Contact, and the Themes picker. No login, no chrome from the apps; they share `lib/site.php`.
- **Chat** (`public/chat/`) — deliberately public, no login.
- **Aki's Bookshelf** (`public/akisbookshelf/`) — behind the login, then gated to the `aki` account. Books from the Open Library API, per-book notes, its own themes.
- **The themes workbench** (`public/akisthemes/`) — behind the login, for building colour palettes. Deliberately wired to nothing.
- **The status page** (`public/status/`) — behind the login, then gated to the `sean` account. Mind-suite deploy/sync status, dtp history, live reachability.
- **`public/processing/`** — a legacy standalone p5 login, unrelated to everything else.

### What used to be here, and is not

**The plain-PHP CalMind suite was deleted on 2026-08-22.** Reminders · Calendar · Add · Notes · Habits, its `api/`, the Scriptable widget feed, sharing, folders, palettes and the `/userpalettes/` viewer all lived in a top-level `calmind/` area stitched into `public/` and `lib/` by symlinks. It has been superseded by the **CalMind monorepo** (`~/GIT/CalMind`), which has owned `/calmind/` on the live host since 2026-08-20 and authenticates with its own tokens/passkeys — not this login.

Deleted with it, because nothing else used them: `lib/{tabbar,folders,sharing,palette,util}.php`, `public/{reminders,calendar,notes,habits,add,api,userpalettes}/`, the `ios/` and `android/` native apps and the `spec/` vectors they replayed, `tools/{seed-example,seed-buddy,seed-http,smoke,export-suite,export-web-oneshot}.php`, and twenty-two areas of `tools/test.php`.

**Nothing in this repo may deploy anything to `/calmind/`.** Both deploy scripts carry an anchored `--exclude='/calmind'` for exactly that reason. If you need the app, it is a different repo with its own deploy.

## Commands

```sh
php -S 127.0.0.1:8787 -t public          # local server
php tools/test.php                       # the test run — see TESTING.md
php tools/test.php --list                # the area names, with the case count of each
php tools/test.php auth signup           # only areas whose name contains one of these
find public lib tools -name '*.php' -exec php -l {} \;   # lint everything
./deploy.sh --dry-run                    # preview the (test) deploy, touch nothing
./deploy.sh                              # lint, then rsync to the TEST instance (/test/)
./deploy.sh both                         # …to TEST *and* production in one go
./deploy.sh promote                      # ship the live TEST tree onto prod, server-side
php tools/seed-accounts.php --force      # (re)build the demo accounts the test run uses
php tools/mailtest.php you@example.com   # exercise lib/mail.php (disarmed while mail_send is stubbed)
tools/usagelog.sh -f                     # follow the live usage log over SSH
```

`deploy.sh` is one-way (Mac → server) and deliberately never sends any `config.php`, never touches `/home/protected/data{,-test}/`, and never uses `--delete`. The Mac is the source of truth; if anything was hand-edited on the server, `rsync` it back down before deploying (see README).

**Two live instances share one source tree**, both deployed by `./deploy.sh` — `test` (default) → `/test/`; `prod` → the site root; `both` → both; `promote` → copy the *live test* tree onto prod server-side. A bare `./deploy.sh` is test-only, so production is never hit by accident. It lints first, never sends a `config.php`, never touches a data dir and never `--delete`s.

There was a third instance, `/dev/`, with its own `deploy-dev.sh` that could reach nothing else. Both were removed on 2026-08-23 (Sean: it "shouldn't even exist anymore"). `data-dev` had never been created, so no data moved. **`dev.seancheren.com` and `test.seancheren.com` are ALIASes on the hosting account**, both resolving to this same docroot, so today they serve exactly what production serves. That is fine by Sean for now — he may later choose to deploy different things to different domains, so treat the aliases as a live option rather than a leftover to be cleaned up.

Local login: users come from `lib/config.php` (gitignored; copy `lib/config.sample.php`). Local data lands in `./data/` and is unrelated to live data.

## Layout and the web-root boundary

- `public/` → server `/home/public/`. Anything here is URL-reachable.
- `lib/` → server `/home/protected/lib/`. Shared code, never served.
- `data/` → server `/home/protected/data/`. JSON storage, never served, gitignored.

**Every page starts by locating `lib/` with the instance-aware preamble** — copy it verbatim when adding a page. It picks `lib/` normally and `lib-test/` under `/test/` — matched on the slug at the END of `__DIR__`, on the request URI, and on the host, because the sandbox is a subdomain and only the host check catches it there. The number of `../`s depends on how deep the page sits; everything under `public/<slug>/` uses the same shape. **The marketing pages carry it too, since 2026-08-22** — they hold no data and used to keep a plain `lib`-only preamble, but `site_nav()` builds its links through `suite_base()` now, and without the preamble a sandbox page could not know its own base or even find a lib one directory down.

**The `/test/` sandbox mirror.** A second live instance runs at `seancheren.com/test/`, from the **same source** — there is no forked copy of the code. It is isolated three ways: its pages load `lib-test/` (→ `/home/protected/lib-test/`), that lib's `config.php` sets `data_dir` to `/home/protected/data-test/` and `base` to `/test`, and `suite_base()` (`lib/auth.php`) prefixes cross-page links with that base. Redirects built from `_self_path()` already stay inside `/test/` for free. `lib-test/config.php` **inherits production's**: it `require`s prod's `config.php` for accounts and secrets and overrides only `data_dir` and `base` — a sandbox for *data*, not for *identity*. `deploy.sh` creates it once on the server (delete it to reset test). The `SUITE_BASE` env var forces the prefix without a config (mirrors `SUITE_DATA_DIR`), which is how the test run exercises it.

**The two instances have separate logins and separate sessions.** *Accounts:* the sandbox config is standalone, so `app_users()` sees that instance's own `users` plus whatever signed up into its own `accounts.json`. *Sessions:* both live on one domain, and a cookie set at path `/` is sent to every path beneath it — so the cookie **name** is the only thing that actually separates them. `session_cookie_name()` (`lib/auth.php`) derives one from `base` (`SCSESS_TEST`); **production deliberately keeps PHP's own `PHPSESSID` and its default session store**, because renaming its cookie would sign everyone out for nothing. `session_store_dir()` gives the sandbox its own `sessions/` inside its own data dir, and its cookie path is narrowed to its base.

**The public top-level pages** (Home `/`, `projects/`, `about/`, `contact/`, `themepicker/`) are the site's marketing front: no login, no `chrome.php`. They share their own chrome through `lib/site.php` — `site_nav($active)` renders the pill nav (and its phone-width `<details>` dropdown) and `site_page($active, $title, $bodyHtml)` wraps a page in the full HTML shell (dark theme: `#111`/`#eee`/`#34d399`), with **the site's logo — a cursive SC (`.sitelogo`, Savoye LET, accent-coloured) — centred above the nav** and the baked icons linked (`/favicon-32.png`, `/apple-touch-icon.png`). Add a page by calling `site_page()` and adding its slug to the `$links` map in `site_nav()`.

## Core mechanics

**Storage.** All data goes through `store_read()` / `store_write()` (`lib/store.php`), which encrypt with AES-256-CBC under an `ENC1:` prefix. Reads transparently accept legacy plaintext JSON and re-encrypt on next write. Never `file_get_contents` a data file directly. Key comes from config `data_key` or an auto-generated `data/.datakey`.

**Per-user files.** `user_data_file($dir, $base, $user = null)` → `data/<base>-<user>.json`, defaulting to the signed-in user. Bases still in use: `palettes`, `prefs`, `books`, `booknotes`. `chat.json` and `token-<user>.json` are handled with hand-built paths.

**The clock.** `lib/auth.php` sets `date_default_timezone_set()` from config `timezone`, defaulting to `America/Chicago`. The server keeps UTC, so without it "today" turns over in the evening.

**Auth.** `require_login('Area')` (`lib/auth.php`) — session-based, `hash_equals` compare. **You stay signed in until you log out**: `session_boot()` sets a year-long cookie *and* a year-long `gc_maxlifetime`, and re-sends the cookie on every visit — both halves matter, since a long cookie pointing at a collected session file is still a logout. A password the user changes themselves lands in the encrypted `data/passwords.json` and wins over the config entry, because `config.php` is hand-kept on the server and never deployed — delete that file to fall back to config. `require_login()` also seeds `$_SESSION['csrf']` and answers the settings window's `change_password` and `set_theme` POSTs, so no page has to wire them up. **Signing in always lands on `LOGIN_LANDING`, which is `/`** — it was the suite's Calendar until the suite went; with the login now guarding four unrelated pages, home is the one that links to all of them. `suite_path()` survives as an alias for `suite_base()`; it used to append `/calmind`, and doing that now would send a signed-in visitor to an app that has never heard of this session. **Anyone can sign up from the login page**: `signup_handle()` takes a username, a valid email and a password, and parks the half-made account in `data/signups.json` for fifteen minutes (five wrong codes and it's gone). Only when the code comes back does it land in `data/accounts.json`, which `app_users()` merges with the config accounts — **config wins on a clash**, which is why `tools/seed-accounts.php` deliberately does not seed `aki`. **The mail stub lives in `lib/mail.php` (the baseline's Mail rule), and the code is a deliberate one**: `signup_send_code()` calls `mail_send()` for real, shrugs off the stub's `false`, and returns `true` so the flow proceeds; every sign-up takes the fixed `SIGNUP_CODE` (`5678`). That constant is doing real work — it is the invite code Sean hands out himself, so the gate is *who he tells*, not a mailbox. **It is not an oversight; leave it alone.** Restoring mail is un-commenting `mail_send()`'s real body in `lib/mail.php` and putting `random_int` back in `signup_handle()`. An account grants only the shared login — `/akisthemes/` — since the bookshelf gates on `aki` and the status page on `sean`.

**The usage log** (`lib/usagelog.php`, hooked in `lib/auth.php`). Every authenticated POST leaves one tab-separated line in `data/usage.log` — time, IP, username, app, the `action`'s name — and login/logout/sign-up log themselves. **Never any content**: the kind of operation only, every field squeezed to one clean token so nothing can smuggle a newline or someone's text into the file. Plain text (greppable, deliberately not encrypted), per-instance for free, rotated once at 5MB. The writer also self-heals the permissions the SSH login needs to `tail` it — group traversal on the data dir, group read on the log, nothing else. **`tools/usagelog.sh` is the way to read it** (`-f` follows, `test`/`dev` pick an instance); it sources `deploy.conf` for the host like `deploy.sh` does.

**Mutations.** Every write is POST with a CSRF token from `$_SESSION['csrf']`, checked with `hash_equals`, then either a redirect (POST→redirect→GET) or, for AJAX callers, a `json_encode` response. AJAX posts send `X-Requested-With: XMLHttpRequest`.

**Note bodies are HTML** (`lib/richtext.php`). Book notes are edited in a `contenteditable` (`.rt-body`) mirrored into a hidden `input.rt-value` named `body`. Everything is stored through `rt_sanitize()` — a DOMDocument allowlist (`b i u strong em blockquote ul ol li br div p span`, plus `class` only when it matches `rt-*`) — because the body is rendered rather than escaped. `rt_body_html()` spots a body with no tags at all as an old plain-text note and escapes it. `rt_toolbar_html(true)` adds the bookshelf-only `+✏️` window that inserts a quote, a note about it, a page number and an optional date stamp.

**Themes.** The suite's `THEMES` (`lib/auth.php`) are full palettes: `--bg` / `--surface` / `--surface-2` / `--line` / `--line-soft` / `--text` / `--text-dim` / `--muted` / `--gold` beside the `--accent` trio, emitted by `theme_css()` (`theme_vars()` is the one place the columns are named). **Four themes — `midnight`, `sage`, `forest`, `olive`.** `midnight` is the old `#111`/`#eee`/`#34d399` look and the default, so an untouched account is unchanged; a legacy stored name falls back to it. `THEMES_LIGHT` names the cream themes, which flip `color-scheme` so native controls follow, and every page's `<meta name="theme-color">` reads `theme_bg()` — never a literal, which was a real bug on `/akisthemes/` for as long as the suite's own pages carried it correctly. New styles must use the variables; a hardcoded `#111`-family hex will look broken on Sage.

**Aki's Bookshelf** (`public/akisbookshelf/`) is a standalone app: it gates on `current_user() !== 'aki'` after the shared login and renders its own chrome. Books come from the Open Library search API via `http_get()` (an 8-second `file_get_contents` with a User-Agent, this repo's only outbound HTTP besides the status page's reachability checks). `book_cover()` prefers the locally cached `covers/<id>.webp` and falls back to `covers.openlibrary.org`; that cache is generated server-side and gitignored. Per-book notes are a sectioned list (`booknotes-<user>.json`). **It has its own themes** (`BOOK_THEMES`), a separate setting from the suite's (`book_theme` vs `theme` in the same prefs file), emitted by `book_theme_css()` *after* `theme_css()` so this app's accent wins. `midnight` is the original look and the default. `BOOK_THEMES_LIGHT` names the two cream themes, which set `color-scheme: light` — without it a light page opens black native dropdowns. `--gold` is themed because it had to be: the original `#f0b429` is barely visible on cream.

**The themes workbench** (`public/akisthemes/`) builds colour palettes and is **deliberately not wired to anything**. It seeds itself with Aki's Bookshelf's eight as a starting point — a *copy* of those values, not a reference — so editing a colour here changes nothing in that app. A palette is a name plus one colour per role. Stored per-user in `palettes-<user>.json`. Every value ends up inside a `style` attribute, so `clean_hex()` admits nothing but `#rrggbb` and `set_color` refuses an unknown role *and says so*. The picker saves on `change` rather than `input`, so dragging the wheel repaints live but writes once. Each palette shows a preview card and live WCAG contrast chips against its own page colour, anything under 4.5:1 flagged.

**The status page** (`public/status/`) is sean-only. It reads dtp/tdtp history from `/home/protected/status/history.json` (written by CoreMind's `bin/dtp.sh`, kept outside `public/` so it is never web-exposed) and makes live outbound reachability checks, cached 45s at `/home/protected/status/reachability-cache.json`. It refreshes itself every 60s.

## UI conventions

Dark by default, themed throughout. Pill-shaped controls. Shared chrome comes from `lib/chrome.php`. **The top bar is one row, 32px tall, in the same place in every app** (1.5rem from the top, 0.5rem of gap under its rule), **with a rule under it**: back button and the app's name on the left; on the right, the app's own title controls, then the username, whose dropdown holds Settings and Log out. Aki's Bookshelf repeats the same rules locally since it doesn't use `chrome_styles()`. Inputs use `font-size: 16px` so iOS doesn't zoom on focus. These are iOS home-screen PWAs — respect `env(safe-area-inset-*)`.

**Edit mode.** Destructive controls (delete buttons, drag handles) are hidden unless `body.editing` is set. **The top-left back button becomes a black × while editing**, and that × leaves edit mode: `back_button()` emits both (`.backbtn.goback` and `.backbtn.exitedit`) into the one slot and CSS swaps them. Drag handles hide with `visibility: hidden`, not `display: none`, so entering edit mode doesn't nudge every line of text sideways. It is deliberately *not* persisted. Across a POST→redirect the rule is **echo, never originate**: `keep_edit_script()` stamps `edit=1` onto any form submitted *while editing* (it listens for submit **and** patches `HTMLFormElement.prototype.submit`, since rename fields commit programmatically and fire no event), the handler carries the posted flag back on its redirect, and the page turns edit on and strips the param with `history.replaceState`. A handler must never append `edit=1` on its own.

**User settings** live in the **username's own dropdown**: **Settings** then **Log out**. Settings opens `settings_modal_html/styles/script` (`lib/chrome.php`). Changing your password posts `change_password` to whatever page you're on — `require_login()` answers it and replies JSON. The **Change password** button sits on its own row (`.setpwrow`) directly under the password fields, above the Theme picker. The footer below is a row of identical 40px round icon buttons sharing the `.setact` class — **Done** (accent, primary) is what's left; Share needed `lib/sharing.php` and Widget needed the Calendar's feed page, and both went with the suite.

**Pages revive on return** (`revive_script()`, in `chrome_script()`). iOS restores a home-screen PWA's page from memory on app-switch, and Safari's back-cache does the same. Every chrome page reloads itself when it comes back after **five clear seconds** away (and on a `pageshow` with `persisted`), unless it would interrupt something: never in edit mode, never while a field or the note editor holds focus, never with a window (`[class*="modal"].open`) or a swatch tray (`details[open]`) open. The reload stashes `scrollY` under the scroll keeper's key first.

**Scroll position survives an action.** `keep_scroll_script()` stashes `scrollY` in `sessionStorage` on submit and restores it on the next load, then forgets it, so a fresh visit still opens at the top. It hooks the `submit` event *and* patches `HTMLFormElement.prototype.submit`.

**Swipe a row left to delete it** (`swipe_delete_styles()` / `swipe_delete_script()`). Mark the row `swipe-row` and give it a `needs-confirm` delete control: the gesture adds `swiped`, which reveals that control, and the swipe counts as the first press so it deletes on one tap. It stands down in edit mode.

**Deleting is a two-press gesture** (`confirm_delete_styles()` / `confirm_delete_script()`) — there is no `confirm()` box and no Undo anywhere. Give the control class `needs-confirm`: the first press fills it red — the label never changes, since a `×` that became the word `Delete?` resized its row — and the second submits and the script injects a hidden `confirm=1`. Server handlers must require `!empty($_POST['confirm'])` before destroying anything, so a stale page can't delete on one tap. Pages that don't use `chrome_styles()`/`chrome_script()` emit the two helpers themselves.

**Icon buttons are circles.** Any button whose label is a glyph (`+`, `×`, pencils, swatches, ticks) wears `border-radius: 50%`. Text buttons (Save, Done) stay rectangles and pills.

**Every icon button is centred — check this before every deploy.** Any button whose label is a glyph rather than words must carry `display: inline-flex; align-items: center; justify-content: center;`. A glyph left in a `display: block`/`inline-block` button sits on the text baseline with the font's own leading above it, so it rides high or low by a pixel or two — invisible in isolation, obvious the moment it sits beside another button. `line-height: 1` alone does not fix it, and neither does `text-align: center`, which only handles the horizontal half. Grep the diff for new buttons and confirm each one has the three properties.

**A row of buttons is one size.** Where several sit together they share a height (32px) set on the row, not per button. Labels are short. Button sizing reference: `padding: 0.35rem 0.9rem; font-size: 0.9rem; border-radius: 999px`, accent (`#34d399` on `#06251b`, weight 700) for the primary action and outlined (`1px solid #333`/`#444`) for the rest.

## Working here

- Commit granularly and deploy promptly.
- **Change a feature, change its test in the same commit; add a feature, add a test with it; fix a bug, add the case that would have caught it.** `php tools/test.php` is the run (no framework: it seeds a scratch data dir with the real seeder, boots `php -S` against it and drives the real pages over real HTTP) and `TESTING.md` is the map of what it covers and what still has to be checked by eye. Keep that map in step, or a thing ends up in neither list and nobody is looking at it. The harness runs no JavaScript, so every gesture is in the by-eye column — and that is where nearly every bug that has actually reached a phone has come from.
- Before every deploy, re-check that each icon button you touched is visually centred.
- Match the existing style: procedural PHP, short helper functions with one-line docblocks, `e()` for escaping, inline `<style>`/`<script>` in the page.
- To exercise a page without credentials, drive it from the CLI: start a session, set `$_SESSION['auth']`/`$_SESSION['user']`, set `$_SERVER['REQUEST_URI']`, then `require` the page.

### Standing rules

- **Behaviour lives in `lib/`; a page holds plumbing.**
- **`/calmind/` is not this repo's.** It belongs to the CalMind monorepo and its own
  deploy. Both deploy scripts exclude it; do not remove that exclusion, and do not add
  anything at that path.
- **Two sessions share this repo.** `git pull --autostash` first — another agent's
  half-finished work must not ride along on your commit.
- **Production is never touched unless Sean says so in that message.** A bare
  `./deploy.sh` is test-only for exactly this reason. A fix goes to test and prod
  together; a feature goes to test and waits for him before `promote`.
- The `deploy` test area here only reads the scripts as text and parses them with
  `bash -n`, and proves an idiom by running a one-line snippet rather than the script
  itself. Keep it that way.
- `tools/seed-accounts.php` touches the demo accounts and nothing else.

### Traps that have cost real time

- This has already bitten here: the
  harness looked for `'Warning:'` in pages that render `<b>Warning</b>:`, so every PHP
  warning sailed through green for as long as that check existed. `quiet()` in
  `tools/test.php` is the repair.
- **Ask what happens when a write fails.** The expensive bugs here are the silent ones —
  a `store_write()` whose `false` goes nowhere, a suppressed `@file_put_contents`, a data
  dir the web user cannot write. That last one is exactly how a seeding run printed
  "Seeded…" over a hundred failed writes. On this host `/home/protected/data/` is owned
  by the `web` user (`drwx------`) and the SSH login only shares its group, so anything
  that must *decrypt* has to run as the web user — over HTTP, not over SSH.
- **Some bugs exist only on the phone.** `env(safe-area-inset-*)`,
  `window.navigator.standalone`, a fixed overlay under the clock, a tap target that is
  comfortable with a mouse and cramped with a thumb. Open it on the phone before calling
  a layout done.
