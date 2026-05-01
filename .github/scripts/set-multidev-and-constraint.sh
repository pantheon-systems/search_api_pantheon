#!/bin/bash
set -eo pipefail

# Set multidev environment variables and composer constraint for CI.
# Reads from job env: DRUPAL_VERSION, PHP_VERSION, SOLR_VERSION, GITHUB_RUN_NUMBER
# Reads from step env: GIT_REF, GIT_REF_TYPE
# Writes to $GITHUB_ENV: MULTIDEV_NAME, MULTIDEV_PREFIX, GIT_CONSTRAINT

if [[ -z "$DRUPAL_VERSION" || -z "$PHP_VERSION" || -z "$GITHUB_RUN_NUMBER" ]]; then
  echo "::error::DRUPAL_VERSION, PHP_VERSION, and GITHUB_RUN_NUMBER must be set."
  exit 1
fi

# Multidev name: d{drupal}p{php}s{solr}-{run} e.g. d10p81s8-42 (max 11 chars, truncated in create-multidev.sh)
PHP_SHORT=$(echo "$PHP_VERSION" | tr -d '.')
echo "MULTIDEV_NAME=d${DRUPAL_VERSION}p${PHP_SHORT}s${SOLR_VERSION}-${GITHUB_RUN_NUMBER}" >> "$GITHUB_ENV"

# Prefix used by cleanup script to find stale multidevs from prior runs
echo "MULTIDEV_PREFIX=d${DRUPAL_VERSION}p${PHP_SHORT}s${SOLR_VERSION}-" >> "$GITHUB_ENV"

# Convert git ref to a composer version constraint:
#   Tags (e.g. 8.5.0-beta1)             -> use as-is: 8.5.0-beta1
#   Version-like branches (e.g. 8.5.x)  -> branch-dev format: 8.5.x-dev
#   Other branches (e.g. my-feature)     -> dev-branch format: dev-my-feature
REF="${GIT_REF:?GIT_REF must be set}"
if [ "${GIT_REF_TYPE}" = "tag" ]; then
  echo "GIT_CONSTRAINT=${REF}" >> "$GITHUB_ENV"
elif echo "$REF" | grep -qE '^v?[0-9]+(\.[0-9x]+)*$'; then
  echo "GIT_CONSTRAINT=${REF}-dev" >> "$GITHUB_ENV"
else
  echo "GIT_CONSTRAINT=dev-${REF}" >> "$GITHUB_ENV"
fi
