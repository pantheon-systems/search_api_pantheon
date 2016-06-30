#!/bin/bash

# Bring the code down to Circle so that modules can be added via composer.
git clone $(terminus site connection-info --field=git_url) drupal8 --branch=$TERMINUS_ENV
cd drupal8

# Tell Composer where to find packages.
composer config repositories.drupal composer https://packagist.drupal-composer.org

composer config repositories.search_api_pantheon vcs git@github.com:stevector/search_api_pantheon.git
composer require  drupal/search_api_pantheon:dev-master#$CIRCLE_SHA1




composer require drupal/search_api_page:8.1.x-dev --prefer-dist
# Make sure submodules are not committed.

rm -rf modules/search_api_solr/.git/
rm -rf modules/search_api/.git/
rm -rf modules/search_api_page/.git/
rm -rf modules/search_api_pantheon/.git/
rm -rf vendor/solarium/solarium/.git/

mkdir modules/search_api_pantheon
# @todo, need better way to setup module. What is the composer way of doing this?
cp  ../../search_api_pantheon.* modules/search_api_pantheon/


# Make a git commit
git config user.email "$GitEmail"
git config user.name "Circle CI Migration Automation"
git add .
git commit -m 'Result of build step'
git push
