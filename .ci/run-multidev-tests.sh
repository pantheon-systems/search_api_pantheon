#!/bin/bash
# Multidev-based CI test suite for search_api_pantheon
# Uses a stable site and creates temporary multidev environments for testing
#
# Usage: ./run-multidev-tests.sh
#
# Environment variables:
#   TEST_SITE (required): Base site name without environment suffix
#   MULTIDEV_PREFIX (optional): Prefix for multidev names. Default: "test"
#
# Example:
#   TEST_SITE=my-stable-site ./run-multidev-tests.sh

set -e

# Check required environment variable
if [ -z "$TEST_SITE" ]; then
  echo "Error: TEST_SITE environment variable must be set"
  echo "Example: TEST_SITE=my-site ./run-multidev-tests.sh"
  exit 1
fi

SITE="$TEST_SITE"
MULTIDEV_PREFIX="${MULTIDEV_PREFIX:-test}"

# Generate unique multidev names (max 11 chars for Pantheon)
# Format: test-XXXXX where XXXXX is random 5-char suffix
generate_multidev_name() {
  local suffix=$(cat /dev/urandom | LC_ALL=C tr -dc 'a-z0-9' | fold -w 5 | head -n 1)
  echo "${MULTIDEV_PREFIX}-${suffix}"
}

MULTIDEV1=$(generate_multidev_name)
MULTIDEV2=$(generate_multidev_name)

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

# Cleanup function
cleanup_multidevs() {
  echo ""
  echo "========================================"
  echo -e "${CYAN}Cleaning up multidev environments${NC}"
  echo "========================================"

  for MULTIDEV in "$MULTIDEV1" "$MULTIDEV2"; do
    if terminus multidev:list "$SITE" --format=list 2>/dev/null | grep -q "^$MULTIDEV$"; then
      echo "Deleting multidev: $MULTIDEV"
      terminus multidev:delete "$SITE.$MULTIDEV" --delete-branch -y || echo "Failed to delete $MULTIDEV"
    fi
  done
}

# Set trap to cleanup on exit
trap cleanup_multidevs EXIT

# Multidev creation with retry logic
create_multidev_with_retry() {
  local site="$1"
  local multidev_name="$2"
  local max_attempts=3
  local retry_delay=30
  local attempt=1

  while [ $attempt -le $max_attempts ]; do
    echo "Creating multidev: $multidev_name (attempt $attempt/$max_attempts)"

    if terminus multidev:create "$site.dev" "$multidev_name" 2>&1; then
      echo -e "${GREEN}✓ Created $multidev_name${NC}"
      return 0
    else
      if [ $attempt -lt $max_attempts ]; then
        echo -e "${YELLOW}⚠ Failed to create $multidev_name (attempt $attempt/$max_attempts)${NC}"
        echo "Waiting ${retry_delay} seconds before retry..."
        sleep $retry_delay
        ((attempt++))
      else
        echo -e "${RED}✗ Failed to create $multidev_name after $max_attempts attempts${NC}"
        return 1
      fi
    fi
  done

  return 1
}

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
echo "search_api_pantheon Multidev Test Suite"
echo "========================================"
echo "Site: $SITE"
echo "Multidev 1: $MULTIDEV1"
echo "Multidev 2: $MULTIDEV2"
echo "Started: $(date)"
echo "========================================"
echo ""

# Step 0: Verify site exists
echo "========================================"
echo -e "${CYAN}Step 0: Verifying site exists${NC}"
echo "========================================"

if ! terminus site:info "$SITE" >/dev/null 2>&1; then
  echo -e "${RED}✗ ERROR: Site $SITE does not exist${NC}"
  echo "Please create the site first or set TEST_SITE to an existing site"
  exit 1
fi

echo -e "${GREEN}✓ Site $SITE exists${NC}"
echo ""

# Step 1: Create multidev environments
echo "========================================"
echo -e "${CYAN}Step 1: Creating multidev environments${NC}"
echo "========================================"

if ! create_multidev_with_retry "$SITE" "$MULTIDEV1"; then
  echo -e "${RED}✗ Failed to create $MULTIDEV1 after multiple attempts${NC}"
  exit 1
fi

echo ""

if ! create_multidev_with_retry "$SITE" "$MULTIDEV2"; then
  echo -e "${RED}✗ Failed to create $MULTIDEV2 after multiple attempts${NC}"
  exit 1
fi

echo ""
echo -e "${GREEN}✓ Both multidev environments created successfully${NC}"
echo ""

# Step 2: Wait for multidevs to be ready
echo "========================================"
echo -e "${CYAN}Step 2: Waiting for multidevs to be ready${NC}"
echo "========================================"

sleep 5

for MULTIDEV in "$MULTIDEV1" "$MULTIDEV2"; do
  echo "Checking $MULTIDEV..."
  if terminus env:info "$SITE.$MULTIDEV" >/dev/null 2>&1; then
    echo -e "${GREEN}✓ $MULTIDEV is ready${NC}"
  else
    echo -e "${RED}✗ $MULTIDEV is not ready${NC}"
    exit 1
  fi
done

echo ""

# Suite 1: Field Mapping Tests (on first multidev)
run_test_suite \
  "Field Mapping Tests" \
  "$SCRIPT_DIR/test-field-mapping.sh" \
  "$SITE" \
  "$MULTIDEV1" || true

# Suite 2: Solr Query Tests (on first multidev)
run_test_suite \
  "Solr Query Tests" \
  "$SCRIPT_DIR/test-solr-queries-terminus.sh" \
  "$SITE.$MULTIDEV1" || true

# Suite 3: Environment Parity Tests (between multidevs)
run_test_suite \
  "Environment Parity Tests (Multidevs)" \
  "$SCRIPT_DIR/test-multidev-parity.sh" \
  "$SITE" \
  "$MULTIDEV1" \
  "$MULTIDEV2" || true

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
