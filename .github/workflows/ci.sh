#!/usr/bin/bash
set -e
. "$(dirname "${BASH_SOURCE[0]}")/git-constraint.sh"
CONSTRAINT=$(get_current_constraint)
terminus site:create $SITE $SITE drupal-$DRUPAL_VERSION-composer-managed --org $TERMINUS_ORG
terminus connection:set $SITE.dev git
terminus local:clone $SITE
cd $HOME/pantheon-local-copies/$SITE
echo "search:" >> pantheon.yml
echo "  version: 8" >> pantheon.yml
composer require pantheon-systems/search_api_pantheon:$CONSTRAINT drupal/devel
terminus solr:enable $SITE
git commit -am 'modules, search'
git push
terminus workflow:wait --max=260 $SITE.dev
terminus drush -y $SITE.dev si standard
terminus drush -y $SITE.dev en search_api_pantheon,devel_generate
terminus drush $SITE.dev genc 5
indexed=$(terminus drush $SITE.dev sapi-s|grep primary|cut -c 36-36)
if [ "$indexed" == "5" ]; then
  exit 0
else
  echo "Expected '5', got '$result'" >&2
  terminus drush $SITE.dev sapd
  exit 1
fi
