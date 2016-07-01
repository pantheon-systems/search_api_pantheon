#!/bin/bash

# Create a drush alias file so that Behat tests can be executed against Pantheon.
terminus sites aliases
# Drush Behat driver fails without this option.
echo "\$options['strict'] = 0;" >> ~/.drush/pantheon.aliases.drushrc.php

export BEHAT_PARAMS='{"extensions" : {"Behat\\MinkExtension" : {"base_url" : "http://'$TERMINUS_ENV'-'$TERMINUS_SITE'.pantheonsite.io/"}, "Drupal\\DrupalExtension" : {"drush" :   {  "alias":  "@pantheon.'$TERMINUS_SITE'.'$TERMINUS_ENV'" }}}}'
# Make sure the site is accessible over the web before making requests to it with Behat.
#curl http://$TERMINUS_ENV-$TERMINUS_SITE.pantheonsite.io/
cd ../../ && ./vendor/bin/behat --config=tests/behat/behat-pantheon.yml tests/behat/features
