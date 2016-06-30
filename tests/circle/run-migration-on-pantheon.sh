#!/bin/bash

# Make sure the source site is available.
terminus site wake --site=$PANTHEON_D7_SITE --env=$PANTHEON_D7_BRANCH

# Report the status of migrations before and after running migrations.
terminus drush "migrate-status"
# Run all the migrations.
terminus drush "migrate-import --all"
# Report the status of migrations before and after running migrations.
terminus drush "migrate-status"
