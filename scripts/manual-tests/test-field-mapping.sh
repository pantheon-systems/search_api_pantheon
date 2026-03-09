#!/bin/bash
# Test field mapping accuracy between Drupal and Solr
# Ensures all Drupal field types correctly map to Solr field types
# and that data is preserved accurately during indexing

set -e

if [ -z "$1" ]; then
  echo "Usage: $0 SITE_NAME [ENVIRONMENT]"
  echo "Example: $0 my-test-site"   # defaults to dev
  echo "Example: $0 my-test-site ci-abc12" # multidev
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
  # Remove any of the three possible suffixes
  SUGGESTED_SITE="${SITE%.dev}"
  SUGGESTED_SITE="${SUGGESTED_SITE%.test}"
  SUGGESTED_SITE="${SUGGESTED_SITE%.live}"
  echo "Use instead: $SUGGESTED_SITE"
  exit 1
fi

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
NC='\033[0m'

log_info() {
  : # Silent - no output
}

log_success() {
  echo -e "${GREEN}$1${NC}"
}

log_error() {
  echo -e "${RED}$1${NC}"
}

# Test tracking
TESTS_PASSED=0
TESTS_FAILED=0

test_result() {
  local test_name="$1"
  local expected="$2"
  local actual="$3"

  if [ "$expected" = "$actual" ]; then
    echo -e "${GREEN}PASS: $test_name${NC}"
    ((TESTS_PASSED++))
    return 0
  else
    echo -e "${RED}FAIL: $test_name (exp: $expected, got: $actual)${NC}"
    ((TESTS_FAILED++))
    return 1
  fi
}

echo "Field Mapping: $SITE.$ENV"

# Step 1: Install required modules
log_info "Step 1: Installing required modules"

# Switch to git mode
terminus connection:set "$SITE.$ENV" git

# Clone the site using terminus (creates proper local setup)
log_info "Cloning site locally..."
terminus local:clone "$SITE"

# Navigate to the local copy
cd "$HOME/pantheon-local-copies/$SITE"

# Checkout the multidev branch
log_info "Checking out multidev branch: $ENV..."
git fetch origin
git checkout "$ENV" || git checkout -b "$ENV" "origin/$ENV"
echo "Current branch: $(git branch --show-current)"

# Add Solr configuration to pantheon.yml
log_info "Configuring Solr in pantheon.yml..."
echo "Before modification:"
cat pantheon.yml

cat >> pantheon.yml <<EOF
search:
  version: 8
EOF

echo "After modification:"
cat pantheon.yml

# Install modules via Composer
log_info "Installing search_api_pantheon 8.4.x-dev via Composer..."
composer require "pantheon-systems/search_api_pantheon:8.4.x-dev" -n

log_info "Committing and pushing changes..."
git add -A
git commit -m "Add search_api_pantheon module and Solr config for field mapping tests" || log_info "No changes to commit"

log_info "Syncing with remote..."
# Detect current branch
CURRENT_BRANCH=$(git rev-parse --abbrev-ref HEAD)
echo "Current branch: $CURRENT_BRANCH"

# Pull latest changes from remote (split remote and branch)
echo "Pulling from origin ${CURRENT_BRANCH}..."
git pull --rebase origin "$CURRENT_BRANCH" || {
  echo "Pull failed, checking status..."
  git status
  exit 1
}

# Push to current branch
echo "Pushing to origin/${CURRENT_BRANCH}..."
git push origin "$CURRENT_BRANCH" || {
  echo "Push failed, remote state:"
  git fetch origin
  git log HEAD..origin/${CURRENT_BRANCH} --oneline || true
  exit 1
}

log_info "Waiting for code deployment workflow..."
terminus workflow:wait --max=60 "$SITE.$ENV"

# Enable Solr AFTER code deployment so pantheon.yml is processed first. Sleep needed to avoid race condition
log_info "Enabling Solr..."
terminus solr:enable "$SITE"
log_info "Waiting for Solr to be provisioned..."
sleep 5

log_info "Enabling modules..."
terminus drush "$SITE.$ENV" -- pm:enable search_api search_api_solr search_api_pantheon -y

# Rebuild Drush cache to discover new commands. After enabling search_api_pantheon module, sometimes cache needs to be rebuilt so commands are available.
log_info "Rebuilding Drush cache..."
terminus drush "$SITE.$ENV" -- cache:rebuild
sleep 3

# Verify search-api-pantheon commands are available
log_info "Verifying Drush commands are available..."
COMMAND_CHECK=$(terminus drush "$SITE.$ENV" -- list 2>&1 | grep -c "search-api-pantheon:" || true)
if [ "$COMMAND_CHECK" -eq 0 ]; then
  log_error "search-api-pantheon Drush commands not found after cache rebuild"
  log_info "Attempting second cache rebuild..."
  terminus drush "$SITE.$ENV" -- cache:rebuild
  sleep 5

  COMMAND_CHECK=$(terminus drush "$SITE.$ENV" -- list 2>&1 | grep -c "search-api-pantheon:" || true)
  if [ "$COMMAND_CHECK" -eq 0 ]; then
    log_error "search-api-pantheon Drush commands still not available. Listing all commands:"
    terminus drush "$SITE.$ENV" -- list | grep -i search
    exit 1
  fi
fi
log_success "Drush commands verified ($COMMAND_CHECK commands found)"

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

log_info "Step 2: Creating test content type 'field_test' with all field types"

terminus drush "$SITE.$ENV" -- ev "
  \$type = \Drupal::entityTypeManager()->getStorage('node_type')->create([
    'type' => 'field_test',
    'name' => 'Field Test',
  ]);
  \$type->save();
  echo 'Content type created\n';
"
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

# Fix http_method configuration for Pantheon Solr compatibility
log_info "Configuring Solr connector for Pantheon compatibility..."
terminus drush "$SITE.$ENV" -- config:set search_api.server.pantheon_search backend_config.connector_config.http_method POST -y

# Post schema
log_info "Posting schema to Solr..."
SCHEMA_RESULT=$(terminus drush "$SITE.$ENV" -- search-api-pantheon:postSchema 2>&1)
echo "$SCHEMA_RESULT"

if echo "$SCHEMA_RESULT" | grep -qi "error\|fail"; then
  log_error "Schema post failed. Cannot continue with field mapping tests."
  echo "$SCHEMA_RESULT"
  exit 1
fi

# Wait for schema to be applied
sleep 5

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

log_info "Step 5: Indexing content"
terminus drush "$SITE.$ENV" -- search-api:index primary
sleep 5

# Step 6: Query Solr and verify field values
log_info "Step 6: Querying Solr to verify field mappings"

SOLR_RESPONSE=$(terminus drush "$SITE.$ENV" -- search-api-pantheon:select "*:*" --defType="" --rows=1 2>/dev/null | grep -v "notice" | grep -v "WARNING" | jq -c '.')

echo ""
echo "Testing fields:"

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
if echo "$TEXT_LONG" | grep -q "special chars"; then
  echo -e "${GREEN}PASS: Text long field${NC}"
  ((TESTS_PASSED++))
else
  echo -e "${RED}FAIL: Text long field (got: $TEXT_LONG)${NC}"
  ((TESTS_FAILED++))
fi

# Test 4: Integer
INTEGER=$(echo "$DOC" | jq -r '.its_field_integer // empty')
test_result "Integer field" "42" "$INTEGER"

# Test 5: Decimal
DECIMAL=$(echo "$DOC" | jq -r '.fs_field_decimal // .fts_field_decimal // empty')
if [ -n "$DECIMAL" ] && echo "$DECIMAL" | grep -qE "^3\.14|^3\.1[0-9]"; then
  echo -e "${GREEN}PASS: Decimal field${NC}"
  ((TESTS_PASSED++))
else
  echo -e "${RED}FAIL: Decimal field (got: $DECIMAL)${NC}"
  ((TESTS_FAILED++))
fi

# Test 6: Boolean
BOOLEAN=$(echo "$DOC" | jq -r '.bs_field_boolean // empty')
test_result "Boolean field" "true" "$BOOLEAN"

# Test 7: Date
DATE=$(echo "$DOC" | jq -r '.ds_field_date // empty')
if echo "$DATE" | grep -q "2026-01-22"; then
  echo -e "${GREEN}PASS: Date field${NC}"
  ((TESTS_PASSED++))
else
  echo -e "${RED}FAIL: Date field (got: $DATE)${NC}"
  ((TESTS_FAILED++))
fi

# Test 8: Datetime
DATETIME=$(echo "$DOC" | jq -r '.ds_field_datetime // empty')
if echo "$DATETIME" | grep -q "2026-01-22T10:30"; then
  echo -e "${GREEN}PASS: Datetime field${NC}"
  ((TESTS_PASSED++))
else
  echo -e "${RED}FAIL: Datetime field (got: $DATETIME)${NC}"
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

# Re-enable exit on error
set -e

# Summary
echo ""
echo "Pass:$TESTS_PASSED Fail:$TESTS_FAILED"

if [ $TESTS_FAILED -eq 0 ]; then
  echo -e "${GREEN}PASS${NC}"
  exit 0
else
  echo -e "${RED}FAIL${NC}"
  exit 1
fi
