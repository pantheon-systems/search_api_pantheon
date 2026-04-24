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

# Portable variables (no associative arrays - bash 3 compatible)
HOST_1="" HOST_2=""
PORT_1="" PORT_2=""
PATH_1="" PATH_2=""
CORE_1="" CORE_2=""

# Disable exit on error for test assertions
set +e

ENV_INDEX=0
for ENV in "$MULTIDEV1" "$MULTIDEV2"; do
  ((ENV_INDEX++))
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
  " 2>/dev/null | grep -v "\[" | grep -v "WARNING")

  CUR_HOST=$(echo "$ENV_VARS" | grep "^HOST=" | cut -d= -f2)
  CUR_PORT=$(echo "$ENV_VARS" | grep "^PORT=" | cut -d= -f2)
  CUR_PATH=$(echo "$ENV_VARS" | grep "^PATH=" | cut -d= -f2)
  CUR_CORE=$(echo "$ENV_VARS" | grep "^CORE=" | cut -d= -f2-)

  if [ "$ENV_INDEX" -eq 1 ]; then
    HOST_1="$CUR_HOST"; PORT_1="$CUR_PORT"; PATH_1="$CUR_PATH"; CORE_1="$CUR_CORE"
  else
    HOST_2="$CUR_HOST"; PORT_2="$CUR_PORT"; PATH_2="$CUR_PATH"; CORE_2="$CUR_CORE"
  fi

  # Validate environment variables
  if [ -z "$CUR_HOST" ] || [ "$CUR_HOST" = "false" ]; then
    echo -e "${RED}PANTHEON_INDEX_HOST not set${NC}"
    ((TESTS_FAILED++))
    continue
  fi

  if [ -z "$CUR_PORT" ] || [ "$CUR_PORT" = "false" ]; then
    echo -e "${RED}PANTHEON_INDEX_PORT not set${NC}"
    ((TESTS_FAILED++))
    continue
  fi

  if [ -z "$CUR_PATH" ] || [ "$CUR_PATH" = "false" ]; then
    echo -e "${RED}PANTHEON_INDEX_PATH not set${NC}"
    ((TESTS_FAILED++))
    continue
  fi

  if [ -z "$CUR_CORE" ] || [ "$CUR_CORE" = "false" ]; then
    echo -e "${RED}PANTHEON_INDEX_CORE not set${NC}"
    ((TESTS_FAILED++))
    continue
  fi

  ((TESTS_PASSED++))

  # Extract Solr core name from CORE path
  CORE_NAME=$(echo "$CUR_CORE" | sed -n 's/.*environment\/\([^/]*\)\/.*/\1/p')

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
if [ -n "$CORE_1" ] && [ -n "$CORE_2" ]; then
  if [ "$CORE_1" != "$CORE_2" ]; then
    echo -e "${GREEN}Different cores${NC}"
    ((TESTS_PASSED++))
  else
    echo -e "${RED}Same cores (should differ)${NC}"
    ((TESTS_FAILED++))
  fi
fi

# Test 2: Same host and port
if [ "$HOST_1" = "$HOST_2" ]; then
  echo -e "${GREEN}Same host${NC}"
  ((TESTS_PASSED++))
else
  echo -e "${RED}Different hosts${NC}"
  ((TESTS_FAILED++))
fi

if [ "$PORT_1" = "$PORT_2" ]; then
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
