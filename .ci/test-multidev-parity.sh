#!/bin/bash
# Test environment parity for search_api_pantheon using multidev environments
# Validates that Pantheon Search API correctly detects and uses different Solr cores
# for different multidev environments

set -e

if [ -z "$1" ] || [ -z "$2" ] || [ -z "$3" ]; then
  echo "Usage: $0 SITE_NAME MULTIDEV1 MULTIDEV2"
  exit 1
fi

SITE="$1"
MULTIDEV1="$2"
MULTIDEV2="$3"

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
NC='\033[0m'

# Test tracking
TESTS_PASSED=0
TESTS_FAILED=0

echo "Parity: $SITE ($MULTIDEV1, $MULTIDEV2)"

# Arrays to store results
declare -A ENV_HOSTS
declare -A ENV_PORTS
declare -A ENV_PATHS
declare -A ENV_CORES_FULL
declare -A ENV_TOKENS
declare -A ENV_CORES

# Disable exit on error for test assertions
set +e

for ENV in "$MULTIDEV1" "$MULTIDEV2"; do
  echo "Testing: $ENV"

  # Check if environment exists
  if ! terminus env:info "$SITE.$ENV" >/dev/null 2>&1; then
    echo -e "${RED}$ENV not accessible${NC}"
    ((TESTS_FAILED++))
    continue
  fi

  # Extract Pantheon environment variables
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

  # Validate environment variables
  if [ -z "${ENV_HOSTS[$ENV]}" ] || [ "${ENV_HOSTS[$ENV]}" = "false" ]; then
    echo -e "${RED}PANTHEON_INDEX_HOST not set${NC}"
    ((TESTS_FAILED++))
    continue
  fi

  if [ -z "${ENV_PORTS[$ENV]}" ] || [ "${ENV_PORTS[$ENV]}" = "false" ]; then
    echo -e "${RED}PANTHEON_INDEX_PORT not set${NC}"
    ((TESTS_FAILED++))
    continue
  fi

  if [ -z "${ENV_PATHS[$ENV]}" ] || [ "${ENV_PATHS[$ENV]}" = "false" ]; then
    echo -e "${RED}PANTHEON_INDEX_PATH not set${NC}"
    ((TESTS_FAILED++))
    continue
  fi

  if [ -z "${ENV_CORES_FULL[$ENV]}" ] || [ "${ENV_CORES_FULL[$ENV]}" = "false" ]; then
    echo -e "${RED}PANTHEON_INDEX_CORE not set${NC}"
    ((TESTS_FAILED++))
    continue
  fi

  ((TESTS_PASSED++))

  # Extract Solr core name from CORE path
  CORE_NAME=$(echo "${ENV_CORES_FULL[$ENV]}" | sed -n 's/.*environment\/\([^/]*\)\/.*/\1/p')
  ENV_CORES[$ENV]=$CORE_NAME

  # Validate core name matches environment
  if [ "$CORE_NAME" = "$ENV" ]; then
    ((TESTS_PASSED++))
  else
    echo -e "${RED}Core ($CORE_NAME) != env ($ENV)${NC}"
    ((TESTS_FAILED++))
  fi

  # Check Search API server configuration
  SERVER_CONFIG=$(terminus drush "$SITE.$ENV" -- config:get search_api.server.pantheon_search backend_config 2>/dev/null | grep -v "\[" | grep -v "WARNING" || echo "")

  if [ -n "$SERVER_CONFIG" ]; then
    ((TESTS_PASSED++))
  fi
done

# Cross-environment validation
echo ""
echo "Cross-env:"

# Test 1: Different Solr cores
if [ -n "${ENV_CORES_FULL[$MULTIDEV1]}" ] && [ -n "${ENV_CORES_FULL[$MULTIDEV2]}" ]; then
  if [ "${ENV_CORES_FULL[$MULTIDEV1]}" != "${ENV_CORES_FULL[$MULTIDEV2]}" ]; then
    echo -e "${GREEN}Different cores${NC}"
    ((TESTS_PASSED++))
  else
    echo -e "${RED}Same cores (should differ)${NC}"
    ((TESTS_FAILED++))
  fi
fi

# Test 2: Same host and port
if [ "${ENV_HOSTS[$MULTIDEV1]}" = "${ENV_HOSTS[$MULTIDEV2]}" ]; then
  echo -e "${GREEN}Same host${NC}"
  ((TESTS_PASSED++))
else
  echo -e "${RED}Different hosts${NC}"
  ((TESTS_FAILED++))
fi

if [ "${ENV_PORTS[$MULTIDEV1]}" = "${ENV_PORTS[$MULTIDEV2]}" ]; then
  echo -e "${GREEN}Same port${NC}"
  ((TESTS_PASSED++))
else
  echo -e "${RED}Different ports${NC}"
  ((TESTS_FAILED++))
fi

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
