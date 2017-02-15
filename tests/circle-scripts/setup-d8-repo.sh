#!/bin/bash

set -x

# Bring the code down to Circle so that modules can be added via composer.
#git clone $(terminus connection:info $SITE_ENV --field=git_url) drupal8
#cd drupal8
#git checkout -b $TERMINUS_ENV
terminus connection:set $SITE_ENV sftp

# Tell Composer where to find packages.
terminus composer $SITE_ENV -- config repositories.drupal composer https://packages.drupal.org/8

# These two lines are necessary only to force dev installs,
# otherwise the latest releases would be used.
terminus composer $SITE_ENV -- require drupal/search_api:1.x-dev --prefer-dist
terminus composer $SITE_ENV -- require drupal/search_api_solr:1.x-dev --prefer-dist

terminus composer $SITE_ENV -- require drupal/search_api_page:1.x-dev
terminus composer $SITE_ENV -- config repositories.search_api_pantheon vcs git@github.com:pantheon-systems/search_api_pantheon.git
terminus composer $SITE_ENV -- require drupal/search_api_pantheon:dev-8.x-1.x#$CIRCLE_SHA1

terminus env:diffstat $SITE_ENV
terminus env:commit $SITE_ENV --message="Adding Search API modules"
