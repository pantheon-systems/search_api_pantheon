#!/bin/bash

# Bring the code down to Circle so that modules can be added via composer.
git clone $(terminus site connection-info --field=git_url) drupal8 --branch=$TERMINUS_ENV
cd drupal8

# Tell Composer where to find packages.
composer config repositories.drupal composer https://packagist.drupal-composer.org

# Bring in Migrate-related contrib modules.
composer require drupal/migrate_plus:8.2.x-dev --prefer-dist
composer require drupal/migrate_tools:8.2.x-dev --prefer-dist
composer require drupal/migrate_upgrade:8.2.x-dev --prefer-dist
# Make sure submodules are not committed.
rm -rf modules/migrate_plus/.git/
rm -rf modules/migrate_tools/.git/
rm -rf modules/migrate_upgrade/.git/

# Set up the settings.php connection to the source database.
cp ../fixtures/settings.migrate-on-pantheon.php sites/default/
cat ../fixtures/settings.php.addition >> sites/default/settings.php

# Make a git commit
git config user.email "$GitEmail"
git config user.name "Circle CI Migration Automation"
git add .
git commit -m 'Result of build step'
git push
