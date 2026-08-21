#!/usr/bin/env bash
#
# Deploy the /dev/ sandbox — and ONLY the /dev/ sandbox.
#
#   DEV (sandbox)   served at  /dev/    public -> /home/public/dev
#                                       lib    -> /home/protected/lib-dev
#                                       data   -> /home/protected/data-dev
#
# This is deliberately a SEPARATE script from deploy.sh, not another mode inside it, so
# that work on /dev/ and work on test/production cannot collide:
#
#   * deploy.sh     is test / prod / both / promote.  It never writes /dev/.
#   * deploy-dev.sh is /dev/ only.  The destination paths are constants — there is no
#     mode argument that could aim it at production, and it refuses to run if the
#     destination it computed does not sit under /dev.
#
# It also deploys from a PRISTINE GIT CHECKOUT rather than the working tree. deploy.sh
# ships whatever happens to be in public/ and lib/ right now, which is right for one
# person but wrong when someone else is editing those same files: their half-finished
# work would ride along into /dev/, and a file written mid-rsync would ship torn. Here a
# throwaway worktree is made at a chosen commit (HEAD by default), and that is what goes
# up — so what is on /dev/ is always exactly some commit, and never a snapshot of a
# directory that two people are writing to.
#
# Usage:
#   ./deploy-dev.sh                  deploy HEAD to /dev/
#   ./deploy-dev.sh --ref <commit>   deploy a particular commit/branch/tag instead
#   ./deploy-dev.sh --dirty          deploy the working tree (the old behaviour; only
#                                    when you are certain nobody else is mid-edit)
#   add --dry-run (or -n) to preview and touch nothing
#
# What it NEVER touches:
#   - /home/public, /home/public/test and their libs — production and test are deploy.sh's
#   - lib-dev/config.php  (the instance keeps its own accounts/secrets)
#   - /home/protected/data-dev/  (the sandbox's own data)
#   - and it never uses --delete
#
set -euo pipefail
cd "$(dirname "$0")"
REPO="$PWD"

# Same gitignored deploy.conf as deploy.sh — the SSH login is not kept in tracked code.
[ -f "$REPO/deploy.conf" ] && . "$REPO/deploy.conf"
HOST="${HOST:-${SUITE_DEPLOY_HOST:-}}"
if [ -z "$HOST" ]; then
  echo "No deploy target set. Create deploy.conf (gitignored) from deploy.conf.sample with" >&2
  echo "  HOST=<USERNAME>@ssh.<region>.nearlyfreespeech.net   (or export SUITE_DEPLOY_HOST)." >&2
  exit 2
fi
SSH="ssh -o BatchMode=yes"

# The three destinations are constants, never built from an argument. Everything this
# script writes has to sit under one of them.
PUB=/home/public/dev
LIB=/home/protected/lib-dev
DATA=/home/protected/data-dev
BASE=/dev

DRY=""
REF="HEAD"
DIRTY=0
while [ $# -gt 0 ]; do
  case "$1" in
    -n|--dry-run) DRY="--dry-run" ;;
    --dirty)      DIRTY=1 ;;
    --ref)        shift; REF="${1:-}"; [ -z "$REF" ] && { echo "--ref needs a commit" >&2; exit 2; } ;;
    *) echo "Unknown argument: $1"
       echo "Usage: ./deploy-dev.sh [--ref <commit>] [--dirty] [--dry-run]"; exit 2 ;;
  esac
  shift
done

# A last line of defence: if any destination ever stops naming /dev, stop. This is the
# one script that must not be able to write production, and a typo in the constants
# above is the only way it could.
case "$PUB" in */dev) ;; *) echo "Refusing: PUB ($PUB) is not a /dev path." >&2; exit 1 ;; esac
case "$LIB" in *-dev)  ;; *) echo "Refusing: LIB ($LIB) is not a -dev path." >&2; exit 1 ;; esac
if [ "$PUB" = "/home/public" ] || [ "$LIB" = "/home/protected/lib" ]; then
  echo "Refusing: that is production." >&2; exit 1
fi

[ -n "$DRY" ] && echo "──  DRY RUN — nothing will actually change  ──"
echo "==> Target: DEV only  ($BASE/  ->  $PUB, $LIB)"

# Build the tree that will actually be shipped.
if [ "$DIRTY" = "1" ]; then
  SRC="$REPO"
  echo "==> Source: the WORKING TREE (--dirty). Anything uncommitted here ships."
else
  git -C "$REPO" rev-parse --verify --quiet "$REF^{commit}" >/dev/null \
    || { echo "No such commit: $REF" >&2; exit 2; }
  SHA=$(git -C "$REPO" rev-parse --short "$REF")
  SRC=$(mktemp -d "${TMPDIR:-/tmp}/devdeploy.XXXXXX")
  # A detached worktree of exactly that commit: no other agent's in-flight edits, and
  # nothing can change under us while the rsync runs.
  git -C "$REPO" worktree add -q --detach "$SRC" "$REF"
  cleanup() { git -C "$REPO" worktree remove --force "$SRC" >/dev/null 2>&1 || rm -rf "$SRC"; git -C "$REPO" worktree prune >/dev/null 2>&1 || true; }
  trap cleanup EXIT
  echo "==> Source: clean checkout of $REF ($SHA) — the working tree is not read."
fi

# Lint what is about to ship, not what happens to be checked out beside it.
echo "==> Linting PHP…"
errors=0
while IFS= read -r f; do
  if ! php -l "$f" >/dev/null 2>&1; then
    echo "    SYNTAX ERROR in $f"; php -l "$f" 2>&1 | tail -1
    errors=1
  fi
done < <(find "$SRC/public" "$SRC/calmind" "$SRC/lib" -name '*.php')
if [ "$errors" -ne 0 ]; then
  echo "Aborting — fix the syntax errors above and try again."
  exit 1
fi
echo "    all PHP OK."

# The sandbox's config.php is STANDALONE: it does not read production's. Its accounts,
# its session cookie and its encryption key are its own, so signing into /dev/ has
# nothing to do with signing into the live site, and a sandbox login cannot reach real
# data. (Nothing is lost by splitting them: no config sets data_key, so every instance
# already generates its own .datakey inside its own data dir.)
#
# A standalone instance starts with no accounts, which would leave no way in — so one
# bootstrap login is generated on the server and printed ONCE. An older config that
# still inherits production is reported rather than rewritten: replacing it changes who
# can log into a live instance, which is not a thing a deploy should decide.
ensure_dev_config() {
  if [ -n "$DRY" ]; then
    echo "==> [DEV] would ensure $LIB/config.php exists (standalone: own accounts, own session cookie)"
    return 0
  fi
  echo "==> [DEV] ensuring $LIB/config.php exists…"
  $SSH "$HOST" sh -s -- "$LIB" "$DATA" "$BASE" <<'REMOTE'
set -e
lib="$1"; data="$2"; base="$3"
mkdir -p "$lib"
if [ -f "$lib/config.php" ]; then
  if grep -q '/home/protected/lib/config.php' "$lib/config.php"; then
    echo "    !! $lib/config.php still INHERITS production's config, so this instance"
    echo "    !! shares production's accounts and secrets."
    echo "    !! To give it its own login instead:   rm $lib/config.php"
    echo "    !! then re-run this deploy. Safe: the instance has its own .datakey, so"
    echo "    !! its existing data stays readable."
  else
    echo "    already present (standalone)"
  fi
  exit 0
fi
pw=$(php -r 'echo bin2hex(random_bytes(5));')
cat > "$lib/config.php" <<PHPCONF
<?php
// DEV sandbox config — STANDALONE. This instance deliberately does NOT read
// production's config: its accounts, its session cookie name and its encryption key are
// its own, so signing in here is unrelated to signing in on the live site or on /test/.
// Not deployed; created once by deploy-dev.sh. Delete it to reset this instance (its
// data keeps its own .datakey, so the data stays readable either way).
return [
    'users'        => ['dev' => '$pw'],
    'data_dir'     => '$data',
    'base'         => '$base',
    'session_name' => 'SCSESS_DEV',
    'timezone'     => 'America/Chicago',
];
PHPCONF
chmod a+r "$lib/config.php"
echo "    created $lib/config.php"
echo "    ----------------------------------------------------------------"
echo "    LOGIN for $base/    user: dev    password: $pw"
echo "    Shown once. Delete that config and re-run to generate a new one."
echo "    ----------------------------------------------------------------"
REMOTE
}

ensure_dev_config

# config.php is never sent (the instance keeps its own), the data dir is not in these
# paths, and --delete is never used. openrsync on macOS has no --chmod, so anything left
# at 0600 is made web-readable on the server afterwards. -L dereferences the symlinks
# that stitch the top-level calmind/ area into public/ and lib/, so the server gets real
# files and its layout is unchanged by the repo split.
echo "==> [DEV] public/ -> $PUB/"
# --exclude=/calmind: /dev/calmind is the NEW CalMind app's dev instance since
# 2026-08-20, not this suite's. Same reason deploy.sh skips it everywhere.
rsync -rLptz $DRY -e "$SSH" --exclude='.DS_Store' --exclude='*.swp' --exclude='/calmind' "$SRC/public/" "$HOST:$PUB/"
echo "==> [DEV] lib/    -> $LIB/   (config.php protected)"
rsync -rLptz $DRY -e "$SSH" --exclude='config.php' --exclude='.DS_Store' --exclude='*.swp' "$SRC/lib/" "$HOST:$LIB/"
if [ -z "$DRY" ]; then
  echo "==> [DEV] ensuring web-readable perms…"
  $SSH "$HOST" "
    chmod -R a+rX '$PUB'
    find '$LIB' -type d -exec chmod a+rx {} +
    find '$LIB' -type f ! -name config.php -exec chmod a+r {} +
  "
fi

echo "==> Done (dev). Production and /test/ were not touched; $DATA was not touched."
if [ -n "$DRY" ]; then
  echo "    (that was a dry run — re-run without --dry-run to apply)"
fi
