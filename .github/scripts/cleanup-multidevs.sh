#!/bin/bash
set -eo pipefail

# Clean up CI multidev environments:
# 1. Delete stale multidevs matching PREFIX from prior runs (skip current)
# 2. Delete any CI-pattern multidev older than 72h (catches orphans from retired matrix combos)

PREFIX="$1"          # e.g. "d10p81s8-" (matches all multidevs for this matrix combo)
CURRENT_ENV="${2:-}" # e.g. "d10p81s8-25" (skip this one, it's the current run)
MAX_AGE_HOURS=72

# Matches CI-generated multidev names like d10p81s8-25, d11p83s9-31
CI_PATTERN='^(d[0-9]+p[0-9]+s[0-9]+|[0-9]+s[0-9]+)-[0-9]+'

if [[ -z "$TERMINUS_SITE" || -z "$PREFIX" ]]; then
  echo "::error::TERMINUS_SITE and PREFIX (arg 1) must be set."
  exit 1
fi

NOW=$(date +%s)
CUTOFF=$((NOW - MAX_AGE_HOURS * 3600))

# JSON output includes creation timestamps needed for age-based cleanup
MULTIDEVS=$(terminus multidev:list "$TERMINUS_SITE" --format=json 2>/dev/null || echo "{}")

for env in $(echo "$MULTIDEVS" | jq -r 'keys[]' 2>/dev/null); do
  # Skip the current run's multidev (protected from both prefix and age-based deletion).
  # On success, the "Delete current multidev" CI step already removed it.
  # On failure, we want it to persist for investigation.
  if [[ "$env" == "$CURRENT_ENV" ]]; then
    continue
  fi

  # Prefix match: delete stale multidevs from same matrix combo regardless of age
  if [[ "$env" == ${PREFIX}* ]]; then
    echo "Deleting stale multidev (prefix match): $env"
    terminus multidev:delete "$TERMINUS_SITE.$env" --delete-branch --yes || true
    continue
  fi

  # Timestamp check: delete old CI-pattern multidevs from any matrix combo
  if [[ "$env" =~ $CI_PATTERN ]]; then
    CREATED=$(echo "$MULTIDEVS" | jq -r --arg e "$env" '.[$e].created // 0' 2>/dev/null)
    if [[ "$CREATED" -gt 0 && "$CREATED" -lt "$CUTOFF" ]]; then
      echo "Deleting old CI multidev (age > ${MAX_AGE_HOURS}h): $env"
      terminus multidev:delete "$TERMINUS_SITE.$env" --delete-branch --yes || true
    fi
  fi
done
