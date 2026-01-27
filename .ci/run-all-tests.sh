#!/bin/bash
# Comprehensive CI test suite for search_api_pantheon
# Runs all test scripts in sequence
#
# Usage: ./run-all-tests.sh SITE_NAME
# Example: ./run-all-tests.sh my-test-site
#
# Note: Use site name only, WITHOUT environment suffix (.dev/.test/.live)

set -e

if [ -z "$1" ]; then
  echo "Usage: $0 SITE_NAME"
  echo "Example: $0 my-test-site"
  echo ""
  echo "Note: Use site name only, WITHOUT environment suffix"
  exit 1
fi

SITE="$1"

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
echo "Started: $(date)"
echo "========================================"
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
