#!/bin/bash
# Test environment parity for search_api_pantheon using multidev environments
# Validates that PSA correctly detects and uses different Solr cores
# for different multidev environments

set -e

if [ -z "$1" ] || [ -z "$2" ] || [ -z "$3" ]; then
  echo "Usage: $0 SITE_NAME MULTIDEV1 MULTIDEV2"
  echo "Example: $0 my-site test-abc12 test-xyz89"
  echo ""
  echo "Note: Use site name only, WITHOUT environment suffix (.dev/.test/.live)"
  echo "This script will test the two multidev environments provided"
  exit 1
fi

SITE="$1"
MULTIDEV1="$2"
MULTIDEV2="$3"

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
  local condition="$2"

  if [ "$condition" = "true" ]; then
    log_success "✓ $test_name: PASS"
    ((TESTS_PASSED++))
    return 0
  else
    log_error "✗ $test_name: FAIL"
    ((TESTS_FAILED++))
    return 1
  fi
}

echo "========================================"
echo "Multidev Environment Parity Test Suite"
echo "========================================"
log_info "Site: $SITE"
log_info "Testing: $MULTIDEV1, $MULTIDEV2"
echo "========================================"

# Arrays to store results
declare -A ENV_HOSTS
declare -A ENV_PORTS
declare -A ENV_PATHS
declare -A ENV_CORES_FULL
declare -A ENV_TOKENS
declare -A ENV_CORES

# Disable exit on error for test assertions so all tests run
set +e

# Test each multidev environment
for ENV in "$MULTIDEV1" "$MULTIDEV2"; do
  echo ""
  log_info "=== Testing $SITE.$ENV ==="
  echo ""

  # Step 1: Check if environment exists and is accessible
  log_info "Step 1: Checking environment accessibility"

  if ! terminus env:info "$SITE.$ENV" >/dev/null 2>&1; then
    log_error "Environment $ENV not accessible or doesn't exist"
    ((TESTS_FAILED++))
    continue
  fi

  log_success "Environment $ENV is accessible"

  # Step 2: Extract Pantheon environment variables
  log_info "Step 2: Extracting PANTHEON_INDEX_* variables"

  ENV_VARS=$(terminus drush "$SITE.$ENV" -- ev "
    echo 'HOST=' . getenv('PANTHEON_INDEX_HOST') . PHP_EOL;
    echo 'PORT=' . getenv('PANTHEON_INDEX_PORT') . PHP_EOL;
    echo 'PATH=' . getenv('PANTHEON_INDEX_PATH') . PHP_EOL;
    echo 'CORE=' . getenv('PANTHEON_INDEX_CORE') . PHP_EOL;
    echo 'TOKEN_LEN=' . strlen(getenv('PANTHEON_INDEX_TOKEN')) . PHP_EOL;
  " 2>/dev/null | grep -v "\[" | grep -v "WARNING")

  ENV_HOSTS[$ENV]=$(echo "$ENV_VARS" | grep "^HOST=" | cut -d= -f2)
  ENV_PORTS[$ENV]=$(echo "$ENV_VARS" | grep "^PORT=" | cut -d= -f2)
  ENV_PATHS[$ENV]=$(echo "$ENV_VARS" | grep "^PATH=" | cut -d= -f2)
  ENV_CORES_FULL[$ENV]=$(echo "$ENV_VARS" | grep "^CORE=" | cut -d= -f2-)
  ENV_TOKENS[$ENV]=$(echo "$ENV_VARS" | grep "^TOKEN_LEN=" | cut -d= -f2)

  log_info "Host: ${ENV_HOSTS[$ENV]}"
  log_info "Port: ${ENV_PORTS[$ENV]}"
  log_info "Path: ${ENV_PATHS[$ENV]}"
  log_info "Core: ${ENV_CORES_FULL[$ENV]}"
  log_info "Token length: ${ENV_TOKENS[$ENV]} chars"

  # Validate environment variables are set
  if [ -z "${ENV_HOSTS[$ENV]}" ] || [ "${ENV_HOSTS[$ENV]}" = "false" ]; then
    log_error "PANTHEON_INDEX_HOST not set in $ENV environment"
    ((TESTS_FAILED++))
    continue
  fi

  if [ -z "${ENV_PORTS[$ENV]}" ] || [ "${ENV_PORTS[$ENV]}" = "false" ]; then
    log_error "PANTHEON_INDEX_PORT not set in $ENV environment"
    ((TESTS_FAILED++))
    continue
  fi

  if [ -z "${ENV_PATHS[$ENV]}" ] || [ "${ENV_PATHS[$ENV]}" = "false" ]; then
    log_error "PANTHEON_INDEX_PATH not set in $ENV environment"
    ((TESTS_FAILED++))
    continue
  fi

  if [ -z "${ENV_CORES_FULL[$ENV]}" ] || [ "${ENV_CORES_FULL[$ENV]}" = "false" ]; then
    log_error "PANTHEON_INDEX_CORE not set in $ENV environment"
    ((TESTS_FAILED++))
    continue
  fi

  if [ -z "${ENV_TOKENS[$ENV]}" ] || [ "${ENV_TOKENS[$ENV]}" = "0" ]; then
    log_warning "PANTHEON_INDEX_TOKEN not set in $ENV environment (may be optional)"
    # Not failing the test as token appears to be unused in current implementation
  fi

  log_success "All PANTHEON_INDEX_* variables are set"
  ((TESTS_PASSED++))

  # Extract Solr core name from CORE path (do this early so it's available even if search_api not installed)
  # Core format: /site/{SITE_ID}/environment/{ENV}/backend
  CORE_NAME=$(echo "${ENV_CORES_FULL[$ENV]}" | sed -n 's/.*environment\/\([^/]*\)\/.*/\1/p')
  ENV_CORES[$ENV]=$CORE_NAME
  log_info "Solr core: $CORE_NAME"

  # Validate core name matches environment
  if [ "$CORE_NAME" = "$ENV" ]; then
    log_success "Solr core matches environment name"
    ((TESTS_PASSED++))
  else
    log_error "Solr core ($CORE_NAME) doesn't match environment ($ENV)"
    ((TESTS_FAILED++))
  fi

  # Step 3: Check Search API server configuration
  log_info "Step 3: Checking Search API server configuration"

  # Check if pantheon_search server exists
  SERVER_CONFIG=$(terminus drush "$SITE.$ENV" -- config:get search_api.server.pantheon_search backend_config 2>/dev/null | grep -v "\[" | grep -v "WARNING" || echo "")

  if [ -z "$SERVER_CONFIG" ]; then
    log_warning "pantheon_search server not configured in $ENV - skipping server config tests"
    continue
  fi

  log_success "pantheon_search server found"
  ((TESTS_PASSED++))

  # Step 4: Verify PSA can connect to Solr
  log_info "Step 4: Testing Solr connectivity"

  SOLR_RESPONSE=$(terminus drush "$SITE.$ENV" -- search-api-pantheon:select "*:*" --defType="" --rows=0 2>/dev/null | grep -v "notice" | grep -v "WARNING" || echo "")

  if echo "$SOLR_RESPONSE" | jq -e '.responseHeader.status == 0' >/dev/null 2>&1; then
    log_success "Solr connection successful"
    ((TESTS_PASSED++))
  else
    log_error "Solr connection failed"
    ((TESTS_FAILED++))
    continue
  fi

  # Step 5: Run diagnostics
  log_info "Step 5: Running PSA diagnostics"

  DIAG_OUTPUT=$(terminus drush "$SITE.$ENV" -- search-api-pantheon:diagnose 2>/dev/null | grep -v "WARNING" || echo "")

  if echo "$DIAG_OUTPUT" | grep -q "success"; then
    log_success "Diagnostics passed"
    ((TESTS_PASSED++))
  else
    log_warning "Diagnostics output: $DIAG_OUTPUT"
  fi

done

# Cross-environment validation
echo ""
echo "========================================"
log_info "Cross-Environment Validation"
echo "========================================"

# Test 1: Each multidev should have different Solr core paths
echo ""
log_info "Test 1: Verifying multidevs use different Solr cores"

if [ -n "${ENV_CORES_FULL[$MULTIDEV1]}" ] && [ -n "${ENV_CORES_FULL[$MULTIDEV2]}" ]; then
  if [ "${ENV_CORES_FULL[$MULTIDEV1]}" != "${ENV_CORES_FULL[$MULTIDEV2]}" ]; then
    log_success "✓ $MULTIDEV1 and $MULTIDEV2 use different Solr cores"
    ((TESTS_PASSED++))
  else
    log_error "✗ $MULTIDEV1 and $MULTIDEV2 use same Solr core (they should be different)"
    ((TESTS_FAILED++))
  fi
fi

# Test 2: All environments should use same host and port
echo ""
log_info "Test 2: Verifying consistent Solr host/port across environments"

if [ "${ENV_HOSTS[$MULTIDEV1]}" = "${ENV_HOSTS[$MULTIDEV2]}" ]; then
  log_success "✓ All multidevs use same Solr host"
  ((TESTS_PASSED++))
else
  log_error "✗ Multidevs use different Solr hosts (expected same)"
  ((TESTS_FAILED++))
fi

if [ "${ENV_PORTS[$MULTIDEV1]}" = "${ENV_PORTS[$MULTIDEV2]}" ]; then
  log_success "✓ All multidevs use same Solr port"
  ((TESTS_PASSED++))
else
  log_error "✗ Multidevs use different Solr ports (expected same)"
  ((TESTS_FAILED++))
fi

# Test 3: Index content in multidev1, verify it doesn't appear in multidev2
echo ""
log_info "Test 3: Testing data isolation between environments"

if [ -n "${ENV_CORES_FULL[$MULTIDEV1]}" ] && [ -n "${ENV_CORES_FULL[$MULTIDEV2]}" ]; then
  # Create a unique test node in multidev1
  log_info "Creating test content in $MULTIDEV1 environment..."

  UNIQUE_TITLE="MultidevParityTest-$(date +%s)"

  MD1_NODE_ID=$(terminus drush "$SITE.$MULTIDEV1" -- ev "
    \$node = \Drupal\node\Entity\Node::create([
      'type' => 'article',
      'title' => '$UNIQUE_TITLE',
    ]);
    \$node->save();
    echo \$node->id();
  " 2>/dev/null | grep -v "\[" | grep -v "WARNING" | tail -n1)

  if [ -n "$MD1_NODE_ID" ]; then
    log_info "Created node $MD1_NODE_ID in $MULTIDEV1 with title: $UNIQUE_TITLE"

    # Index in multidev1
    terminus drush "$SITE.$MULTIDEV1" -- search-api:index primary >/dev/null 2>&1 || true
    sleep 3

    # Search for it in multidev1 (should find it)
    MD1_SEARCH=$(terminus drush "$SITE.$MULTIDEV1" -- search-api-pantheon:select "title:$UNIQUE_TITLE" --defType="" --rows=1 2>/dev/null | grep -v "notice" | grep -v "WARNING" | jq -r '.response.numFound' || echo "0")

    if [ "$MD1_SEARCH" -gt 0 ]; then
      log_success "✓ Content found in $MULTIDEV1 environment"
      ((TESTS_PASSED++))
    else
      log_warning "Content not found in $MULTIDEV1 (might need more time to index)"
    fi

    # Search for it in multidev2 (should NOT find it)
    MD2_SEARCH=$(terminus drush "$SITE.$MULTIDEV2" -- search-api-pantheon:select "title:$UNIQUE_TITLE" --defType="" --rows=1 2>/dev/null | grep -v "notice" | grep -v "WARNING" | jq -r '.response.numFound' || echo "0")

    if [ "$MD2_SEARCH" -eq 0 ]; then
      log_success "✓ $MULTIDEV1 content correctly isolated from $MULTIDEV2 environment"
      ((TESTS_PASSED++))
    else
      log_error "✗ $MULTIDEV1 content leaked into $MULTIDEV2 environment (data isolation broken)"
      ((TESTS_FAILED++))
    fi
  fi
fi

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

# Display environment details
echo ""
log_info "Environment Details:"
for ENV in "$MULTIDEV1" "$MULTIDEV2"; do
  if [ -n "${ENV_HOSTS[$ENV]}" ]; then
    echo ""
    log_info "$ENV environment:"
    echo "  Host: ${ENV_HOSTS[$ENV]}"
    echo "  Port: ${ENV_PORTS[$ENV]}"
    echo "  Path: ${ENV_PATHS[$ENV]}"
    echo "  Core: ${ENV_CORES[$ENV]}"
    echo "  Core Full Path: ${ENV_CORES_FULL[$ENV]}"
  fi
done

echo ""
echo "========================================"

if [ $TESTS_FAILED -eq 0 ]; then
  log_success "All multidev parity tests passed!"
  exit 0
else
  log_error "Some tests failed. Please review the output above."
  exit 1
fi
