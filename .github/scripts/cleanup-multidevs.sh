#!/bin/bash
set -eo pipefail

# Delete stale CI multidevs matching the prefix, except the current one.
# Runs with "if: always()" so it cleans up even when tests fail.

PREFIX="$1"          # e.g. "d10p81s8-" (matches all multidevs for this matrix combo)
CURRENT_ENV="${2:-}" # e.g. "d10p81s8-25" (skip this one, it's the current run)

if [[ -z "$TERMINUS_SITE" || -z "$PREFIX" ]]; then
  echo "::error::TERMINUS_SITE and PREFIX (arg 1) must be set."
  exit 1
fi

for env in $(terminus multidev:list "$TERMINUS_SITE" --format=list 2>/dev/null); do
  if [[ "$env" == ${PREFIX}* && "$env" != "$CURRENT_ENV" ]]; then
    echo "Deleting stale multidev: $env"
    terminus multidev:delete "$TERMINUS_SITE.$env" --delete-branch --yes || true
  fi
done
