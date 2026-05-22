#!/bin/bash
set -eo pipefail

# Field mapping integration test for CI.
# Validates that all Drupal field types correctly map to Solr field types.
# Expects the module is already installed and enabled (by create-multidev.sh).
# Expects field_test content type, fields, and test node to be pre-baked on
# the dev environment (inherited by the multidev on creation).
# Reads TERMINUS_SITE, MULTIDEV_ENV, and SOLR_VERSION from environment.

if [[ -z "$TERMINUS_SITE" || -z "$MULTIDEV_ENV" ]]; then
  echo "::error::TERMINUS_SITE and MULTIDEV_ENV must be set."
  exit 1
fi

SITE_ENV="${TERMINUS_SITE}.${MULTIDEV_ENV}"
FAILED=0

# Verify pre-baked content type and fields exist (inherited from dev environment)
echo "::group::Verify field_test content type and fields"
terminus drush "$SITE_ENV" -- ev "
  \$type = \Drupal::entityTypeManager()->getStorage('node_type')->load('field_test');
  if (!\$type) { echo 'MISSING: field_test content type' . PHP_EOL; exit(1); }
  echo 'field_test content type: OK' . PHP_EOL;
  \$fields = ['field_text_plain','field_text_long','field_integer','field_boolean','field_email','field_link','field_decimal','field_date','field_datetime','field_list_text'];
  foreach (\$fields as \$f) {
    \$storage = \Drupal\field\Entity\FieldStorageConfig::loadByName('node', \$f);
    if (!\$storage) { echo 'MISSING: ' . \$f . PHP_EOL; exit(1); }
    echo \$f . ': OK' . PHP_EOL;
  }
"
echo "::endgroup::"

# Configure Search API index
echo "::group::Configure Search API index"
terminus drush "$SITE_ENV" -- ev "
  \$index = \Drupal::entityTypeManager()->getStorage('search_api_index')->load('primary');
  if (!\$index) { echo 'ERROR: primary index not found' . PHP_EOL; exit(1); }
  \$index->set('datasource_settings', [
    'entity:node' => ['bundles' => ['default' => false, 'selected' => ['field_test']], 'languages' => ['default' => true, 'selected' => []]],
  ]);
  \$index->set('field_settings', [
    'title' => ['label' => 'Title', 'datasource_id' => 'entity:node', 'property_path' => 'title', 'type' => 'text'],
    'field_text_plain' => ['label' => 'Text Plain', 'datasource_id' => 'entity:node', 'property_path' => 'field_text_plain', 'type' => 'text'],
    'field_text_long' => ['label' => 'Text Long', 'datasource_id' => 'entity:node', 'property_path' => 'field_text_long', 'type' => 'text'],
    'field_integer' => ['label' => 'Integer', 'datasource_id' => 'entity:node', 'property_path' => 'field_integer', 'type' => 'integer'],
    'field_decimal' => ['label' => 'Decimal', 'datasource_id' => 'entity:node', 'property_path' => 'field_decimal', 'type' => 'decimal'],
    'field_boolean' => ['label' => 'Boolean', 'datasource_id' => 'entity:node', 'property_path' => 'field_boolean', 'type' => 'boolean'],
    'field_date' => ['label' => 'Date', 'datasource_id' => 'entity:node', 'property_path' => 'field_date', 'type' => 'date'],
    'field_datetime' => ['label' => 'Datetime', 'datasource_id' => 'entity:node', 'property_path' => 'field_datetime', 'type' => 'date'],
    'field_list_text' => ['label' => 'List Text', 'datasource_id' => 'entity:node', 'property_path' => 'field_list_text', 'type' => 'string'],
    'field_email' => ['label' => 'Email', 'datasource_id' => 'entity:node', 'property_path' => 'field_email', 'type' => 'string'],
    'field_link' => ['label' => 'Link', 'datasource_id' => 'entity:node', 'property_path' => 'field_link:uri', 'type' => 'string'],
  ]);
  \$index->setStatus(TRUE);
  \$index->save();
  echo 'Index configured' . PHP_EOL;
"
terminus drush "$SITE_ENV" -- config:set search_api.server.pantheon_search backend_config.connector_config.http_method POST -y
echo "::endgroup::"

# Post schema (retry on transient 502s)
echo "::group::Post Solr schema"
MAX_RETRIES=3
RETRY_DELAY=60
for attempt in $(seq 1 $MAX_RETRIES); do
  echo "Schema post attempt $attempt of $MAX_RETRIES"
  if terminus drush "$SITE_ENV" -- search-api-pantheon:postSchema; then
    echo "::notice::Schema post completed (attempt $attempt)"
    break
  fi
  if [ "$attempt" -eq "$MAX_RETRIES" ]; then
    echo "::error::Schema post failed after $MAX_RETRIES attempts"
    exit 1
  fi
  echo "::warning::Schema post failed (attempt $attempt), retrying in ${RETRY_DELAY}s..."
  sleep $RETRY_DELAY
done
echo "::endgroup::"

# Verify test node exists (inherited from dev environment) and index it
echo "::group::Verify test node and index"
NODE_COUNT=$(terminus drush "$SITE_ENV" -- ev "
  \$query = \Drupal::entityQuery('node')->condition('type', 'field_test')->condition('title', 'Field Mapping Test Node')->accessCheck(FALSE)->count();
  echo \$query->execute();
" 2>/dev/null | grep -v notice | grep -v WARNING | tr -d '[:space:]')
if [ "$NODE_COUNT" = "0" ] || [ -z "$NODE_COUNT" ]; then
  echo "::error::No field_test node found — dev environment may not be set up correctly"
  exit 1
fi
echo "::notice::Found $NODE_COUNT field_test node(s)"
terminus drush "$SITE_ENV" -- search-api:index primary
sleep 5
echo "::endgroup::"

# Query Solr and verify field mappings
echo "::group::Verify field mappings"
SOLR_RESPONSE=$(terminus drush "$SITE_ENV" -- search-api-pantheon:select "*:*" --defType="" --rows=1 2>/dev/null | grep -v "notice" | grep -v "WARNING" | jq -c '.')
DOC=$(echo "$SOLR_RESPONSE" | jq -r '.response.docs[0]')

if [ "$DOC" = "null" ] || [ -z "$DOC" ]; then
  echo "::error::No documents found in Solr index"
  exit 1
fi

check_field() {
  local name="$1" expected="$2" actual="$3"
  if [ "$expected" = "$actual" ]; then
    echo "::notice::PASS: $name"
  else
    echo "::error::FAIL: $name (expected: $expected, got: $actual)"
    FAILED=1
  fi
}

check_contains() {
  local name="$1" substring="$2" actual="$3"
  if echo "$actual" | grep -q "$substring"; then
    echo "::notice::PASS: $name"
  else
    echo "::error::FAIL: $name (expected to contain: $substring, got: $actual)"
    FAILED=1
  fi
}

check_field "Title" "Field Mapping Test Node" "$(echo "$DOC" | jq -r '.tm_X3b_en_title[0] // empty')"
check_field "Text plain" "Plain text value" "$(echo "$DOC" | jq -r '.tm_X3b_en_field_text_plain[0] // empty')"
check_contains "Text long" "special chars" "$(echo "$DOC" | jq -r '.tm_X3b_en_field_text_long[0] // empty')"
check_field "Integer" "42" "$(echo "$DOC" | jq -r '.its_field_integer // empty')"
check_contains "Decimal" "3.14" "$(echo "$DOC" | jq -r '.fs_field_decimal // .fts_field_decimal // empty')"
check_field "Boolean" "true" "$(echo "$DOC" | jq -r '.bs_field_boolean // empty')"
check_contains "Date" "2026-01-22" "$(echo "$DOC" | jq -r '.ds_field_date // empty')"
check_contains "Datetime" "2026-01-22T10:30" "$(echo "$DOC" | jq -r '.ds_field_datetime // empty')"
check_field "List text" "option2" "$(echo "$DOC" | jq -r '.ss_field_list_text // empty')"
check_field "Email" "test@example.com" "$(echo "$DOC" | jq -r '.ss_field_email // empty')"
check_field "Link" "https://example.com" "$(echo "$DOC" | jq -r '.ss_field_link // empty')"
echo "::endgroup::"

if [ "$FAILED" -ne 0 ]; then
  echo "::error::One or more field mapping tests failed"
  exit 1
fi

echo "All field mapping tests passed"
