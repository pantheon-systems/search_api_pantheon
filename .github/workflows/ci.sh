#!/usr/bin/bash
set -e
. "$(dirname "${BASH_SOURCE[0]}")/git-constraint-helper"
CONSTRAINT=$(get_current_constraint)
terminus site:create "$SITE" "$SITE" "drupal-$DRUPAL_VERSION-composer-managed" --org "$TERMINUS_ORG"
terminus connection:set "$SITE.dev" git
terminus local:clone "$SITE"
cd "$HOME/pantheon-local-copies/$SITE"
echo "search:" >> pantheon.yml
echo "  version: 8" >> pantheon.yml
composer require "pantheon-systems/search_api_pantheon:$CONSTRAINT" drupal/devel
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
FOUND=$(terminus drush sap-old-is-new.dev -- search-api-pantheon:select '*' |grep -v notice|jq '.response.numFound')
if [ "$FOUND" != "5" ]; then
  echo "Solr reports the number of documents is $FOUND, 5 was expected. See the following report for more info."
  terminus drush "$SITE.dev" sapd
  exit 1
fi
echo "Testing schema post"
terminus drush "$SITE.dev" -- search-api-pantheon:postSchema /code/web/modules/contrib/search_api_solr/jump-start/solr8/config-set
