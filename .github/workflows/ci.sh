#!/usr/bin/bash
. ./git-constraint.sh
terminus site:create $SITE_NAME $SITE_NAME drupal-10-composer-managed
terminus local:clone $SITE_NAME
cd $HOME/pantheon-local-copies/$SITE_NAME
echo "search:" >> pantheon.yml
echo "  version: 8" >> pantheon.yml
composer require pantheon-systems/search_api_pantheon:$(get_current_constraint) drupal/devel
terminus solr:enable $SITE_NAME
git commit -am 'modules, search'
git push
terminus workflow:wait --max=260 $SITE_NAME.dev
yes|terminus drush $SITE_NAME.dev si standard
yes|terminus drush $SITE_NAME.dev en search_api_pantheon,devel_generate
terminus drush $SITE_NAME.dev genc 5
indexed=$(terminus drush $SITE_NAME.dev sapi-s|grep primary|cut -c 36-36)
if [ "$result" == "5" ]; then
  exit 0
else
  echo "Expected '5', got '$result'" >&2
  terminus drush $SITE_NAME.dev sapd
  exit 1
fi
