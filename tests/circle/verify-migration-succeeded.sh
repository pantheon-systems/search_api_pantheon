#!/bin/bash

export BEHAT_PARAMS='{"extensions" : {"Behat\\MinkExtension" : {"base_url" : "http://'$TERMINUS_ENV'-'$TERMINUS_SITE'.pantheonsite.io"} }}'
cd ~/search_api_pantheon/tests/circle && ./../../vendor/bin/behat --config=behat/config.yml


export BEHAT_PARAMS='{"extensions" : {"Behat\\MinkExtension" : {"base_url" : "http://'$TERMINUS_ENV'-'$TERMINUS_SITE'.pantheonsite.io/"}, "Drupal\\DrupalExtension" : {"drush" :   {  "alias":  "@pantheon.'$TERMINUS_SITE'.'$TERMINUS_ENV'" }}}}'
# Make sure the site is accessible over the web before making requests to it with Behat.
curl http://$TERMINUS_ENV-$TERMINUS_SITE.pantheonsite.io/
cd ~/search_api_pantheon && ./vendor/bin/behat      --config=behat/behat-pantheon.yml      behat/features/solr
