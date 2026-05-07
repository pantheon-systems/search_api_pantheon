#!/bin/bash
set -eo pipefail

# Field mapping integration test for CI.
# Validates that all Drupal field types correctly map to Solr field types.
# Expects the module is already installed and enabled (by create-multidev.sh).
# Reads TERMINUS_SITE, MULTIDEV_ENV, and SOLR_VERSION from environment.

if [[ -z "$TERMINUS_SITE" || -z "$MULTIDEV_ENV" ]]; then
  echo "::error::TERMINUS_SITE and MULTIDEV_ENV must be set."
  exit 1
fi

SITE_ENV="${TERMINUS_SITE}.${MULTIDEV_ENV}"
FAILED=0

# Clean up any existing test data from a previous run
echo "::group::Cleanup existing test data"
terminus drush "$SITE_ENV" -- ev "
  \$nids = \Drupal::entityQuery('node')->condition('type', 'field_test')->accessCheck(FALSE)->execute();
  if (\$nids) {
    \$nodes = \Drupal::entityTypeManager()->getStorage('node')->loadMultiple(\$nids);
    foreach (\$nodes as \$node) { \$node->delete(); }
    echo 'Deleted ' . count(\$nids) . ' existing test nodes' . PHP_EOL;
  }
  \$index = \Drupal::entityTypeManager()->getStorage('search_api_index')->load('primary');
  if (\$index) { \$index->delete(); echo 'Deleted existing primary index' . PHP_EOL; }
  \$type = \Drupal::entityTypeManager()->getStorage('node_type')->load('field_test');
  if (\$type) { \$type->delete(); echo 'Deleted existing field_test content type' . PHP_EOL; }
  echo 'Cleanup complete' . PHP_EOL;
"
echo "::endgroup::"

# Create content type
echo "::group::Create field_test content type and fields"
terminus drush "$SITE_ENV" -- ev "
  \$type = \Drupal::entityTypeManager()->getStorage('node_type')->create([
    'type' => 'field_test', 'name' => 'Field Test',
  ]);
  \$type->save();
  echo 'Content type created' . PHP_EOL;
"

# Create all test fields
FIELDS=(
  "field_text_plain:string"
  "field_text_long:text_long"
  "field_integer:integer"
  "field_boolean:boolean"
  "field_email:email"
  "field_link:link"
)

for FIELD_DEF in "${FIELDS[@]}"; do
  FIELD_NAME="${FIELD_DEF%%:*}"
  FIELD_TYPE="${FIELD_DEF##*:}"
  terminus drush "$SITE_ENV" -- ev "
    use Drupal\field\Entity\FieldStorageConfig;
    use Drupal\field\Entity\FieldConfig;
    FieldStorageConfig::create(['field_name' => '$FIELD_NAME', 'entity_type' => 'node', 'type' => '$FIELD_TYPE'])->save();
    FieldConfig::create(['field_name' => '$FIELD_NAME', 'entity_type' => 'node', 'bundle' => 'field_test', 'label' => '$FIELD_NAME'])->save();
    echo '$FIELD_NAME created' . PHP_EOL;
  "
done

# Fields with custom settings
terminus drush "$SITE_ENV" -- ev "
  use Drupal\field\Entity\FieldStorageConfig;
  use Drupal\field\Entity\FieldConfig;
  FieldStorageConfig::create(['field_name' => 'field_decimal', 'entity_type' => 'node', 'type' => 'decimal', 'settings' => ['precision' => 10, 'scale' => 2]])->save();
  FieldConfig::create(['field_name' => 'field_decimal', 'entity_type' => 'node', 'bundle' => 'field_test', 'label' => 'field_decimal'])->save();
  echo 'field_decimal created' . PHP_EOL;
"
terminus drush "$SITE_ENV" -- ev "
  use Drupal\field\Entity\FieldStorageConfig;
  use Drupal\field\Entity\FieldConfig;
  FieldStorageConfig::create(['field_name' => 'field_date', 'entity_type' => 'node', 'type' => 'datetime', 'settings' => ['datetime_type' => 'date']])->save();
  FieldConfig::create(['field_name' => 'field_date', 'entity_type' => 'node', 'bundle' => 'field_test', 'label' => 'field_date'])->save();
  echo 'field_date created' . PHP_EOL;
"
terminus drush "$SITE_ENV" -- ev "
  use Drupal\field\Entity\FieldStorageConfig;
  use Drupal\field\Entity\FieldConfig;
  FieldStorageConfig::create(['field_name' => 'field_datetime', 'entity_type' => 'node', 'type' => 'datetime', 'settings' => ['datetime_type' => 'datetime']])->save();
  FieldConfig::create(['field_name' => 'field_datetime', 'entity_type' => 'node', 'bundle' => 'field_test', 'label' => 'field_datetime'])->save();
  echo 'field_datetime created' . PHP_EOL;
"
terminus drush "$SITE_ENV" -- ev "
  use Drupal\field\Entity\FieldStorageConfig;
  use Drupal\field\Entity\FieldConfig;
  FieldStorageConfig::create(['field_name' => 'field_list_text', 'entity_type' => 'node', 'type' => 'list_string', 'settings' => ['allowed_values' => ['option1' => 'Option 1', 'option2' => 'Option 2', 'option3' => 'Option 3']]])->save();
  FieldConfig::create(['field_name' => 'field_list_text', 'entity_type' => 'node', 'bundle' => 'field_test', 'label' => 'field_list_text'])->save();
  echo 'field_list_text created' . PHP_EOL;
"
echo "::endgroup::"

# Configure Search API index
echo "::group::Configure Search API index"
terminus drush "$SITE_ENV" -- ev "
  \$index = \Drupal::entityTypeManager()->getStorage('search_api_index')->create([
    'id' => 'primary', 'name' => 'Primary', 'server' => 'pantheon_search',
    'datasource_settings' => [
      'entity:node' => ['bundles' => ['default' => false, 'selected' => ['field_test']], 'languages' => ['default' => true, 'selected' => []]],
    ],
    'field_settings' => [
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
    ],
  ]);
  \$index->setStatus(TRUE);
  \$index->save();
  echo 'Index configured' . PHP_EOL;
"
terminus drush "$SITE_ENV" -- config:set search_api.server.pantheon_search backend_config.connector_config.http_method POST -y
echo "::endgroup::"

# Post schema
echo "::group::Post Solr schema"
terminus drush "$SITE_ENV" -- search-api-pantheon:postSchema
echo "::endgroup::"

# Create test node with known values
echo "::group::Create test node and index"
terminus drush "$SITE_ENV" -- ev "
  use Drupal\node\Entity\Node;
  \$node = Node::create([
    'type' => 'field_test',
    'title' => 'Field Mapping Test Node',
    'field_text_plain' => 'Plain text value',
    'field_text_long' => 'Long text with special chars: <>&',
    'field_integer' => 42,
    'field_decimal' => '3.14',
    'field_boolean' => TRUE,
    'field_date' => '2026-01-22',
    'field_datetime' => '2026-01-22T10:30:00',
    'field_list_text' => 'option2',
    'field_email' => 'test@example.com',
    'field_link' => ['uri' => 'https://example.com', 'title' => 'Example'],
  ]);
  \$node->save();
  echo 'Node ' . \$node->id() . ' created' . PHP_EOL;
"
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
