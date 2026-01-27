#!/bin/bash
# Comprehensive CI test suite for search_api_pantheon
# Runs all test scripts in sequence
#
# Usage: ./run-all-tests.sh [SITE_NAME] [DRUPAL_VERSION] [TERMINUS_ORG]
# Example: ./run-all-tests.sh my-test-site 11 my-org
#
# Arguments:
#   SITE_NAME (optional): Name without environment suffix. Auto-generated if omitted.
#   DRUPAL_VERSION (optional): 10 or 11. Default: 11
#   TERMINUS_ORG (optional): Pantheon organization. Default: $TERMINUS_ORG env var
#
# Note: Use site name only, WITHOUT environment suffix (.dev/.test/.live)
#       If no site name provided, a random one will be generated

set -e

# Generate random site name
generate_site_name() {
  local prefix="test-sap"
  local random_suffix=$(cat /dev/urandom | LC_ALL=C tr -dc 'a-z0-9' | fold -w 8 | head -n 1)
  echo "${prefix}-${random_suffix}"
}

# Get site name from argument or generate random one
if [ -z "$1" ]; then
  SITE=$(generate_site_name)
  echo "No site name provided, using: $SITE"
else
  SITE="$1"
fi

# Get Drupal version (default to 11)
DRUPAL_VERSION="${2:-11}"

# Get Terminus org (use env var if not provided, default to "CMS Platform")
ORG="${3:-${TERMINUS_ORG:-CMS Platform}}"

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
CYAN='\033[0;36m'
NC='\033[0m'

# Get script directory
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Summary tracking
SUITE_START=$(date +%s)
SUITES_PASSED=0
SUITES_FAILED=0
SUITES_SKIPPED=0

run_test_suite() {
  local suite_name="$1"
  local script_path="$2"
  shift 2
  local args="$@"

  echo ""
  echo "========================================"
  echo -e "${CYAN}Running: $suite_name${NC}"
  echo "========================================"

  if [ ! -f "$script_path" ]; then
    echo -e "${YELLOW}⚠ SKIP: Script not found: $script_path${NC}"
    ((SUITES_SKIPPED++))
    return 0
  fi

  if bash "$script_path" $args; then
    echo -e "${GREEN}✓ SUITE PASSED: $suite_name${NC}"
    ((SUITES_PASSED++))
    return 0
  else
    echo -e "${RED}✗ SUITE FAILED: $suite_name${NC}"
    ((SUITES_FAILED++))
    return 1
  fi
}

echo "========================================"
echo "search_api_pantheon CI Test Suite"
echo "========================================"
echo "Site: $SITE"
echo "Drupal Version: $DRUPAL_VERSION"
echo "Organization: $ORG"
echo "Started: $(date)"
echo "========================================"
echo ""

# Suite 0: Base Installation (via ci.sh)
echo "========================================"
echo -e "${CYAN}Suite 0: Base Installation${NC}"
echo "========================================"
echo "Running ci.sh to create site and install modules..."

CI_SCRIPT="$SCRIPT_DIR/../.github/workflows/ci.sh"
if [ ! -f "$CI_SCRIPT" ]; then
  echo -e "${RED}✗ ERROR: ci.sh not found at $CI_SCRIPT${NC}"
  exit 1
fi

# Export variables for ci.sh
export SITE
export DRUPAL_VERSION
export TERMINUS_ORG="$ORG"

# Override CONSTRAINT for test branches to use 8.4.x-dev
# We need to modify git-constraint-helper temporarily to respect our override
HELPER_SCRIPT="$SCRIPT_DIR/../.github/workflows/git-constraint-helper"
HELPER_BACKUP="$SCRIPT_DIR/../.github/workflows/git-constraint-helper.bak"

# Backup original helper
cp "$HELPER_SCRIPT" "$HELPER_BACKUP"

# Modify helper to check for existing CONSTRAINT
cat > "$HELPER_SCRIPT" <<'HELPER_EOF'
get_current_constraint() {
  # Check if CONSTRAINT is already set
  if [ -n "$CONSTRAINT" ]; then
    echo "$CONSTRAINT"
    return
  fi

  branch=$(git rev-parse --abbrev-ref HEAD | tr -d '[:space:]')
  if [ "$branch" != "HEAD" ]; then
    echo "${branch}-dev"
    return
  fi

  tag=$(git describe --exact-match --tags "$(git log -n1 --pretty='%h')" 2>/dev/null | tr -d '[:space:]')
  if [ -n "$tag" ]; then
    echo "$tag"
    return
  fi

  if [ -n "$GITHUB_HEAD_REF" ]; then
    IFS='/' read -ra parts <<< "$GITHUB_HEAD_REF"
    branch="${parts[-1]}"
    if [ -n "$branch" ]; then
      echo "${branch}-dev"
      return
    fi
  fi

  echo "^8"
}
HELPER_EOF

# Set CONSTRAINT for ci.sh to use
export CONSTRAINT="8.4.x-dev"

# Run ci.sh
CI_RESULT=0
if bash "$CI_SCRIPT" "$SITE" "$DRUPAL_VERSION" "$ORG"; then
  CI_RESULT=0
  echo -e "${GREEN}✓ Base installation completed successfully${NC}"
else
  CI_RESULT=1
  echo -e "${RED}✗ Base installation failed${NC}"
fi

# Restore original helper
mv "$HELPER_BACKUP" "$HELPER_SCRIPT"

# Exit if ci.sh failed
if [ $CI_RESULT -ne 0 ]; then
  exit 1
fi

echo ""

# Suite 1: Field Mapping Tests
run_test_suite \
  "Field Mapping Tests" \
  "$SCRIPT_DIR/test-field-mapping.sh" \
  "$SITE" || true

# Suite 2: Solr Query Tests
run_test_suite \
  "Solr Query Tests" \
  "$SCRIPT_DIR/test-solr-queries-terminus.sh" \
  "$SITE.dev" || true

# Suite 3: Environment Parity Tests
run_test_suite \
  "Environment Parity Tests" \
  "$SCRIPT_DIR/test-environment-parity.sh" \
  "$SITE" || true

# Calculate duration
SUITE_END=$(date +%s)
DURATION=$((SUITE_END - SUITE_START))
MINUTES=$((DURATION / 60))
SECONDS=$((DURATION % 60))

# Print summary
echo ""
echo "========================================"
echo "Test Suite Summary"
echo "========================================"
echo -e "Suites Passed:  ${GREEN}$SUITES_PASSED${NC}"
echo -e "Suites Failed:  ${RED}$SUITES_FAILED${NC}"
echo -e "Suites Skipped: ${YELLOW}$SUITES_SKIPPED${NC}"
echo "Duration: ${MINUTES}m ${SECONDS}s"
echo "========================================"

if [ $SUITES_FAILED -eq 0 ]; then
  echo -e "${GREEN}✓ All test suites passed!${NC}"
  exit 0
else
  echo -e "${RED}✗ Some test suites failed${NC}"
  exit 1
fi
