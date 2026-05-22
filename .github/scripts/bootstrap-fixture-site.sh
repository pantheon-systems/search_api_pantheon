#!/usr/bin/env bash
set -eou pipefail

# Bootstrap a Search API Pantheon CI fixture site from scratch.
#
# Recreates the full dev environment that CI multidev tests depend on.
# Run this if a fixture site is destroyed, corrupted, or needs to be
# rebuilt from zero. All steps are idempotent (safe to re-run).
#
# What it sets up:
#   1. Pantheon site on correct upstream + Drupal install
#   2. Performance Small plan + Solr enabled
#   3. field_test content type with 10 fields
#   4. "Field Mapping Test Node" with known values
#   5. Verification
#
# Note: pantheon.yml (search version, php_version) must be configured
# manually via git push after running this script.
#
# Examples:
#   .github/scripts/bootstrap-fixture-site.sh -n search-api-pantheon-d10 -u d10
#   .github/scripts/bootstrap-fixture-site.sh -n search-api-pantheon-d11 -u d11
#   .github/scripts/bootstrap-fixture-site.sh -n search-api-pantheon-d10 -u d10 -o "CI Fixtures for Projects"
#
# Prerequisites:
#   - terminus authenticated (terminus auth:whoami)
#   - SSH key loaded for Pantheon git operations
#   - git configured (user.email, user.name)

show_help() {
    echo "Usage: $0 -n <site-name> -u <upstream> [-o <org>]"
    echo "Options:"
    echo "  -n <arg>         Site name (e.g. search-api-pantheon-d10)"
    echo "  -u <arg>         Upstream: 'd10' or 'd11'"
    echo "  -o <arg>         Organization name or UUID (default: CI Fixtures for Projects)"
    echo "  -h               Show help"
    exit 1
}

main() {
    local UPSTREAM=""
    local SITE_NAME=""
    local ORG="CI Fixtures for Projects"

    while getopts "u:o:n:h" opt; do
        case $opt in
            u) UPSTREAM="$OPTARG" ;;
            o) ORG="$OPTARG" ;;
            n) SITE_NAME="$OPTARG" ;;
            h) show_help ;;
            *) show_help ;;
        esac
    done

    shift "$((OPTIND-1))"

    if [[ -z "$SITE_NAME" ]]; then
        echo "ERROR: -n <site-name> is required"
        show_help
    fi

    if [[ -z "$UPSTREAM" ]]; then
        echo "ERROR: -u <upstream> is required (d10 or d11)"
        show_help
    fi

    local PHP_VER
    case "$UPSTREAM" in
        d10)
            UPSTREAM="drupal-10-composer-managed"
            PHP_VER="8.1"
            ;;
        d11)
            UPSTREAM="drupal-11-composer-managed"
            PHP_VER="8.3"
            ;;
        *)
            echo "ERROR: Upstream must be 'd10' or 'd11', got: ${UPSTREAM}"
            show_help
            ;;
    esac

    local SITE_NAME_LC
    SITE_NAME_LC=$(echo "$SITE_NAME" | tr '[:upper:]' '[:lower:]')
    local SITE_ENV="${SITE_NAME_LC}.dev"

    echo "=== Bootstrap CI Fixture Site ==="
    echo "Site: ${SITE_NAME}"
    echo "Upstream: ${UPSTREAM}"
    echo "PHP: ${PHP_VER}"
    echo "Org: ${ORG}"
    echo ""

    # -----------------------------------------------------------------------
    # Step 1: Create site (exit if exists)
    # -----------------------------------------------------------------------
    if terminus site:info "$SITE_NAME_LC" &>/dev/null; then
        echo "ERROR: Site ${SITE_NAME} already exists. Exiting to avoid modifying an existing site."
        exit 1
    fi

    echo "[1/5] Creating site..."
    terminus site:create "$SITE_NAME" "$SITE_NAME" "$UPSTREAM" --org="$ORG"
    echo "Waiting for site creation workflow..."
    terminus workflow:wait "$SITE_ENV"

    local SITE_ID
    SITE_ID=$(terminus site:info "$SITE_NAME_LC" --field=ID)

    # -----------------------------------------------------------------------
    # Step 2: Install Drupal
    # -----------------------------------------------------------------------
    echo "[2/5] Installing Drupal..."
    if terminus drush "$SITE_ENV" -- status --field=bootstrap 2>/dev/null | grep -q "Successful"; then
        echo "[skip] Drupal already installed"
    else
        terminus drush "$SITE_ENV" -- site-install -y
    fi

    # -----------------------------------------------------------------------
    # Step 3: Upgrade plan + enable Solr
    # -----------------------------------------------------------------------
    echo "[3/5] Configuring plan and Solr..."
    local CURRENT_PLAN
    CURRENT_PLAN=$(terminus site:info "$SITE_NAME_LC" --field=plan_name 2>/dev/null || echo "")
    if [[ "$CURRENT_PLAN" == *"Sandbox"* ]]; then
        echo "Upgrading to Performance Small..."
        terminus plan:set "$SITE_ID" "plan-performance_small-contract-annual-1"
    else
        echo "[skip] Already on plan: ${CURRENT_PLAN}"
    fi

    echo "Enabling Solr..."
    terminus solr:enable "$SITE_ID" 2>/dev/null || echo "[skip] Solr already enabled or enable failed"

    # Wait for Drupal to be available
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

    # -----------------------------------------------------------------------
    # Step 5: Create field_test content type, fields, and test node
    # -----------------------------------------------------------------------
    echo "[4/5] Creating field_test content type, fields, and test node..."

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
    local SIMPLE_FIELDS="field_text_plain:string field_text_long:text_long field_integer:integer field_boolean:boolean field_email:email field_link:link"

    for FIELD_DEF in $SIMPLE_FIELDS; do
        local FIELD_NAME="${FIELD_DEF%%:*}"
        local FIELD_TYPE="${FIELD_DEF##*:}"
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

    # Create test node
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

    # -----------------------------------------------------------------------
    # Step 7: Verify setup
    # -----------------------------------------------------------------------
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
    echo "Site: ${SITE_NAME_LC}"
    echo "Site ID: ${SITE_ID}"
    echo "Dev URL: https://dev-${SITE_NAME_LC}.pantheonsite.io"
    echo ""
    echo "CI multidevs will inherit field_test content type, fields, and test node from dev."
    echo "Modules (search_api_pantheon, devel_generate) are installed per-multidev by create-multidev.sh."
}

main "$@"
