#!/bin/bash



terminus drush "cache-rebuild"
terminus drush "cache-rebuild"
terminus drush "en -y search_api_pantheon search_api_solr search_api_page search_api"
terminus drush "cache-rebuild"
terminus site set-connection-mode --mode=sftp
terminus drush "cache-rebuild"
terminus drush search-api-pantheon-schema-post
