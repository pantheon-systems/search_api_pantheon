#!/usr/bin/bash
set -e
. "$(dirname "${BASH_SOURCE[0]}")/git-constraint-helper"
CONSTRAINT=$(get_current_constraint)
terminus site:create "$SITE" "$SITE" "drupal-$DRUPAL_VERSION-composer-managed" --org "$TERMINUS_ORG"
terminus connection:set "$SITE.dev" git
terminus local:clone "$SITE"
cd "$HOME/pantheon-local-copies/$SITE"
SOLR_VERSION="${SOLR_VERSION:-8}"
echo "search:" >> pantheon.yml
echo "  version: $SOLR_VERSION" >> pantheon.yml
composer require "pantheon-systems/search_api_pantheon:$CONSTRAINT" drupal/devel:~5.4
terminus solr:enable "$SITE"
git commit -am 'modules, search'
git push
terminus workflow:wait --max=260 "$SITE.dev"
terminus drush -y "$SITE.dev" si standard
terminus drush -y "$SITE.dev" en search_api_pantheon,devel_generate
echo "Generating five nodes"
terminus drush "$SITE.dev" genc 5
echo "Verifying the number of indexed items in Search API"
INDEXED=$(terminus drush "$SITE.dev" -- sapi-s primary --fields=total --format=string)
if [ "$INDEXED" != "5" ]; then
  echo "Search API reports the number of indexed documents is $INDEXED, 5 was expected. See the following report for more info."
  terminus drush "$SITE.dev" sapd
  exit 1
fi
echo "Verifying the number of items in Solr directly"
FOUND=$(terminus drush "$SITE.dev" -- search-api-pantheon:select '*' |grep -v notice|jq .response.numFound)
if [ "$FOUND" != "5" ]; then
  echo "Solr reports the number of documents is $FOUND, 5 was expected. See the following report for more info."
  terminus drush "$SITE.dev" sapd
  exit 1
fi
echo "Testing schema post"
MAX_RETRIES=3
RETRY_DELAY=60
for i in $(seq 1 $MAX_RETRIES); do
  echo "Schema post attempt $i of $MAX_RETRIES"
  if terminus drush "$SITE.dev" -- search-api-pantheon:postSchema "/code/web/modules/contrib/search_api_solr/jump-start/solr${SOLR_VERSION}/config-set"; then
    echo "Schema post succeeded"
    break
  fi
  if [ "$i" -eq "$MAX_RETRIES" ]; then
    echo "Schema post failed after $MAX_RETRIES attempts"
    exit 1
  fi
  echo "Schema post failed, retrying in ${RETRY_DELAY}s..."
  sleep $RETRY_DELAY
done
