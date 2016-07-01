#!/bin/bash

# Bring the code down to Circle so that modules can be added via composer.
git clone $(terminus site connection-info --field=git_url) drupal8
#git clone $(terminus site connection-info --field=git_url) drupal8 --branch=$TERMINUS_ENV
cd drupal8
git checkout -b $TERMINUS_ENV

# Tell Composer where to find packages.
composer config repositories.drupal composer https://packagist.drupal-composer.org


# This is a section that applies a patch to composer.json... So that Composer
# can apply a patch to drupal/migrate_upgrade from
# https://www.drupal.org/node/2751151.
# I hope you find the Rube Goldberg absurdity of this section as enjoyable
# as I do.
cp ../../../patches/core-composer.patch .
git apply core-composer.patch
rm core-composer.patch
composer require cweagans/composer-patches --prefer-dist

composer require solarium/solarium:3.6.*
composer require drupal/search_api:8.1.x-dev --prefer-dist
composer require drupal/search_api_solr:8.1.x-dev --prefer-dist
composer require drupal/search_api_page:8.1.x-dev --prefer-dist
# Make sure submodules are not committed.


composer config repositories.search_api_pantheon vcs git@github.com:stevector/search_api_pantheon.git
composer require  drupal/search_api_pantheon:dev-master#$CIRCLE_SHA1


rm -rf modules/search_api_solr/.git/
rm -rf modules/search_api/.git/
rm -rf modules/search_api_page/.git/
rm -rf modules/search_api_pantheon/.git/
rm -rf vendor/solarium/solarium/.git/


# Make a git commit
git config user.email "$GitEmail"
git config user.name "Circle CI Migration Automation"
git add .
git commit -m 'Result of build step'
git push
