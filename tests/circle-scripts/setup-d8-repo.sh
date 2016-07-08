#!/bin/bash

# Bring the code down to Circle so that modules can be added via composer.
git clone $(terminus site connection-info --field=git_url) drupal8
cd drupal8
git checkout -b $TERMINUS_ENV

# Tell Composer where to find packages.
composer config repositories.drupal composer https://packagist.drupal-composer.org


# This is a section that applies a patch to composer.json... So that Composer
# can apply a patches. This step should not be necessary. It should be enough
# to require drupal/search_api_pantheon which can then define patches.
# I hope you find the Rube Goldberg absurdity of this section as enjoyable
# as I do.
# @todo, instead of using patches, make forks of solarium and search_api_solr
# and use those fork repos.
cp ../../../patches/core-composer.patch .
git apply core-composer.patch
rm core-composer.patch
composer require cweagans/composer-patches --prefer-dist
composer require drupal/search_api:8.1.x-dev#f8f9591057a387b879c7ce9af70884f6a1c51850 --prefer-dist
composer require drupal/search_api_page:8.1.x-dev#78cae9b745c55aded16e9fcf8e14bd0748fabcb3 --prefer-dist

#composer config repositories.solarium vcs git@github.com:stevector/solarium.git
composer require solarium/solarium:3.6.*
composer require drupal/search_api_solr:8.1.x-dev#98c0f51efb47b1dbcf85d3fd7a57ba6f57d4ba2d --prefer-dist


composer config repositories.search_api_pantheon vcs git@github.com:stevector/search_api_pantheon.git
composer require  drupal/search_api_pantheon:dev-master#$CIRCLE_SHA1

# Make sure submodules are not committed.
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
git push --set-upstream origin $TERMINUS_ENV
