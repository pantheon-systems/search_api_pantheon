#!/bin/bash
set -eo pipefail

if [[ -z "$TERMINUS_SITE" || -z "$MULTIDEV_ENV" ]]; then
  echo "::error::TERMINUS_SITE and MULTIDEV_ENV must be set."
  exit 1
fi

SITE_ENV="${TERMINUS_SITE}.${MULTIDEV_ENV}"
FAILED=0

# Wait for Solr to be reachable (may lag behind code deployment)
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

# Post schema before indexing content (per module docs: schema first, then index)
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

# Generate test content using devel_generate module
echo "::group::Generate test content"
echo "Generating five nodes..."
terminus drush "$SITE_ENV" -- genc 5
echo "::endgroup::"

# Verify Search API tracked the indexed items
echo "::group::Verify Search API index"
INDEXED=$(terminus drush "$SITE_ENV" -- sapi-s primary --fields=total --format=string)
if [ "$INDEXED" != "5" ]; then
  echo "::error::Search API reports $INDEXED indexed documents, expected 5"
  terminus drush "$SITE_ENV" -- sapd
  FAILED=1
else
  echo "::notice::Search API index count: $INDEXED (expected 5)"
fi
echo "::endgroup::"

# Verify documents reached Solr by querying directly
echo "::group::Verify Solr document count"
FOUND=$(terminus drush "$SITE_ENV" -- search-api-pantheon:select '*' | grep -v notice | jq .response.numFound)
if [ "$FOUND" != "5" ]; then
  echo "::error::Solr reports $FOUND documents, expected 5"
  terminus drush "$SITE_ENV" -- sapd
  FAILED=1
else
  echo "::notice::Solr document count: $FOUND (expected 5)"
fi
echo "::endgroup::"

if [ "$FAILED" -ne 0 ]; then
  echo "::error::One or more tests failed"
  exit 1
fi

echo "All tests passed"
