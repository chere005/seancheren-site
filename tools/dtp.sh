#!/bin/sh
# dtp — deploy, tag, push, for the SITE. tdtp — the same with the full test
# run in front: tools/tdtp.sh, which calls this with --full.
#
# Sean, 2026-08-23: "tdtp all apps and sites" — the apps each carry a lane and
# this repo carried none, so a suite-wide release always left the site to be
# deployed by hand and untagged. Same gesture as everywhere else now:
#
#   0. refuse a tree with uncommitted TRACKED changes
#   1. (--full only) php tools/test.php — all or nothing
#   2. bump the MINOR version tag (x.y.0; this repo has no version FILE — the
#      tag is the version, so a previous bare tag is the counter). A bump that
#      failed to deploy is reused, not skipped past, same as the apps.
#   3. ./deploy.sh all   (prod + test + dev; the lint gate lives in there)
#   4. tag, push --follow-tags
#   5. report to seancheren.com/status via CoreMind's report-status.sh —
#      never fatal, a status page must not stop a release.
#
# No devices, no desktop: this is a website. What the apps' lanes spend on
# platform builds this one spends on deploying three instances.
set -e
cd "$(dirname "$0")/.."

FULL=0
for a in "$@"; do
  case "$a" in
    --full) FULL=1 ;;
    *) echo "unknown flag: $a" >&2; exit 1 ;;
  esac
done

BRANCH=$(git rev-parse --abbrev-ref HEAD)
if [ "$BRANCH" != "main" ]; then
  echo "refusing: this lane ships main, and HEAD is on '$BRANCH'" >&2
  exit 1
fi

# ------------------------------------------------------------- the status page
REPORTER="${MIND_DIR:-$(cd .. && pwd)}/CoreMind/bin/report-status.sh"
RUN_ID=""
REPORT_DONE=0
if [ -f "$REPORTER" ]; then
  KIND=dtp; [ "$FULL" = 1 ] && KIND=tdtp
  RUN_ID=$(sh "$REPORTER" start "$KIND" seancheren-site 2>/dev/null || true)
  BEAT_PID=""
  beat_stop() { [ -n "$BEAT_PID" ] && { kill "$BEAT_PID" >/dev/null 2>&1; wait "$BEAT_PID" 2>/dev/null; }; BEAT_PID=""; return 0; }
  trap 'beat_stop; if [ -n "$RUN_ID" ] && [ "$REPORT_DONE" != 1 ]; then sh "$REPORTER" finish "$RUN_ID" failed 3 "stopped before finishing" >/dev/null 2>&1 || true; fi' EXIT INT TERM
  # A BEAT A MINUTE — Sean, 2026-09-07: "make sure during dtp that status is
  # updated every minute at least". start/finish alone leave the card frozen at
  # "running" through a multi-minute build; a beat every 60s keeps the page
  # showing the run alive, and a hung run then shows as a stamp that stops
  # moving. Only when this lane OWNS the run — under `dtp all` the parent beats.
  if [ -n "$RUN_ID" ]; then
    ( while :; do sleep 60; sh "$REPORTER" beat "$RUN_ID" "shipping — $KIND" >/dev/null 2>&1 || true; done ) &
    BEAT_PID=$!
  fi
fi

# ------------------------------------------------------------------- the tree
# TRACKED changes only: two sessions share this repo, and untracked scratch
# must not block a release the way an unstaged edit must.
if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
  echo "refusing: uncommitted tracked changes — the tag must name exactly what shipped" >&2
  exit 1
fi
git pull --autostash --quiet
if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
  echo "refusing: the pull left the tree dirty (a conflicted autostash pop exits 0)" >&2
  exit 1
fi

# -------------------------------------------------------------------- the gate
if [ "$FULL" = 1 ]; then
  echo "==> tdtp: the full run, before anything is touched"
  php tools/test.php || { echo "tests failed — nothing shipped" >&2; exit 1; }
fi

# ---------------------------------------------------------------- the version
# The tag IS the version here. The last bare x.y.0 tag is the counter; an
# ancestor tag equal to HEAD means this exact tree already shipped.
CUR=$(git tag --list '[0-9]*.[0-9]*.[0-9]*' --sort=-v:refname | head -1)
CUR=${CUR:-0.0.0}
if [ "$(git rev-list -n 1 "$CUR" 2>/dev/null)" = "$(git rev-parse HEAD)" ]; then
  echo "refusing: HEAD is already tagged $CUR — nothing new to ship" >&2
  exit 1
fi
MAJ=${CUR%%.*}; REST=${CUR#*.}; MIN=${REST%%.*}
NEW="$MAJ.$((MIN + 1)).0"

# ----------------------------------------------------------------- the deploy
./deploy.sh all

# --------------------------------------------------------------- tag and push
git tag -a "$NEW" -m "seancheren-site $NEW"
git push --atomic --follow-tags origin main "$NEW" || {
  git tag -d "$NEW" >/dev/null 2>&1 || true
  echo "push rejected — main moved on the remote. Pull and re-run; the deploy already landed." >&2
  exit 1
}

[ -n "$RUN_ID" ] && beat_stop
REPORT_DONE=1
if [ -n "$RUN_ID" ]; then
  sh "$REPORTER" finish "$RUN_ID" ok 0 "$NEW live on prod, test and dev" >/dev/null 2>&1 || true
fi
echo "==> dtp done: $NEW is live on prod, test and dev"
