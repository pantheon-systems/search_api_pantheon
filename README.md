# Drupal 8 Migrate + Pantheon

[![CircleCI](https://circleci.com/gh/stevector/migrate_pantheon.svg?style=svg)](https://circleci.com/gh/stevector/migrate_pantheon)

This repository exists to validate a process for using Drupal 8's migrate suite of modules on Pantheon.
At some point in the future this repository might expand to include a helper module and/or scripts that are intended to be reused for performing real-world migrations.
However as this repository stands now, it is simply a meant to be an executable reference that shows how Drupal 8 migrations can be configured and run from a Drupal 6 or 7 source site on Pantheon to a different Drupal 8 site on Pantheon. CircleCI is used to run a series of scripts that validate that a very simple migration (A single node with no attached files) can migrate from one site to another.

## Steps performed by CircleCI repository

* Within a pre-existing plain Drupal 8 site, create a Pantheon Multidev environment based on CircleCI build number.
* Clone that Drupal 8 site inside CircleCI and install Migrate Upgrade, Migrate Tools, and Migrate Plus using Composer.
  * [Add a patch to Migrate Upgrade](https://www.drupal.org/node/2751151).
  * Configure settings.php to include settings.migrate-on-pantheon.php, which reads source database credentials from secrets.json.
* Creates secrets.json using the [Terminus secrets plugin](https://github.com/pantheon-systems/terminus-secrets-plugin).
* Configures migration using `drush migrate-upgrade --configure-only`
* Run migrations using `drush migrate-import --all`
* Validate that a node was migrated using Behat.

## Omissions

* This repository uses a Drupal 7 source site. If there is a significant difference in how Drupal 6 is handled it may be worthwhile to script that process as well.
* [File migrations are not shown or tested yet by these scripts](https://github.com/stevector/migrate_pantheon/issues/14).
