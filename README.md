# CalMind

**CalMind** is the suite — Reminders, Calendar, Add, Notes and Habits — on the web at
[seancheren.com](https://seancheren.com), with native iOS/watchOS and Android apps in
`ios/` and `android/`.

I haven't been quite happy with subtle things like not being able to have reminders from previous days on the calendar not continue to show until they are checked off.. I also wanted to tie together reminders, notes, and my calendar.. I also like enforcing date and time patterns.

Feel free to deploy this on your own website, build and deploy the iOS version, etc. 


**This is a personal project to have some fun with claude code, which generated essentially all of the code, and the rest of this readme:**



A small multi-user app suite in plain PHP on [NearlyFreeSpeech.NET](https://nearlyfreespeech.net) —
no framework, no build step, no database. Each app is one `index.php` that renders its own
HTML/CSS/JS and posts back to itself; data is encrypted JSON on disk. A matching **native
iOS + Apple Watch** app lives in `ios/` (SwiftUI, local-only, shares no code with the web).

## Features

- **Reminders** — folders + sections, subtasks, dates/times parsed from what you type, repeats, drag-reorder, Copy-as-Markdown.
- **Calendar** — month/week views, several calendars, a per-day panel of events + reminders + notes (deleting a reminder or note here just takes it off the calendar).
- **Notes** — folders + sections, rich-text bodies.
- **Habits** — colour-coded sections (at least one), a week tick-grid and a month of per-day pie charts with a section filter.
- **Add** — one box that captures a reminder, event or note straight into the folder/section or calendar you choose.
- **Chat** (public, no login) and **Aki's Bookshelf** (aki only).
- One login covers the suite; each user has their own encrypted data; sharing is opt-in, per item, between mutual partners (each side keeps a partner list, and sharing only exists while both lists name each other).

## Web — run & test

```sh
php -S 127.0.0.1:8787 -t public     # apps at /calmind/reminders/, /calmind/calendar/, /calmind/add/, /calmind/notes/, /calmind/habits/, /chat/
php tools/test.php                  # the test suite (~15s, no framework)
find public calmind lib tools -name '*.php' -exec php -l {} \;   # lint
```

Local logins come from `lib/config.php` (copy `lib/config.sample.php`); local data lands in `./data/`, separate from the live site.

## Web — deploy

Three live instances share one source tree — **production** (`/`), a **`/test/` sandbox** and a **`/dev/` sandbox**, each with its own data, accounts and sessions — and two scripts deploy them: `deploy.sh` owns test and production, `deploy-dev.sh` owns `/dev/` and can't reach anything else. Both are one-way (Mac → server), lint first, and never send `config.php`, never touch the data dirs, never use `--delete`.

```sh
./deploy.sh            # → TEST only (the safe default)
./deploy.sh promote    # copy the verified TEST tree onto PROD (server-side)
./deploy.sh both       # → TEST and PROD at once
./deploy.sh --dry-run  # preview, change nothing
./deploy-dev.sh        # → /dev/ only, from a clean git checkout of HEAD
```

The SSH target lives in a gitignored `deploy.conf` (copy `deploy.conf.sample`). Secrets live in
`lib/config.php` (gitignored, never deployed): the user map, the `data_key` for at-rest encryption,
and NFSN credentials. A blank `data_key` is generated into `data/.datakey` on first use — keep it.

## iOS + Apple Watch — build & run

A fully native SwiftUI app (no web view, no login, no network) with all data in one local `suite.json`.

```sh
open ios/CalMind.xcodeproj   # pick a scheme + device, then ⌘R:
                                #   CalMind      → iPhone (installs the embedded watch app)
                                #   CalMindWatch → Apple Watch (needs a paired simulator/device)
```

From the command line (no simulator needed):

```sh
cd ios
DEVELOPER_DIR=/Applications/Xcode.app/Contents/Developer swift test        # logic tests
DEVELOPER_DIR=/Applications/Xcode.app/Contents/Developer \
  xcodebuild -scheme CalMind -destination 'generic/platform=iOS' CODE_SIGNING_ALLOWED=NO build
```

Nothing in `ios/` is deployed. See `ios/README.md` for detail. A native **Android** clone of
the same app (Kotlin + Jetpack Compose, same local-only design) is taking shape in `android/`.

## License

BSD 3-Clause — see [LICENSE](LICENSE). Do what you like with it: use it,
change it, fold it into something else, commercially or not, no permission
needed and no warranty given. The two things the licence does ask are that
the copyright notice travels with the source, and that you don't use Sean's
name to endorse whatever you build from it.
