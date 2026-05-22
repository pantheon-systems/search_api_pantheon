#!/bin/bash
set -euo pipefail

# Bootstrap a Search API Pantheon CI fixture site from scratch.
#
# Recreates the full dev environment that CI multidev tests depend on.
# Run this if a fixture site is destroyed, corrupted, or needs to be
# rebuilt from zero. All steps are idempotent (safe to re-run).
#
# What it sets up:
#   1. Pantheon site on drupal-composer-managed upstream (CI Fixtures org)
#   2. pantheon.yml with Solr 8 and PHP version (8.1 for D10, 8.3 for D11)
#   3. field_test content type with 10 fields (string, text_long, integer,
#      boolean, email, link, decimal, datetime/date, datetime/datetime, list_string)
#   4. "Field Mapping Test Node" with known values for all 10 fields
#   5. Verification that everything was created correctly
#
# How to use:
#   1. Authenticate terminus:
#        terminus auth:login --machine-token=<token>
#   2. Load your SSH key:
#        ssh-add ~/.ssh/id_rsa
#   3. Set git identity:
#        git config --global user.email "you@example.com"
#        git config --global user.name "Your Name"
#   4. Run the script from the repo root:
#        .github/scripts/bootstrap-fixture-site.sh search-api-pantheon-d10 10
#        .github/scripts/bootstrap-fixture-site.sh search-api-pantheon-d11 11
#   5. Verify the dev site at:
#        https://dev-<site-name>.pantheonsite.io
#
# Arguments:
#   $1  site-name       Pantheon site machine name (e.g. search-api-pantheon-d10)
#   $2  drupal-version  Drupal major version: 10 or 11
#   $3  org-uuid        (optional) Pantheon org UUID. Defaults to CI Fixtures org.
#
# Examples:
#   .github/scripts/bootstrap-fixture-site.sh search-api-pantheon-d10 10
#   .github/scripts/bootstrap-fixture-site.sh search-api-pantheon-d11 11
#   .github/scripts/bootstrap-fixture-site.sh search-api-pantheon-d10 10 5ae1fa30-8cc4-4894-8ca9-d50628dcba17

SITE_NAME="${1:?Usage: $0 <site-name> <drupal-version> [org-uuid]}"
DRUPAL_VERSION="${2:?Usage: $0 <site-name> <drupal-version> [org-uuid]}"
ORG="${3:-5ae1fa30-8cc4-4894-8ca9-d50628dcba17}"  # CI Fixtures for Projects

UPSTREAM="drupal-composer-managed"
SITE_ENV="${SITE_NAME}.dev"

echo "=== Bootstrap CI Fixture Site ==="
echo "Site: ${SITE_NAME}"
echo "Drupal: ${DRUPAL_VERSION}"
echo "Org: ${ORG}"
echo ""

# ---------------------------------------------------------------------------
# Step 1: Create site (skip if exists)
# ---------------------------------------------------------------------------
if terminus site:info "$SITE_NAME" &>/dev/null; then
  echo "[skip] Site ${SITE_NAME} already exists"
else
  echo "[1/5] Creating site..."
  terminus site:create "$SITE_NAME" "$SITE_NAME" "$UPSTREAM" --org="$ORG"
  echo "Waiting for site creation workflow..."
  terminus workflow:wait "$SITE_ENV" --max=300
fi

# ---------------------------------------------------------------------------
# Step 2: Configure pantheon.yml (Solr 8, PHP version)
# ---------------------------------------------------------------------------
echo "[2/5] Configuring pantheon.yml..."

GIT_URL=$(terminus connection:info "$SITE_ENV" --field=git_url)
WORK_DIR=$(mktemp -d)
GIT_SSH_COMMAND="ssh -o StrictHostKeyChecking=no" git clone "$GIT_URL" "$WORK_DIR/site"
cd "$WORK_DIR/site"

if [ "$DRUPAL_VERSION" = "10" ]; then
  PHP_VER="8.1"
elif [ "$DRUPAL_VERSION" = "11" ]; then
  PHP_VER="8.3"
else
  PHP_VER="8.3"
fi

if [ -f pantheon.yml ]; then
  if ! grep -q "^search:" pantheon.yml; then
    echo "search:" >> pantheon.yml
    echo "  version: 8" >> pantheon.yml
  fi
  if ! grep -q "php_version:" pantheon.yml; then
    echo "php_version: ${PHP_VER}" >> pantheon.yml
  fi
else
  cat > pantheon.yml <<YAML
api_version: 1
php_version: ${PHP_VER}
search:
  version: 8
YAML
fi

if git diff --quiet pantheon.yml; then
  echo "[skip] pantheon.yml already configured"
else
  git add pantheon.yml
  git commit -m "Configure Solr 8 and PHP ${PHP_VER} for CI fixture"
  git push origin master
  terminus workflow:wait "$SITE_ENV" --max=300
fi

cd /tmp
rm -rf "$WORK_DIR"

# Wait for environment to be ready after code push
echo "Waiting for Drupal to be available..."
for i in $(seq 1 12); do
  if terminus drush "$SITE_ENV" -- status --field=bootstrap 2>/dev/null | grep -q "Successful"; then
    echo "Drupal ready"
    break
  fi
  if [ "$i" -eq 12 ]; then
    echo "ERROR: Drupal not ready after 2 minutes"
    exit 1
  fi
  sleep 10
done

# ---------------------------------------------------------------------------
# Step 3: Create field_test content type and 10 fields
# ---------------------------------------------------------------------------
echo "[3/5] Creating field_test content type and fields..."

terminus drush "$SITE_ENV" -- ev "
  \$type = \Drupal::entityTypeManager()->getStorage('node_type')->load('field_test');
  if (\$type) { echo 'field_test already exists, skipping' . PHP_EOL; return; }
  \$type = \Drupal::entityTypeManager()->getStorage('node_type')->create([
    'type' => 'field_test',
    'name' => 'Field Test',
  ]);
  \$type->save();
  echo 'Content type created' . PHP_EOL;
"

# Simple fields (no custom settings)
SIMPLE_FIELDS="field_text_plain:string field_text_long:text_long field_integer:integer field_boolean:boolean field_email:email field_link:link"

for FIELD_DEF in $SIMPLE_FIELDS; do
  FIELD_NAME="${FIELD_DEF%%:*}"
  FIELD_TYPE="${FIELD_DEF##*:}"
  terminus drush "$SITE_ENV" -- ev "
    use Drupal\field\Entity\FieldStorageConfig;
    use Drupal\field\Entity\FieldConfig;
    if (FieldStorageConfig::loadByName('node', '${FIELD_NAME}')) { echo '${FIELD_NAME} exists' . PHP_EOL; return; }
    FieldStorageConfig::create(['field_name' => '${FIELD_NAME}', 'entity_type' => 'node', 'type' => '${FIELD_TYPE}'])->save();
    FieldConfig::create(['field_name' => '${FIELD_NAME}', 'entity_type' => 'node', 'bundle' => 'field_test', 'label' => '${FIELD_NAME}'])->save();
    echo '${FIELD_NAME} created' . PHP_EOL;
  "
done

# Fields with custom settings
terminus drush "$SITE_ENV" -- ev "
  use Drupal\field\Entity\FieldStorageConfig;
  use Drupal\field\Entity\FieldConfig;
  if (FieldStorageConfig::loadByName('node', 'field_decimal')) { echo 'field_decimal exists' . PHP_EOL; return; }
  FieldStorageConfig::create(['field_name' => 'field_decimal', 'entity_type' => 'node', 'type' => 'decimal', 'settings' => ['precision' => 10, 'scale' => 2]])->save();
  FieldConfig::create(['field_name' => 'field_decimal', 'entity_type' => 'node', 'bundle' => 'field_test', 'label' => 'field_decimal'])->save();
  echo 'field_decimal created' . PHP_EOL;
"

terminus drush "$SITE_ENV" -- ev "
  use Drupal\field\Entity\FieldStorageConfig;
  use Drupal\field\Entity\FieldConfig;
  if (FieldStorageConfig::loadByName('node', 'field_date')) { echo 'field_date exists' . PHP_EOL; return; }
  FieldStorageConfig::create(['field_name' => 'field_date', 'entity_type' => 'node', 'type' => 'datetime', 'settings' => ['datetime_type' => 'date']])->save();
  FieldConfig::create(['field_name' => 'field_date', 'entity_type' => 'node', 'bundle' => 'field_test', 'label' => 'field_date'])->save();
  echo 'field_date created' . PHP_EOL;
"

terminus drush "$SITE_ENV" -- ev "
  use Drupal\field\Entity\FieldStorageConfig;
  use Drupal\field\Entity\FieldConfig;
  if (FieldStorageConfig::loadByName('node', 'field_datetime')) { echo 'field_datetime exists' . PHP_EOL; return; }
  FieldStorageConfig::create(['field_name' => 'field_datetime', 'entity_type' => 'node', 'type' => 'datetime', 'settings' => ['datetime_type' => 'datetime']])->save();
  FieldConfig::create(['field_name' => 'field_datetime', 'entity_type' => 'node', 'bundle' => 'field_test', 'label' => 'field_datetime'])->save();
  echo 'field_datetime created' . PHP_EOL;
"

terminus drush "$SITE_ENV" -- ev "
  use Drupal\field\Entity\FieldStorageConfig;
  use Drupal\field\Entity\FieldConfig;
  if (FieldStorageConfig::loadByName('node', 'field_list_text')) { echo 'field_list_text exists' . PHP_EOL; return; }
  FieldStorageConfig::create(['field_name' => 'field_list_text', 'entity_type' => 'node', 'type' => 'list_string', 'settings' => ['allowed_values' => ['option1' => 'Option 1', 'option2' => 'Option 2', 'option3' => 'Option 3']]])->save();
  FieldConfig::create(['field_name' => 'field_list_text', 'entity_type' => 'node', 'bundle' => 'field_test', 'label' => 'field_list_text'])->save();
  echo 'field_list_text created' . PHP_EOL;
"

# ---------------------------------------------------------------------------
# Step 4: Create test node with known field values
# ---------------------------------------------------------------------------
echo "[4/5] Creating test node..."

terminus drush "$SITE_ENV" -- ev "
  use Drupal\node\Entity\Node;
  \$existing = \Drupal::entityQuery('node')
    ->condition('type', 'field_test')
    ->condition('title', 'Field Mapping Test Node')
    ->accessCheck(FALSE)
    ->count()
    ->execute();
  if (\$existing > 0) { echo 'Test node already exists' . PHP_EOL; return; }
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

# ---------------------------------------------------------------------------
# Step 5: Verify setup
# ---------------------------------------------------------------------------
echo "[5/5] Verifying setup..."

terminus drush "$SITE_ENV" -- ev "
  \$type = \Drupal::entityTypeManager()->getStorage('node_type')->load('field_test');
  if (!\$type) { echo 'FAIL: field_test content type missing' . PHP_EOL; exit(1); }
  echo 'field_test content type: OK' . PHP_EOL;

  \$fields = [
    'field_text_plain' => 'string',
    'field_text_long' => 'text_long',
    'field_integer' => 'integer',
    'field_boolean' => 'boolean',
    'field_email' => 'email',
    'field_link' => 'link',
    'field_decimal' => 'decimal',
    'field_date' => 'datetime',
    'field_datetime' => 'datetime',
    'field_list_text' => 'list_string',
  ];
  foreach (\$fields as \$name => \$expected_type) {
    \$storage = \Drupal\field\Entity\FieldStorageConfig::loadByName('node', \$name);
    if (!\$storage) { echo 'FAIL: ' . \$name . ' missing' . PHP_EOL; exit(1); }
    if (\$storage->getType() !== \$expected_type) { echo 'FAIL: ' . \$name . ' type is ' . \$storage->getType() . ', expected ' . \$expected_type . PHP_EOL; exit(1); }
    echo \$name . ': OK (' . \$expected_type . ')' . PHP_EOL;
  }

  \$query = \Drupal::entityQuery('node')
    ->condition('type', 'field_test')
    ->condition('title', 'Field Mapping Test Node')
    ->accessCheck(FALSE)
    ->count();
  \$count = \$query->execute();
  if (\$count == 0) { echo 'FAIL: test node missing' . PHP_EOL; exit(1); }
  echo 'Test node: OK (' . \$count . ' found)' . PHP_EOL;
"

echo ""
echo "=== Bootstrap complete ==="
echo "Site: ${SITE_NAME}"
echo "Dev URL: https://dev-${SITE_NAME}.pantheonsite.io"
echo ""
echo "pantheon.yml:"
terminus drush "$SITE_ENV" -- ev "echo file_get_contents('/code/pantheon.yml');"
echo ""
echo "CI multidevs will inherit field_test content type, fields, and test node from dev."
echo "Modules (search_api_pantheon, devel_generate) are installed per-multidev by create-multidev.sh."
