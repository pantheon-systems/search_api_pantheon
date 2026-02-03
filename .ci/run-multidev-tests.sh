#!/bin/bash
# Multidev-based CI test suite for search_api_pantheon
# Uses a stable site and creates temporary multidev environments for testing
# Environment variables:
#   TEST_SITE (required): Base site name without environment suffix
# Example:
#   TEST_SITE=my-stable-site ./run-multidev-tests.sh

set -e

if [ -z "$TEST_SITE" ]; then
  echo "Error: TEST_SITE environment variable must be set"
  echo "Example: TEST_SITE=my-site ./run-multidev-tests.sh"
  exit 1
fi

# Validate that SITE doesn't include environment suffix
if [[ "$SITE" =~ \.(dev|test|live)$ ]]; then
  echo "Error: Site name should NOT include environment suffix (.dev/.test/.live)"
  echo "You provided: $SITE"
  exit 1
fi

SITE="$TEST_SITE"
MULTIDEV_PREFIX="ci"

generate_multidev_name() {
  local suffix=$(cat /dev/urandom | LC_ALL=C tr -dc 'a-z0-9' | fold -w 5 | head -n 1)
}

MULTIDEV1=$(generate_multidev_name)
MULTIDEV2=$(generate_multidev_name)

RED='\033[0;31m'
GREEN='\033[0;32m'
NC='\033[0m'

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

SUITE_START=$(date +%s)
SUITES_PASSED=0
SUITES_FAILED=0
SUITES_SKIPPED=0

cleanup_multidevs() {
  echo ""
  echo "Cleanup: Deleting multidevs..."
  for MULTIDEV in "$MULTIDEV1" "$MULTIDEV2"; do
    if terminus multidev:list "$SITE" --format=list 2>/dev/null | grep -q "^$MULTIDEV$"; then
      terminus multidev:delete "$SITE.$MULTIDEV" --delete-branch -y 2>&1 | grep -v "^$" || true
    fi
  done
}

# Set trap to cleanup on exit
trap cleanup_multidevs EXIT

create_multidev_with_retry() {
  local site="$1"
  local multidev_name="$2"
  local max_attempts=3
  local retry_delay=30
  local attempt=1

  while [ $attempt -le $max_attempts ]; do
    # Check if multidev already exists (from a previous failed attempt)
    if terminus multidev:list "$site" --format=list 2>/dev/null | grep -q "^$multidev_name$"; then
      echo "Multidev $multidev_name exists"
      return 0
    fi

    if terminus multidev:create "$site.dev" "$multidev_name" 2>&1 | grep -v "^$"; then
      echo "Created $multidev_name"
      return 0
    else
      if [ $attempt -lt $max_attempts ]; then
        echo "Retry $attempt/$max_attempts in ${retry_delay}s..."
        sleep $retry_delay
        ((attempt++))
      else
        echo -e "${RED}Failed to create $multidev_name${NC}"
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
  echo "Running: $suite_name"

  if [ ! -f "$script_path" ]; then
    echo "SKIP: Script not found"
    ((SUITES_SKIPPED++))
    return 0
  fi

  if bash "$script_path" $args; then
    echo -e "${GREEN}PASS: $suite_name${NC}"
    ((SUITES_PASSED++))
    return 0
  else
    echo -e "${RED}FAIL: $suite_name${NC}"
    ((SUITES_FAILED++))
    return 1
  fi
}

echo "Test: $SITE ($MULTIDEV1, $MULTIDEV2) - $(date +%H:%M:%S)"

# Verify site exists
if ! terminus site:info "$SITE" >/dev/null 2>&1; then
  echo -e "${RED}ERROR: Site $SITE does not exist${NC}"
  exit 1
fi

# Create multidev environments
echo "Creating multidevs..."
if ! create_multidev_with_retry "$SITE" "$MULTIDEV1"; then
  echo -e "${RED}Failed to create $MULTIDEV1${NC}"
  exit 1
fi

if ! create_multidev_with_retry "$SITE" "$MULTIDEV2"; then
  echo -e "${RED}Failed to create $MULTIDEV2${NC}"
  exit 1
fi

# Wait for multidevs to be ready
sleep 5
for MULTIDEV in "$MULTIDEV1" "$MULTIDEV2"; do
  if ! terminus env:info "$SITE.$MULTIDEV" >/dev/null 2>&1; then
    echo -e "${RED}$MULTIDEV not ready${NC}"
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

# Summary
SUITE_END=$(date +%s)
DURATION=$((SUITE_END - SUITE_START))
MINUTES=$((DURATION / 60))
SECONDS=$((DURATION % 60))

echo ""
echo "Summary: ${MINUTES}m${SECONDS}s - Pass:$SUITES_PASSED Fail:$SUITES_FAILED Skip:$SUITES_SKIPPED"

if [ $SUITES_FAILED -eq 0 ]; then
  echo -e "${GREEN}PASS${NC}"
  exit 0
else
  echo -e "${RED}FAIL${NC}"
  exit 1
fi
