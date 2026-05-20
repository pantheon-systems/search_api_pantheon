#!/bin/bash
set -eo pipefail

# Integration tests for search_api_pantheon on a Pantheon multidev environment.
# Test order follows module docs: schema post first, then generate content, then verify.
# Reads TERMINUS_SITE, MULTIDEV_ENV, and SOLR_VERSION from environment (set by ci.yml).

if [[ -z "$TERMINUS_SITE" || -z "$MULTIDEV_ENV" ]]; then
  echo "::error::TERMINUS_SITE and MULTIDEV_ENV must be set."
  exit 1
fi

SITE_ENV="${TERMINUS_SITE}.${MULTIDEV_ENV}"
FAILED=0

# Solr may not be immediately available after multidev creation/code push.
# Poll until the select query returns results (up to ~2.5 minutes).
echo "::group::Wait for Solr readiness"
for i in $(seq 1 10); do
  if terminus drush "$SITE_ENV" -- search-api-pantheon:select '*' 2>/dev/null | grep -q numFound; then
    echo "Solr ready (attempt $i)"
    break
  fi
  if [ "$i" -eq 10 ]; then
    echo "::error::Solr not ready after 10 attempts"
    exit 1
  fi
  echo "Solr not ready yet, waiting 15s... (attempt $i)"
  sleep 15
done
echo "::endgroup::"

# Post the Solr schema config set. Must happen before content generation,
# otherwise Solr may be busy indexing with the wrong schema.
# Retries handle transient 502s from Solr endpoint availability.
echo "::group::Test schema post"
SOLR_VER="${SOLR_VERSION:-8}"
SCHEMA_PATH="/code/web/modules/contrib/search_api_solr/jump-start/solr${SOLR_VER}/config-set"
MAX_RETRIES=3
RETRY_DELAY=60
for attempt in $(seq 1 $MAX_RETRIES); do
  echo "Schema post attempt $attempt of $MAX_RETRIES"
  if terminus drush "$SITE_ENV" -- search-api-pantheon:postSchema "$SCHEMA_PATH"; then
    echo "::notice::Schema post completed (attempt $attempt)"
    break
  fi
  if [ "$attempt" -eq "$MAX_RETRIES" ]; then
    echo "::error::Schema post failed after $MAX_RETRIES attempts"
    FAILED=1
  else
    echo "::warning::Schema post failed (attempt $attempt), retrying in ${RETRY_DELAY}s..."
    sleep $RETRY_DELAY
  fi
done
echo "::endgroup::"

# Count pre-existing content (inherited from dev environment)
echo "::group::Generate test content"
BEFORE=$(terminus drush "$SITE_ENV" -- sapi-s primary --fields=total --format=string 2>/dev/null || echo "0")
echo "Pre-existing tracked items: $BEFORE"

# Generate test content using devel_generate module (installed by create-multidev.sh)
echo "Generating five nodes..."
terminus drush "$SITE_ENV" -- genc 5
EXPECTED=$((BEFORE + 5))
echo "::endgroup::"

# Verify Drupal's Search API tracked the generated content
echo "::group::Verify Search API index"
INDEXED=$(terminus drush "$SITE_ENV" -- sapi-s primary --fields=total --format=string)
if [ "$INDEXED" != "$EXPECTED" ]; then
  echo "::error::Search API reports $INDEXED indexed documents, expected $EXPECTED ($BEFORE pre-existing + 5 generated)"
  terminus drush "$SITE_ENV" -- sapd
  FAILED=1
else
  echo "::notice::Search API index count: $INDEXED (expected $EXPECTED)"
fi
echo "::endgroup::"

# Verify documents actually reached the Solr backend (not just tracked by Drupal)
echo "::group::Verify Solr document count"
FOUND=$(terminus drush "$SITE_ENV" -- search-api-pantheon:select '*' | grep -v notice | jq .response.numFound)
if [ "$FOUND" != "$EXPECTED" ]; then
  echo "::error::Solr reports $FOUND documents, expected $EXPECTED"
  terminus drush "$SITE_ENV" -- sapd
  FAILED=1
else
  echo "::notice::Solr document count: $FOUND (expected $EXPECTED)"
fi
echo "::endgroup::"

if [ "$FAILED" -ne 0 ]; then
  echo "::error::One or more tests failed"
  exit 1
fi

echo "All tests passed"
