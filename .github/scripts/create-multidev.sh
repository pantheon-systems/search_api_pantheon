#!/bin/bash
set -euo pipefail

MULTIDEV_NAME="$1"
TERMINUS_SITE="$2"
GITHUB_ENV_FILE="${3:-${GITHUB_ENV:-}}"
GIT_REF="${4:-dev-8.5.x}"
PHP_VERSION="${5:-}"

MULTIDEV="${MULTIDEV_NAME:0:11}"

if terminus multidev:list "$TERMINUS_SITE" --format=list | grep -q "^$MULTIDEV$"; then
  terminus multidev:delete "$TERMINUS_SITE.$MULTIDEV" --delete-branch --yes
fi

terminus multidev:create "$TERMINUS_SITE.dev" "$MULTIDEV"

echo "Getting Pantheon git URL..."
GIT_URL=$(terminus connection:info "$TERMINUS_SITE.$MULTIDEV" --field=git_url)
echo "Git URL: $GIT_URL"

echo "Cloning repository..."
GIT_SSH_COMMAND="ssh -v -o StrictHostKeyChecking=no" git clone "$GIT_URL" pantheon-site

cd pantheon-site

echo "Checking out branch $MULTIDEV..."
git checkout "$MULTIDEV"

composer config repositories.search_api_pantheon '{"type": "vcs", "url": "git@github.com:pantheon-systems/search_api_pantheon.git", "canonical": false}'
composer require "pantheon-systems/search_api_pantheon:${GIT_REF}" drupal/devel:~5.4

echo "Module installed at:"
find . -path '*/search_api_pantheon/search_api_pantheon.info.yml' -not -path './vendor/*' | head -1

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

SOLR_VER="${SOLR_VERSION:-8}"
echo "Setting Solr version to ${SOLR_VER}..."
if [ -f pantheon.yml ]; then
  if grep -q "^search:" pantheon.yml; then
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

if [ -n "$PHP_VERSION" ]; then
  echo "Setting PHP version to ${PHP_VERSION}..."
  if grep -q "php_version:" pantheon.yml; then
    sed -i "s/php_version:.*/php_version: ${PHP_VERSION}/" pantheon.yml
  else
    echo "php_version: ${PHP_VERSION}" >> pantheon.yml
  fi
fi

git add .
git commit -m "Add search_api_pantheon module (PHP ${PHP_VERSION:-default}, Solr ${SOLR_VER})"
git push --set-upstream origin "$MULTIDEV"

cd ..

echo "Waiting for Pantheon build to complete..."
terminus workflow:wait "$TERMINUS_SITE.$MULTIDEV" --max=300

echo "Enabling search_api_pantheon and devel_generate..."
terminus drush "$TERMINUS_SITE.$MULTIDEV" -- pm:enable search_api_pantheon devel_generate -y

echo "MULTIDEV_ENV=$MULTIDEV" >> "$GITHUB_ENV_FILE"
