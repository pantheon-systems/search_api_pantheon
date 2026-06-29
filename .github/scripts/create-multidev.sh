#!/bin/bash
set -euo pipefail

# Create a Pantheon multidev environment for CI testing.
# Clones the base site's dev environment, installs the module under test
# via Composer, configures PHP/Solr versions, and enables the module.

# Arguments
MULTIDEV_NAME="$1"       # Full multidev name (may exceed 11 chars, truncated below)
TERMINUS_SITE="$2"       # Pantheon site name (e.g. d10-search-api-pantheon)
GITHUB_ENV_FILE="${3:-${GITHUB_ENV:-}}"  # Path to $GITHUB_ENV file for exporting vars
GIT_REF="${4:-dev-8.5.x}" # Composer version constraint (e.g. dev-my-branch, 8.5.x-dev, 8.5.0-beta1)
PHP_VERSION="${5:-}"      # PHP version to set on the multidev (e.g. 8.1)

# Pantheon multidev names are limited to 11 characters
MULTIDEV="${MULTIDEV_NAME:0:11}"

# Delete existing multidev if present (from a previous failed run with same truncated name)
if terminus multidev:list "$TERMINUS_SITE" --format=list | grep -q "^$MULTIDEV$"; then
  terminus multidev:delete "$TERMINUS_SITE.$MULTIDEV" --delete-branch --yes
fi

# Create multidev from dev environment (inherits code, database, and Solr config)
terminus multidev:create "$TERMINUS_SITE.dev" "$MULTIDEV"

# Clone the Pantheon site repo so we can push composer changes
echo "Getting Pantheon git URL..."
GIT_URL=$(terminus connection:info "$TERMINUS_SITE.$MULTIDEV" --field=git_url)
echo "Git URL: $GIT_URL"

echo "Cloning repository..."
GIT_SSH_COMMAND="ssh -v -o StrictHostKeyChecking=no" git clone "$GIT_URL" pantheon-site

cd pantheon-site

echo "Checking out branch $MULTIDEV..."
git checkout "$MULTIDEV"

# Authenticate Composer to the GitHub API so VCS metadata requests use the
# authenticated rate limit (5000/hr) instead of the anonymous one (60/hr),
# which returns HTTP 429 on busy matrix runs. GH_TOKEN is the run's built-in
# GITHUB_TOKEN (contents: read on this repo is sufficient; no PAT needed).
if [ -n "${GH_TOKEN:-}" ]; then
  composer config --global --auth github-oauth.github.com "$GH_TOKEN"
fi

# Add module via VCS repo so we can install branch builds (not just tagged releases).
# devel provides the genc (generate content) command used by run-tests.sh.
composer config repositories.search_api_pantheon '{"type": "vcs", "url": "git@github.com:pantheon-systems/search_api_pantheon.git", "canonical": false}'
composer require "pantheon-systems/search_api_pantheon:${GIT_REF}" drupal/devel:~5.4

echo "Module installed at:"
find . -path '*/search_api_pantheon/search_api_pantheon.info.yml' -not -path './vendor/*' | head -1

# Remove nested .git dirs so Pantheon accepts the push.
# Composer VCS repos and some dependencies include their own .git directories
# which conflict with Pantheon's git-based deployment.
if [ -d web/modules/contrib/search_api_pantheon/.git ]; then
  MODULE_INSTALL_PATH="web/modules/contrib/search_api_pantheon"
elif [ -d modules/contrib/search_api_pantheon/.git ]; then
  MODULE_INSTALL_PATH="modules/contrib/search_api_pantheon"
fi
if [ -n "${MODULE_INSTALL_PATH:-}" ]; then
  echo "Removing .git from $MODULE_INSTALL_PATH"
  rm -rf "$MODULE_INSTALL_PATH/.git/"
fi
rm -rf vendor/*/.git/

# Set Solr version in pantheon.yml (SOLR_VERSION env var from CI matrix).
# Must anchor sed to indented lines to avoid clobbering api_version.
SOLR_VER="${SOLR_VERSION:-8}"
echo "Setting Solr version to ${SOLR_VER}..."
if [ -f pantheon.yml ]; then
  if grep -q "^search:" pantheon.yml; then
    # Update existing search version (anchored to indented lines to avoid clobbering api_version)
    sed -i "s/^\([[:space:]]*\)version: [0-9]*/\1version: ${SOLR_VER}/" pantheon.yml
  else
    echo "search:" >> pantheon.yml
    echo "  version: ${SOLR_VER}" >> pantheon.yml
  fi
else
  echo "api_version: 1" > pantheon.yml
  echo "search:" >> pantheon.yml
  echo "  version: ${SOLR_VER}" >> pantheon.yml
fi

# Set PHP version in pantheon.yml to match the CI matrix target
if [ -n "$PHP_VERSION" ]; then
  echo "Setting PHP version to ${PHP_VERSION}..."
  if grep -q "php_version:" pantheon.yml; then
    sed -i "s/php_version:.*/php_version: ${PHP_VERSION}/" pantheon.yml
  else
    echo "php_version: ${PHP_VERSION}" >> pantheon.yml
  fi
fi

# Push code to Pantheon and wait for the platform build to complete
git add .
git commit -m "Add search_api_pantheon module (PHP ${PHP_VERSION:-default}, Solr ${SOLR_VER})"
git push --set-upstream origin "$MULTIDEV"

cd ..

echo "Waiting for Pantheon build to complete..."
terminus workflow:wait "$TERMINUS_SITE.$MULTIDEV" --max=300

# Enable the module and devel_generate (provides genc command for generating test content)
echo "Enabling search_api_pantheon and devel_generate..."
terminus drush "$TERMINUS_SITE.$MULTIDEV" -- pm:enable search_api_pantheon devel_generate -y

# Export the truncated multidev name for subsequent CI steps (run-tests.sh, cleanup)
echo "MULTIDEV_ENV=$MULTIDEV" >> "$GITHUB_ENV_FILE"

# Create a second bare multidev for parity testing.
# Replace the last char of the truncated name to stay within the 11-char limit.
# e.g. 1081s8-2630 -> 1081s8-263b
PARITY_ENV="${MULTIDEV:0:10}b"
echo "Creating parity multidev: $PARITY_ENV"
if terminus multidev:list "$TERMINUS_SITE" --format=list | grep -q "^$PARITY_ENV$"; then
  terminus multidev:delete "$TERMINUS_SITE.$PARITY_ENV" --delete-branch --yes
fi
terminus multidev:create "$TERMINUS_SITE.dev" "$PARITY_ENV"
echo "PARITY_ENV=$PARITY_ENV" >> "$GITHUB_ENV_FILE"
