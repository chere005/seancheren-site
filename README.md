# seancheren.com

The hosting account behind [seancheren.com](https://seancheren.com) and the handful of
pages that live directly on it, in plain PHP on
[NearlyFreeSpeech.NET](https://nearlyfreespeech.net) — no framework, no build step, no
database. Each page is one `index.php` that renders its own HTML/CSS/JS and posts back to
itself; data is encrypted JSON on disk.

**This is a personal project to have some fun with claude code, which generated
essentially all of the code, and the rest of this readme.**

## What's here

- **The public front** — Home, Projects, About, Contact and a Themes picker, sharing one
  shell (`lib/site.php`). No login.
- **Chat** — deliberately public, no login.
- **Aki's Bookshelf** — behind the login, then gated to one account. Books from the Open
  Library API, per-book rich-text notes, its own themes.
- **The themes workbench** — behind the login, for building colour palettes. Wired to
  nothing on purpose, so playing with colours can't repaint something someone is using.
- **A status page** — behind the login, gated to one account: deploy and sync status for
  the Mind-suite repos, their release history, and live endpoint reachability.

### What used to be here

**CalMind** — Reminders, Calendar, Add, Notes and Habits — was a plain-PHP app suite in
this repo, with native iOS/watchOS and Android clones alongside it. It has been superseded
by the **CalMind monorepo**, which has served `/calmind/` on this host since 2026-08-20 and
carries its own accounts, its own deploy and its own native apps. The PHP suite, the two
native codebases and the shared behaviour vectors they replayed were deleted here on
2026-08-22. Nothing in this repo deploys to `/calmind/` any more, and both deploy scripts
refuse to.

## Run & test

```sh
php -S 127.0.0.1:8787 -t public     # the site at /, /chat/, /akisbookshelf/, /akisthemes/, /status/
php tools/test.php                  # the test suite (no framework)
find public lib tools -name '*.php' -exec php -l {} \;   # lint
```

Local logins come from `lib/config.php` (copy `lib/config.sample.php`); local data lands in
`./data/`, separate from the live site. `php tools/seed-accounts.php --force` builds the
demo accounts the test run signs in as.

## Deploy

Three live instances share one source tree — **production** (`/`), a **`/test/` sandbox**
and a **`/dev/` sandbox**, each with its own data, accounts and sessions — and two scripts
deploy them: `deploy.sh` owns test and production, `deploy-dev.sh` owns `/dev/` and can't
reach anything else. Both are one-way (Mac → server), lint first, and never send
`config.php`, never touch the data dirs, never use `--delete`.

```sh
./deploy.sh            # → TEST only (the safe default)
./deploy.sh promote    # copy the verified TEST tree onto PROD (server-side)
./deploy.sh both       # → TEST and PROD at once
./deploy.sh --dry-run  # preview, change nothing
./deploy-dev.sh        # → /dev/ only, from a clean git checkout of HEAD
```

The SSH target lives in a gitignored `deploy.conf` (copy `deploy.conf.sample`). Secrets live
in `lib/config.php` (gitignored, never deployed): the user map, the `data_key` for at-rest
encryption, and NFSN credentials. A blank `data_key` is generated into `data/.datakey` on
first use — keep it.

## License

BSD 3-Clause — see [LICENSE](LICENSE). Do what you like with it: use it,
change it, fold it into something else, commercially or not, no permission
needed and no warranty given. The two things the licence does ask are that
the copyright notice travels with the source, and that you don't use Sean's
name to endorse whatever you build from it.
