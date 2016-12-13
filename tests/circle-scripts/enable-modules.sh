#!/bin/bash

set -ex

terminus drush $TERMINUS_SITE.$TERMINUS_ENV "cache-rebuild"
terminus drush $TERMINUS_SITE.$TERMINUS_ENV "pml"
# Uninstall core search to reduce confusion in the UI.
terminus drush $TERMINUS_SITE.$TERMINUS_ENV "pm-uninstall search -y"
terminus drush $TERMINUS_SITE.$TERMINUS_ENV "en -y search_api_pantheon search_api_solr search_api_page search_api"
# @todo, would this test benefit from exporting/committing config?
terminus connection:set $TERMINUS_SITE.$TERMINUS_ENV sftp
