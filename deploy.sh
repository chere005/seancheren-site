#!/usr/bin/env bash
#
# Deploy the site to NearlyFreeSpeech.  One-way:  your Mac  ->  the server.
#
# Three live instances share ONE source tree (no forked copy of the code):
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
#   ./deploy.sh dev          deploy the working tree to the DEV sandbox
#   ./deploy.sh all          deploy to PROD, TEST and DEV in one go
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
# deploy.conf and set HOST, or export SUITE_DEPLOY_HOST. See README ("Deploy").
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
    test|dev|prod|both|all|promote)  MODE="$arg" ;;
    *) echo "Unknown argument: $arg"
       # Every mode the case above accepts. `all` is not obscure — tools/dtp.sh
       # runs it on every release — and this line told anyone who mistyped that
       # the mode its own lane uses does not exist.
       echo "Usage: ./deploy.sh [test|dev|prod|both|all|promote] [--dry-run]"; exit 2 ;;
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
done < <(find public lib -name '*.php')
if [[ $errors -ne 0 ]]; then
  echo "Aborting — fix the syntax errors above and try again."
  exit 1
fi
echo "    all PHP OK."


# The page's own build stamp, read by /status/'s live poller to know when the
# PAGE (not the data) changed underneath an open tab, and reload it.
git rev-parse --short HEAD > public/status/.page-ver 2>/dev/null || printf 'unknown' > public/status/.page-ver

# rsync the source into one instance's public + lib dirs, then make it web-readable
# there. config.php is never sent (each instance keeps its own), data dirs are never in
# these paths, and --delete is never used — so a plain deploy can only ever add/update
# code. openrsync on macOS has no --chmod, so a file left at 0600 is fixed on the server
# (add-only: a+rX never grants write or strips anything, and config.php is skipped).
# -L is kept for any symlink that appears here later: it sends real files rather than a
# link the server cannot follow. The calmind/ area it was added for is gone (2026-08-22).
push_instance() {   # $1 = public dest   $2 = lib dest   $3 = human label
  local pub="$1" lib="$2" label="$3"
  # calmind/ belongs to the NEW CalMind app (the ~/GIT/CalMind monorepo, its own
  # deploy script), which has owned /home/public/calmind on TEST since 2026-08-07 and
  # on PROD since 2026-08-20. This repo no longer HOLDS a calmind/ of its own — the old
  # plain-PHP suite was deleted on 2026-08-22 — so there is nothing here to send. The
  # exclusion stays anyway: --delete is never used, but an anchored exclude is the one
  # thing standing between a stray local calmind/ directory and the live app.
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

# Each sandbox instance needs its own config.php beside its own lib. It inherits
# production's for the operational secrets (mail, NFSN keys), then CUTS the
# account seed loose — Sean, 2026-08-23: "make sure test and dev have completely
# separate accounts and data". The sandboxes used to inherit prod's users, which
# separated the data and not the identity: a sandbox password WAS the production
# password. Now a sandbox starts with no accounts at all and you sign up through
# its own signup flow (verification code 5678 while mail is stubbed), landing in
# its own encrypted store. Created once, on the server; delete the file to reset.
ensure_sandbox_config() {   # $1 = instance name (test | dev)
  local INST="$1"
  [[ -n "$DRY" ]] && { echo "==> [$INST] would ensure /home/protected/lib-$INST/config.php exists"; return 0; }
  echo "==> [$INST] ensuring lib-$INST/config.php exists…"
  $SSH "$HOST" "
    mkdir -p /home/protected/lib-$INST
    if [ ! -f /home/protected/lib-$INST/config.php ]; then
      {
        echo '<?php'
        echo '// $INST-instance config. Inherits operational secrets from production,'
        echo '// then isolates storage, links and ACCOUNTS — a sandbox login must not'
        echo '// be a production login. Not deployed; created by deploy.sh. Delete to reset.'
        echo '\$c = require \"/home/protected/lib/config.php\";'
        echo '\$c[\"data_dir\"] = \"/home/protected/data-$INST\";'
        echo '\$c[\"base\"]     = \"/$INST\";'
        echo 'unset(\$c[\"users\"], \$c[\"data_key\"]);'
        echo 'return \$c;'
      } > /home/protected/lib-$INST/config.php
      chmod a+r /home/protected/lib-$INST/config.php
      mkdir -p /home/protected/data-$INST && chgrp web /home/protected/data-$INST && chmod 2770 /home/protected/data-$INST
      echo \"    created lib-$INST/config.php and data-$INST/\"
    else
      echo '    already present'
    fi
  "
}

case "$MODE" in
  test)
    ensure_sandbox_config test
    push_instance /home/public/test /home/protected/lib-test TEST
    ;;
  dev)
    ensure_sandbox_config dev
    push_instance /home/public/dev /home/protected/lib-dev DEV
    ;;
  prod)
    push_instance /home/public /home/protected/lib PROD
    ;;
  both)
    push_instance /home/public /home/protected/lib PROD
    ensure_sandbox_config test
    push_instance /home/public/test /home/protected/lib-test TEST
    ;;
  all)
    # PROD first, then its two clones — Sean, 2026-08-23: "deploy a clone from
    # prod to test and dev". One tree, three instances, three data dirs.
    push_instance /home/public /home/protected/lib PROD
    ensure_sandbox_config test
    push_instance /home/public/test /home/protected/lib-test TEST
    ensure_sandbox_config dev
    push_instance /home/public/dev /home/protected/lib-dev DEV
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
