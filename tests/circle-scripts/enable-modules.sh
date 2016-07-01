#!/bin/bash

terminus drush "cache-rebuild"
terminus drush "en -y search_api_pantheon search_api_solr search_api_page search_api"
# @todo, would this test benefit from exporting/committing config?
terminus site set-connection-mode --mode=sftp
terminus drush "cache-rebuild"
# @todo, make schema posting part of the install step for search_api_pantheon.
terminus drush search-api-pantheon-schema-post
