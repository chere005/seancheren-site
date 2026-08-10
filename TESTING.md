# Testing

```sh
php tools/test.php              # everything — about 15 seconds; --list for the current counts
php tools/test.php reminders    # one area, by name
php tools/test.php --list       # the area names and their case counts
php tools/test.php --keep       # keep the scratch data dir and the server log
```

Exit code is 0 when everything passed, 1 when anything didn't. `--keep` prints the
scratch directory it used; `server.log` in there has anything PHP wrote to stderr.

## The bargain

**Change a feature, change its test in the same commit. Add a feature, add a test with
it. Fix a bug, add the case that would have caught it before you fix it.**

That last one is not ceremony. Every bug listed under `regress` in `tools/test.php` had
already shipped once. The point of the area is that none of them can ship twice.

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
2. seeds it with the **real** seeders (`tools/seed-example.php`, `tools/seed-buddy.php`),
   which is itself a test of them;
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
nothing else in the suite sets it. **A test run cannot touch `data/`.** It also never
reads `lib/config.php` for credentials: it signs in as the two accounts it just seeded.

Two kinds of assertion, and the labels say which:

- **Behaviour** — a request is made and the result is checked. Most cases.
- **Wiring** — the page has to still *contain* the handler, rule or attribute that makes
  a behaviour possible. Used where the behaviour itself needs a finger. A wiring case
  can't tell you the gesture feels right; it can tell you someone deleted the line that
  makes it work, which is how most of these broke in the first place.

## What is covered

### `test-instance`
`suite_base()` is empty for production, so the tab bar and other cross-app links stay
unprefixed and prod output is unchanged. Under a base (as the `/test/` mirror runs, via
`SUITE_BASE` here), every cross-app link — tab bar tabs, login landing — is prefixed with
`/test`, and no unprefixed one leaks out. A messy prefix (`test/`) is normalised to
`/test`. What this cannot see: the actual `/test/` URL on the live server, the isolation
of `data-test/`, and `deploy.sh promote` — those are in *What only eyes can check*.

### `seed`
Both seeders build a complete account. Buddy is paired with example both ways — shares
out its folders and calendar, and example shares back and carries the same dinners from
its side. Re-seeding is idempotent: it can't double up example's events. The seeders
write nothing outside the scratch directory.

### `auth`
Signed-out visitors get the login page and never a leak of app markup. A wrong password
is refused; a right one redirects to `/calmind/calendar/` from whichever app asked. The login
page is sized to `100svh` and draws no scrollbar. Logout ends the session. A POST with a
missing or wrong CSRF token is a 400 and writes nothing. One session covers every app.

### `storage`
Files are `ENC1:`-prefixed and the plaintext is not readable in them. Legacy plaintext
JSON still reads. `user_data_file()` keeps one person's data out of another's.

### `reminders`
Adding, with and without a date and time typed into the line. Ticking a plain reminder
marks it done; ticking a repeat rolls it to the next occurrence instead and never marks
it done — and the redirect carries `rolled=<id>` (a plain toggle must not), which the
page turns into a flash on that row so the roll never reads as a dead checkbox. The
calendar's `toggle_reminder` does the same. *(By eye: the flash itself, in both apps.)* Inline text edit. Delete needs the confirmed second press. Sections add, rename
and delete. The subtask `+` creates a child under its parent — same folder, same section,
`indent` 1, empty, focused on return — and does **not** indent the row it was pressed on.
A subtask lifts back out. A section can never be indented. `clear_done` removes only the
ticked rows. The rendered list is undated-first. **Required real sections (no catch-all):**
a fresh folder is seeded with a real, renameable default section (`General`), a blank/unknown
section on add resolves to the folder's first, and the folder's last section can't be
deleted — covered for both Reminders and Notes, plus `sections_normalize()` as a unit
(default per folder, re-home loose, idempotent). The **Manage-folders "Default for new
items"** picker sets folder+section together (`set_default_section`) and coerces a bogus
section to a real one. The **collapse-all** button ships in the toolbar. **The full-edit
pencil (`edit_full`)**: updates text/date/time/repeat in place and re-files to another
folder/section (subtasks travelling along); switching the kind to **Event** writes the
event into my events file — a stray calendar id falls back to a real one, an undated
conversion lands on today — and switching to **Note** writes a note titled with the text
into the picked note folder/section (junk destinations fall back). Either conversion
removes the reminder *unless it has subtasks*, which keep it here as a deliberate copy.
A shared view answers `edit_full` with a 403 and renders no pencil, and no conversion
window. **The inline edit field carries `min-width: 0`** (wiring) — without it a flex
input refuses to shrink below ~185px, and on a phone the subtask + and delete × were
shoved off the right edge the moment a row's edit opened; the on-screen check itself was
done in a real browser at phone width. **The three-second grace on a tick** (wiring): a
ticked row stays visible, struck through, in place — `li.done.grace` must override both
the hide and `order: 1` — while the toggle posts immediately with `keepalive`; the timing
itself was watched in a browser (visible at 1.5s, gone at 3.5s, untick inside the window
cancels, a repeat still rolls with its flash). **The swiped delete pins to the screen's
right edge** (wiring): the reveal rule counter-translates by `--swipe-x`, which the
gesture script sets from its own LIMIT, so the CSS and the JS can't drift apart; where
the × actually lands was measured in a browser (20px from the edge — the page's own
padding — where it used to sit 104px in). **`duplicate`** copies the whole block — parent and subtask, fresh ids — directly
under the original and stays in edit mode. *(By eye: the
collapse-all toggle actually folds/expands, the toolbar↔first-divider gap matches the gap
above the toolbar, and the folder-head rename field in edit mode. The row pencil opens the
edit window filled from the row; the kind switch swaps List for Calendar and the hint says
whether a copy stays; +Date/+Time/+Repeat reveal and fold.)*

### `folders`
Add and delete, with a deleted folder's items falling back rather than being destroyed.
The permanent folders can't be deleted. A colour off the palette is refused. The picker's
three gestures: the box toggles one **and lands on the All view** (ticks describe the All
canvas — from a single-folder view the flags used to change while the screen didn't, in
the Calendar's picker too), a row tap makes it the only one showing, `All` shows
everything and then hides everything. The default folder. The heading wears its colour as
a wash and no longer carries a dot. *(By eye: box taps from a single-folder view land on
All with the menu reopened, in Reminders, Notes and the Calendar.)*

### `notes`
Adding opens the editor. A body is sanitised on the way in — `<script>`, event handlers
and tags off the allowlist are stripped, allowed tags survive. `rt_sanitize()` keeps only
`rt-*` classes. An old plain-text note is escaped rather than rendered. Folders behave
like the reminders ones — including the required-real-sections model (a fresh folder gets a
renameable `General`, "Notes" is an ordinary name now, the last section is undeletable) and
the Default-for-new-items picker. **Folder rename** works from the list heading (edit mode)
*and* the Manage-folders window; `folders_rename()` carries colour/hidden/order/default
across and refuses a fixed/duplicate/empty name. **`duplicate`** copies a note in place —
body, folder, section and date carried over, fresh id, directly under the original. **The
editor's date buttons** are pinned at the wiring level: "+ Add date" and the clearing ×
set the field from JS, which fires no event, so both must dispatch the autosave handoff
(`change`) themselves and the autosave fetch must carry `keepalive` — before that, adding
a date read Saved while the file held none. The harness can't click, so the dispatch is
asserted in the served page; the click-through was verified in a real browser when fixed.
*(By eye: both rename fields commit on
Enter/blur; the collapse-all button above the top folder.)*

### `usage`
Every operation leaves one five-field line (time, IP, user, app, action) in
`data/usage.log`: sign-in, failed sign-in, sign-out and a POST action are all logged, and
the log **never carries what an action posted** — that negative is the promise under test.
The file sits outside the web root (a fetch of `/data/usage.log` finds nothing) and stays
plain text, not `ENC1:`; the writer leaves it group-readable and the data dir
group-traversable, which is what lets the SSH login tail it on the live host.
*(By eye: nothing — there is no UI for this log; `tools/usagelog.sh` reads it over SSH.)*

### `calendar`
The day payload is keyed by date. Within a day: events first in time order, then
reminders, then notes; a day's reminders are undated-first, then oldest, then by time. An
undated Calendar-folder reminder rides on today and is **not** flagged overdue. Adding,
editing and deleting an item from the day panel, with delete needing the second press.
Calendars add, recolour, **rename** (`cal_rename`: id keeps, colour survives, a blank name
or a partner's id changes nothing; the manage window renames in place from a pencil) —
plus default and delete. **The edit window converts one way into notes**: `edit_item` with
`kindchoice=note` turns an event or reminder into the title of a new note (an event moves
out entirely, a matching `kindchoice` stays a plain edit, a partner's id creates nothing);
**`duplicate_item`** copies an event, or a reminder with its whole block, staying in edit
mode. *(By eye: hold/double-click on a day-panel row reveals the pencil/duplicate/× circles
instead of jumping into the window; the kind pills show [itself, Note] when editing.)* A calendar row tap leaves only it showing and
`All` puts them back. Ticking a repeat from the calendar rolls it. A month cell wears **one
kind icon per colour** — two calendars' events on one day draw two calendar glyphs, and no
cell repeats a kind+colour pair. The **dot legend** is
built in JS from the cells on screen (keyed by owner and kind — a calendar/checkbox/page
glyph, tinted the item's own colour, before each kind's names); the page only ships its
key (`LEG_OWNERS` order, `LEG_CALS` names, `LEG_ICONS` glyphs) and the empty container.
The legend's bar sits between the calendar half and the day panel (never inside the
scrolling `.cal-top`), so it's on screen the moment the app opens; it hides when empty. The
**add/edit modal** hides Time and Repeat behind **+ Time** / **+ Repeat** buttons (both start
hidden), with the repeat count before its unit selector. *(By eye: the day-panel reminder
picker lists real sections and lands new reminders in one; the + buttons reveal their fields
and the × folds them. **The legend renders only the dots actually in view** — the whole month
in month mode, only the shown week(s) in week mode, updating as you page weeks — and its glyph
colours match the day dots, including colours propagated from a Reminders/Notes folder.
**Paging back and forward works in both modes**, including across month edges in week mode —
the remembered day restores only on a bare arrival, never by bouncing deliberate paging.
Every day cell is the same size: the icons sit in a fixed two-row well, three per row on a
phone, and a day with more than six wears five plus a `+` in the sixth slot.)*

### `habits`
Ticking a day answers with the new state and stores it. Habits add, rename and delete. A
section colour must come from the palette, and the response says what actually stuck.
Both views render. **Sections are required:** a fresh account opens with a default section
(persisted with a stable id), each section header carries its own **+ Habit** shown out of
edit mode, and the last section is undeletable. The **Manage sections** window (in the filter
dropdown) renders and reorders sections without disturbing the habits. The **section filter**
has the suite's three picker gestures — the box toggles one, a row tap makes it the only one
counted, `All` counts everything and then nothing — and an unknown section key is a no-op
rather than a stored ghost (there is no "Ungrouped" key any more). It sits by the Week/Month
switch in **both** views; filtering changes the pies and the legend, but only the month pies —
the week grid still holds every habit whatever the filter says. Each day's pie is drawn in its
**sections' own colours** (a conic-gradient of section-coloured slices), never the flat accent.
The month view also draws a **section colour legend** (a dot and name per counted section).
*(By eye: sections collapse from the header chevron and the collapse-all button folds/expands
them all; the collapse-all sits above the top section, aligned with the back button.)*

### `add`
A reminder lands in the chosen folder and section, an event in the chosen calendar, a
note in the chosen note folder. A destination that doesn't exist falls back instead of
being taken on trust — an unknown section now resolves to the folder's real default (which
must exist), not a nameless catch-all. The line is parsed for a date and time. *(By eye: the
section dropdowns list real sections and open on the stored default; the **+ Repeat** control
reveals its aligned count/unit and is hidden for notes.)*

### `editmode`
**No action originates edit mode — the server only echoes it.** Edit mode has exactly two
doors, long-press and double-click, so every redirecting handler is run twice: a bare POST
must come back *without* `edit=1` in its Location, and one posted with the flag must carry
it back. Covered per app on the actions that used to append it unconditionally — delete
(confirmed and the unconfirmed bounce), duplicate, add_subtask, add_section and
rename_section in Reminders; delete, duplicate and add_section in Notes; the day panel's
delete_item and duplicate_item in the Calendar; the delete_habit bounce in Habits. Plus
one wiring pin: every page with edit mode must patch
`HTMLFormElement.prototype.submit`, because the rename fields commit programmatically
(no submit event) and without the patch the echo rule would kick you out on every rename.
*(By eye: swiping a row away, deleting and duplicating all leave the page out of edit
mode; a rename mid-edit stays in it.)*

### `sharing`
`SHARE_PAIRS` seeds are right and a stranger has no partner. A partner's shared folder
shows and an unshared one doesn't. Writing into a shared folder writes to *their* file,
not mine. Structural edits to their folder are a 403 that changes nothing. `share_set`
both ways. A shared row **ticks from the All view**: the check posts against their file
and `ret=All` lands the redirect back on All — the dead read-only mark is gone.
**Partner lists, strictly mutual**: two fresh accounts share *nothing* until each has
added the other (`partner_add`) — one-sided is nothing, for either side; the moment one
removes the other (`partner_del`, confirmed-second-press required), sharing ends both
ways and the shared folder leaves the other's app. Rename replaces the entry; junk names
and adding yourself are refused; case and whitespace clean to the stored form. The
built-in pairs act as never-written seeds — untouched they still pair, a share toggle
never disturbs them (`shares_save` carries `partners` through), deleting the seeded name
opts out both ways and re-adding restores it. The share window ships its pencil and the
partner window on every non-shared page, partner or none. *(By eye: the tick's flash and
the row hiding/reappearing with Completed, from the All listing; the partner window's
add/rename/two-press delete and the sharing/waiting badges updating live.)*

### `widget`
The feed refuses a bad token and answers a good one with JSON. **The feed is read-only** —
a POST behind the token changes nothing. The reminders API has no anonymous read.
`quick.php` adds for today.

### `lib`
The parser is slash-only and US-order, and `2/3 cup` parsing as a date is asserted as the
known limitation it is rather than left to surprise someone. Month and year repeats clamp
the day (Jan 31 → Feb 28, Feb 29 → Feb 28) instead of sliding. `repeat_next()`.
`folder_tint()` is 8-digit hex and refuses anything else. `plus_icon_svg()` is never a
text plus. Every palette has six colours and validates its own; each app's shade of a
hue is measurably distinct from every other app's, every own colour clears 3:1 on the
dark themes' card, and each shared set is a clearly lighter same-hue version of its
own. A colour stored under any earlier palette generation bumps to the same slot in
today's (`palette_recolor`) — folders, calendars, habit sections and shared overrides
alike — while a stranger still falls back positionally.
The palettes viewer (`/userpalettes/`) renders and grades every swatch's hex label
by its contrast on each theme board, and groups its boards into drafts, newest first —
Draft 3 the live leaned palette (all-clear on Midnight), Draft 2 the II retunes (each
must report all-clear), Draft 1 the frozen earlier tiers.
`reminders_folder_migrate()` is idempotent. Output is escaped.

### `pages`
Every page renders for two seeded users with no fatal, warning, notice or deprecation.
The sweep (`quiet()`) matches PHP's HTML-mode spelling too — `<b>Warning</b>:` — because
the plain `Warning:` needle never matched a real displayed warning, which is how the
folder manager's `$fixed[0]` crash reached a phone with the suite green. The public
pages need no login. **An empty brand-new account is a working empty suite, not a crash** —
the case that catches "works for me, my account has data".

### `regress`
One case per bug that reached a phone. Picker row taps stop the click reaching the PWA
link interceptor. A partner's folder view still shows the checkmarks. The edit gesture
opens a section name (and renaming works in Notes as well as Reminders, with the rows
following the rename). Editing reads the date out of the text; a rename with no date in
it leaves the date alone; a date picked by hand wins the *value* while the typed tokens
still leave the title — held across the calendar's add and edit windows, Reminders' add
with an explicit due (which used to skip parsing and lose the typed time), and the
full-edit pencil. Habits
reorder, and a reorder that never mentions a habit keeps it. The Calendar remembers the
day and the tab bar is what forgets it. The tab bar `+` uses a symmetric margin. Every
chrome page ships the revive machinery: reload on return after five clear seconds away
(and on a back-cache `pageshow`), standing down mid-edit and mid-typing. *(By eye: the
actual revive — background the PWA, tick something from the widget or another device,
come back and the check mark is there; and returning mid-typing must NOT reload.)*

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

### `notes2`
What the first pass skimmed: a note's folder, section and date through a full add → save →
delete, with delete needing the second press; sections added, renamed and deleted per folder; a fresh
folder getting a real renameable `General` ("Notes" is an ordinary section name now, not
a reserved catch-all); a note folder colour refusing another app's palette; and the drag
payloads — section reorder, cross-folder re-files with the duplicate-name refusal.

### `drag`
The payload half of every drag, replayed exactly as the JS posts it — the gesture itself
is by-eye, but "it looked right then reverted on reload" bugs live entirely on this side.
Section order in the All view and in a single-folder view (the folder-keyed id map both
apps post now); a section dragged into another folder re-filing by id with its rows
following; the duplicate-name refusal with the realistic whole-screen payload (the
destination's own same-named section is in the map, and being mentioned must not free its
name); row re-filing across folders, with a shared/unknown folder key ignored; row order
inside a section; the manager's folder drag (`reorder_folders`); rows and sections the
payload never mentions surviving untouched; the stale flat-name payload from a page left
open across the deploy reordering nothing; a section id posted twice stored once; and the
"last section out" sequence — the reorder that empties a folder, then the chained
`delete_folder`. **Every one of these ends with a fresh page load** (the read path
re-normalises and may write the file back) and re-checks that the order still holds.

### `calendar2`
A repeat expanded across the month being drawn. Paging to another month. A reminder folder
switched to Off with `rf_mode` really leaving the calendar. Adding a reminder from the day
panel into a chosen folder and group. A stale calendar id on an event falling back.

### `calshow`
The full showing matrix for one calendar day, in one place — this area exists because a
partner's dated shared notes silently never reached the calendar (reminders and events
each had their partner pass, notes didn't) and nothing was watching the whole table.
**Every kind from both owners lands on the day**: my event, reminder and note, and the
partner's event on a shared calendar, reminder in a shared folder and note in a shared
note folder — each with the right kind, the right owner mark and a colour. **What must
never show stays off**: the partner's event/reminder/note in things they never shared,
and my own event while its calendar is hidden (coming back when it's shown). **The
week-mode and swipe machinery ships wired**: the touch handlers on the grid, the
`calWeekMode` persistence, the `wk-hide` fold, the cross-month `wk=first|last` links,
the arrow interception, the sideways-paging threshold and the pointerdown/pointerup tap
rule are all pinned. *(By eye: the gestures themselves — though they were also driven
synthetically in a real browser against both local and production when this area was
written: swipe up engages week mode, the arrows step a week, swipe down restores.)*

### `habits2`
The month view's per-day count (never more done than there are habits), and each day's pie
drawn in section colours rather than the accent. Week paging moving a whole week at a time.
Deleting a section keeps its habits, moving them into a remaining section (never orphaned,
since sections are required). The chosen view remembered per user.

### `feed`
The widget feed groups by day, never carries a note, and gives reminders the id their tick
link needs. A stale `cals=` pin narrows rather than errors.

### `edges`
An unknown id is a no-op, not a crash. A malformed JSON payload is ignored rather than
believed. Unicode and very long text survive a round trip and clip at the documented 500.
An empty or whitespace-only add is refused. The same section name in two folders stays two
sections, and renaming one leaves the other alone.

### `lib2`
Dates in every documented shape (`m/d`, `m/d/yy`, `m/d/yyyy`) and times (`2pm`, `2:30pm`,
`12:05 am`). `repeat_clean()` refusing an unknown unit. `folder_clean()` collapsing
whitespace, stripping control characters and clipping to 40. `folders_reorder()` losing
nothing. `kind_color_css()` emitting variables, and the event blue still being a blue.

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
can check*.

### `token`
`token_user()` matches the whole string or nothing — a prefix, an extension and a
different case are all nobody. Two people's tokens read two different feeds. **The feed
writes nothing whatever it is asked**, and the reminders API has no anonymous read and no
anonymous write.

### `chat`
Open to anyone, no login. A message posts and shows. A message and a name are escaped
rather than rendered. Whitespace is not a message.

### `themes`
The palette workbench (`public/akisthemes/`). Behind the login; opens seeded with the eight
starters, twelve editable roles each. A colour is stored only when it is a real `#rrggbb` in
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

### `shared2`
Recolouring a partner's shared folder writes only on the viewer's side, keyed by the
`@<partner>:<Folder>` view name, and leaves the owner's file byte-identical. A colour off
the palette, or a folder they never shared, is refused. Resolution goes mine, then theirs,
then a shared-palette default by position.

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

### `quick`
`quick.php` is the one page the widget can reach that writes. A quick add lands on today
in the fallback folder with no section; the line is parsed for a date and time.
`?tick=<id>` shows that one reminder and its Done button marks it — or, for a repeat,
rolls it instead. A POST with no token or a wrong one is a 400 and changes nothing.

### `deploy`
Static checks on `deploy.sh`, because a deploy is the one thing here that can destroy data
and the one thing a test run may never actually perform. It parses; no `rsync` line uses
`--delete`; every `lib` push excludes `config.php`; nothing names a live data directory; a
bare deploy is the test instance and production needs saying out loud. **`/test/calmind/`
belongs to the NEW CalMind monorepo (`~/GIT/CalMind`) as of 2026-08-08**: a suite test
deploy must exclude the top-level `calmind/`, and `promote` must exclude it from the
server-side copy — prod's suite is only ever updated by a direct prod deploy, and the
suite's own pre-promote review now happens on `/dev/`. Also
`tools/seed-http.php`: the committed copy carries no key, compares in constant time, has
no default data directory, and is never deployed. The `calmind/` repo split is guarded
here too: `public/calmind` and the four CalMind-only lib files must be symlinks into the
top-level `calmind/` area, and both deploy scripts must rsync with `-L` so the server
always receives real files in the pre-split layout. `deploy-dev.sh` gets the same static
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
- [ ] Nothing is clipped by the notch or the home indicator (`env(safe-area-inset-*)`).
- [ ] Tapping a link doesn't kick you out to Safari with browser chrome.

**The `/test/` and `/dev/` sandboxes** (after touching a deploy script or any cross-app link)

- [ ] `./deploy.sh test` publishes to `seancheren.com/test/`; the app opens there and the
      tab bar, login and widget links all stay inside `/test/` (never jump to the root).
- [ ] Signing in on `/test/` lands on `/test/calmind/calendar/`, and the data you add there does
      **not** appear in production (and vice versa) — `data-test/` is separate.
- [ ] `./deploy.sh promote` leaves prod running what test ran; production's data and both
      `config.php` files are untouched.
- [ ] `./deploy-dev.sh` publishes to `/dev/` only; its own login (not production's) signs
      in there, and neither production nor `/test/` changed.

**Gestures** — one pass per app

- [ ] Long-press (touch) and double-click (desktop) enter edit mode: on a reminder row,
      a note row, a section head, a folder head, a calendar row.
- [ ] Holding to enter edit mode does **not** paint the text blue as if it were selected
      (Reminders, Notes, Calendar day panel, Habits).
- [ ] A section head's gesture opens its **name** for typing, in Reminders and Notes.
- [ ] A reminder's gesture opens its **text** inline.
- [ ] The `+` beside a folder name (adds a section) shows **without** entering edit mode, in
      Reminders and Notes; each Habits section header's **+ Habit** likewise shows out of
      edit mode, and adding from there doesn't flip edit mode on.
- [ ] Tapping empty space leaves edit mode; Escape leaves edit mode.
- [ ] The back button is a black × while editing, and it leaves edit mode.
- [ ] Swipe a row left: the delete appears pinned at the screen's right edge and deletes
      on one tap — and neither the swipe nor the delete turns edit mode on.
- [ ] Two-press delete fills red on the first press and only deletes on the second.
- [ ] Tick a reminder with Completed off: it stays in place, struck through, for three
      seconds before hiding; unticking inside the window keeps it; a repeat still rolls
      to its next date with the flash.
- [ ] Drag: a reminder between sections; a whole section as a block; a note; a habit;
      a habit section; a folder in the manager; a calendar in the manager.
- [ ] Drag **across folders** (Reminders and Notes, All view): a row into another folder's
      section; a whole section into another folder — both survive a reload. (The payload
      side of this is covered by the `drag` test area; the gesture itself is by-eye.)
- [ ] Dragging a section into a folder that already holds one by that name snaps back on
      reload rather than making a duplicate.
- [ ] Dragging a folder's **last** section into another folder asks before deleting the
      emptied folder — OK moves the section and the folder disappears; Cancel puts the
      section back and nothing posts. The permanent Calendar folder (Reminders) and the
      last remaining Notes folder are never offered for deletion.
- [ ] Icon buttons are circles everywhere — row clusters, manager ×s/pencils/swatches,
      modal clear-×s, habit grid cells — and the tab bar's active highlight is one fixed
      circle that never nudges the icons' spacing.
- [ ] A drop line never appears over a partner's shared block, for rows or for sections.
- [ ] Nothing moves until the drop, and the drop line says where it will land.
- [ ] Collapse a section and a folder; both survive a reload.

**Lists**

- [ ] Reminders/Notes, All view: each folder's name wears its colour wash, and the divider
      ladder reads right — heaviest above a folder, middleweight above a section, hairline
      between rows.
- [ ] A picker dropdown (folders, Habits section filter, calendars) never grows a horizontal
      scrollbar on a long name — the name wraps; a partner's shared badge stays with the name.
- [ ] Copy-as-Markdown appears in the Reminders toolbar only on the `sean` account.

**Themes** — one pass per theme worth checking (the harness sees the vars, not the paint)

- [ ] Midnight is pixel-for-pixel the old dark look on every app page.
- [ ] Sage (the one cream theme): text, muted text, section gold and the accent all
      read on the cream page; native dropdowns and date fields open light, not black.
- [ ] The tab bar, day panel, pickers/dropdown menus and every modal (settings, folder
      manager, calendar manager, share) follow the theme — no black slab on a cream page.
- [ ] The theme swatches in Settings each show their page colour with their accent dot,
      and picking one repaints after the reload, including the iOS status-bar colour.
- [ ] Habits keeps its violet identity on every theme; kind colours and the error red
      never change with the theme.
- [ ] Habits: the section filter sits by the Week/Month switch in **both** views, and its
      menu opens and stays open (over the grid below) while you tick through it.
- [ ] Habits, month view: each day's pie is drawn in its sections' own colours, and a full
      day reads as a solid circle in one colour when only one section is counted.

**Calendar**

- [ ] A day is selected by a tap and never by a swipe across the grid.
- [ ] Swipe up on the grid for week mode; it sticks across a reload.
- [ ] A firm sideways swipe pages by the same step the arrows do.
- [ ] Tap a note, come back — you land on the day you were on.
- [ ] Tap the Calendar tab — you land on today.
- [ ] Close the app, reopen it — you land on today.
- [ ] The dots on a day read as "how much is on" and the reminder dot takes the worst
      state of the day.
- [ ] Deleting a reminder or note from the day panel (swipe × or the edit window's Delete)
      only takes the date off — it stays in its own app; an event deletes outright.

**Keyboard and input**

- [ ] iOS does not zoom when a field takes focus (every input is `font-size: 16px`).
- [ ] The keyboard doesn't cover the field you're typing in.
- [ ] Autosave in the note editor says Editing… then Saved.

**Widget and quick add**

- [ ] The Scriptable widget still renders after a change to `feed.php`.
- [ ] Its tick box opens `quick.php?tick=` and the Done button marks or rolls.
- [ ] A widget built from an older script still works.

**Things the harness deliberately doesn't do**

- No browser: no JavaScript is executed, so anything JS-only is wiring at best.
- Beware a marker word that also appears in the stylesheet. Asserting that the habits page
  "contains mgrid" passed on a page with no month grid at all, because `.mgrid` is in the
  CSS — the month view went untested for a while behind a green tick. Assert on *rendered
  elements* (`<div class="mcell`), not on a word.
- No screenshots and no layout assertions — nothing here measures a pixel.
- Habit history is random per seed, so nothing asserts on its counts.
- Aki's Bookshelf is covered only at the gate and for its themes; the books, covers, notes
  and shelves are not — it gates on one username and is its own app.
- The chat app is only checked for rendering.
- Nothing tests the live server, TLS, the deploy, or the seeding-over-HTTP procedure.
- The iOS/watch and Android apps are separate codebases with their own suites (`swift test`
  in `ios/`, `./gradlew :core:test` in `android/`), both replaying the shared vectors in
  `spec/`; nothing here drives them.

## Adding a test

Areas are declared with `area('name')` and cases with `t('label', function () { … });`.
Assertions are `ok`, `eq`, `has`, `hasnt` — each throws a message the runner prints.

```php
area('reminders');

t('a thing does what it should', function () {
    $jar = login('example', 'examplepassword');
    req('POST', '/calmind/reminders/', ['csrf' => csrf($jar), 'action' => 'add',
        'view' => 'All', 'text' => 'x', 'folder' => 'Reminders', 'section' => ''], $jar);
    ok(rowBy('example', 'x') !== null, 'it was written');
});
```

Helpers: `login()`, `csrf()`, `req()`, `stored()`, `rows()`, `rowBy()`, `datadir()`,
`showAll()`. Cases in an area run in order and share the seeded account, so a case that
switches folders off should put them back — `showAll($jar)` — or the next one is reading
a list something else hid. That is the one sharp edge in here.
