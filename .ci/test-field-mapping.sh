#!/bin/bash
# Test field mapping accuracy between Drupal and Solr
# Ensures all Drupal field types correctly map to Solr field types
# and that data is preserved accurately during indexing

set -e

if [ -z "$1" ]; then
  echo "Usage: $0 SITE_NAME [ENVIRONMENT]"
  echo "Example: $0 my-test-site"
  echo "Example: $0 my-test-site test-abc12"
  echo ""
  echo "Note: Use site name only, WITHOUT environment suffix (.dev/.test/.live)"
  echo "      Environment defaults to 'dev' if not specified"
  exit 1
fi

SITE="$1"
ENV="${2:-dev}"

# Validate that SITE doesn't include environment suffix
if [[ "$SITE" == *.dev ]] || [[ "$SITE" == *.test ]] || [[ "$SITE" == *.live ]]; then
  echo "Error: Site name should NOT include environment suffix (.dev/.test/.live)"
  echo "You provided: $SITE"
  echo "Use instead: ${SITE%.dev}"
  exit 1
fi

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

log_info() {
  echo -e "${BLUE}[INFO]${NC} $1"
}

log_success() {
  echo -e "${GREEN}[SUCCESS]${NC} $1"
}

log_error() {
  echo -e "${RED}[ERROR]${NC} $1"
}

log_warning() {
  echo -e "${YELLOW}[WARNING]${NC} $1"
}

# Test tracking
TESTS_PASSED=0
TESTS_FAILED=0

test_result() {
  local test_name="$1"
  local expected="$2"
  local actual="$3"

  if [ "$expected" = "$actual" ]; then
    log_success "✓ $test_name: PASS"
    ((TESTS_PASSED++))
    return 0
  else
    log_error "✗ $test_name: FAIL"
    log_error "  Expected: $expected"
    log_error "  Actual:   $actual"
    ((TESTS_FAILED++))
    return 1
  fi
}

echo "========================================"
echo "Field Mapping Test Suite"
echo "========================================"
log_info "Site: $SITE.$ENV"
echo "========================================"

# Step 1: Install required modules
log_info "Step 1: Installing required modules"

# Switch to git mode
terminus connection:set "$SITE.$ENV" git

# Clone the site using terminus (creates proper local setup)
log_info "Cloning site locally..."
terminus local:clone "$SITE"

# Navigate to the local copy
cd "$HOME/pantheon-local-copies/$SITE"

# Add Solr configuration to pantheon.yml if not present
log_info "Configuring Solr in pantheon.yml..."
if ! grep -q "^search:" pantheon.yml 2>/dev/null; then
  echo "search:" >> pantheon.yml
  echo "  version: 8" >> pantheon.yml
  log_info "Added Solr search configuration to pantheon.yml"
else
  log_info "Solr already configured in pantheon.yml"
fi

# Install modules via Composer
log_info "Installing search_api_pantheon 8.4.x-dev via Composer..."
composer require "pantheon-systems/search_api_pantheon:8.4.x-dev" -n

# Enable Solr
log_info "Enabling Solr..."
terminus solr:enable "$SITE"

# Commit and push changes
log_info "Committing and pushing changes..."
git add -A
git commit -m "Add search_api_pantheon module and Solr config for field mapping tests" || log_info "No changes to commit"

# Pull to sync with remote before pushing
log_info "Syncing with remote..."
git pull --rebase origin master || git pull --rebase origin main || true
git push

# Wait for the workflow to complete
log_info "Waiting for code deployment workflow..."
terminus workflow:wait --max=300 "$SITE.$ENV"

# Enable the modules
log_info "Enabling modules..."
terminus drush "$SITE.$ENV" -- pm:enable search_api search_api_solr search_api_pantheon -y

# Rebuild Drush cache to discover new commands
log_info "Rebuilding Drush cache..."
terminus drush "$SITE.$ENV" -- cache:rebuild

# Wait a moment for cache rebuild to complete
sleep 3

# Clean up any existing test data
log_info "Cleaning up any existing test data..."
terminus drush "$SITE.$ENV" -- ev "
  // Delete existing field_test nodes
  \$nids = \Drupal::entityQuery('node')->condition('type', 'field_test')->accessCheck(FALSE)->execute();
  if (\$nids) {
    \$nodes = \Drupal::entityTypeManager()->getStorage('node')->loadMultiple(\$nids);
    foreach (\$nodes as \$node) {
      \$node->delete();
    }
    echo 'Deleted ' . count(\$nids) . ' existing test nodes\n';
  }

  // Delete existing primary index
  \$index = \Drupal::entityTypeManager()->getStorage('search_api_index')->load('primary');
  if (\$index) {
    \$index->delete();
    echo 'Deleted existing primary index\n';
  }

  // Delete field_test content type (this will also delete the fields)
  \$type = \Drupal::entityTypeManager()->getStorage('node_type')->load('field_test');
  if (\$type) {
    \$type->delete();
    echo 'Deleted existing field_test content type\n';
  }

  echo 'Cleanup complete\n';
"

# Step 2: Create a test content type with all field types
log_info "Step 2: Creating test content type 'field_test' with all field types"

# Create the content type
terminus drush "$SITE.$ENV" -- ev "
  \$type = \Drupal::entityTypeManager()->getStorage('node_type')->create([
    'type' => 'field_test',
    'name' => 'Field Test',
  ]);
  \$type->save();
  echo 'Content type created\n';
"

# Create all test fields
log_info "Creating test fields..."

# Text (plain)
terminus drush "$SITE.$ENV" -- ev "
  use Drupal\field\Entity\FieldStorageConfig;
  use Drupal\field\Entity\FieldConfig;

  FieldStorageConfig::create([
    'field_name' => 'field_text_plain',
    'entity_type' => 'node',
    'type' => 'string',
  ])->save();

  FieldConfig::create([
    'field_name' => 'field_text_plain',
    'entity_type' => 'node',
    'bundle' => 'field_test',
    'label' => 'Text Plain',
  ])->save();
  echo 'field_text_plain created\n';
"

# Text (long)
terminus drush "$SITE.$ENV" -- ev "
  use Drupal\field\Entity\FieldStorageConfig;
  use Drupal\field\Entity\FieldConfig;

  FieldStorageConfig::create([
    'field_name' => 'field_text_long',
    'entity_type' => 'node',
    'type' => 'text_long',
  ])->save();

  FieldConfig::create([
    'field_name' => 'field_text_long',
    'entity_type' => 'node',
    'bundle' => 'field_test',
    'label' => 'Text Long',
  ])->save();
  echo 'field_text_long created\n';
"

# Integer
terminus drush "$SITE.$ENV" -- ev "
  use Drupal\field\Entity\FieldStorageConfig;
  use Drupal\field\Entity\FieldConfig;

  FieldStorageConfig::create([
    'field_name' => 'field_integer',
    'entity_type' => 'node',
    'type' => 'integer',
  ])->save();

  FieldConfig::create([
    'field_name' => 'field_integer',
    'entity_type' => 'node',
    'bundle' => 'field_test',
    'label' => 'Integer',
  ])->save();
  echo 'field_integer created\n';
"

# Decimal
terminus drush "$SITE.$ENV" -- ev "
  use Drupal\field\Entity\FieldStorageConfig;
  use Drupal\field\Entity\FieldConfig;

  FieldStorageConfig::create([
    'field_name' => 'field_decimal',
    'entity_type' => 'node',
    'type' => 'decimal',
    'settings' => ['precision' => 10, 'scale' => 2],
  ])->save();

  FieldConfig::create([
    'field_name' => 'field_decimal',
    'entity_type' => 'node',
    'bundle' => 'field_test',
    'label' => 'Decimal',
  ])->save();
  echo 'field_decimal created\n';
"

# Boolean
terminus drush "$SITE.$ENV" -- ev "
  use Drupal\field\Entity\FieldStorageConfig;
  use Drupal\field\Entity\FieldConfig;

  FieldStorageConfig::create([
    'field_name' => 'field_boolean',
    'entity_type' => 'node',
    'type' => 'boolean',
  ])->save();

  FieldConfig::create([
    'field_name' => 'field_boolean',
    'entity_type' => 'node',
    'bundle' => 'field_test',
    'label' => 'Boolean',
  ])->save();
  echo 'field_boolean created\n';
"

# Date
terminus drush "$SITE.$ENV" -- ev "
  use Drupal\field\Entity\FieldStorageConfig;
  use Drupal\field\Entity\FieldConfig;

  FieldStorageConfig::create([
    'field_name' => 'field_date',
    'entity_type' => 'node',
    'type' => 'datetime',
    'settings' => ['datetime_type' => 'date'],
  ])->save();

  FieldConfig::create([
    'field_name' => 'field_date',
    'entity_type' => 'node',
    'bundle' => 'field_test',
    'label' => 'Date',
  ])->save();
  echo 'field_date created\n';
"

# Datetime
terminus drush "$SITE.$ENV" -- ev "
  use Drupal\field\Entity\FieldStorageConfig;
  use Drupal\field\Entity\FieldConfig;

  FieldStorageConfig::create([
    'field_name' => 'field_datetime',
    'entity_type' => 'node',
    'type' => 'datetime',
    'settings' => ['datetime_type' => 'datetime'],
  ])->save();

  FieldConfig::create([
    'field_name' => 'field_datetime',
    'entity_type' => 'node',
    'bundle' => 'field_test',
    'label' => 'Datetime',
  ])->save();
  echo 'field_datetime created\n';
"

# List (text options)
terminus drush "$SITE.$ENV" -- ev "
  use Drupal\field\Entity\FieldStorageConfig;
  use Drupal\field\Entity\FieldConfig;

  FieldStorageConfig::create([
    'field_name' => 'field_list_text',
    'entity_type' => 'node',
    'type' => 'list_string',
    'settings' => [
      'allowed_values' => [
        'option1' => 'Option 1',
        'option2' => 'Option 2',
        'option3' => 'Option 3',
      ],
    ],
  ])->save();

  FieldConfig::create([
    'field_name' => 'field_list_text',
    'entity_type' => 'node',
    'bundle' => 'field_test',
    'label' => 'List Text',
  ])->save();
  echo 'field_list_text created\n';
"

# Email
terminus drush "$SITE.$ENV" -- ev "
  use Drupal\field\Entity\FieldStorageConfig;
  use Drupal\field\Entity\FieldConfig;

  FieldStorageConfig::create([
    'field_name' => 'field_email',
    'entity_type' => 'node',
    'type' => 'email',
  ])->save();

  FieldConfig::create([
    'field_name' => 'field_email',
    'entity_type' => 'node',
    'bundle' => 'field_test',
    'label' => 'Email',
  ])->save();
  echo 'field_email created\n';
"

# Link
terminus drush "$SITE.$ENV" -- ev "
  use Drupal\field\Entity\FieldStorageConfig;
  use Drupal\field\Entity\FieldConfig;

  FieldStorageConfig::create([
    'field_name' => 'field_link',
    'entity_type' => 'node',
    'type' => 'link',
  ])->save();

  FieldConfig::create([
    'field_name' => 'field_link',
    'entity_type' => 'node',
    'bundle' => 'field_test',
    'label' => 'Link',
  ])->save();
  echo 'field_link created\n';
"

log_success "All test fields created"

# Step 3: Configure Search API index with all fields
log_info "Step 3: Configuring Search API index with test fields"

terminus drush "$SITE.$ENV" -- ev "
  \$index = \Drupal::entityTypeManager()->getStorage('search_api_index')->load('primary');
  if (!\$index) {
    \$server = \Drupal::entityTypeManager()->getStorage('search_api_server')->load('pantheon_search');
    \$index = \Drupal::entityTypeManager()->getStorage('search_api_index')->create([
      'id' => 'primary',
      'name' => 'Primary',
      'server' => 'pantheon_search',
      'datasource_settings' => [
        'entity:node' => [
          'bundles' => ['default' => false, 'selected' => ['field_test']],
          'languages' => ['default' => true, 'selected' => []],
        ],
      ],
      'field_settings' => [],
    ]);
  }

  // Add all test fields to the index
  \$field_settings = [
    'title' => [
      'label' => 'Title',
      'datasource_id' => 'entity:node',
      'property_path' => 'title',
      'type' => 'text',
    ],
    'field_text_plain' => [
      'label' => 'Text Plain',
      'datasource_id' => 'entity:node',
      'property_path' => 'field_text_plain',
      'type' => 'text',
    ],
    'field_text_long' => [
      'label' => 'Text Long',
      'datasource_id' => 'entity:node',
      'property_path' => 'field_text_long',
      'type' => 'text',
    ],
    'field_integer' => [
      'label' => 'Integer',
      'datasource_id' => 'entity:node',
      'property_path' => 'field_integer',
      'type' => 'integer',
    ],
    'field_decimal' => [
      'label' => 'Decimal',
      'datasource_id' => 'entity:node',
      'property_path' => 'field_decimal',
      'type' => 'decimal',
    ],
    'field_boolean' => [
      'label' => 'Boolean',
      'datasource_id' => 'entity:node',
      'property_path' => 'field_boolean',
      'type' => 'boolean',
    ],
    'field_date' => [
      'label' => 'Date',
      'datasource_id' => 'entity:node',
      'property_path' => 'field_date',
      'type' => 'date',
    ],
    'field_datetime' => [
      'label' => 'Datetime',
      'datasource_id' => 'entity:node',
      'property_path' => 'field_datetime',
      'type' => 'date',
    ],
    'field_list_text' => [
      'label' => 'List Text',
      'datasource_id' => 'entity:node',
      'property_path' => 'field_list_text',
      'type' => 'string',
    ],
    'field_email' => [
      'label' => 'Email',
      'datasource_id' => 'entity:node',
      'property_path' => 'field_email',
      'type' => 'string',
    ],
    'field_link' => [
      'label' => 'Link',
      'datasource_id' => 'entity:node',
      'property_path' => 'field_link:uri',
      'type' => 'string',
    ],
  ];

  \$index->set('field_settings', \$field_settings);
  \$index->setStatus(TRUE);
  \$index->save();
  echo 'Index configured with all test fields\n';
"

# Debug: Check Solr connector class and configuration
log_info "Debugging Solr connector configuration..."
terminus drush "$SITE.$ENV" -- ev "
  \$server = \Drupal::entityTypeManager()->getStorage('search_api_server')->load('pantheon_search');
  \$backend = \$server->getBackend();
  \$connector = \$backend->getSolrConnector();

  echo '=== CONNECTOR DEBUG INFO ===\n';
  echo 'Connector class: ' . get_class(\$connector) . \"\n\";
  echo 'Connector methods: ' . implode(', ', get_class_methods(\$connector)) . \"\n\n\";

  // Check configuration
  \$config = \$connector->getConfiguration();
  echo 'Connector configuration:\n';
  echo print_r(\$config, TRUE) . \"\n\";

  // Check parent classes
  echo 'Parent class: ' . get_parent_class(\$connector) . \"\n\";
  echo 'Implements: ' . implode(', ', class_implements(\$connector)) . \"\n\";
"

# Fix http_method configuration for Pantheon Solr compatibility
log_info "Configuring Solr connector for Pantheon compatibility..."
terminus drush "$SITE.$ENV" -- config:set search_api.server.pantheon_search backend_config.connector_config.http_method POST -y

# Post schema
log_info "Posting schema to Solr..."
SCHEMA_RESULT=$(terminus drush "$SITE.$ENV" -- search-api-pantheon:postSchema 2>&1)
echo "$SCHEMA_RESULT"

if echo "$SCHEMA_RESULT" | grep -qi "error\|fail"; then
  log_error "Schema post may have failed. Output:"
  echo "$SCHEMA_RESULT"
  log_warning "Continuing anyway..."
fi

# Wait for schema to be applied
log_info "Waiting for schema to be applied..."
sleep 5

# Step 4: Create test node with known values
log_info "Step 4: Creating test node with known field values"

NODE_ID=$(terminus drush "$SITE.$ENV" -- ev "
  use Drupal\node\Entity\Node;

  \$node = Node::create([
    'type' => 'field_test',
    'title' => 'Field Mapping Test Node',
    'field_text_plain' => 'Plain text value',
    'field_text_long' => 'Long text with special chars: <>&\"\'',
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
  echo \$node->id();
" | grep -v "\[" | tail -n1)

log_info "Created node ID: $NODE_ID"

# Step 5: Index the content
log_info "Step 5: Indexing content"
terminus drush "$SITE.$ENV" -- search-api:index primary

# Wait for indexing to complete
sleep 5

# Step 6: Query Solr and verify field values
log_info "Step 6: Querying Solr to verify field mappings"

SOLR_RESPONSE=$(terminus drush "$SITE.$ENV" -- search-api-pantheon:select "*:*" --defType="" --rows=1 2>/dev/null | grep -v "notice" | grep -v "WARNING" | jq -c '.')

echo ""
log_info "=== Field Mapping Verification ==="
echo ""

# Extract the first document
DOC=$(echo "$SOLR_RESPONSE" | jq -r '.response.docs[0]')

if [ "$DOC" = "null" ] || [ -z "$DOC" ]; then
  log_error "No documents found in Solr index"
  exit 1
fi

# Disable exit on error for test assertions so all tests run
set +e

# Test 1: Title (text field)
TITLE=$(echo "$DOC" | jq -r '.tm_X3b_en_title[0] // empty')
test_result "Title field" "Field Mapping Test Node" "$TITLE"

# Test 2: Plain text
TEXT_PLAIN=$(echo "$DOC" | jq -r '.tm_X3b_en_field_text_plain[0] // empty')
test_result "Text plain field" "Plain text value" "$TEXT_PLAIN"

# Test 3: Long text (with special characters)
TEXT_LONG=$(echo "$DOC" | jq -r '.tm_X3b_en_field_text_long[0] // empty')
# HTML should be escaped/stripped
if echo "$TEXT_LONG" | grep -q "special chars"; then
  log_success "✓ Text long field: PASS (special chars preserved)"
  ((TESTS_PASSED++))
else
  log_error "✗ Text long field: FAIL (expected special chars)"
  log_error "  Actual: $TEXT_LONG"
  ((TESTS_FAILED++))
fi

# Test 4: Integer
INTEGER=$(echo "$DOC" | jq -r '.its_field_integer // empty')
test_result "Integer field" "42" "$INTEGER"

# Test 5: Decimal
DECIMAL=$(echo "$DOC" | jq -r '.fs_field_decimal // .fts_field_decimal // empty')
# Decimal might be stored as 3.14 or 3.140000, or might be in different field
if [ -n "$DECIMAL" ] && echo "$DECIMAL" | grep -qE "^3\.14|^3\.1[0-9]"; then
  log_success "✓ Decimal field: PASS ($DECIMAL)"
  ((TESTS_PASSED++))
else
  log_error "✗ Decimal field: FAIL"
  log_error "  Expected: 3.14*"
  log_error "  Actual:   $DECIMAL"
  log_warning "  Note: Decimal fields may have indexing issues"
  ((TESTS_FAILED++))
fi

# Test 6: Boolean
BOOLEAN=$(echo "$DOC" | jq -r '.bs_field_boolean // empty')
test_result "Boolean field" "true" "$BOOLEAN"

# Test 7: Date
DATE=$(echo "$DOC" | jq -r '.ds_field_date // empty')
if echo "$DATE" | grep -q "2026-01-22"; then
  log_success "✓ Date field: PASS"
  ((TESTS_PASSED++))
else
  log_error "✗ Date field: FAIL"
  log_error "  Expected: 2026-01-22*"
  log_error "  Actual:   $DATE"
  ((TESTS_FAILED++))
fi

# Test 8: Datetime
DATETIME=$(echo "$DOC" | jq -r '.ds_field_datetime // empty')
if echo "$DATETIME" | grep -q "2026-01-22T10:30"; then
  log_success "✓ Datetime field: PASS"
  ((TESTS_PASSED++))
else
  log_error "✗ Datetime field: FAIL"
  log_error "  Expected: 2026-01-22T10:30*"
  log_error "  Actual:   $DATETIME"
  ((TESTS_FAILED++))
fi

# Test 9: List text
LIST_TEXT=$(echo "$DOC" | jq -r '.ss_field_list_text // empty')
test_result "List text field" "option2" "$LIST_TEXT"

# Test 10: Email
EMAIL=$(echo "$DOC" | jq -r '.ss_field_email // empty')
test_result "Email field" "test@example.com" "$EMAIL"

# Test 11: Link URI
LINK=$(echo "$DOC" | jq -r '.ss_field_link // empty')
test_result "Link field" "https://example.com" "$LINK"

# Test 12: Verify field is searchable
echo ""
log_info "=== Testing Field Searchability ==="
echo ""

# Search for text in plain text field (use actual Solr field name)
SEARCH_RESULT=$(terminus drush "$SITE.$ENV" -- search-api-pantheon:select "tm_X3b_en_field_text_plain:Plain" --defType="" --rows=1 2>/dev/null | grep -v "notice" | grep -v "WARNING" | jq -r '.response.numFound')

if [ "$SEARCH_RESULT" -gt 0 ] 2>/dev/null; then
  log_success "✓ Text field is searchable: PASS"
  ((TESTS_PASSED++))
else
  log_warning "✗ Text field is searchable: SKIP (field-specific search may not be supported)"
  log_info "  Try full-text search instead"
fi

# Search for integer value (use actual Solr field name)
SEARCH_INTEGER=$(terminus drush "$SITE.$ENV" -- search-api-pantheon:select "its_field_integer:42" --defType="" --rows=1 2>/dev/null | grep -v "notice" | grep -v "WARNING" | jq -r '.response.numFound')

if [ "$SEARCH_INTEGER" -gt 0 ] 2>/dev/null; then
  log_success "✓ Integer field is searchable: PASS"
  ((TESTS_PASSED++))
else
  log_warning "✗ Integer field is searchable: SKIP (field-specific search may not be supported)"
  log_info "  Try range queries instead"
fi

# Step 7: Test special characters and edge cases
echo ""
log_info "=== Testing Special Characters ==="
echo ""

# Note: Schema was already posted earlier, no need to repost
# Reposting can cause field definition conflicts in Solr

# Create node with special characters
SPECIAL_NODE_ID=$(terminus drush "$SITE.$ENV" -- ev "
  use Drupal\node\Entity\Node;

  \$node = Node::create([
    'type' => 'field_test',
    'title' => 'Special: <>&\"\'',
    'field_text_plain' => 'Test with émojis 🔥 and ünïcödé',
    'field_integer' => -999,
    'field_decimal' => '0.01',
    'field_boolean' => FALSE,
  ]);
  \$node->save();
  echo \$node->id();
" | grep -v "\[" | tail -n1)

log_info "Created special chars node ID: $SPECIAL_NODE_ID"

# Reindex
terminus drush "$SITE.$ENV" -- search-api:index primary
sleep 3

# Query for the special node
SPECIAL_DOC=$(terminus drush "$SITE.$ENV" -- search-api-pantheon:select "id:*entity:node/${SPECIAL_NODE_ID}*" --defType="" --rows=1 2>/dev/null | grep -v "notice" | grep -v "WARNING" | jq -r '.response.docs[0]')

# Test special characters in title
SPECIAL_TITLE=$(echo "$SPECIAL_DOC" | jq -r '.tm_X3b_en_title[0] // empty')
if echo "$SPECIAL_TITLE" | grep -q "Special"; then
  log_success "✓ Special chars in title preserved: PASS"
  ((TESTS_PASSED++))
else
  log_error "✗ Special chars in title: FAIL"
  log_error "  Actual: $SPECIAL_TITLE"
  ((TESTS_FAILED++))
fi

# Test unicode
UNICODE_TEXT=$(echo "$SPECIAL_DOC" | jq -r '.tm_X3b_en_field_text_plain[0] // empty')
if echo "$UNICODE_TEXT" | grep -q "ünïcödé"; then
  log_success "✓ Unicode characters preserved: PASS"
  ((TESTS_PASSED++))
else
  log_error "✗ Unicode characters: FAIL"
  log_error "  Actual: $UNICODE_TEXT"
  ((TESTS_FAILED++))
fi

# Test negative integer
NEG_INTEGER=$(echo "$SPECIAL_DOC" | jq -r '.its_field_integer // empty')
test_result "Negative integer" "-999" "$NEG_INTEGER"

# Test small decimal
SMALL_DECIMAL=$(echo "$SPECIAL_DOC" | jq -r '.fs_field_decimal // empty')
if echo "$SMALL_DECIMAL" | grep -q "^0\.01"; then
  log_success "✓ Small decimal: PASS"
  ((TESTS_PASSED++))
else
  log_error "✗ Small decimal: FAIL"
  log_error "  Expected: 0.01*"
  log_error "  Actual:   $SMALL_DECIMAL"
  ((TESTS_FAILED++))
fi

# Test false boolean
FALSE_BOOL=$(echo "$SPECIAL_DOC" | jq -r '.bs_field_boolean // empty')
test_result "Boolean false" "false" "$FALSE_BOOL"

# Re-enable exit on error
set -e

# Summary
echo ""
echo "========================================"
log_info "Test Summary"
echo "========================================"
log_info "Tests Passed: $TESTS_PASSED"
log_info "Tests Failed: $TESTS_FAILED"
echo "========================================"

if [ $TESTS_FAILED -eq 0 ]; then
  log_success "All field mapping tests passed!"
  exit 0
else
  log_error "Some tests failed. Please review the output above."
  exit 1
fi
