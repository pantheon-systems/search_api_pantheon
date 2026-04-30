#!/bin/bash
set -eo pipefail

PREFIX="$1"
CURRENT_ENV="${2:-}"

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
