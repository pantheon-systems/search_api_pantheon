#!/bin/bash

export BEHAT_PARAMS='{"extensions" : {"Behat\\MinkExtension" : {"base_url" : "http://'$TERMINUS_ENV'-'$TERMINUS_SITE'.pantheonsite.io"} }}'
cd ~/migrate_pantheon/tests/circle && ./../../vendor/bin/behat --config=behat/config.yml
