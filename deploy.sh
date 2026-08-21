#!/usr/bin/env bash
#
# Deploy the site to NearlyFreeSpeech.  One-way:  your Mac  ->  the server.
#
# Two live instances share ONE source tree (no forked copy of the code):
#
#   PRODUCTION      served at  /          public -> /home/public
#                                         lib    -> /home/protected/lib
#                                         data   -> /home/protected/data
#   TEST (sandbox)  served at  /test/     public -> /home/public/test
#                                         lib    -> /home/protected/lib-test
#                                         data   -> /home/protected/data-test
#
# The same PHP serves both. A page under /test/ loads lib-test/, whose config.php sets
# base=/test and data_dir=data-test, so the mirror is isolated in code, config AND data.
# Which instance a cross-app link points at comes from suite_base() at runtime, never a
# hand-kept second copy of the code — edit once, it works in both places.
#
# Usage:
#   ./deploy.sh              deploy the working tree to TEST only        (default; safe)
#   ./deploy.sh test         same
#   ./deploy.sh prod         deploy the working tree straight to PRODUCTION
#   ./deploy.sh both         deploy to TEST *and* PRODUCTION in one go
#   ./deploy.sh promote      copy the live TEST tree onto PRODUCTION, server-side —
#                            ship exactly what you verified on /test/, no re-upload
#   add --dry-run (or -n) to any of the above to preview and touch nothing
#
# What it NEVER touches, in any mode:
#   - lib/config.php  and  lib-test/config.php   (each instance keeps its own secrets)
#   - /home/protected/data/  and  /home/protected/data-test/   (everyone's live data)
#   - and it never uses --delete
#
set -euo pipefail
cd "$(dirname "$0")"

# The deploy target (SSH <USERNAME>@host) names a real login, so it's kept OUT of the repo:
# it lives in a gitignored deploy.conf beside this script. Copy deploy.conf.sample to
# deploy.conf and set HOST, or export SUITE_DEPLOY_HOST. See README ("Reconcile"/"Secrets").
[ -f "$(dirname "$0")/deploy.conf" ] && . "$(dirname "$0")/deploy.conf"
HOST="${HOST:-${SUITE_DEPLOY_HOST:-}}"
if [ -z "$HOST" ]; then
  echo "No deploy target set. Create deploy.conf (gitignored) from deploy.conf.sample with" >&2
  echo "  HOST=<USERNAME>@ssh.<region>.nearlyfreespeech.net   (or export SUITE_DEPLOY_HOST)." >&2
  exit 2
fi
SSH="ssh -o BatchMode=yes"

DRY=""
MODE=""
for arg in "$@"; do
  case "$arg" in
    -n|--dry-run)            DRY="--dry-run" ;;
    test|prod|both|promote)  MODE="$arg" ;;
    *) echo "Unknown argument: $arg"
       echo "Usage: ./deploy.sh [test|prod|both|promote] [--dry-run]"; exit 2 ;;
  esac
done
MODE="${MODE:-test}"      # a bare deploy is TEST-only, so prod is never hit by accident
[[ -n "$DRY" ]] && echo "──  DRY RUN — nothing will actually change  ──"
echo "==> Mode: $MODE"

# 1. Lint every PHP file first. Both instances run the *same* source, so one pass covers
#    them both. Abort the whole deploy if anything is broken.
echo "==> Linting PHP…"
errors=0
while IFS= read -r f; do
  if ! php -l "$f" >/dev/null 2>&1; then
    echo "    SYNTAX ERROR in $f"; php -l "$f" 2>&1 | tail -1
    errors=1
  fi
done < <(find public calmind lib -name '*.php')
if [[ $errors -ne 0 ]]; then
  echo "Aborting — fix the syntax errors above and try again."
  exit 1
fi
echo "    all PHP OK."

# rsync the source into one instance's public + lib dirs, then make it web-readable
# there. config.php is never sent (each instance keeps its own), data dirs are never in
# these paths, and --delete is never used — so a plain deploy can only ever add/update
# code. openrsync on macOS has no --chmod, so a file left at 0600 is fixed on the server
# (add-only: a+rX never grants write or strips anything, and config.php is skipped).
# -L dereferences the symlinks that stitch the top-level calmind/ area into public/ and
# lib/ (public/calmind, lib/tabbar.php, …), so the server always gets real files and its
# layout is unchanged by the repo split.
push_instance() {   # $1 = public dest   $2 = lib dest   $3 = human label
  local pub="$1" lib="$2" label="$3"
  # calmind/ belongs to the NEW CalMind app (the ~/GIT/CalMind monorepo, its own
  # deploy script) — on TEST since 2026-08-07 and on PROD since 2026-08-20, when it
  # took over /home/public/calmind and the suite's pages moved to
  # /home/protected/suite-retired. This exclusion used to be test-only, under a
  # comment reading "Prod still gets the suite"; a prod deploy after the cutover
  # would have rsynced the retired suite straight back over the live app.
  # Anchored, so only the top-level calmind/ is skipped.
  local skip=(--exclude='/calmind')
  echo "==> [$label] public/ -> $pub/"
  # ${skip[@]+"${skip[@]}"}, not a bare "${skip[@]}": macOS ships bash 3.2, where an empty
  # array expansion counts as unset and set -u kills the script. Only prod leaves skip
  # empty, so a bare expansion breaks `prod`/`both` while test deploys sail through.
  rsync -rLptzv $DRY -e "$SSH" \
    --exclude='.DS_Store' --exclude='*.swp' ${skip[@]+"${skip[@]}"} \
    public/ "$HOST:$pub/"
  echo "==> [$label] lib/    -> $lib/   (config.php protected)"
  rsync -rLptzv $DRY -e "$SSH" \
    --exclude='config.php' --exclude='.DS_Store' --exclude='*.swp' \
    lib/ "$HOST:$lib/"
  if [[ -z "$DRY" ]]; then
    echo "==> [$label] ensuring web-readable perms…"
    $SSH "$HOST" "
      chmod -R a+rX '$pub'
      find '$lib' -type d -exec chmod a+rx {} +
      find '$lib' -type f ! -name config.php -exec chmod a+r {} +
    "
  fi
}

# The test instance needs its own config.php in lib-test. It carries NO secrets of its
# own: it requires production's config.php for the real accounts/secrets, then overrides
# only where data lives (data-test) and what prefix links are built under (/test). So we
# create it once, on the server, if it is missing. Delete it to reset the test instance.
ensure_test_config() {
  [[ -n "$DRY" ]] && { echo "==> [TEST] would ensure /home/protected/lib-test/config.php exists"; return 0; }
  echo "==> [TEST] ensuring lib-test/config.php exists…"
  $SSH "$HOST" '
    mkdir -p /home/protected/lib-test
    if [ ! -f /home/protected/lib-test/config.php ]; then
      {
        echo "<?php"
        echo "// Test-instance config for the /test/ sandbox mirror. Inherits the real"
        echo "// accounts/secrets from production, then isolates storage and prefixes"
        echo "// links. Not deployed; created once by deploy.sh. Delete to reset test."
        echo "\$c = require \"/home/protected/lib/config.php\";"
        echo "\$c[\"data_dir\"] = \"/home/protected/data-test\";"
        echo "\$c[\"base\"]     = \"/test\";"
        echo "return \$c;"
      } > /home/protected/lib-test/config.php
      chmod a+r /home/protected/lib-test/config.php
      echo "    created /home/protected/lib-test/config.php"
    else
      echo "    already present"
    fi
  '
}

case "$MODE" in
  test)
    ensure_test_config
    push_instance /home/public/test /home/protected/lib-test TEST
    ;;
  prod)
    push_instance /home/public /home/protected/lib PROD
    ;;
  both)
    push_instance /home/public /home/protected/lib PROD
    ensure_test_config
    push_instance /home/public/test /home/protected/lib-test TEST
    ;;
  promote)
    # Copy the *live test* tree onto production, entirely on the server, so prod ends up
    # running exactly what you verified on /test/ without re-uploading from the Mac.
    # config.php (both instances), the data dirs and the nested test/ tree are left alone.
    if [[ -n "$DRY" ]]; then
      echo "==> [promote] DRY RUN — would copy, server-side:"
      echo "        /home/public/test/       -> /home/public/          (excl. config.php n/a)"
      echo "        /home/protected/lib-test/ -> /home/protected/lib/   (config.php protected)"
      echo "    Data dirs and both config.php files untouched; no --delete."
    else
      echo "==> [promote] /home/public/test/ -> /home/public/  and  lib-test -> lib (server-side)…"
      # /test/calmind/ is the NEW CalMind app, never promoted — prod's suite is only
      # ever updated by a direct prod deploy from the Mac.
      $SSH "$HOST" '
        set -e
        rsync -rlpt --exclude=.DS_Store --exclude=/calmind /home/public/test/ /home/public/
        rsync -rlpt --exclude=config.php --exclude=.DS_Store /home/protected/lib-test/ /home/protected/lib/
        chmod -R a+rX /home/public
        find /home/protected/lib -type d -exec chmod a+rx {} +
        find /home/protected/lib -type f ! -name config.php -exec chmod a+r {} +
      '
    fi
    ;;
esac

echo "==> Done ($MODE). Live data in /home/protected/data{,-test}/ was not touched."
# This must not be the script's last command as a bare `&&` list — on a real run $DRY is
# empty, the test is false, and its exit 1 would become the script's exit code, breaking
# `./deploy.sh && git push`. An `if` returns 0.
if [[ -n "$DRY" ]]; then
  echo "    (that was a dry run — re-run without --dry-run to apply)"
fi
