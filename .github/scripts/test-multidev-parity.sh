#!/bin/bash
set -eo pipefail

# Environment parity test for CI.
# Validates that different multidev environments get different Solr cores
# but share the same host and port.
# Reads TERMINUS_SITE, MULTIDEV_ENV, and PARITY_ENV from environment,
# or accepts them as positional arguments.

SITE="${1:-$TERMINUS_SITE}"
MULTIDEV1="${2:-$MULTIDEV_ENV}"
MULTIDEV2="${3:-$PARITY_ENV}"

if [[ -z "$SITE" || -z "$MULTIDEV1" || -z "$MULTIDEV2" ]]; then
  echo "::error::Usage: $0 SITE_NAME MULTIDEV1 MULTIDEV2 (or set TERMINUS_SITE, MULTIDEV_ENV, PARITY_ENV)"
  exit 1
fi

TESTS_PASSED=0
TESTS_FAILED=0

echo "Parity: $SITE ($MULTIDEV1, $MULTIDEV2)"

# Portable variables (no associative arrays - bash 3 compatible)
HOST_1="" HOST_2=""
PORT_1="" PORT_2=""
CORE_1="" CORE_2=""

ENV_INDEX=0
for ENV in "$MULTIDEV1" "$MULTIDEV2"; do
  ENV_INDEX=$((ENV_INDEX + 1))
  echo "::group::Testing: $ENV"

  # Check if environment exists
  if ! terminus env:info "$SITE.$ENV" >/dev/null 2>&1; then
    echo "::error::$ENV not accessible"
    TESTS_FAILED=$((TESTS_FAILED + 1))
    echo "::endgroup::"
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
    HOST_1="$CUR_HOST"; PORT_1="$CUR_PORT"; CORE_1="$CUR_CORE"
  else
    HOST_2="$CUR_HOST"; PORT_2="$CUR_PORT"; CORE_2="$CUR_CORE"
  fi

  # Validate environment variables
  if [ -z "$CUR_HOST" ] || [ "$CUR_HOST" = "false" ]; then
    echo "::error::PANTHEON_INDEX_HOST not set for $ENV"
    TESTS_FAILED=$((TESTS_FAILED + 1))
    echo "::endgroup::"
    continue
  fi

  if [ -z "$CUR_PORT" ] || [ "$CUR_PORT" = "false" ]; then
    echo "::error::PANTHEON_INDEX_PORT not set for $ENV"
    TESTS_FAILED=$((TESTS_FAILED + 1))
    echo "::endgroup::"
    continue
  fi

  if [ -z "$CUR_PATH" ] || [ "$CUR_PATH" = "false" ]; then
    echo "::error::PANTHEON_INDEX_PATH not set for $ENV"
    TESTS_FAILED=$((TESTS_FAILED + 1))
    echo "::endgroup::"
    continue
  fi

  if [ -z "$CUR_CORE" ] || [ "$CUR_CORE" = "false" ]; then
    echo "::error::PANTHEON_INDEX_CORE not set for $ENV"
    TESTS_FAILED=$((TESTS_FAILED + 1))
    echo "::endgroup::"
    continue
  fi

  TESTS_PASSED=$((TESTS_PASSED + 1))

  # Extract Solr core name from CORE path
  CORE_NAME=$(echo "$CUR_CORE" | sed -n 's/.*environment\/\([^/]*\)\/.*/\1/p')

  # Validate core name matches environment
  if [ "$CORE_NAME" = "$ENV" ]; then
    echo "::notice::PASS: Core name matches environment ($ENV)"
    TESTS_PASSED=$((TESTS_PASSED + 1))
  else
    echo "::error::FAIL: Core ($CORE_NAME) != env ($ENV)"
    TESTS_FAILED=$((TESTS_FAILED + 1))
  fi

  # Check Search API server configuration
  SERVER_CONFIG=$(terminus drush "$SITE.$ENV" -- config:get search_api.server.pantheon_search backend_config 2>/dev/null | grep -v "\[" | grep -v "WARNING" || echo "")

  if [ -n "$SERVER_CONFIG" ]; then
    TESTS_PASSED=$((TESTS_PASSED + 1))
  fi

  echo "::endgroup::"
done

# Cross-environment validation
echo "::group::Cross-environment validation"

# Test 1: Different Solr cores
if [ -n "$CORE_1" ] && [ -n "$CORE_2" ]; then
  if [ "$CORE_1" != "$CORE_2" ]; then
    echo "::notice::PASS: Different cores"
    TESTS_PASSED=$((TESTS_PASSED + 1))
  else
    echo "::error::FAIL: Same cores (should differ)"
    TESTS_FAILED=$((TESTS_FAILED + 1))
  fi
fi

# Test 2: Same host and port
if [ "$HOST_1" = "$HOST_2" ]; then
  echo "::notice::PASS: Same host"
  TESTS_PASSED=$((TESTS_PASSED + 1))
else
  echo "::error::FAIL: Different hosts"
  TESTS_FAILED=$((TESTS_FAILED + 1))
fi

if [ "$PORT_1" = "$PORT_2" ]; then
  echo "::notice::PASS: Same port"
  TESTS_PASSED=$((TESTS_PASSED + 1))
else
  echo "::error::FAIL: Different ports"
  TESTS_FAILED=$((TESTS_FAILED + 1))
fi

echo "::endgroup::"

# Summary
echo "Pass:$TESTS_PASSED Fail:$TESTS_FAILED"

if [ $TESTS_FAILED -eq 0 ]; then
  echo "All parity tests passed"
  exit 0
else
  echo "::error::One or more parity tests failed"
  exit 1
fi
