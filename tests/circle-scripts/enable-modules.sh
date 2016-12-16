#!/bin/bash

set -x

terminus drush "cache-rebuild"
terminus drush "pml"
# Uninstall core search to reduce confusion in the UI.
terminus drush "pm-uninstall search -y"
terminus drush "en -y search_api_pantheon search_api_solr search_api_page search_api"
# @todo, would this test benefit from exporting/committing config?
terminus connection:set sftp
